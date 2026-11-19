<?php
/** Copy to disposable WordPress wp-content/mu-plugins only for HTTP tests. */
defined('ABSPATH') || exit;
if (PHP_SAPI !== 'cli-server' || !defined('CORT_AI_PB_TEST_INSTALLATION') || CORT_AI_PB_TEST_INSTALLATION !== true || wp_get_environment_type() !== 'local' ||
    !is_file(ABSPATH . '.cort-ai-studio-test') || trim(file_get_contents(ABSPATH . '.cort-ai-studio-test')) !== 'CORT_AI_STUDIO_ISOLATED_TEST') return;
add_filter('pre_http_request', static function ($pre, $args, $url) {
    if (!str_starts_with($url, 'https://api.openai.com/v1')) return new WP_Error('test_network_blocked', 'External networking disabled in HTTP tests.');
    require_once dirname(CORT_AI_STUDIO_FILE) . '/tests/fixtures.php';
    if (str_contains($url, '/models/')) return ['response' => ['code' => 200], 'headers' => [], 'body' => wp_json_encode(['id' => basename($url)])];
    $plan = CortAIStudioTests\plan(2);
    $plan['sections'][0]['label'] = 'Hero';
    $plan['sections'][0]['columns'][0]['widgets'][0]['text'] = 'Build your next chapter';
    $plan['sections'][0]['columns'][0]['widgets'][0]['size'] = 48;
    $plan['sections'][0]['columns'][0]['widgets'][1]['text'] = 'An isolated test of native Elementor preview and approval.';
    $plan['sections'][1]['label'] = 'Our services';
    $plan['sections'][1]['columns'] = array_fill(0, 3, ['widgets' => [CortAIStudioTests\widget('icon'), CortAIStudioTests\widget('heading', 'A clear service'), CortAIStudioTests\widget('text-editor', 'Confirmed test information.')]]);
    $payload = json_decode($args['body'] ?? '{}', true);
    if (($payload['text']['format']['name'] ?? '') === 'cort_ai_design_direction') $plan = CortAIStudioTests\direction(2);
    return ['response' => ['code' => 200], 'headers' => [], 'body' => wp_json_encode([
        'id' => 'resp_http_test', 'status' => 'completed', 'usage' => ['input_tokens' => 120, 'output_tokens' => 220],
        'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => wp_json_encode($plan)]]]],
    ])];
}, 10, 3);
