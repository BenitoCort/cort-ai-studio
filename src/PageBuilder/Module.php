<?php
namespace CortAIStudio\PageBuilder;

use CortAIStudio\Images;
use CortAIStudio\OpenAI;
use CortAIStudio\Settings;
use CortAIStudio\Usage;

defined('ABSPATH') || exit;

final class Module
{
    public const PAGE = 'cort-ai-studio-page-builder';
    private Repository $repository;
    private ElementorAdapter $adapter;
    private Service $service;
    private string $screen = '';
    private bool $preview_rendered = false;

    public function __construct(private Settings $settings, OpenAI $api)
    {
        $this->repository = new Repository();
        $this->adapter = new ElementorAdapter();
        $this->service = new Service($settings, $api, $this->repository, $this->adapter, new Blueprint(), new PreviewBackup($this->adapter), new Images());
    }

    public function register(): void
    {
        add_action('rest_api_init', [new Rest($this->service, $this->adapter, $this->repository), 'register']);
        add_action('admin_menu', [$this, 'menu'], 20);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('cort_ai_studio_dashboard_page_builder', [$this, 'dashboard']);
        add_filter('elementor/frontend/the_content', [$this, 'observe_preview']);
        add_action('wp_footer', [$this, 'preview_loaded'], 99);
        add_filter('wp_robots', [$this, 'robots']);
        add_filter('wp_insert_post_data', [$this, 'keep_preview_draft'], 10, 2);
        add_filter('display_post_states', [$this, 'post_states'], 10, 2);
        add_action('wp_enqueue_scripts', [$this, 'frontend_assets']);
        add_action('elementor_pro/forms/validation', [DraftForm::class, 'validate'], 10, 2);
    }

    public static function can_edit(): bool
    {
        return current_user_can('edit_pages') && current_user_can('edit_others_pages');
    }

    public function menu(): void
    {
        $this->screen = (string) add_submenu_page('cort-ai-studio', 'AI Page Builder', 'AI Page Builder', 'edit_others_pages', self::PAGE, [$this, 'page'], 2);
        add_submenu_page('cort-ai-studio', __('Coming Soon', 'cort-ai-studio'), __('Coming Soon', 'cort-ai-studio'), 'edit_others_posts', 'cort-ai-studio-coming-soon', [$this, 'coming_soon'], 3);
    }

    public function assets(string $screen): void
    {
        if ($screen !== $this->screen) return;
        wp_enqueue_style('cort-ai-studio-admin', plugins_url('assets/admin.css', CORT_AI_STUDIO_FILE), [], CORT_AI_STUDIO_VERSION);
        wp_enqueue_style('cort-ai-page-builder', plugins_url('assets/page-builder.css', CORT_AI_STUDIO_FILE), ['cort-ai-studio-admin'], CORT_AI_STUDIO_VERSION);
        if (current_user_can('upload_files')) wp_enqueue_media();
        $script = 'assets/page-builder.js';
        $script_version = CORT_AI_STUDIO_VERSION . '.' . filemtime(dirname(__DIR__, 2) . '/' . $script);
        wp_enqueue_script('cort-ai-page-builder', plugins_url($script, CORT_AI_STUDIO_FILE), [], $script_version, true);
        $efforts = [];
        foreach (Settings::models() as $model => $label) $efforts[$model] = Settings::efforts($model);
        wp_add_inline_script('cort-ai-page-builder', 'window.CortAIPageBuilder = ' . wp_json_encode([
            'root' => rest_url(\CortAIStudio\Rest::ROUTE . '/page-builder/'), 'nonce' => wp_create_nonce('wp_rest'),
            'efforts' => $efforts,
            'strings' => [
                'network' => __('The request could not be completed. Reload or resume the saved operation.', 'cort-ai-studio'),
                'timeout' => __('WordPress took too long to respond. Try again or resume the saved operation.', 'cort-ai-studio'),
                'http_error' => __('WordPress returned an unusable Page Builder response (HTTP %s).', 'cort-ai-studio'),
                'session' => __('Your session expired. Reload this page.', 'cort-ai-studio'),
                'loading' => __('Checking whether Page Builder can start a proposal…', 'cort-ai-studio'),
                'working' => __('Waiting for the current Page Builder request…', 'cort-ai-studio'),
                'loading_pages' => __('Loading existing pages…', 'cort-ai-studio'),
                'pages_failed' => __('Could not load existing pages. %s', 'cort-ai-studio'),
                'inspecting' => __('Checking the selected Elementor page…', 'cort-ai-studio'),
                'pending_review' => __('Review and apply or discard your current proposal before generating another.', 'cort-ai-studio'),
                'pending_generation' => __('Finish or discard your current proposal before generating another.', 'cort-ai-studio'),
                'pending_write' => __('Finish verifying the current operation before generating another.', 'cort-ai-studio'),
                'create_permission' => __('You do not have permission to create WordPress pages.', 'cort-ai-studio'),
                'operation_required' => __('Select an operation supported for this page selection.', 'cort-ai-studio'),
                'title_required' => __('Enter a page title.', 'cort-ai-studio'),
                'instructions_required' => __('Enter AI instructions.', 'cort-ai-studio'),
                'section_required' => __('Select an existing top-level section.', 'cort-ai-studio'),
                'anchor_required' => __('Select a different section as the insertion anchor.', 'cort-ai-studio'),
                'widgets_required' => __('No compatible classic widgets are registered for page generation.', 'cort-ai-studio'),
                'key_required' => __('Configure your OpenAI API key in Cort AI Studio Settings, then retry the status check.', 'cort-ai-studio'),
                'model_required' => __('Select a supported OpenAI model.', 'cort-ai-studio'),
                'effort_required' => __('Select a reasoning level supported by the OpenAI model.', 'cort-ai-studio'),
                'new_page' => __('Create New Page', 'cort-ai-studio'),
                'select_section' => __('Select a section', 'cort-ai-studio'), 'preview' => __('Proposal ready for review', 'cort-ai-studio'),
                'generating' => __('Generating proposal', 'cort-ai-studio'), 'applied' => __('Changes saved and validated', 'cort-ai-studio'),
                'restored' => __('Previous version restored', 'cort-ai-studio'), 'failed' => __('Proposal could not finish', 'cort-ai-studio'),
                'cancelled' => __('Proposal discarded', 'cort-ai-studio'), 'conflict' => __('Document changed; generate a fresh proposal', 'cort-ai-studio'),
                'applying' => __('Verifying application', 'cort-ai-studio'), 'restoring' => __('Verifying restoration', 'cort-ai-studio'),
                'review_pending' => __('Open the real preview and wait for Elementor to load before approving.', 'cort-ai-studio'),
                'review_ready' => __('The Elementor preview loaded. Review the layout and changes, then approve if correct.', 'cort-ai-studio'),
                'partial_usage' => __('Some API usage could not be measured. Check OpenAI for the billed amount.', 'cort-ai-studio'),
                'media_title' => __('Select or upload page images', 'cort-ai-studio'), 'media_button' => __('Use these images', 'cort-ai-studio'),
                'read_only' => __('This page cannot be edited safely in this release.', 'cort-ai-studio'),
            ],
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';', 'before');
    }

    public function frontend_assets(): void
    {
        $id = get_queried_object_id();
        $data = $id ? get_post_meta($id, '_elementor_data', true) : '';
        if (is_string($data) && str_contains($data, 'cort-ai-designed')) wp_enqueue_style('cort-ai-page-design', plugins_url('assets/page-design.css', CORT_AI_STUDIO_FILE), [], CORT_AI_STUDIO_VERSION);
    }

    public function page(): void
    {
        if (!self::can_edit()) wp_die(esc_html__('You cannot edit pages.', 'cort-ai-studio'), '', ['response' => 403]);
        $settings = $this->settings->read();
        $availability = $this->adapter->availability();
        $uploads = current_user_can('upload_files');
        require dirname(__DIR__, 2) . '/views/page-builder.php';
    }

    public function coming_soon(): void
    {
        if (!\CortAIStudio\Plugin::can_write()) wp_die(esc_html__('Access denied.', 'cort-ai-studio'));
        echo '<div class="wrap"><h1>Cort AI Studio · ' . esc_html__('Coming Soon', 'cort-ai-studio') . '</h1><p>' . esc_html__('More AI-powered WordPress tools are coming soon.', 'cort-ai-studio') . '</p></div>';
    }

    public function dashboard(): void
    {
        if (!self::can_edit() || !$this->repository->ready()) return;
        $scope = current_user_can('manage_options') ? null : get_current_user_id();
        $metrics = $this->repository->metrics($scope);
        ?>
        <section class="cort-ai-card" style="margin-top:15px">
            <div class="cort-ai-section-heading"><h2>AI Page Builder</h2>
                <p><?php echo esc_html(sprintf(__('Operations: %1$s · Tokens: %2$s · Estimated USD: %3$s', 'cort-ai-studio'), number_format_i18n((int) ($metrics['operations'] ?? 0)), number_format_i18n((int) ($metrics['tokens'] ?? 0)), Usage::dollars((float) ($metrics['cost'] ?? 0)))); ?></p>
            </div>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE)); ?>"><?php esc_html_e('Open Page Builder and operation history', 'cort-ai-studio'); ?></a>
        </section>
        <?php
    }

    public function observe_preview(string $content): string
    {
        $id = get_queried_object_id();
        if (get_post_meta($id, '_cort_ai_pb_preview', true) && str_contains($content, 'data-elementor-id="' . $id . '"')) $this->preview_rendered = true;
        return $content;
    }

    public function preview_loaded(): void
    {
        if (!$this->preview_rendered || !self::can_edit() || !is_preview()) return;
        $id = get_queried_object_id();
        $operation = (int) get_post_meta($id, '_cort_ai_pb_preview', true);
        $nonce = $_GET['cort_ai_pb_nonce'] ?? '';
        if (!$operation || !is_string($nonce) || !wp_verify_nonce(wp_unslash($nonce), 'cort_ai_pb_preview_' . $operation) || !current_user_can('edit_post', $id)) return;
        $row = $this->repository->find($operation);
        if (!$row || $row['preview_id'] !== $id) return;
        $hash = $row['state']['preview_hash'] ?? '';
        if (!$hash || !$this->service->mark_reviewed($operation, $hash)) return;
        echo '<script>window.parent.postMessage(' . wp_json_encode(['type' => 'cort-ai-pb-preview', 'id' => $operation, 'hash' => $hash], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ', window.location.origin);</script>';
    }

    public function robots(array $robots): array
    {
        if (get_post_meta(get_queried_object_id(), '_cort_ai_pb_preview', true)) $robots['noindex'] = true;
        return $robots;
    }

    public function keep_preview_draft(array $data, array $postarr): array
    {
        $id = (int) ($postarr['ID'] ?? 0);
        if ($id && ($data['post_type'] ?? '') === 'page' && get_post_meta($id, '_cort_ai_pb_preview', true) &&
            in_array($data['post_status'] ?? '', ['publish', 'future', 'private', 'pending'], true)) $data['post_status'] = 'draft';
        return $data;
    }

    public function post_states(array $states, \WP_Post $post): array
    {
        if (get_post_meta($post->ID, '_cort_ai_pb_preview', true)) $states['cort_ai_pb_preview'] = __('AI Page Builder proposal · approve in Page Builder', 'cort-ai-studio');
        return $states;
    }
}
