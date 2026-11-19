<?php
/** Run only against a disposable WordPress installation; see docs/page-builder.md. */
namespace CortAIStudioTests;

use CortAIStudio\PageBuilder\Blueprint;
use CortAIStudio\PageBuilder\ElementorAdapter;
use CortAIStudio\PageBuilder\Module;
use CortAIStudio\PageBuilder\PreviewBackup;
use CortAIStudio\PageBuilder\Repository;
use CortAIStudio\PageBuilder\Service;
use CortAIStudio\Settings;
use CortAIStudio\OpenAI;
use CortAIStudio\Images;

if (PHP_SAPI !== 'cli') exit('CLI only.');
$root = rtrim((string) getenv('CORT_AI_TEST_WP_ROOT'), '/\\');
if (!$root || !is_file($root . '/.cort-ai-studio-test') || trim(file_get_contents($root . '/.cort-ai-studio-test')) !== 'CORT_AI_STUDIO_ISOLATED_TEST') exit("Refusing to run: an explicit isolated test installation marker is required.\n");
require $root . '/wp-load.php';
if (!defined('CORT_AI_PB_TEST_INSTALLATION') || CORT_AI_PB_TEST_INSTALLATION !== true || wp_get_environment_type() !== 'local') exit("Refusing to run outside an explicitly isolated local test installation.\n");
require __DIR__ . '/fixtures.php';
require_once ABSPATH . 'wp-admin/includes/post.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$admin = get_user_by('login', 'cort_test_admin');
if (!$admin) exit("Create the cort_test_admin test user first.\n");
wp_set_current_user($admin->ID);
$restInitHooks = clone $GLOBALS['wp_filter']['rest_api_init'];
$settings = new Settings();
$settings->save_key('sk-cort-ai-isolated-test-key', false);
$config = $settings->read(); $config['budget'] = 25; $settings->save($config);
$adapter = new ElementorAdapter(); $repo = new Repository(); $repo->ready();
$blueprint = new Blueprint(); $api = new OpenAI($settings);
$service = new Service($settings, $api, $repo, $adapter, $blueprint, new PreviewBackup($adapter), new Images());
$pending = $repo->active($admin->ID);
if ($pending && in_array($pending['status'], ['applying', 'restoring'], true)) $service->advance($pending['id']);
$pending = $repo->active($admin->ID);
if ($pending) $service->discard($pending['id']);
$calls = []; $nextPlan = plan(); $nextArticle = null; $transportFailure = false; $imageFailure = true;
add_filter('pre_http_request', function ($pre, $args, $url) use (&$calls, &$nextPlan, &$nextArticle, &$transportFailure, &$imageFailure) {
    if (!str_starts_with($url, 'https://api.openai.com/v1')) return new \WP_Error('test_network_blocked', 'External networking disabled in tests.');
    $calls[] = [$args['method'], $url];
    if ($transportFailure) return new \WP_Error('test_interrupted', 'Simulated transport interruption.');
    if (str_ends_with($url, '/images/generations')) {
        if ($imageFailure) return ['response' => ['code' => 500], 'body' => '{"error":{"code":"server_error"}}', 'headers' => []];
        $jpeg = getenv('CORT_AI_TEST_JPEG');
        return ['response' => ['code' => 200], 'body' => wp_json_encode(['data' => [['b64_json' => base64_encode(file_get_contents($jpeg))]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 20]]), 'headers' => []];
    }
    $payload = json_decode($args['body'] ?? '{}', true);
    $result = ($payload['text']['format']['name'] ?? '') === 'cort_ai_blog_article' ? $nextArticle : $nextPlan;
    if (($payload['text']['format']['name'] ?? '') === 'cort_ai_design_direction') $result = direction(max(1, count($nextPlan['sections'])));
    return ['response' => ['code' => 200], 'body' => wp_json_encode(['id' => 'resp_isolated_' . count($calls), 'status' => 'completed', 'usage' => ['input_tokens' => 100, 'output_tokens' => 200, 'input_tokens_details' => ['cached_tokens' => 10]], 'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => wp_json_encode($result)]]]]]), 'headers' => []];
}, 10, 3);

$passed = 0; $failed = 0;
function check(bool $condition, string $message): void { if (!$condition) throw new \RuntimeException($message); }
function usable(mixed $value): mixed { if (is_wp_error($value)) throw new \RuntimeException($value->get_error_code() . ': ' . $value->get_error_message()); return $value; }
function test(string $name, callable $callback): void {
    global $passed, $failed, $admin, $repo, $service;
    try { $callback(); ++$passed; echo "PASS $name\n"; }
    catch (\Throwable $error) { ++$failed; echo "FAIL $name: {$error->getMessage()}\n"; }
    finally {
        wp_set_current_user($admin->ID);
        $pending = $repo->active($admin->ID);
        if ($pending && in_array($pending['status'], ['generating', 'preview'], true)) $service->discard($pending['id']);
    }
}
function options(string $op = 'create', int $id = 0, array $extra = []): array { return $extra + ['request_id' => wp_generate_uuid4(), 'page_id' => $id, 'operation' => $op, 'title' => 'Isolated AI page', 'slug' => 'isolated-ai-page', 'model' => Settings::DEFAULT_MODEL, 'effort' => 'medium', 'instructions' => 'Use confirmed business information.', 'position' => 'end', 'image_count' => 0, 'media_ids' => []]; }
function release_cooldown(): void { global $wpdb; $wpdb->query("UPDATE {$wpdb->prefix}cort_ai_pb_operations SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 SECOND)"); }
function finish(Service $service, Repository $repo, array $row): array {
    for ($i = 0; $i < 20 && $row['status'] === 'generating'; ++$i) {
        $claimed = $repo->claim($row['id']);
        if ($claimed) { $claimed['state']['retry_at'] = 0; $repo->store($claimed); }
        $row = usable($service->advance($row['id']));
    }
    return $row;
}
function approve(Service $service, array $row): array { check($service->mark_reviewed($row['id'], $row['preview_hash']), 'Preview acknowledgement failed.'); return usable($service->approve($row['id'], ['approved' => true, 'preview_hash' => $row['preview_hash']])); }
function flatten(array $elements): array {
    $result = [];
    foreach ($elements as $node) { $result[] = $node; $result = array_merge($result, flatten($node['elements'] ?? [])); }
    return $result;
}

$sourceId = 0; $newId = 0;
test('Elementor detection and existing OpenAI settings', function () use ($adapter, $settings) {
    check($adapter->availability()['supported'], 'Installed Elementor must be in the supported range.');
    check($settings->api_key() === 'sk-cort-ai-isolated-test-key', 'Shared API key missing.');
    check(isset($settings->read()['rates']['luna_input']), 'Shared rates missing.');
});
test('Native Flexbox save/render and exact settings round trip', function () use ($blueprint, $adapter, &$sourceId) {
    $data = usable($blueprint->apply([], plan(2), options(), [], $adapter->design(), 'flexbox'));
    $document = \Elementor\Plugin::$instance->documents->create('wp-page', ['post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Original test page']);
    $sourceId = $document->get_post()->ID;
    usable($adapter->save($sourceId, $data['elements']));
    check(str_contains(usable($adapter->render($sourceId)), 'Confirmed business content'), 'Real Elementor render missing.');
    wp_update_post(['ID' => $sourceId, 'post_status' => 'publish']);
});
test('Classic sections/columns save as native Elementor elements', function () use ($blueprint, $adapter) {
    $plan = plan(); $plan['sections'][0]['columns'] = array_fill(0, 3, $plan['sections'][0]['columns'][0]);
    $data = usable($blueprint->apply([], $plan, options(), [], $adapter->design(), 'classic'));
    check($data['elements'][0]['elements'][0]['settings']['_column_size'] === 33, 'Three-column classic CSS width must use Elementor col-33.');
    $document = \Elementor\Plugin::$instance->documents->create('wp-page', ['post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Classic fixture']);
    usable($adapter->save($document->get_post()->ID, $data['elements']));
    check($adapter->inspect($document->get_post()->ID)['layout'] === 'classic', 'Classic hierarchy missing.');
});
test('AI page generation, enforced preview approval, and draft-only creation', function () use ($service, $repo, &$nextPlan, &$newId) {
    release_cooldown(); $nextPlan = plan();
    $row = finish($service, $repo, usable($service->start(options())));
    check($row['status'] === 'preview', json_encode($row['warnings']));
    check($row['usage']['input_tokens'] === 200 && $row['usage']['output_tokens'] === 400, 'Both planning and composition usage must be recorded.');
    wp_update_post(['ID' => $row['preview_id'], 'post_status' => 'publish']);
    check(get_post_status($row['preview_id']) === 'draft', 'A temporary proposal was published outside approval.');
    // A rejected manual publication may still update the timestamp; regenerate the preview hash.
    $operation = $repo->claim($row['id']);
    $operation['state']['preview_hash'] = (new ElementorAdapter())->fingerprint(usable((new ElementorAdapter())->snapshot($row['preview_id'])));
    $repo->store($operation); $row = usable($service->get($row['id']));
    check(is_wp_error($service->approve($row['id'], ['approved' => true, 'preview_hash' => $row['preview_hash']])), 'Unreviewed preview must not apply.');
    $row = approve($service, $row);
    check($row['status'] === 'applied', json_encode($row['warnings']));
    $newId = $row['page_id'];
    check(get_post_status($newId) === 'draft' && !get_post_meta($newId, '_cort_ai_pb_preview', true), 'New page must remain a final editable draft.');
    check($service->approve($row['id'], ['approved' => true])['page_id'] === $newId, 'Approval must be idempotent.');
});
test('Add section preserves published original until approval and restores exact backup', function () use ($service, $repo, $adapter, &$nextPlan, &$sourceId) {
    check($sourceId > 0, 'Native source fixture required.'); release_cooldown(); $nextPlan = plan();
    $before = usable($adapter->snapshot($sourceId));
    $row = finish($service, $repo, usable($service->start(options('add', $sourceId))));
    check($row['status'] === 'preview', json_encode($row['warnings']));
    check($adapter->fingerprint(usable($adapter->snapshot($sourceId))) === $adapter->fingerprint($before), 'Published original changed before approval.');
    $row = approve($service, $row); check($row['status'] === 'applied', json_encode($row['warnings']));
    $after = usable($adapter->snapshot($sourceId));
    check(count($after['elements']) === 3 && get_post_status($sourceId) === 'publish', 'Append or status preservation failed.');
    check(ElementorAdapter::hash(array_slice($after['elements'], 0, 2)) === ElementorAdapter::hash($before['elements']), 'Unrelated nodes changed.');
    $restored = usable($service->restore($row['id'], ['approved' => true]));
    check($restored['status'] === 'restored', json_encode($restored['warnings']));
    $snapshot = usable($adapter->snapshot($sourceId));
    check(ElementorAdapter::hash($snapshot['elements']) === ElementorAdapter::hash($before['elements']), 'Backup elements not restored.');
    check(ElementorAdapter::hash($snapshot['meta']) === ElementorAdapter::hash($before['meta']), 'Backup metadata not restored.');
});
test('Text update keeps widget IDs and responsive styling intact', function () use ($service, $repo, $adapter, &$nextPlan, &$sourceId) {
    release_cooldown(); $before = usable($adapter->snapshot($sourceId));
    $section = $before['elements'][0]; $widget = $section['elements'][0]['elements'][0];
    $nextPlan = ['summary' => 'Rewrite selected heading.', 'unsupported_reason' => '', 'sections' => [], 'images' => [], 'updates' => [['id' => $widget['id'], 'field' => 'title', 'value' => 'A professional heading', 'media_ref' => '']]];
    $row = finish($service, $repo, usable($service->start(options('content', $sourceId, ['section_id' => $section['id']]))));
    check($row['status'] === 'preview', json_encode($row['warnings'])); $row = approve($service, $row);
    check($row['status'] === 'applied', json_encode($row['warnings']));
    $after = usable($adapter->snapshot($sourceId));
    $expected = $before['elements']; $expected[0]['elements'][0]['elements'][0]['settings']['title'] = 'A professional heading';
    check(ElementorAdapter::hash($after['elements']) === ElementorAdapter::hash($expected), 'Settings outside the requested title changed.');
    usable($service->restore($row['id'], ['approved' => true]));
});
test('Remove and reorder use deterministic proposals without API charges', function () use ($service, $repo, $adapter, &$calls, &$sourceId) {
    foreach (['reorder', 'remove'] as $op) {
        release_cooldown(); $before = usable($adapter->snapshot($sourceId)); $count = count($calls);
        $row = finish($service, $repo, usable($service->start(options($op, $sourceId, ['section_id' => $before['elements'][0]['id'], 'position' => 'end']))));
        check($row['status'] === 'preview', json_encode($row['warnings'])); check(count($calls) === $count, 'Deterministic operation called OpenAI.');
        $row = approve($service, $row); check($row['status'] === 'applied', json_encode($row['warnings']));
        $row = usable($service->restore($row['id'], ['approved' => true])); check($row['status'] === 'restored', json_encode($row['warnings']));
    }
});
test('Concurrency conflict refuses application without overwriting an editor', function () use ($service, $repo, &$nextPlan, &$sourceId) {
    release_cooldown(); $nextPlan = plan();
    $row = finish($service, $repo, usable($service->start(options('add', $sourceId))));
    check($row['status'] === 'preview', json_encode($row['warnings']));
    wp_update_post(['ID' => $sourceId, 'post_title' => 'Concurrent editor title']);
    $row = approve($service, $row);
    check($row['status'] === 'conflict' && get_the_title($sourceId) === 'Concurrent editor title', 'Concurrent changes were overwritten.');
});
test('A failed Elementor save rolls back the published page', function () use ($service, $repo, $adapter, &$nextPlan, &$sourceId) {
    release_cooldown(); $nextPlan = plan(); $before = usable($adapter->snapshot($sourceId));
    $row = finish($service, $repo, usable($service->start(options('add', $sourceId))));
    check($row['status'] === 'preview', json_encode($row['warnings']));
    $filter = static function ($data, $doc) use ($sourceId) { if ($doc->get_post()->ID === $sourceId && isset($data['elements'])) array_pop($data['elements']); return $data; };
    add_filter('elementor/document/save/data', $filter, 99, 2);
    try { $row = approve($service, $row); }
    finally { remove_filter('elementor/document/save/data', $filter, 99); }
    check($row['status'] === 'preview', 'Failed application was marked successful.');
    check($adapter->fingerprint(usable($adapter->snapshot($sourceId))) === $adapter->fingerprint($before), 'Failed application did not roll back.');
});
test('Preview tampering and active editor locks prevent writes', function () use ($service, $repo, &$nextPlan, &$sourceId) {
    release_cooldown();
    update_post_meta($sourceId, '_edit_lock', time() . ':' . get_current_user_id());
    check(is_wp_error($service->start(options('add', $sourceId))), 'Active editor lock was ignored.');
    delete_post_meta($sourceId, '_edit_lock');
    $nextPlan = plan(); $row = finish($service, $repo, usable($service->start(options('add', $sourceId))));
    check($row['status'] === 'preview', json_encode($row['warnings']));
    check($service->mark_reviewed($row['id'], $row['preview_hash']), 'Preview review failed.');
    wp_update_post(['ID' => $row['preview_id'], 'post_title' => 'Modified preview']);
    $row = usable($service->approve($row['id'], ['approved' => true, 'preview_hash' => $row['preview_hash']]));
    check($row['status'] === 'conflict', 'Modified preview was applied.');
});
test('Restoration refuses to overwrite changes made after an application', function () use ($service, $repo, &$nextPlan, &$sourceId) {
    release_cooldown(); $nextPlan = plan();
    $row = finish($service, $repo, usable($service->start(options('add', $sourceId))));
    check($row['status'] === 'preview', json_encode($row['warnings'])); $row = approve($service, $row);
    check($row['status'] === 'applied', json_encode($row['warnings']));
    wp_update_post(['ID' => $sourceId, 'post_title' => 'Later human edit']);
    $row = usable($service->restore($row['id'], ['approved' => true]));
    check($row['status'] === 'applied' && get_the_title($sourceId) === 'Later human edit', 'Restoration overwrote a later edit.');
});
test('Untrusted generated code, links and out-of-scope targets are rejected', function () use ($blueprint, $adapter, &$sourceId) {
    $bad = plan(); $bad['sections'][0]['columns'][0]['widgets'][0]['link'] = 'javascript:alert(1)';
    check(is_wp_error($blueprint->apply([], $bad, options(), [], [], 'flexbox')), 'JavaScript URL accepted.');
    check(!str_contains(Blueprint::plain('[dangerous_shortcode]Safe text', 100), '[dangerous_shortcode]'), 'Generated shortcode accepted.');
    $original = usable($adapter->snapshot($sourceId))['elements'];
    $bad = ['sections' => [], 'updates' => [['id' => 'outside', 'field' => 'title', 'value' => 'Overwrite', 'media_ref' => '']]];
    check(is_wp_error($blueprint->apply($original, $bad, options('content', $sourceId, ['section_id' => $original[0]['id']]), [], [], 'flexbox')), 'Out-of-scope target accepted.');
});
test('Atomic V4 pages are detected and blocked without conversion', function () use ($adapter, $service) {
    release_cooldown();
    $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Atomic fixture', 'meta_input' => ['_elementor_edit_mode' => 'builder', '_elementor_template_type' => 'wp-page']]);
    update_post_meta($id, '_elementor_data', wp_slash(wp_json_encode([['id' => 'atomic12', 'elType' => 'e-div-block', 'version' => '0.0', 'settings' => [], 'elements' => [], 'styles' => []]])));
    check(!usable($adapter->inspect($id))['editable'], 'Atomic document was incorrectly editable.');
    check(is_wp_error($service->start(options('add', $id))), 'Atomic write was not blocked.');
});
test('Duplicate requests do not duplicate drafts or billable submissions', function () use ($service, $repo, &$nextPlan, &$calls) {
    release_cooldown(); $nextPlan = plan(); $options = options();
    $one = usable($service->start($options)); $two = usable($service->start($options)); check($one['id'] === $two['id'], 'Duplicate operation created.');
    $row = finish($service, $repo, $one); $count = count($calls);
    usable($service->advance($row['id'])); check(count($calls) === $count, 'Completed proposal resubmitted.');
    usable($service->discard($row['id']));
});
test('An uncertain API submission is not retried or silently billed as complete', function () use ($service, $repo, &$transportFailure, &$calls) {
    release_cooldown(); $transportFailure = true;
    $row = finish($service, $repo, usable($service->start(options()))); $transportFailure = false;
    check($row['status'] === 'failed' && $row['usage']['usage_incomplete'], 'Uncertain call not identified.');
    $count = count($calls); usable($service->advance($row['id'])); check(count($calls) === $count, 'Uncertain call was retried.');
});
test('Image failure preserves the native page proposal', function () use ($service, $repo, &$nextPlan) {
    release_cooldown(); $nextPlan = plan(); $image = widget('image'); $image['media_ref'] = 'generated_1';
    $nextPlan['sections'][0]['columns'][0]['widgets'][] = $image;
    $nextPlan['images'] = [['ref' => 'generated_1', 'title' => 'Editorial image', 'alt' => 'Accessible generated image', 'prompt' => 'A realistic abstract workspace, no lettering.']];
    $row = finish($service, $repo, usable($service->start(options('create', 0, ['image_count' => 1]))));
    check($row['status'] === 'preview' && count($row['warnings']) > 0, json_encode($row['warnings']));
    usable($service->discard($row['id']));
});
if (getenv('CORT_AI_TEST_JPEG') && is_file(getenv('CORT_AI_TEST_JPEG'))) test('Generated images retain Media Library IDs and alt text', function () use ($service, $repo, $adapter, &$nextPlan, &$imageFailure) {
    release_cooldown(); $imageFailure = false; $nextPlan = plan();
    $image = widget('image'); $image['media_ref'] = 'generated_1'; $nextPlan['sections'][0]['columns'][0]['widgets'][] = $image;
    $nextPlan['images'] = [['ref' => 'generated_1', 'title' => 'Generated test image', 'alt' => 'Accessible test image', 'prompt' => 'A simple editorial image.']];
    try { $row = finish($service, $repo, usable($service->start(options('create', 0, ['image_count' => 1])))); }
    finally { $imageFailure = true; }
    check($row['status'] === 'preview', json_encode($row['warnings']));
    $snapshot = usable($adapter->snapshot($row['preview_id']));
    $images = array_values(array_filter(flatten($snapshot['elements']), static fn(array $node): bool => ($node['widgetType'] ?? '') === 'image'));
    $id = $images[0]['settings']['image']['id'] ?? 0;
    check($id > 0 && wp_attachment_is_image($id), 'Native media reference was not saved.');
    check(get_post_meta($id, '_wp_attachment_image_alt', true) === 'Accessible test image', 'Generated alt text missing.');
    $row = approve($service, $row); check($row['status'] === 'applied', json_encode($row['warnings']));
});
else echo "SKIP successful image persistence: set CORT_AI_TEST_JPEG to a disposable JPEG fixture.\n";
test('Budget enforcement prevents an unaffordable API call', function () use ($service, $settings, &$calls) {
    release_cooldown(); $config = $settings->read(); $config['budget'] = 0.000001; $settings->save($config); $count = count($calls);
    check(is_wp_error($service->start(options())), 'Budget not enforced.'); check(count($calls) === $count, 'Unaffordable call was sent.'); $config['budget'] = 25; $settings->save($config);
});
test('REST capabilities and nonces protect Page Builder', function () use ($admin, $restInitHooks) {
    // Elementor's internal simulated REST server removes rest_api_init callbacks.
    // Restore bootstrap hooks to represent a fresh external HTTP request.
    $GLOBALS['wp_filter']['rest_api_init'] = clone $restInitHooks;
    $GLOBALS['wp_rest_server'] = null;
    $server = rest_get_server();
    $request = new \WP_REST_Request('GET', '/cort-ai-studio/v1/page-builder/status');
    $result = $server->dispatch($request);
    check($result->get_status() === 403, 'Missing nonce accepted: ' . wp_json_encode($result->get_data()));
    $subscriber = wp_create_user('cort_test_subscriber_' . wp_rand(1, 999999), 'test-password'); wp_set_current_user($subscriber);
    $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
    check($server->dispatch($request)->get_status() === 403, 'Subscriber accessed Page Builder.'); wp_set_current_user($admin->ID);
    $request->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
    check($server->dispatch($request)->get_status() === 200, 'Authorized admin cannot inspect status.');
});
test('Blog Writer request/schema and native draft pipeline remain usable', function () use (&$nextArticle, $api) {
    $settings = (new Settings())->read(); $article = new \CortAIStudio\Article();
    $options = usable($article->options(['topic' => 'Confirmed business services', 'model' => Settings::DEFAULT_MODEL, 'effort' => 'medium', 'language' => 'en', 'tone' => 'professional', 'length' => 'short'], $settings));
    $payload = $article->request(['options' => $options, 'profile' => $settings['profile'], 'style' => '', 'internal' => []]);
    check($payload['text']['format']['name'] === 'cort_ai_blog_article', 'Blog Writer schema changed.');
    $line = ['text' => 'Confirmed editorial content.', 'links' => []];
    $response = ['output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => wp_json_encode(['title' => 'Blog regression draft', 'slug' => 'blog-regression', 'seo_title' => 'Blog regression', 'meta_description' => 'Confirmed description', 'introduction' => [$line], 'sections' => [['heading' => 'Services', 'blocks' => [['kind' => 'paragraph', 'lines' => [$line]]]]], 'conclusion' => [$line], 'call_to_action' => $line, 'categories' => [], 'tags' => [], 'images' => []])]]]]];
    $decoded = usable($article->decode($response, $options)); $rendered = $article->render($decoded, $options, [], wp_generate_uuid4());
    $drafts = new \CortAIStudio\Drafts(); $request = wp_generate_uuid4();
    $id = usable($drafts->create(['id' => wp_rand(100000, 999999), 'request_id' => $request, 'user_id' => get_current_user_id()], $decoded, $rendered['content']));
    $drafts->classify($id, $decoded, $options, $request);
    check(get_post_status($id) === 'draft' && has_blocks(get_post($id)->post_content), 'Blog draft/block creation failed.');
    check(get_post_meta($id, '_cort_ai_studio_seo_title', true) === 'Blog regression', 'Blog SEO persistence failed.');
    $nextArticle = json_decode($response['output'][0]['content'][0]['text'], true);
    $jobs = new \CortAIStudio\Jobs(); $jobs->ready();
    global $wpdb;
    $wpdb->query("UPDATE {$wpdb->prefix}cort_ai_studio_jobs SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 SECOND)");
    $writer = new \CortAIStudio\Writer(new Settings(), $jobs, $api, new \CortAIStudio\Sources(), new \CortAIStudio\Article(), new \CortAIStudio\Drafts(), new Images());
    $run = usable($writer->start(['request_id' => wp_generate_uuid4(), 'topic' => 'Blog workflow regression', 'model' => Settings::DEFAULT_MODEL, 'effort' => 'medium', 'language' => 'en', 'tone' => 'professional', 'length' => 'short', 'internal' => false]));
    for ($i = 0; $i < 12 && $run['status'] === 'running'; ++$i) $run = usable($writer->advance($run['id']));
    check(in_array($run['status'], ['complete', 'partial'], true) && $run['draft_saved'], 'Original Writer orchestration did not save a draft.');
    check($run['usage']['input_tokens'] === 100 && $run['usage']['output_tokens'] === 200, 'Original usage tracking changed.');
});

require __DIR__ . '/visual.php';
require __DIR__ . '/classic-widgets.php';

echo "\n$passed passed; $failed failed. Elementor " . ELEMENTOR_VERSION . '; WordPress ' . get_bloginfo('version') . '; PHP ' . PHP_VERSION . ".\n";
exit($failed ? 1 : 0);
