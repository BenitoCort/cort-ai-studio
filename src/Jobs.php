<?php
namespace CortAIStudio;

defined('ABSPATH') || exit;

final class Jobs
{
    private const SCHEMA_OPTION = 'cort_ai_studio_schema';
    private const SCHEMA_VERSION = 1;

    public static function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $collation = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            request_id char(36) NOT NULL,
            model varchar(48) NOT NULL,
            status varchar(16) NOT NULL,
            active_slot tinyint(1) unsigned DEFAULT NULL,
            post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            input_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
            output_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
            web_calls int(10) unsigned NOT NULL DEFAULT 0,
            images int(10) unsigned NOT NULL DEFAULT 0,
            estimated_usd decimal(16,6) NOT NULL DEFAULT 0,
            usage_incomplete tinyint(1) unsigned NOT NULL DEFAULT 0,
            lease_token char(36) NOT NULL DEFAULT '',
            lease_until bigint(20) unsigned NOT NULL DEFAULT 0,
            state longtext NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY active_slot (active_slot),
            UNIQUE KEY request_owner (user_id,request_id),
            KEY owner_activity (user_id,id)
        ) $collation;");

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table) {
            update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
        }
    }

    public function ready(): bool
    {
        if ((int) get_option(self::SCHEMA_OPTION, 0) !== self::SCHEMA_VERSION) {
            self::install();
        }
        return (int) get_option(self::SCHEMA_OPTION, 0) === self::SCHEMA_VERSION;
    }

    public function create(int $user, string $request, string $model, array $state): ?array
    {
        global $wpdb;

        $json = wp_json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return null;
        }
        $now = gmdate('Y-m-d H:i:s');
        $previous = $wpdb->suppress_errors(true);
        try {
            // A nullable unique slot allows any number of completed jobs and one active job.
            $inserted = $wpdb->insert(self::table(), [
                'user_id' => $user, 'request_id' => $request, 'model' => $model,
                'status' => 'running', 'active_slot' => 1, 'state' => $json,
                'created_at' => $now, 'updated_at' => $now,
            ], ['%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s']);
        } finally {
            $wpdb->suppress_errors($previous);
        }
        return $inserted === 1 ? $this->find((int) $wpdb->insert_id) : null;
    }

    public function find(int $id): ?array
    {
        global $wpdb;
        $table = self::table();
        return $this->read($wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id), ARRAY_A));
    }

    public function by_request(int $user, string $request): ?array
    {
        global $wpdb;
        $table = self::table();
        return $this->read($wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d AND request_id = %s",
            $user, $request
        ), ARRAY_A));
    }

    public function active(): ?array
    {
        global $wpdb;
        $table = self::table();
        return $this->read($wpdb->get_row("SELECT * FROM $table WHERE active_slot = 1", ARRAY_A));
    }

    public function latest(int $user): ?array
    {
        global $wpdb;
        $table = self::table();
        return $this->read($wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d ORDER BY id DESC LIMIT 1",
            $user
        ), ARRAY_A));
    }

    public function claim(int $id): ?array
    {
        global $wpdb;
        $table = self::table();
        $now = time();
        $token = wp_generate_uuid4();
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE $table SET lease_token = %s, lease_until = %d
            WHERE id = %d AND active_slot = 1 AND (lease_token = '' OR lease_until <= %d)",
            $token, $now + 240, $id, $now
        ));
        if ($claimed !== 1) {
            return null;
        }
        $job = $this->find($id);
        return $job && $job['lease_token'] === $token ? $job : null;
    }

    public function store(array $job, bool $release): bool
    {
        global $wpdb;
        $json = wp_json_encode($job['state'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $job['lease_token'] === '') {
            return false;
        }
        $fields = [
            'state' => $json,
            'status' => $job['status'],
            'post_id' => $job['post_id'],
            'active_slot' => $job['status'] === 'running' ? 1 : null,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
        $formats = ['%s', '%s', '%d', '%d', '%s'];
        foreach (Usage::empty() as $key => $value) {
            $fields[$key] = $job['usage'][$key];
            $formats[] = is_float($value) ? '%.6f' : '%d';
        }
        if ($release) {
            $fields['lease_token'] = '';
            $fields['lease_until'] = 0;
            array_push($formats, '%s', '%d');
        }
        return $wpdb->update(
            self::table(),
            $fields,
            ['id' => $job['id'], 'lease_token' => $job['lease_token']],
            $formats,
            ['%d', '%s']
        ) === 1;
    }

    public function metrics(?int $user): array
    {
        global $wpdb;
        $table = self::table();
        $where = $user === null ? '' : $wpdb->prepare(' WHERE user_id = %d', $user);
        $row = $wpdb->get_row("SELECT
            SUM(CASE WHEN post_id > 0 THEN 1 ELSE 0 END) AS articles,
            SUM(input_tokens + output_tokens) AS tokens,
            SUM(estimated_usd) AS cost,
            SUM(usage_incomplete) AS incomplete
            FROM $table$where", ARRAY_A);
        return is_array($row) ? $row : [];
    }

    public function recent(?int $user): array
    {
        global $wpdb;
        $table = self::table();
        $where = $user === null ? '' : $wpdb->prepare(' WHERE user_id = %d', $user);
        return $wpdb->get_results("SELECT id, user_id, model, status, post_id, input_tokens, output_tokens,
            images, web_calls, estimated_usd, usage_incomplete, created_at
            FROM $table$where ORDER BY id DESC LIMIT 12", ARRAY_A) ?: [];
    }

    private function read(?array $row): ?array
    {
        if (!$row) {
            return null;
        }
        foreach (['id', 'post_id', 'user_id', 'lease_until'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['state'] = json_decode($row['state'], true, 32);
        $row['state'] = is_array($row['state']) ? $row['state'] : [];
        $row['usage'] = [];
        foreach (Usage::empty() as $key => $value) {
            $row['usage'][$key] = is_float($value) ? (float) $row[$key] : (int) $row[$key];
        }
        return $row;
    }

    private static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'cort_ai_studio_jobs';
    }
}
