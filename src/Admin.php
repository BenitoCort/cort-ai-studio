<?php
namespace CortAIStudio;

defined('ABSPATH') || exit;

final class Admin
{
    private const DASHBOARD = 'cort-ai-studio';
    private const WRITER = 'cort-ai-studio-writer';
    private const SETTINGS = 'cort-ai-studio-settings';

    private array $screens = [];

    public function __construct(private Settings $settings, private Jobs $jobs)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_post_cort_ai_studio_save', [$this, 'save']);
    }

    public function menu(): void
    {
        $cap = 'edit_others_posts';
        $this->screens['dashboard'] = add_menu_page(
            'Cort AI Studio', 'Cort AI Studio', $cap, self::DASHBOARD, [$this, 'dashboard'], 'dashicons-edit-large'
        );
        add_submenu_page(
            self::DASHBOARD, __('Dashboard', 'cort-ai-studio'), __('Dashboard', 'cort-ai-studio'),
            $cap, self::DASHBOARD, [$this, 'dashboard']
        );
        $this->screens['writer'] = add_submenu_page(
            self::DASHBOARD, __('AI Blog Writer', 'cort-ai-studio'), __('AI Blog Writer', 'cort-ai-studio'),
            $cap, self::WRITER, [$this, 'writer']
        );
        $this->screens['settings'] = add_submenu_page(
            self::DASHBOARD, __('Settings', 'cort-ai-studio'), __('Settings', 'cort-ai-studio'),
            'manage_options', self::SETTINGS, [$this, 'settings']
        );
    }

    public function assets(string $screen): void
    {
        if (!in_array($screen, $this->screens, true)) {
            return;
        }
        wp_enqueue_style('cort-ai-studio-admin', plugins_url('assets/admin.css', CORT_AI_STUDIO_FILE), [], CORT_AI_STUDIO_VERSION);
        if ($screen === $this->screens['dashboard']) {
            return;
        }
        wp_enqueue_script('cort-ai-studio-admin', plugins_url('assets/admin.js', CORT_AI_STUDIO_FILE), [], CORT_AI_STUDIO_VERSION, true);
        $efforts = [];
        foreach (Settings::models() as $model => $label) {
            $efforts[$model] = Settings::efforts($model);
        }
        $config = [
            'root' => rest_url(Rest::ROUTE . '/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'efforts' => $efforts,
            'strings' => [
                'network' => __('WordPress did not return a usable response. Check the connection and try to resume.', 'cort-ai-studio'),
                'session' => __('Your admin session expired. Reload this page.', 'cort-ai-studio'),
                'request' => __('Submitting the generation…', 'cort-ai-studio'),
                'resume_available' => __('A generation is ready to resume.', 'cort-ai-studio'),
                'retrying' => __('Connection interrupted. Checking the saved generation…', 'cort-ai-studio'),
                'stop_wait' => __('Ending the operation after the current stage…', 'cort-ai-studio'),
                'complete' => __('Draft ready', 'cort-ai-studio'),
                'partial' => __('Draft ready with notices', 'cort-ai-studio'),
                'failed' => __('Generation could not finish', 'cort-ai-studio'),
                'cancelled' => __('Generation cancelled', 'cort-ai-studio'),
                'draft_saved' => __('The article is saved as a WordPress draft.', 'cort-ai-studio'),
                'draft_unavailable' => __('The draft is no longer available to this account.', 'cort-ai-studio'),
                'draft_with_status' => __('WordPress status', 'cort-ai-studio'),
                'edit' => __('Edit Post', 'cort-ai-studio'),
                'preview' => __('Preview Post', 'cort-ai-studio'),
                'tokens_in' => __('Input tokens', 'cort-ai-studio'),
                'tokens_out' => __('Output tokens', 'cort-ai-studio'),
                'images' => __('Images generated', 'cort-ai-studio'),
                'web' => __('Web search calls', 'cort-ai-studio'),
                'cost' => __('Estimated cost (USD)', 'cort-ai-studio'),
                'usage_partial' => __('Some usage could not be measured.', 'cort-ai-studio'),
                'category_suggestions' => __('Suggested categories', 'cort-ai-studio'),
                'tag_suggestions' => __('Suggested tags', 'cort-ai-studio'),
                'seo_title' => __('SEO title', 'cort-ai-studio'),
                'seo_description' => __('Meta description', 'cort-ai-studio'),
                'testing' => __('Testing the saved API key…', 'cort-ai-studio'),
                'skip_images' => __('Skip remaining images', 'cort-ai-studio'),
                'cancel' => __('Cancel generation', 'cort-ai-studio'),
            ],
        ];
        wp_add_inline_script(
            'cort-ai-studio-admin',
            'window.CortAIStudioAdmin = ' .
                wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';',
            'before'
        );
    }

    public function dashboard(): void
    {
        $this->require_writer();
        $settings = $this->settings->read();
        $connection = $this->settings->connection($settings['model']);
        $admin = current_user_can('manage_options');
        $ready = $this->jobs->ready();
        $scope = $admin ? null : get_current_user_id();
        $metrics = $ready ? $this->jobs->metrics($scope) : [];
        $history = $ready ? $this->jobs->recent($scope) : [];
        require dirname(__DIR__) . '/views/dashboard.php';
    }

    public function writer(): void
    {
        $this->require_writer();
        $settings = $this->settings->read();
        $configured = $this->settings->api_key() !== '';
        $uploads = current_user_can('upload_files');
        $terms = get_terms(['taxonomy' => 'category', 'hide_empty' => false]);
        $categories = [0 => __('Match suggestions to existing categories', 'cort-ai-studio')];
        foreach (is_array($terms) ? $terms : [] as $term) {
            $categories[$term->term_id] = $term->name;
        }
        require dirname(__DIR__) . '/views/writer.php';
    }

    public function settings(): void
    {
        $this->require_settings();
        $settings = $this->settings->read();
        $configured = $this->settings->api_key() !== '';
        $config_key = $this->settings->has_config_key();
        $connection = $this->settings->connection($settings['model']);
        $saved = isset($_GET['cort_saved']) && is_string($_GET['cort_saved']) ? sanitize_key(wp_unslash($_GET['cort_saved'])) : '';
        require dirname(__DIR__) . '/views/settings.php';
    }

    public function save(): void
    {
        $this->require_settings();
        check_admin_referer('cort_ai_studio_save');
        $input = isset($_POST['settings']) && is_array($_POST['settings']) ? wp_unslash($_POST['settings']) : [];
        $key = isset($_POST['api_key']) && is_string($_POST['api_key']) ? wp_unslash($_POST['api_key']) : '';
        $accepted = $this->settings->save_key($key, isset($_POST['remove_key']));
        $this->settings->save($input);
        wp_safe_redirect(add_query_arg('cort_saved', $accepted ? 'success' : 'key_error', $this->url('settings')));
        exit;
    }

    private function select(string $id, string $name, array $choices, string|int $value): void
    {
        ?>
        <select id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>">
            <?php foreach ($choices as $key => $label) : ?>
                <option value="<?php echo esc_attr((string) $key); ?>" <?php selected((string) $key, (string) $value); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    private function url(string $screen): string
    {
        $pages = ['dashboard' => self::DASHBOARD, 'writer' => self::WRITER, 'settings' => self::SETTINGS,
            'page_builder' => PageBuilder\Module::PAGE, 'coming_soon' => 'cort-ai-studio-coming-soon'];
        return admin_url('admin.php?page=' . $pages[$screen]);
    }

    private function require_writer(): void
    {
        if (!Plugin::can_write()) {
            wp_die(esc_html__('You do not have permission to generate articles.', 'cort-ai-studio'), '', ['response' => 403]);
        }
    }

    private function require_settings(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage Cort AI Studio settings.', 'cort-ai-studio'), '', ['response' => 403]);
        }
    }
}
