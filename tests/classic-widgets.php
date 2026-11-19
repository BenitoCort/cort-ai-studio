<?php
namespace CortAIStudioTests;

use CortAIStudio\PageBuilder\ClassicWidgets;
use CortAIStudio\PageBuilder\DraftForm;
use CortAIStudio\PageBuilder\ElementorCapabilities;
use CortAIStudio\PageBuilder\WidgetContracts;
use CortAIStudio\PageBuilder\ElementorAdapter;

if (PHP_SAPI !== 'cli' || !defined('CORT_AI_PB_TEST_INSTALLATION') || CORT_AI_PB_TEST_INSTALLATION !== true || !function_exists(__NAMESPACE__ . '\\test')) exit;

function composite_widget(string $type, array $media): array
{
    $refs = array_keys($media);
    return array_replace(widget($type, 'Confirmed component title'), [
        'description' => 'Confirmed component description.', 'button_text' => 'Learn more', 'link' => '/contact/', 'role' => 'body',
        'media_ref' => $type === 'call-to-action' ? ($refs[0] ?? '') : '', 'image_subject' => '',
        'items' => array_slice($refs, 0, 2), 'counter_value' => 24, 'counter_prefix' => '', 'counter_suffix' => '+',
        'entries' => [
            ['title' => 'First confirmed item', 'text' => 'First plain description.', 'icon' => 'coffee', 'button_text' => '', 'link' => '/contact/', 'media_ref' => '', 'image_subject' => ''],
            ['title' => 'Second confirmed item', 'text' => 'Second plain description.', 'icon' => 'leaf', 'button_text' => '', 'link' => '', 'media_ref' => '', 'image_subject' => ''],
        ],
        'form_fields' => [['label' => 'Name', 'type' => 'text', 'placeholder' => 'Your name', 'required' => true], ['label' => 'Email', 'type' => 'email', 'placeholder' => 'you@example.test', 'required' => true]],
    ]);
}

function composite_plan(array $widget): array
{
    $plan = plan(); $plan['sections'][0]['title'] = 'Native classic components';
    $plan['sections'][0]['columns'][0]['widgets'] = [$widget];
    return $plan;
}

test('Compatibility inventory identifies PRO Elements separately and excludes Atomic contracts', function () use ($adapter) {
    $report = $adapter->availability(); $caps = new ElementorCapabilities();
    check($report['classic'] && $report['containers'] && $report['atomic'] === false, 'Classic/Flexbox/Atomic report incorrect.');
    check(count($report['widget_catalog']) >= count($caps->widgets()), 'Registered widget inventory missing.');
    foreach ($report['widget_catalog'] as $widget) if (!$widget['classic']) check(!$widget['authoring'], 'Atomic catalog entry was offered for generation.');
    if (function_exists('pro_elements_plugin_load_plugin')) check($report['providers']['pro_elements']['detected'] && !$report['providers']['elementor_pro']['detected'], 'PRO Elements was mislabeled as official Elementor Pro.');
    foreach (['icon-list', 'accordion', 'toggle', 'tabs', 'counter', 'image-gallery'] as $name) check($caps->status($name)['authoring'], 'Core contract not detected: ' . $name . ': ' . $caps->status($name)['reason']);
    echo '  Generatable classic widgets: ' . implode(', ', $caps->widgets()) . ".\n";
});

test('Core lists, accordion, toggle, tabs, counter and gallery save/render in classic and Flexbox', function () use (&$cafeMedia) {
    foreach (['icon-list', 'accordion', 'toggle', 'tabs', 'counter', 'image-gallery'] as $type) foreach (['classic', 'flexbox'] as $layout) {
        $direction = direction(); $direction['motion'] = 'none';
        $compiled = visual_compile(composite_plan(composite_widget($type, $cafeMedia)), $cafeMedia, $layout, $direction);
        $saved = visual_save($compiled['elements'], 'Core composite ' . $type . ' ' . $layout);
        check(str_contains($saved['html'], 'elementor-widget-' . $type), 'Native widget did not render: ' . $type);
        $node = array_values(array_filter(flatten($compiled['elements']), static fn(array $n): bool => ($n['widgetType'] ?? '') === $type))[0];
        if ($type === 'counter') check($node['settings']['starting_number'] === 24 && $node['settings']['ending_number'] === 24, 'Motion-free counter must start at its final value.');
        if (in_array($type, ['accordion', 'toggle', 'tabs'], true)) check(count($node['settings']['tabs']) === 2 && str_contains($saved['html'], 'First plain description.'), 'Native repeater content was lost.');
    }
});

test('Registered advanced gallery, CTA, flip box, animated headline, slides and draft form render natively', function () use (&$cafeMedia) {
    $caps = new ElementorCapabilities(); $tested = [];
    foreach (['gallery', 'call-to-action', 'flip-box', 'animated-headline', 'slides', 'form'] as $type) {
        if (!$caps->status($type)['authoring']) continue;
        foreach (['classic', 'flexbox'] as $layout) {
            $compiled = visual_compile(composite_plan(composite_widget($type, $cafeMedia)), $cafeMedia, $layout, direction());
            $saved = visual_save($compiled['elements'], 'Advanced classic ' . $type . ' ' . $layout);
            check(str_contains($saved['html'], 'elementor-widget-' . $type), 'Registered advanced native widget did not render: ' . $type);
            $node = array_values(array_filter(flatten($compiled['elements']), static fn(array $n): bool => ($n['widgetType'] ?? '') === $type))[0];
            if ($type === 'gallery') check($node['settings']['columns_tablet'] === 2 && $node['settings']['columns_mobile'] === 1, 'Advanced gallery responsive columns missing.');
            if ($type === 'slides') check(in_array($node['settings']['autoplay'], ['', 'no'], true), 'Slides must not autoplay.');
            if ($type === 'form') check($node['settings']['submit_actions'] === [] && count($node['settings']['form_fields']) === 2 && str_contains($saved['html'], 'type="email"'), 'Draft form actions/fields were not preserved.');
        }
        $tested[] = $type;
    }
    if (!$tested) echo "  No advanced provider registered; absence/fallback paths tested separately.\n";
    else echo '  Advanced native rendering verified: ' . implode(', ', $tested) . ".\n";
    if (ElementorCapabilities::providers()['pro_elements']['detected']) check(count($tested) === 6, 'PRO Elements native contracts incomplete: ' . implode(', ', $tested));
});

test('Missing/unsuitable widgets use editable alternatives and unavailable forms fail explicitly', function () use (&$cafeMedia) {
    $direction = direction(); $direction['motion'] = 'none';
    foreach (['flip-box', 'animated-headline'] as $type) {
        $compiled = visual_compile(composite_plan(composite_widget($type, $cafeMedia)), $cafeMedia, 'flexbox', $direction);
        check(!in_array($type, array_column(flatten($compiled['elements']), 'widgetType'), true) && $compiled['design_quality']['warnings'], 'Motion-free alternative was not used: ' . $type);
    }
    $caps = new ElementorCapabilities();
    if (!$caps->status('call-to-action')['registered']) {
        $compiled = visual_compile(composite_plan(composite_widget('call-to-action', $cafeMedia)), $cafeMedia, 'flexbox', $direction);
        check(in_array('button', array_column(flatten($compiled['elements']), 'widgetType'), true), 'Missing CTA lost its editable button.');
    }
    global $adapter, $blueprint;
    $kit = $adapter->design(); $kit['planning'] = usable((new \CortAIStudio\PageBuilder\DesignDirection())->normalize(direction(), $kit));
    $input = composite_widget('form', $cafeMedia); $input['form_fields'][0]['type'] = 'upload';
    check(is_wp_error($blueprint->apply([], composite_plan($input), options(), $cafeMedia, $kit, 'flexbox')), 'Unavailable or unsafe form generation did not fail explicitly.');
});

test('Slides respect actual background repeater fields and preserve imagery through a compatible alternative', function () use (&$cafeMedia) {
    $input = composite_widget('slides', $cafeMedia);
    $input['entries'][0]['media_ref'] = array_key_first($cafeMedia);
    $compiled = visual_compile(composite_plan($input), $cafeMedia, 'flexbox', direction());
    $caps = new ElementorCapabilities();
    $hasNative = $caps->status('slides')['authoring'] && $caps->field('slides', 'slides', 'background_image');
    check(in_array($hasNative ? 'slides' : 'image', array_column(flatten($compiled['elements']), 'widgetType'), true), 'Slide imagery was lost or written to an undeclared control.');
    $saved = visual_save($compiled['elements'], 'Compatible slides with media');
    check(str_contains($saved['html'], 'First confirmed item') && $compiled['design_quality']['checks']['distinct_images'] >= 1, 'Slide content/media was not preserved.');
});

test('Draft forms reject submissions until configured and do not change ordinary forms', function () use (&$cafeMedia) {
    $caps = new ElementorCapabilities();
    if (!$caps->status('form')['authoring']) { echo "  Form widget not registered; generation is explicitly unavailable.\n"; return; }
    $compiled = visual_compile(composite_plan(composite_widget('form', $cafeMedia)), $cafeMedia, 'flexbox', direction());
    $node = array_values(array_filter(flatten($compiled['elements']), static fn(array $n): bool => ($n['widgetType'] ?? '') === 'form'))[0];
    $widget = \Elementor\Plugin::$instance->elements_manager->create_element_instance($node);
    $settings = $widget->get_settings_for_display();
    $form = ['id' => $node['id'], 'widgetType' => 'form', 'settings' => $settings];
    $record = new \ElementorPro\Modules\Forms\Classes\Form_Record(['cort_field_1' => 'Example', 'cort_field_2' => 'example@example.test'], $form);
    $handler = new \ElementorPro\Modules\Forms\Classes\Ajax_Handler();
    check(!$record->validate($handler) && isset($handler->errors['cort_ai_configuration']), 'Native form validation did not block an unconfigured AI form.');
    $form['settings']['submit_actions'] = ['email'];
    $record = new \ElementorPro\Modules\Forms\Classes\Form_Record([], $form);
    $handler = new \ElementorPro\Modules\Forms\Classes\Ajax_Handler();
    DraftForm::validate($record, $handler);
    check(!$handler->errors, 'Configured form was blocked by the draft guard.');
    $form['settings']['submit_actions'] = []; $form['settings']['_css_classes'] = 'ordinary-form';
    $record = new \ElementorPro\Modules\Forms\Classes\Form_Record([], $form);
    DraftForm::validate($record, $handler);
    check(!$handler->errors, 'Ordinary form was modified.');
});

test('Mixed/typed Atomic data is rejected even with classic-looking widget names', function () use ($adapter, &$cafeMedia) {
    $compiled = visual_compile(composite_plan(composite_widget('icon-list', $cafeMedia)), $cafeMedia, 'flexbox', direction());
    $typed = $compiled['elements']; $typed[0]['settings']['width'] = ['$$type' => 'size', 'value' => 100];
    check(is_wp_error($adapter->validate($typed)), 'Typed Atomic property was accepted.');
    $props = $compiled['elements']; $props[0]['props'] = [];
    check(is_wp_error($adapter->validate($props)), 'Atomic props node was accepted.');
    check($adapter->validate($compiled['elements']) === true, 'Valid classic data was rejected.');
});

test('Composite widget data, style tokens and media survive preview, approval and exact restoration', function () use ($adapter, $service, $repo, &$cafeMedia, &$nextPlan) {
    $caps = new ElementorCapabilities();
    $input = composite_widget('icon-list', $cafeMedia);
    $plan = composite_plan($input);
    $plan['sections'][0]['columns'][0]['widgets'][] = composite_widget($caps->status('call-to-action')['authoring'] ? 'call-to-action' : 'accordion', $cafeMedia);
    $original = visual_compile($plan, $cafeMedia, 'flexbox', direction());
    $saved = visual_save($original['elements'], 'Composite source for restoration');
    wp_update_post(['ID' => $saved['id'], 'post_status' => 'publish']);
    $before = usable($adapter->snapshot($saved['id']));
    release_cooldown(); $nextPlan = plan();
    $row = finish($service, $repo, usable($service->start(options('add', $saved['id']))));
    check($row['status'] === 'preview' && $adapter->fingerprint(usable($adapter->snapshot($saved['id']))) === $adapter->fingerprint($before), 'Composite source changed before approval.');
    $row = approve($service, $row);
    check($row['status'] === 'applied', wp_json_encode($row['warnings']));
    $after = usable($adapter->snapshot($saved['id']));
    check(ElementorAdapter::hash($after['elements'][0]) === ElementorAdapter::hash($before['elements'][0]), 'Existing repeater/widget styles or IDs changed.');
    $row = usable($service->restore($row['id'], ['approved' => true]));
    check($row['status'] === 'restored' && ElementorAdapter::hash(usable($adapter->snapshot($saved['id']))['elements']) === ElementorAdapter::hash($before['elements']), 'Composite backup did not restore exactly.');
});

test('A provider change invalidates the reviewed preview without becoming an authoring prerequisite', function () use ($adapter, $service, $repo, &$nextPlan) {
    $providers = ElementorCapabilities::providers();
    if (!$providers['pro_elements']['detected']) { check(isset($adapter->design()['capabilities']['providers']), 'Provider fingerprint metadata missing.'); return; }
    release_cooldown(); $nextPlan = plan();
    $row = finish($service, $repo, usable($service->start(options())));
    check($row['status'] === 'preview' && $service->mark_reviewed($row['id'], $row['preview_hash']), 'Provider-change preview was not ready.');
    $plugins = get_option('active_plugins');
    try {
        update_option('active_plugins', array_values(array_filter($plugins, static fn(string $file): bool => !str_contains($file, 'pro-elements'))));
        // Already registered runtime widgets stay usable: metadata/branding does not grant eligibility.
        check((new ElementorCapabilities())->status('call-to-action')['authoring'], 'Provider branding became an authoring prerequisite.');
        $result = usable($service->approve($row['id'], ['approved' => true, 'preview_hash' => $row['preview_hash']]));
        check($result['status'] === 'failed' && !$result['page_id'] && get_post_meta($row['preview_id'], '_cort_ai_pb_preview', true), 'Changed provider configuration did not invalidate the old preview.');
    } finally { update_option('active_plugins', $plugins); }
});
