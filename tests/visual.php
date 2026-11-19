<?php
/** Included by integration.php only after the isolated-installation guard. */
namespace CortAIStudioTests;

if (PHP_SAPI !== 'cli' || !defined('CORT_AI_PB_TEST_INSTALLATION') || CORT_AI_PB_TEST_INSTALLATION !== true || !function_exists(__NAMESPACE__ . '\\test')) exit;

use CortAIStudio\PageBuilder\DesignDirection;
use CortAIStudio\PageBuilder\DesignQuality;
use CortAIStudio\PageBuilder\ElementorAdapter;
use CortAIStudio\PageBuilder\ElementorCapabilities;
use CortAIStudio\PageBuilder\MediaSelection;

require __DIR__ . '/visual-fixtures.php';

function visual_compile(array $plan, array $media = [], string $layout = 'flexbox', ?array $direction = null): array
{
    global $adapter, $blueprint;
    $kit = $adapter->design();
    $kit['planning'] = usable((new DesignDirection())->normalize($direction ?? cafe_direction(), $kit));
    return usable($blueprint->apply([], $plan, options(), $media, $kit, $layout));
}

function visual_save(array $elements, string $title): array
{
    global $adapter;
    $document = \Elementor\Plugin::$instance->documents->create('wp-page', ['post_type' => 'page', 'post_status' => 'draft', 'post_title' => $title]);
    $id = $document->get_post()->ID;
    usable($adapter->save($id, $elements));
    $html = usable($adapter->render($id));
    $css = \Elementor\Core\Files\CSS\Post::create($id)->get_content();
    return ['id' => $id, 'html' => $html, 'css' => $css];
}

$cafeMedia = []; $cafePlan = null; $cafeBefore = null; $cafeAfter = null;
test('Cafe before/after: composition, unique media, native CSS and three viewport settings', function () use ($adapter, $blueprint, &$cafeMedia, &$cafePlan, &$cafeBefore, &$cafeAfter) {
    $jpeg = getenv('CORT_AI_TEST_JPEG');
    check($jpeg && is_file($jpeg), 'A disposable JPEG fixture is required for the visual media scenario.');
    foreach (['cafe atmosphere interior warm light', 'barista preparing artisan coffee', 'signature latte coffee cup', 'cold brew iced drink', 'fresh pastries bakery croissant'] as $index => $subject) {
        // Distinct files/attachments with controlled metadata; these are not photographic QA assets.
        $upload = wp_upload_bits('cort-cafe-' . $index . '.jpg', null, file_get_contents($jpeg));
        check(empty($upload['error']), 'Could not upload the isolated media fixture.');
        $id = usable(wp_insert_attachment(['post_title' => $subject, 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit'], $upload['file'], 0, true));
        update_post_meta($id, '_wp_attachment_image_alt', $subject);
        $cafeMedia['media_' . $id] = ['ref' => 'media_' . $id, 'title' => $subject, 'alt' => $subject, 'width' => 1200, 'height' => 800, 'image' => ['id' => $id, 'url' => $upload['url']]];
    }
    $cafePlan = cafe_plan(array_key_first($cafeMedia)); // Deliberate duplicate references in the model output.
    $legacy = usable($blueprint->apply([], $cafePlan, options(), $cafeMedia, $adapter->design(), 'flexbox'));
    $cafeBefore = visual_save($legacy['elements'], 'Cafe before visual engine');
    $compiled = visual_compile($cafePlan, $cafeMedia);
    $cafeAfter = visual_save($compiled['elements'], 'Cafe after visual engine');
    $checks = $compiled['design_quality']['checks'];
    check($checks['layout_variants'] === 5 && $checks['distinct_images'] === 5 && $checks['duplicate_images'] === 0 && $checks['placeholders'] === 0, 'New cafe composition/media checks failed: ' . wp_json_encode($checks));
    $beforeImages = array_column(array_column(array_filter(flatten($legacy['elements']), static fn(array $n): bool => ($n['widgetType'] ?? '') === 'image'), 'settings'), 'image');
    check(count(array_unique(array_column($beforeImages, 'id'))) === 1, 'Before scenario must expose the original repeated-image behavior.');
    $nodes = flatten($compiled['elements']);
    $headings = array_values(array_filter($nodes, static fn(array $n): bool => ($n['widgetType'] ?? '') === 'heading'));
    check($headings[0]['settings']['typography_font_size']['size'] === 64 && $headings[0]['settings']['typography_font_size_mobile']['size'] === 38, 'Display hierarchy must adapt to mobile.');
    $cards = array_values(array_filter($nodes, static fn(array $n): bool => isset($n['settings']['box_shadow_box_shadow'])));
    check(count($cards) === 6 && isset($cards[0]['settings']['border_radius'], $cards[0]['settings']['padding_tablet'], $cards[0]['settings']['width_mobile']), 'Card design and responsive settings missing.');
    check(str_contains($cafeAfter['css'], 'calc((100% - 56px) * 0.3333)') && str_contains($cafeAfter['css'], 'calc((100% - 24px) / 2)'), 'Elementor did not emit gap-aware desktop/tablet widths.');
    check(str_contains($cafeAfter['css'], 'box-shadow:') && str_contains($cafeAfter['css'], 'border-radius:18px') && str_contains($cafeAfter['css'], 'font-size:38px'), 'Elementor did not emit visual styles in CSS.');
    check(str_contains($cafeAfter['html'], 'fadeInUp') && $cards[0]['settings']['animation'] === 'fadeInUp', 'Native entrance effect did not reach the render.');
    if ((new ElementorCapabilities())->summary()['hover_transform']) check((bool) preg_match('/--e-(?:con-)?transform-translateY:\s*-4px/', $cafeAfter['css']) && (bool) preg_match('/class="[^"]*\be-transform\b/', $cafeAfter['html']), 'Native card hover transform/class did not reach CSS and markup.');
    check(str_contains($cafeAfter['html'], 'id="menu"') && substr_count($cafeAfter['html'], '<h1') === 1, 'Native rendered anchors/heading hierarchy missing.');
    check($checks['minimum_solid_text_contrast'] >= 4.5, 'Solid text contrast below threshold.');
    $buttons = array_values(array_filter($nodes, static fn(array $n): bool => ($n['widgetType'] ?? '') === 'button'));
    check(end($buttons)['settings']['background_color'] !== cafe_direction()['palette']['accent'], 'CTA button must remain visible on an accent section.');
    echo '  Cafe comparison: extra repeated image uses 4 -> 0; distinct images 1 -> 5; layout variants 1 -> 5; tablet grid gains an explicit two-column configuration.' . "\n";
    echo '  Isolated drafts: before=' . $cafeBefore['id'] . '; after=' . $cafeAfter['id'] . ".\n";
});

test('Visual layouts also save/render in classic sections and preserve kit settings', function () use ($adapter, &$cafeMedia, &$cafePlan) {
    check(is_array($cafePlan), 'Cafe fixture setup failed.');
    $kitId = (int) get_option('elementor_active_kit');
    $before = $kitId ? get_post_meta($kitId, '_elementor_page_settings', true) : [];
    $compiled = visual_compile($cafePlan, $cafeMedia, 'classic');
    $saved = visual_save($compiled['elements'], 'Classic designed cafe');
    check($adapter->inspect($saved['id'])['layout'] === 'classic' && str_contains($saved['html'], 'elementor-inner-section'), 'Classic native hierarchy/render failed.');
    check(str_contains($saved['css'], 'box-shadow:') && str_contains($saved['css'], 'font-size:38px'), 'Classic visual styles did not reach native CSS.');
    $columns = array_values(array_filter(flatten($compiled['elements']), static fn(array $n): bool => ($n['elType'] ?? '') === 'column' && ($n['settings']['_column_size'] ?? 0) === 33));
    check(count($columns) === 6 && $columns[0]['settings']['_inline_size_tablet'] === 50 && $columns[0]['settings']['_inline_size_mobile'] === 100, 'Classic tablet/mobile grid settings missing.');
    check($before === ($kitId ? get_post_meta($kitId, '_elementor_page_settings', true) : []), 'Global kit was modified.');
});

test('Media relevance, file aliases, explicit reuse and identifiable empty-media placeholders', function () use (&$cafeMedia) {
    $picker = new MediaSelection($cafeMedia);
    $latte = $picker->choose('', 'signature latte coffee cup', 'content');
    check(get_the_title($latte['id']) === 'signature latte coffee cup', 'Metadata relevance did not select the latte.');
    $first = reset($cafeMedia); $alias = $first; $alias['image']['id'] += 1000000;
    $picker = new MediaSelection(['original' => $first, 'alias' => $alias]);
    $one = $picker->choose('original', 'cafe atmosphere', 'hero');
    $two = $picker->choose('alias', 'cafe atmosphere', 'content');
    check($one['id'] && $two['id'] === 0 && $two['placeholder'], 'Two attachment aliases must not reuse the same file.');
    $picker = new MediaSelection(['original' => $first], true);
    check($picker->choose('original', 'cafe', 'hero')['id'] === $picker->choose('original', 'cafe', 'content')['id'], 'Explicit reuse option was ignored.');
    $plan = plan(); $image = widget('image'); $image['image_subject'] = 'An office reception'; $plan['sections'][0]['columns'][0]['widgets'][] = $image;
    $compiled = visual_compile($plan, [], 'flexbox', direction());
    $images = array_values(array_filter(flatten($compiled['elements']), static fn(array $n): bool => ($n['widgetType'] ?? '') === 'image'));
    check($images[0]['settings']['image']['url'] === \Elementor\Utils::get_placeholder_image_src() && str_contains($images[0]['settings']['caption'], 'office reception'), 'Missing media must have an identifiable native placeholder.');
});

test('All visual patterns use real native controls; asymmetric proportions work in both layouts', function () use (&$cafeMedia) {
    foreach (DesignDirection::PATTERNS as $pattern) {
        $plan = plan(); $plan['sections'][0]['pattern'] = $pattern;
        $plan['sections'][0]['title'] = 'A different business composition';
        $plan['sections'][0]['columns'][0]['widgets'][0]['text'] = 'A confirmed service';
        if (in_array($pattern, ['background', 'overlay'], true)) $plan['sections'][0]['media_ref'] = array_key_first($cafeMedia);
        if ($pattern === 'asymmetric') $plan['sections'][0]['columns'][] = ['widgets' => [widget('text-editor', 'Complementary content.')]];
        foreach (['flexbox', 'classic'] as $layout) {
            $compiled = visual_compile($plan, $cafeMedia, $layout, direction());
            $saved = visual_save($compiled['elements'], 'Pattern ' . $pattern . ' ' . $layout);
            check(str_contains($saved['html'], 'cort-ai-pattern-' . $pattern), 'Pattern failed to render: ' . $pattern);
            if ($pattern === 'asymmetric') {
                $columns = array_values(array_filter(flatten($compiled['elements']), static fn(array $node): bool => ($node['settings']['_title'] ?? '') === 'Composition 1'));
                check($layout === 'classic' ? $columns[0]['settings']['_inline_size'] === 58.0 : str_contains($columns[0]['settings']['width']['size'], '0.58'), 'Asymmetric width was lost in ' . $layout);
            }
            if ($pattern === 'highlight') check(str_contains($saved['css'], 'linear-gradient('), 'Native gradient did not reach CSS.');
            if (in_array($pattern, ['background', 'overlay'], true)) check(str_contains($saved['css'], 'background-image:') && str_contains($saved['css'], 'opacity:0.72'), 'Background/overlay did not reach native CSS.');
        }
    }
});

test('Capability-gated widgets and motion-free output render as native elements', function () use (&$cafeMedia) {
    $caps = new ElementorCapabilities();
    check(in_array('icon-box', $caps->widgets(), true) && in_array('image-carousel', $caps->widgets(), true), 'Installed Core widgets not exposed.');
    foreach (['call-to-action', 'slides'] as $name) if (!$caps->type($name)) check(!in_array($name, $caps->widgets(), true), 'Unregistered widget was offered for generation.');
    $plan = plan();
    $plan['sections'][0]['columns'][0]['widgets'] = [widget(), array_replace(widget('icon-box', 'Native service'), ['description' => 'A confirmed service.']), array_replace(widget('image-box', 'A relevant image'), ['media_ref' => array_key_first($cafeMedia)]), array_replace(widget('image-carousel'), ['items' => array_slice(array_keys($cafeMedia), 1, 3)])];
    $direction = direction(); $direction['motion'] = 'none';
    $compiled = visual_compile($plan, $cafeMedia, 'flexbox', $direction);
    foreach (flatten($compiled['elements']) as $node) foreach (array_keys($node['settings']) as $key) check(!str_contains($key, 'animation') && !str_contains($key, '_transform_'), 'Motion-free output contained an animation.');
    $saved = visual_save($compiled['elements'], 'Business services without motion');
    check(str_contains($saved['html'], 'elementor-widget-icon-box') && str_contains($saved['html'], 'elementor-widget-image-carousel'), 'New widgets did not render.');
    $carousel = array_values(array_filter(flatten($compiled['elements']), static fn(array $n): bool => ($n['widgetType'] ?? '') === 'image-carousel'))[0];
    check(in_array($carousel['settings']['autoplay'], ['', 'no'], true) && $carousel['settings']['slides_to_show_mobile'] === '1', 'Carousel mobile/accessibility settings missing.');
    check(str_contains(file_get_contents(dirname(__DIR__) . '/assets/page-design.css'), 'prefers-reduced-motion: reduce'), 'Reduced motion guard missing.');
});

test('Invalid directions, unavailable controls/widgets, contrast and duplicate anchors are rejected', function () use ($adapter, $blueprint, &$cafeMedia, &$cafePlan) {
    $normalizer = new DesignDirection(); $kit = $adapter->design();
    foreach (['color', 'scale', 'font'] as $bad) {
        $direction = direction();
        if ($bad === 'color') $direction['palette']['ink'] = 'url(https://example.test)';
        elseif ($bad === 'scale') $direction['typography']['body'] = 9;
        else $direction['typography']['body_font'] = 'made-up-font';
        check(is_wp_error($normalizer->normalize($direction, $kit)), 'Invalid ' . $bad . ' was accepted.');
    }
    $direction = direction(); $direction['typography'] = 'malformed';
    check(is_wp_error($normalizer->normalize($direction, $kit)), 'Malformed direction group was accepted.');
    $kit['planning'] = usable($normalizer->normalize(cafe_direction(), $kit));
    $plan = $cafePlan; $plan['sections'][1]['anchor'] = 'menu';
    check(is_wp_error($blueprint->apply([], $plan, options(), $cafeMedia, $kit, 'flexbox')), 'Duplicate anchors were accepted.');
    $plan = $cafePlan; $plan['sections'][0]['columns'][0]['widgets'][0]['type'] = 'html';
    check(is_wp_error($blueprint->apply([], $plan, options(), $cafeMedia, $kit, 'flexbox')), 'Unbounded HTML widget was accepted.');
    $plan = $cafePlan; $plan['sections'][0]['columns'][0] = 'malformed';
    check(is_wp_error($blueprint->apply([], $plan, options(), $cafeMedia, $kit, 'flexbox')), 'Malformed column did not fail cleanly.');
    $plan = $cafePlan; array_pop($plan['sections']);
    check(is_wp_error($blueprint->apply([], $plan, options(), $cafeMedia, $kit, 'flexbox')), 'Composition changed the planned section count.');
    $plan = $cafePlan; $plan['sections'][0]['columns'][0]['widgets'][2]['link'] = 'javascript:alert(1)';
    check(is_wp_error($blueprint->apply([], $plan, options(), $cafeMedia, $kit, 'flexbox')), 'Unsafe generated link was accepted.');
    $caps = new ElementorCapabilities(); $caught = false; $settings = [];
    try { $caps->set($settings, 'heading', 'invented_control', 'foo', true); } catch (\DomainException $error) { $caught = true; }
    check($caught, 'Unknown required control must stop compilation.');
    $compiled = visual_compile($cafePlan, $cafeMedia);
    $addition = plan(); $addition['sections'][0]['anchor'] = 'menu';
    $additionKit = $kit; $additionKit['planning'] = usable($normalizer->normalize(direction(), $kit));
    check(is_wp_error($blueprint->apply($compiled['elements'], $addition, options('add', 1), [], $additionKit, 'flexbox')), 'Added section duplicated an existing anchor.');
    $compiled['elements'][0]['elements'][0]['elements'][0]['settings']['title_color'] = cafe_direction()['palette']['background'];
    check(is_wp_error((new DesignQuality())->review($compiled['elements'], $compiled['design_quality'], 'create')), 'Low solid text contrast was accepted.');
});

test('Five-section cafe orchestration finds library media by component subject without image charges', function () use ($service, $repo, &$nextPlan, &$calls) {
    release_cooldown(); $nextPlan = cafe_plan('');
    $before = count(array_filter($calls, static fn(array $call): bool => str_ends_with($call[1], '/images/generations')));
    $row = finish($service, $repo, usable($service->start(options('create', 0, ['reuse_media' => true, 'title' => 'Hospitality homepage']))));
    check($row['status'] === 'preview', wp_json_encode($row['warnings']));
    check($row['design_quality']['checks']['distinct_images'] === 5 && $row['design_quality']['checks']['placeholders'] === 0, 'Component subject search did not find the five available media subjects.');
    check($row['usage']['input_tokens'] === 200 && count($row['changes']) === 5, 'Cafe planning/composition usage or section count mismatch.');
    check($before === count(array_filter($calls, static fn(array $call): bool => str_ends_with($call[1], '/images/generations'))), 'Library reuse must not invoke image generation.');
});

test('New document templates persist natively while existing page layout and backups are preserved', function () use ($adapter, &$sourceId) {
    foreach (['default', 'elementor_header_footer', 'elementor_canvas'] as $template) {
        $row = ['id' => 999999, 'page_id' => 0, 'user_id' => get_current_user_id(), 'request_id' => wp_generate_uuid4(), 'state' => ['options' => options('create', 0, ['document_layout' => $template]), 'source' => null]];
        $id = usable($adapter->create_preview($row));
        check((get_post_meta($id, '_wp_page_template', true) ?: 'default') === $template, 'New document template failed: ' . $template);
        check((get_post_meta($id, '_elementor_page_settings', true)['hide_title'] ?? '') === ($template === 'elementor_canvas' ? 'yes' : ''), 'Native document title setting did not respect the layout.');
    }
    update_post_meta($sourceId, '_wp_page_template', 'elementor_header_footer');
    $snapshot = usable($adapter->snapshot($sourceId));
    $row = ['id' => 999999, 'page_id' => $sourceId, 'user_id' => get_current_user_id(), 'request_id' => wp_generate_uuid4(), 'state' => ['options' => options('add', $sourceId, ['document_layout' => 'elementor_canvas']), 'source' => $snapshot]];
    $id = usable($adapter->create_preview($row));
    check(get_post_meta($id, '_wp_page_template', true) === 'elementor_header_footer', 'Existing layout must be preserved even if a different selection is supplied.');
    check(get_post_meta($sourceId, '_wp_page_template', true) === 'elementor_header_footer', 'Original page template changed.');
    update_post_meta($sourceId, '_wp_page_template', 'elementor_canvas');
    usable($adapter->restore_snapshot($sourceId, $snapshot));
    check(get_post_meta($sourceId, '_wp_page_template', true) === 'elementor_header_footer', 'Backup did not restore the document template.');
});

test('Unused generated image plans incur no image request', function () use ($service, $repo, &$nextPlan, &$calls) {
    release_cooldown(); $nextPlan = plan();
    $nextPlan['images'] = [['ref' => 'generated_1', 'title' => 'Unused', 'alt' => 'Unused', 'prompt' => 'An unused image.']];
    $before = count(array_filter($calls, static fn(array $call): bool => str_ends_with($call[1], '/images/generations')));
    $row = finish($service, $repo, usable($service->start(options('create', 0, ['image_count' => 1]))));
    check($row['status'] === 'preview', wp_json_encode($row['warnings']));
    check($before === count(array_filter($calls, static fn(array $call): bool => str_ends_with($call[1], '/images/generations'))), 'Unused optional image was charged.');
});
