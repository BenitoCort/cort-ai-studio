<?php
namespace CortAIStudio;

use WP_Error;
use WP_REST_Request;

defined('ABSPATH') || exit;

final class Rest
{
    public const ROUTE = 'cort-ai-studio/v1';

    public function __construct(
        private Writer $writer,
        private Settings $settings,
        private OpenAI $api,
        private Jobs $jobs
    ) {
    }

    public function register(): void
    {
        $write = [$this, 'writer_access'];
        register_rest_route(self::ROUTE, '/jobs', [
            'methods' => 'POST', 'callback' => [$this, 'start'], 'permission_callback' => $write,
        ]);
        register_rest_route(self::ROUTE, '/jobs/current', [
            'methods' => 'GET', 'callback' => [$this, 'current'], 'permission_callback' => $write,
        ]);
        register_rest_route(self::ROUTE, '/jobs/(?P<id>\d+)/advance', [
            'methods' => 'POST', 'callback' => [$this, 'advance'], 'permission_callback' => $write,
        ]);
        register_rest_route(self::ROUTE, '/jobs/(?P<id>\d+)/stop', [
            'methods' => 'POST', 'callback' => [$this, 'stop'], 'permission_callback' => $write,
        ]);
        register_rest_route(self::ROUTE, '/connection', [
            'methods' => 'POST', 'callback' => [$this, 'test'], 'permission_callback' => [$this, 'settings_access'],
        ]);
    }

    public function writer_access(WP_REST_Request $request): bool|WP_Error
    {
        $allowed = $this->permission($request, Plugin::can_write());
        if (is_wp_error($allowed)) {
            return $allowed;
        }
        return $this->jobs->ready() ? true : new WP_Error(
            'cort_ai_schema', __('The generation table is unavailable. Check the site database permissions.', 'cort-ai-studio'),
            ['status' => 503]
        );
    }

    public function settings_access(WP_REST_Request $request): bool|WP_Error
    {
        return $this->permission($request, current_user_can('manage_options'));
    }

    public function start(WP_REST_Request $request): array|WP_Error
    {
        $input = $request->get_json_params();
        if (!is_array($input) || strlen($request->get_body()) > 44000) {
            return new WP_Error('cort_ai_input', __('Send a valid article form within the allowed size.', 'cort-ai-studio'), ['status' => 400]);
        }
        return $this->writer->start($input);
    }

    public function current(): array|WP_Error
    {
        return $this->writer->current();
    }

    public function advance(WP_REST_Request $request): array|WP_Error
    {
        return $this->writer->advance((int) $request['id']);
    }

    public function stop(WP_REST_Request $request): array|WP_Error
    {
        return $this->writer->stop((int) $request['id']);
    }

    public function test(): array|WP_Error
    {
        $limit = 'cort_ai_studio_test_' . get_current_user_id();
        if (get_transient($limit)) {
            return new WP_Error('cort_ai_test_limit', __('Wait a few seconds before testing again.', 'cort-ai-studio'), ['status' => 429]);
        }
        set_transient($limit, 1, 4);
        $model = $this->settings->read()['model'];
        $fingerprint = $this->settings->key_fingerprint();
        $result = $this->api->test($model);
        $this->settings->record_connection($model, !is_wp_error($result), $fingerprint);

        if (is_wp_error($result)) {
            return new WP_Error($result->get_error_code(), $result->get_error_message(), ['status' => 400]);
        }
        return ['message' => __('Connection verified for the saved text model.', 'cort-ai-studio')];
    }

    private function permission(WP_REST_Request $request, bool $capable): bool|WP_Error
    {
        if (!$capable) {
            return new WP_Error('cort_ai_forbidden', __('You do not have permission to access Cort AI Studio.', 'cort-ai-studio'), ['status' => rest_authorization_required_code()]);
        }
        if (!wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')) {
            return new WP_Error('cort_ai_nonce', __('Your admin session expired. Reload this page and try again.', 'cort-ai-studio'), ['status' => 403]);
        }
        return true;
    }
}
