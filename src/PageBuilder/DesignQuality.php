<?php
namespace CortAIStudio\PageBuilder;

use WP_Error;

defined('ABSPATH') || exit;

final class DesignQuality
{
    public function review(array $elements, array $report, string $operation, array $existing = []): array|WP_Error
    {
        $headings = []; $images = []; $responsive = 0; $styled = 0; $anchors = []; $warnings = $report['warnings']; $contrasts = []; $fragmentLinks = [];
        $walk = function (array $nodes, ?string $background = null, bool $photo = false) use (&$walk, &$headings, &$images, &$responsive, &$styled, &$anchors, &$contrasts, &$fragmentLinks): void {
            foreach ($nodes as $node) {
                $s = $node['settings'];
                if (($node['widgetType'] ?? '') === 'heading' && in_array($s['header_size'] ?? '', ['h1', 'h2', 'h3', 'h4'], true)) $headings[] = $s['header_size'];
                if (($node['widgetType'] ?? '') === 'animated-headline' && in_array($s['tag'] ?? '', ['h1', 'h2', 'h3', 'h4'], true)) $headings[] = $s['tag'];
                if (in_array($node['widgetType'] ?? '', ['image-box', 'icon-box'], true)) $headings[] = $s['title_size'] ?? 'h3';
                $nodeBackground = isset($s['background_color']) && preg_match('/^#[0-9a-fA-F]{6}$/D', $s['background_color']) ? $s['background_color'] : $background;
                $nodePhoto = $photo || !empty($s['background_image']['url']) || !empty($s['bg_image']['url']);
                foreach (['title_color', 'text_color', 'description_color', 'button_text_color'] as $color) {
                    if (!$nodePhoto && $nodeBackground && isset($s[$color]) && preg_match('/^#[0-9a-fA-F]{6}$/D', $s[$color])) $contrasts[] = DesignDirection::contrast($s[$color], $nodeBackground);
                }
                foreach (['image', 'background_image', 'bg_image'] as $field) if (!empty($s[$field]['id'])) $images[] = $s[$field]['id'];
                foreach (['carousel', 'wp_gallery', 'gallery'] as $field) foreach ($s[$field] ?? [] as $image) if (!empty($image['id'])) $images[] = $image['id'];
                foreach ($s['slides'] ?? [] as $slide) if (!empty($slide['background_image']['id'])) $images[] = $slide['background_image']['id'];
                if (isset($s['width_tablet'], $s['width_mobile']) || isset($s['_inline_size_tablet'], $s['_inline_size_mobile'])) ++$responsive;
                if (isset($s['border_radius']) || isset($s['image_border_radius'])) ++$styled;
                if (!empty($s['_element_id'])) $anchors[] = $s['_element_id'];
                if (is_string($s['link']['url'] ?? null) && str_starts_with($s['link']['url'], '#')) $fragmentLinks[] = substr($s['link']['url'], 1);
                $walk($node['elements'], $nodeBackground, $nodePhoto);
            }
        };
        $walk($elements);
        $existingAnchors = [];
        $readAnchors = function (array $nodes) use (&$readAnchors, &$existingAnchors): void {
            foreach ($nodes as $node) {
                foreach (['_element_id', 'css_id'] as $key) if (!empty($node['settings'][$key]) && is_string($node['settings'][$key])) $existingAnchors[] = $node['settings'][$key];
                $readAnchors($node['elements'] ?? []);
            }
        };
        $readAnchors($existing);
        if ($contrasts && min($contrasts) < 4.5 - .001) return Support::error('contrast', __('Generated solid-background text failed the contrast check. The proposal was not saved.', 'cort-ai-studio'));
        if ($operation === 'create' && count(array_filter($headings, static fn(string $tag): bool => $tag === 'h1')) !== 1) return Support::error('heading_hierarchy', __('A new page must have exactly one primary H1. Revise the design proposal.', 'cort-ai-studio'));
        if (count($anchors) !== count(array_unique($anchors)) || array_intersect($anchors, $existingAnchors)) return Support::error('anchors', __('Duplicate section anchors would create ambiguous links. The design was rejected.', 'cort-ai-studio'));
        if (array_diff($fragmentLinks, array_merge($anchors, $existingAnchors))) $warnings[] = __('A button links to an anchor not found in the page content. Verify its destination in the preview.', 'cort-ai-studio');
        $unique = count(array_unique($report['patterns']));
        if (count($report['patterns']) >= 4 && $unique < 3) $warnings[] = __('The page uses limited layout variety. Consider a more varied composition.', 'cort-ai-studio');
        $placeholders = count(array_filter($report['image_assignments'], static fn(array $image): bool => $image['placeholder']));
        $previous = 0;
        foreach ($headings as $tag) { $level = (int) substr($tag, 1); if ($previous && $level > $previous + 1) $warnings[] = __('Review a skipped heading level in the proposal.', 'cort-ai-studio'); $previous = $level; }
        return array_replace($report, ['checks' => ['heading_hierarchy' => 'checked', 'unique_anchors' => 'checked', 'responsive_elements' => $responsive, 'styled_components' => $styled, 'layout_variants' => $unique, 'distinct_images' => count(array_unique($images)), 'duplicate_images' => count($images) - count(array_unique($images)), 'placeholders' => $placeholders, 'minimum_solid_text_contrast' => $contrasts ? round(min($contrasts), 2) : 'review'],
            'validation_scope' => 'Deterministic structure, palette contrast, native controls and media metadata. Rendered visual quality and photographic contrast require human review.',
            'warnings' => array_values(array_unique($warnings))]);
    }
}
