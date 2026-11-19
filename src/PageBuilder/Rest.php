<?php
namespace CortAIStudio\PageBuilder;

use CortAIStudio\Rest as PluginRest;
use Throwable;
use WP_Error;
use WP_REST_Request;

defined('ABSPATH') || exit;

final class Rest
{
    public function __construct(private Service $service, private Adapter $adapter, private Repository $repository) {}

    public function register(): void
    {
        $routes = [
            '/status' => ['GET', [$this->service, 'status']],
            '/pages' => ['GET', [$this, 'pages']],
            '/pages/(?P<id>\d+)' => ['GET', fn(WP_REST_Request $r) => $this->adapter->inspect((int) $r['id'])],
            '/operations' => ['POST', fn(WP_REST_Request $r) => $this->service->start($this->body($r))],
            '/operations/(?P<id>\d+)' => ['GET', fn(WP_REST_Request $r) => $this->service->get((int) $r['id'])],
            '/operations/(?P<id>\d+)/advance' => ['POST', fn(WP_REST_Request $r) => $this->service->advance((int) $r['id'])],
            '/operations/(?P<id>\d+)/approve' => ['POST', fn(WP_REST_Request $r) => $this->service->approve((int) $r['id'], $this->body($r))],
            '/operations/(?P<id>\d+)/restore' => ['POST', fn(WP_REST_Request $r) => $this->service->restore((int) $r['id'], $this->body($r))],
            '/operations/(?P<id>\d+)/discard' => ['POST', fn(WP_REST_Request $r) => $this->service->discard((int) $r['id'])],
        ];
        foreach ($routes as $path => [$method, $callback]) {
            register_rest_route(PluginRest::ROUTE, '/page-builder' . $path, [
                'methods' => $method,
                'permission_callback' => [$this, 'permission'],
                'callback' => static function (WP_REST_Request $request) use ($path, $callback) {
                    try {
                        return $callback($request);
                    } catch (Throwable $error) {
                        error_log(sprintf('Cort AI Page Builder REST %s: %s at %s:%d', $path, get_class($error), basename($error->getFile()), $error->getLine()));
                        return Support::error('server', __('WordPress interrupted this Page Builder request. Check the server log and try again.', 'cort-ai-studio'), 500);
                    }
                },
            ]);
        }
    }

    public function permission(WP_REST_Request $request): bool|WP_Error
    {
        if (!Module::can_edit()) return Support::error('permission', __('You do not have permission to use AI Page Builder.', 'cort-ai-studio'), 403);
        if (!wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')) return Support::error('nonce', __('Your session expired. Reload this page.', 'cort-ai-studio'), 403);
        $body = (string) $request->get_body();
        if (strlen($body) > 44000 || ($request->get_method() === 'POST' && $body !== '' && !is_array($request->get_json_params()))) return Support::error('input', __('Send a valid page form within the allowed size.', 'cort-ai-studio'));
        return $this->repository->ready() ? true : Support::error('schema', __('The Page Builder operation table is unavailable.', 'cort-ai-studio'), 503);
    }

    public function pages(WP_REST_Request $request): array
    {
        $query = new \WP_Query([
            'post_type' => 'page', 'post_status' => ['draft', 'publish', 'private', 'pending', 'future'],
            'posts_per_page' => 30, 'paged' => max(1, min(1000, absint($request['page'] ?? 1))),
            's' => \CortAIStudio\Text::field($request['search'] ?? '', 100), 'orderby' => 'title', 'order' => 'ASC',
            'meta_query' => [['key' => '_cort_ai_pb_preview', 'compare' => 'NOT EXISTS']],
        ]);
        $pages = [];
        foreach ($query->posts as $post) {
            if (current_user_can('edit_post', $post->ID)) $pages[] = ['id' => $post->ID, 'title' => \CortAIStudio\Text::plain($post->post_title, 160), 'status' => $post->post_status];
        }
        return ['pages' => $pages, 'total_pages' => $query->max_num_pages];
    }

    private function body(WP_REST_Request $request): array
    {
        return $request->get_json_params() ?: [];
    }
}
