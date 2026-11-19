<?php
/** Disposable test-server router. NEVER configure on a real site or public interface. */
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/__cort_test_session') return false;
$root = rtrim((string) getenv('CORT_AI_TEST_WP_ROOT'), '/\\');
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) || !$root ||
    !is_file($root . '/.cort-ai-studio-test') || trim(file_get_contents($root . '/.cort-ai-studio-test')) !== 'CORT_AI_STUDIO_ISOLATED_TEST') {
    http_response_code(403); exit('Isolated test installation required.');
}
require $root . '/wp-load.php';
if (!defined('CORT_AI_PB_TEST_INSTALLATION') || CORT_AI_PB_TEST_INSTALLATION !== true || wp_get_environment_type() !== 'local') { http_response_code(403); exit; }
$user = get_user_by('login', 'cort_test_admin');
if (!$user || !user_can($user, 'manage_options')) { http_response_code(403); exit; }
wp_set_current_user($user->ID);
wp_set_auth_cookie($user->ID);
wp_safe_redirect(admin_url('admin.php?page=cort-ai-studio-page-builder'));
exit;
