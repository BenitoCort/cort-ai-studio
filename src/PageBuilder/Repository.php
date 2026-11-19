<?php
namespace CortAIStudio\PageBuilder;

use CortAIStudio\Usage;

defined('ABSPATH') || exit;

/** Separate operation journal; shares Usage, without changing Blog Writer's table or locks. */
final class Repository
{
    public function ready(): bool
    {
        global $wpdb;
        $table = $this->table();
        if ((int) get_option('cort_ai_pb_schema', 0) !== 2) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $collation = $wpdb->get_charset_collate();
            dbDelta("CREATE TABLE $table (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned NOT NULL,
                request_id char(36) NOT NULL,
                page_id bigint(20) unsigned NOT NULL DEFAULT 0,
                preview_id bigint(20) unsigned NOT NULL DEFAULT 0,
                operation varchar(20) NOT NULL,
                model varchar(48) NOT NULL,
                status varchar(20) NOT NULL,
                active_owner bigint(20) unsigned DEFAULT NULL,
                input_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
                output_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
                images int(10) unsigned NOT NULL DEFAULT 0,
                web_calls int(10) unsigned NOT NULL DEFAULT 0,
                estimated_usd decimal(16,6) NOT NULL DEFAULT 0,
                usage_incomplete tinyint(1) unsigned NOT NULL DEFAULT 0,
                lease_token char(36) NOT NULL DEFAULT '',
                lease_until bigint(20) unsigned NOT NULL DEFAULT 0,
                state longtext NOT NULL,
                summary longtext NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY request_owner (user_id,request_id),
                UNIQUE KEY active_owner (active_owner),
                KEY page_activity (page_id,id),
                KEY owner_activity (user_id,id)
            ) $collation;");
            if ($wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM ' . $table . ' LIKE %s', 'summary')) === 'summary') update_option('cort_ai_pb_schema', 2, false);
        }
        return (int) get_option('cort_ai_pb_schema') === 2;
    }

    public function create(array $options, array $state): ?array
    {
        global $wpdb;
        $previous = $wpdb->suppress_errors(true);
        try {
            $ok = $wpdb->insert($this->table(), [
                'user_id' => get_current_user_id(), 'request_id' => $options['request_id'], 'page_id' => $options['page_id'],
                'operation' => $options['operation'], 'model' => $options['model'], 'status' => 'generating',
                'active_owner' => get_current_user_id(), 'state' => wp_json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'summary' => $this->summary($state),
                'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } finally {
            $wpdb->suppress_errors($previous);
        }
        return $ok === 1 ? $this->find((int) $wpdb->insert_id) : null;
    }

    public function find(int $id): ?array
    {
        global $wpdb;
        $table = $this->table();
        return $this->read($wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id), ARRAY_A));
    }

    public function request(int $user, string $request): ?array
    {
        global $wpdb;
        $table = $this->table();
        return $this->read($wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d AND request_id = %s", $user, $request), ARRAY_A));
    }

    public function active(int $user): ?array
    {
        global $wpdb;
        $table = $this->table();
        return $this->read($wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE active_owner = %d", $user), ARRAY_A));
    }

    public function claim(int $id): ?array
    {
        global $wpdb;
        $table = $this->table();
        $token = wp_generate_uuid4();
        $ok = $wpdb->query($wpdb->prepare("UPDATE $table SET lease_token = %s, lease_until = %d WHERE id = %d AND (lease_token = '' OR lease_until < %d)", $token, time() + 240, $id, time()));
        return $ok === 1 ? $this->find($id) : null;
    }

    public function store(array $row, bool $release = true): bool
    {
        global $wpdb;
        $state = wp_json_encode($row['state'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($state) || empty($row['lease_token'])) return false;
        $data = [
            'state' => $state, 'summary' => $this->summary($row['state']), 'page_id' => $row['page_id'], 'preview_id' => $row['preview_id'], 'status' => $row['status'],
            'active_owner' => in_array($row['status'], ['generating', 'preview', 'applying'], true) ? $row['user_id'] : null,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ] + $row['usage'];
        if ($release) $data += ['lease_token' => '', 'lease_until' => 0];
        $result = $wpdb->update($this->table(), $data, ['id' => $row['id'], 'lease_token' => $row['lease_token']]);
        return $result === 1 || ($result === 0 && ($this->find($row['id'])['lease_token'] ?? '') === $row['lease_token']);
    }

    public function history(?int $user, int $limit = 15): array
    {
        global $wpdb;
        $table = $this->table();
        $where = $user ? $wpdb->prepare(' WHERE user_id = %d', $user) : '';
        // History never loads fifteen full document snapshots into PHP memory.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id,user_id,request_id,page_id,preview_id,operation,model,status,
            input_tokens,output_tokens,images,web_calls,estimated_usd,usage_incomplete,lease_token,lease_until,
            summary AS state,created_at,updated_at FROM $table$where ORDER BY id DESC LIMIT %d", $limit), ARRAY_A) ?: [];
        return array_map([$this, 'read'], $rows);
    }

    public function metrics(?int $user): array
    {
        global $wpdb;
        $table = $this->table();
        $where = $user ? $wpdb->prepare(' WHERE user_id = %d', $user) : '';
        return $wpdb->get_row("SELECT COUNT(*) AS operations, SUM(input_tokens + output_tokens) AS tokens, SUM(estimated_usd) AS cost FROM $table$where", ARRAY_A) ?: [];
    }

    private function read(?array $row): ?array
    {
        if (!$row) return null;
        foreach (['id', 'user_id', 'page_id', 'preview_id', 'lease_until'] as $field) $row[$field] = (int) $row[$field];
        $row['state'] = json_decode($row['state'], true, 64) ?: [];
        $row['usage'] = [];
        foreach (Usage::empty() as $key => $value) $row['usage'][$key] = is_float($value) ? (float) $row[$key] : (int) $row[$key];
        return $row;
    }

    private function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'cort_ai_pb_operations';
    }

    private function summary(array $state): string
    {
        $summary = array_intersect_key($state, array_flip(['warnings', 'stage', 'paused', 'retry_at', 'summary', 'changes', 'preview_hash', 'reviewed_hash', 'backup_id', 'design_summary', 'design_quality']));
        $summary['options'] = ['title' => $state['options']['title'] ?? '', 'document_layout' => $state['options']['document_layout'] ?? 'preserve'];
        $summary['backup'] = !empty($state['backup']);
        return (string) wp_json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
