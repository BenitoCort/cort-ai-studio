<?php
namespace CortAIStudioTests;

function widget(string $type = 'heading', string $text = 'Confirmed business content'): array
{
    return ['type' => $type, 'text' => $text, 'link' => '', 'media_ref' => '', 'icon' => 'check-circle', 'heading_tag' => 'h2', 'color' => '', 'background' => '', 'font' => '', 'size' => 0, 'align' => '', 'animation' => 'none'];
}

function plan(int $sections = 1): array
{
    $result = ['summary' => 'Add native editable content.', 'unsupported_reason' => '', 'sections' => [], 'updates' => [], 'images' => []];
    for ($i = 0; $i < $sections; ++$i) $result['sections'][] = ['label' => 'Services ' . ($i + 1), 'background' => '', 'padding' => 32, 'gap' => 24, 'columns' => [['widgets' => [widget(), widget('text-editor', 'A confirmed description.'), widget('button', 'Contact us')]]]];
    return $result;
}

function direction(int $sections = 1): array
{
    $patterns = ['split', 'content', 'cards', 'features', 'cta'];
    return ['art_direction' => 'Warm editorial compositions with clear hierarchy and balanced whitespace.',
        'palette' => ['background' => '#FAF7F0', 'surface' => '#FFFFFF', 'ink' => '#30251E', 'muted' => '#64564A', 'accent' => '#705039', 'accent_ink' => '#FFFFFF', 'dark' => '#30251E', 'on_dark' => '#FFFFFF'],
        'typography' => ['heading_font' => '', 'body_font' => '', 'display' => 64, 'heading' => 40, 'body' => 18],
        'spacing' => ['section' => 88, 'gap' => 28, 'content_width' => 1200, 'radius' => 18],
        'button_style' => 'rounded', 'motion' => 'subtle', 'document_layout' => 'default',
        'image_strategy' => 'Distinct relevant subjects for each component.', 'responsive_strategy' => 'Proportional desktop columns, two-column tablet cards, full-width stacked mobile content.',
        'sections' => array_map(static fn(int $i): array => ['name' => 'Section ' . ($i + 1), 'purpose' => 'Confirmed business content', 'pattern' => $patterns[$i % count($patterns)], 'tone' => 'light', 'image_subject' => ''], range(0, $sections - 1))];
}
