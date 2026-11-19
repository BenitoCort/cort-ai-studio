<?php
/**
 * Plugin Name: Cort AI Studio
 * Description: Create blog drafts and native Elementor page proposals with OpenAI, previews, approval, and backups.
 * Version: 1.3.0
 * Author: Benito Cortes
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Text Domain: cort-ai-studio
 */

defined('ABSPATH') || exit;

if (version_compare(PHP_VERSION, '8.1', '<') || version_compare(get_bloginfo('version'), '6.5', '<')) {
    add_action('admin_notices', static function () {
        echo '<div class="notice notice-error"><p>' .
            esc_html__('Cort AI Studio requires WordPress 6.5 or newer and PHP 8.1 or newer.', 'cort-ai-studio') .
            '</p></div>';
    });
    return;
}

define('CORT_AI_STUDIO_VERSION', '1.3.0');
define('CORT_AI_STUDIO_FILE', __FILE__);

spl_autoload_register(static function (string $class): void {
    $prefix = 'CortAIStudio\\';
    if (str_starts_with($class, $prefix)) {
        $name = substr($class, strlen($prefix));
        if (preg_match('/^[A-Za-z][A-Za-z0-9]*(?:\\\\[A-Za-z][A-Za-z0-9]*)*$/D', $name)) {
            $file = __DIR__ . '/src/' . str_replace('\\', '/', $name) . '.php';
            if (is_readable($file)) {
                require_once $file;
            }
        }
    }
});

register_activation_hook(__FILE__, [CortAIStudio\Jobs::class, 'install']);
add_action('plugins_loaded', [CortAIStudio\Plugin::class, 'boot']);
