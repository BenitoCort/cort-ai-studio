<?php
namespace CortAIStudio;

defined('ABSPATH') || exit;

final class Plugin
{
    public static function boot(): void
    {
        $settings = new Settings();
        $jobs = new Jobs();
        $api = new OpenAI($settings);
        $drafts = new Drafts();
        $writer = new Writer($settings, $jobs, $api, new Sources(), new Article(), $drafts, new Images());

        $rest = new Rest($writer, $settings, $api, $jobs);
        add_action('rest_api_init', [$rest, 'register']);

        // Page Builder owns its routes, persistence and adapter; the article pipeline is unchanged.
        (new PageBuilder\Module($settings, $api))->register();

        if (is_admin()) {
            (new Admin($settings, $jobs))->register();
            $drafts->register_editor();
        }
    }

    public static function can_write(): bool
    {
        return current_user_can('edit_posts') && current_user_can('edit_others_posts');
    }
}
