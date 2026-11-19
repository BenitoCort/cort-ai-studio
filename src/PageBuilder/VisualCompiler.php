<?php
namespace CortAIStudio\PageBuilder;

use CortAIStudio\Text;
use WP_Error;

defined('ABSPATH') || exit;

/** Compile bounded visual roles into controls declared by the installed Elementor runtime. */
final class VisualCompiler
{
    private ElementorCapabilities $caps;
    private MediaSelection $media;
    public array $warnings = [];
    private array $patterns = [];
    private array $anchors = [];
    private bool $primary_heading = false;

    public function __construct(private array $direction, array $media, private array $kit, private array $options, private string $layout)
    {
        $this->caps = new ElementorCapabilities();
        $this->media = new MediaSelection($media, !empty($options['allow_image_reuse']));
    }

    public function section(array $section, int $index): array|WP_Error
    {
        try {
            $planned = $this->direction['sections'][$index] ?? [];
            $pattern = $section['pattern'] ?? $planned['pattern'] ?? 'content';
            $tone = $section['tone'] ?? $planned['tone'] ?? 'light';
            if (!in_array($pattern, DesignDirection::PATTERNS, true) || !in_array($tone, ['light', 'dark', 'accent'], true)) return $this->error();
            $columns = $section['columns'] ?? [];
            if (!is_array($columns) || !array_is_list($columns) || count($columns) < 1 || count($columns) > 4) return $this->error();
            $this->patterns[] = $pattern;
            $palette = $this->direction['palette'];
            $bg = match ($tone) { 'dark' => $palette['dark'], 'accent' => $palette['accent'], default => $palette['background'] };
            $fg = match ($tone) { 'dark' => $palette['on_dark'], 'accent' => $palette['accent_ink'], default => $palette['ink'] };
            $title = Blueprint::plain($section['title'] ?? '', 180);
            $intro = Blueprint::plain($section['intro'] ?? '', 1500);
            $hero = $index === 0 && $this->options['operation'] === 'create';
            $label = Blueprint::plain($section['label'] ?? $planned['name'] ?? '', 100) ?: 'Content section';
            if ($title === '' && in_array($pattern, ['features', 'cards', 'gallery', 'highlight', 'cta', 'centered', 'background', 'overlay'], true)) $title = $label;
            $rootType = $this->layout === 'flexbox' ? 'container' : 'section';
            $root = ['_title' => $label];
            $this->put($root, $rootType, 'css_classes', 'cort-ai-designed cort-ai-pattern-' . $pattern, true);
            $anchor = $section['anchor'] ?? '';
            if ($anchor !== '') {
                if (!is_string($anchor) || !preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,59}$/D', $anchor) || isset($this->anchors[$anchor])) return $this->error();
                $this->anchors[$anchor] = true;
                $this->put($root, $rootType, '_element_id', $anchor, true);
            }
            $spacing = $this->direction['spacing'];
            $this->background($root, $rootType, $bg);
            $pad = $spacing['section'];
            $this->responsive($root, $rootType, 'padding', [self::edges($pad, 28), self::edges((int) round($pad * .75), 24), self::edges((int) round($pad * .55), 20)], true);
            $this->responsive($root, $rootType, 'margin', [self::edges(0), self::edges(0), self::edges(0)]);
            $ref = is_string($section['media_ref'] ?? null) ? $section['media_ref'] : '';
            if (in_array($pattern, ['background', 'overlay'], true) || $ref !== '') {
                $image = $this->media->choose($ref, $section['image_subject'] ?? $planned['image_subject'] ?? $label, $hero ? 'hero' : 'background');
                unset($image['placeholder']);
                $this->put($root, $rootType, 'background_image', $image, true);
                $this->put($root, $rootType, 'background_position', 'center center');
                $this->put($root, $rootType, 'background_size', 'cover');
                $this->put($root, $rootType, 'background_repeat', 'no-repeat');
                $this->put($root, $rootType, 'background_overlay_background', 'classic', true);
                $this->put($root, $rootType, 'background_overlay_color', '#101916', true);
                $this->put($root, $rootType, 'background_overlay_opacity', self::slider(.72), true);
                $fg = '#FFFFFF';
                $bg = '#101916';
                $this->warnings[] = __('Text over photography uses a dark overlay. Verify contrast against the actual photo in the preview.', 'cort-ai-studio');
            } elseif ($pattern === 'highlight') {
                $this->put($root, $rootType, 'background_background', 'gradient', true);
                $this->put($root, $rootType, 'background_color', $bg, true);
                $this->put($root, $rootType, 'background_color_b', $tone === 'light' ? $palette['surface'] : $bg, true);
                $this->put($root, $rootType, 'background_gradient_angle', self::slider(135, 'deg'));
            }
            $header = [];
            $align = in_array($section['heading_align'] ?? '', ['left', 'center', 'right'], true) ? $section['heading_align'] : (in_array($pattern, ['centered', 'background', 'overlay', 'cta'], true) ? 'center' : 'left');
            $eyebrow = Blueprint::plain($section['eyebrow'] ?? '', 140);
            if ($eyebrow !== '') $header[] = $this->widget(['type' => 'heading', 'text' => $eyebrow, 'role' => 'eyebrow', 'heading_tag' => 'h3', 'align' => $align], $fg, $bg, $hero, $index);
            if ($title !== '') $header[] = $this->widget(['type' => 'heading', 'text' => $title, 'role' => $hero ? 'display' : 'title', 'heading_tag' => $hero ? 'h1' : 'h2', 'align' => $align], $fg, $bg, $hero, $index);
            if ($intro !== '') $header[] = $this->widget(['type' => 'text-editor', 'text' => $intro, 'role' => 'body', 'align' => $align], $fg, $bg, false, $index);
            foreach ($header as $item) if (is_wp_error($item)) return $item;
            if ($this->layout === 'flexbox') {
                $this->put($root, 'container', 'content_width', 'boxed', true);
                $this->put($root, 'container', 'boxed_width', self::slider($spacing['content_width']), true);
                $this->put($root, 'container', 'container_type', 'flex');
                $this->put($root, 'container', 'flex_direction', 'column', true);
                $this->responsive($root, 'container', 'flex_gap', [self::gap(36), self::gap(28), self::gap(24)], true);
                $body = ['_title' => $label . ' · composition'];
                $this->put($body, 'container', 'content_width', 'full', true);
                $this->put($body, 'container', 'width', self::slider(100, '%'), true);
                $this->put($body, 'container', 'flex_direction', 'row', true);
                $this->put($body, 'container', 'flex_wrap', 'wrap', true);
                $this->put($body, 'container', 'flex_align_items', in_array($pattern, ['split', 'asymmetric', 'content'], true) ? 'center' : 'stretch');
                $this->put($body, 'container', 'flex_justify_content', 'center');
                $this->responsive($body, 'container', 'flex_gap', [self::gap($spacing['gap']), self::gap(24), self::gap(20)], true);
                $compiledColumns = $this->columns($columns, $pattern, $fg, $bg, $hero && !$this->primary_heading, $index);
                if (is_wp_error($compiledColumns)) return $compiledColumns;
                $children = [];
                if ($header) {
                    $settings = ['_title' => $label . ' · heading'];
                    $this->put($settings, 'container', 'content_width', 'full');
                    $this->put($settings, 'container', 'width', self::slider(100, '%'));
                    $this->put($settings, 'container', 'flex_direction', 'column');
                    $this->put($settings, 'container', 'flex_gap', self::gap(14));
                    $this->put($settings, 'container', 'flex_align_items', $align === 'center' ? 'center' : 'flex-start');
                    $children[] = $this->node('container', $settings, $header, true);
                }
                $children[] = $this->node('container', $body, $compiledColumns, true);
                return $this->node('container', $root, $children);
            }
            // Classic sections retain the documented section -> column -> inner section hierarchy.
            $this->put($root, 'section', 'layout', 'boxed');
            $this->put($root, 'section', 'content_width', self::slider($spacing['content_width']));
            $this->put($root, 'section', 'gap', 'no');
            $compiledColumns = $this->columns($columns, $pattern, $fg, $bg, $hero && !$this->primary_heading, $index);
            if (is_wp_error($compiledColumns)) return $compiledColumns;
            $innerSettings = [];
            $this->put($innerSettings, 'section', 'layout', 'full_width');
            $this->put($innerSettings, 'section', 'gap', 'no');
            $inner = $this->node('section', $innerSettings, $compiledColumns, true);
            $wrapper = ['_column_size' => 100, '_inline_size' => null];
            $this->put($wrapper, 'column', 'padding', self::edges(0));
            $this->responsive($wrapper, 'column', 'space_between_widgets', [18, 16, 14]);
            return $this->node('section', $root, [$this->node('column', $wrapper, array_merge($header, [$inner]))]);
        } catch (\DomainException $error) {
            error_log('Cort AI Page Builder visual control: ' . $error->getMessage());
            return Support::error('visual_control', __('The installed Elementor does not expose a required layout control. This proposal was not saved.', 'cort-ai-studio'));
        }
    }

    private function columns(array $columns, string $pattern, string $foreground, string $background, bool $hero, int $sectionIndex): array|WP_Error
    {
        $count = count($columns); $out = [];
        foreach ($columns as $column) if (!is_array($column) || !is_array($column['widgets'] ?? null) || !array_is_list($column['widgets']) || !$column['widgets'] || count($column['widgets']) > 12) return $this->error();
        $weights = array_map(static fn(array $c): float => is_int($c['width'] ?? null) && $c['width'] >= 20 && $c['width'] <= 80 ? (float) $c['width'] : 100 / $count, $columns);
        if ($pattern === 'asymmetric' && $count === 2 && empty($columns[0]['width']) && empty($columns[1]['width'])) $weights = [58, 42];
        $weightTotal = array_sum($weights);
        foreach ($columns as $i => $column) {
            if (!is_array($column) || !is_array($column['widgets'] ?? null) || !$column['widgets'] || count($column['widgets']) > 12) return $this->error();
            $scope = $this->layout === 'flexbox' ? 'container' : 'column';
            $settings = ['_title' => 'Composition ' . ($i + 1)];
            $surface = $column['surface'] ?? (in_array($pattern, ['cards', 'features'], true) ? 'card' : 'none');
            if (!in_array($surface, ['none', 'card', 'image-overlay'], true)) return $this->error();
            $bg = $surface === 'card' ? $this->direction['palette']['surface'] : $background;
            $fg = DesignDirection::contrast($foreground, $bg) >= 4.5 ? $foreground : DesignDirection::readable($bg);
            if ($this->layout === 'flexbox') {
                $this->put($settings, $scope, 'content_width', 'full', true);
                $this->put($settings, $scope, 'flex_direction', 'column', true);
                $this->put($settings, $scope, 'flex_justify_content', in_array($pattern, ['split', 'asymmetric'], true) ? 'center' : 'flex-start');
                $this->responsive($settings, $scope, 'flex_gap', [self::gap(18), self::gap(16), self::gap(14)], true);
                $gap = $this->direction['spacing']['gap'];
                $fraction = $weights[$i] / $weightTotal;
                $width = $count === 1 ? self::slider(100, '%') : self::slider('calc((100% - ' . (($count - 1) * $gap) . 'px) * ' . round($fraction, 4) . ')', 'custom');
                $tablet = $count > 2 ? self::slider('calc((100% - 24px) / 2)', 'custom') : ($count === 2 ? self::slider('calc((100% - 24px) / 2)', 'custom') : self::slider(100, '%'));
                $this->responsive($settings, $scope, 'width', [$width, $tablet, self::slider(100, '%')], true);
            } else {
                $settings += ['_column_size' => (int) floor(100 / $count), '_inline_size' => null];
                $this->put($settings, $scope, '_inline_size', round($weights[$i] / $weightTotal * 100, 3));
                $this->put($settings, $scope, '_inline_size_tablet', $count > 2 ? 50 : 100 / $count);
                $this->put($settings, $scope, '_inline_size_mobile', 100);
                $this->responsive($settings, $scope, 'space_between_widgets', [18, 16, 14]);
                $gutter = (int) round($this->direction['spacing']['gap'] / 2);
                $this->responsive($settings, $scope, 'margin', [self::edges(0, $gutter), array_replace(self::edges(0, 12), ['bottom' => $count > 2 ? '24' : '0', 'isLinked' => false]), array_replace(self::edges(0), ['bottom' => '20', 'isLinked' => false])]);
                $this->put($settings, $scope, 'content_position', in_array($pattern, ['split', 'asymmetric'], true) ? 'center' : 'top');
            }
            if ($surface !== 'none') {
                $this->background($settings, $scope, $bg);
                $this->responsive($settings, $scope, 'padding', [self::edges(30), self::edges(24), self::edges(22)], true);
                $this->put($settings, $scope, 'border_radius', self::edges($this->direction['spacing']['radius']), true);
                $this->put($settings, $scope, 'border_border', 'solid');
                $this->put($settings, $scope, 'border_width', self::edges(1));
                $this->put($settings, $scope, 'border_color', '#DDE3DF');
                $this->shadow($settings, $scope, 'box_shadow', 18, 42, .09);
                $this->shadow($settings, $scope, 'box_shadow_hover', 24, 54, .16);
                if ($surface === 'image-overlay') {
                    $image = $this->media->choose((string) ($column['media_ref'] ?? ''), (string) ($column['image_subject'] ?? ''), 'card background');
                    unset($image['placeholder']);
                    $this->put($settings, $scope, 'background_image', $image, true);
                    $this->put($settings, $scope, 'background_size', 'cover');
                    $this->put($settings, $scope, 'background_overlay_background', 'classic');
                    $this->put($settings, $scope, 'background_overlay_color', '#101916');
                    $this->put($settings, $scope, 'background_overlay_opacity', self::slider(.78));
                    $fg = '#FFFFFF';
                    $bg = '#101916';
                }
                if ($this->direction['motion'] === 'subtle') $this->hover($settings, $scope, 'lift');
            } else $this->put($settings, $scope, 'padding', self::edges(0));
            $align = in_array($column['align'] ?? '', ['left', 'center', 'right'], true) ? $column['align'] : (in_array($pattern, ['centered', 'background', 'overlay', 'cta'], true) ? 'center' : 'left');
            $this->put($settings, $scope, $scope === 'container' ? 'flex_align_items' : 'align', match ($align) { 'center' => 'center', 'right' => 'flex-end', default => 'flex-start' });
            $widgets = [];
            foreach ($column['widgets'] as $w => $input) {
                if (!is_array($input)) return $this->error();
                if (empty($input['align'])) $input['align'] = $align;
                $inputs = $this->compatible_inputs($input);
                if (is_wp_error($inputs)) return $inputs;
                foreach ($inputs as $part) {
                    $compiled = $this->widget($part, $fg, $bg, $hero, $sectionIndex, $w);
                    if (is_wp_error($compiled)) return $compiled;
                    $widgets[] = $compiled;
                    if (count($widgets) > 48) return $this->error();
                }
            }
            if ($this->direction['motion'] === 'subtle') $this->entrance($settings, $scope, $i * 90);
            $out[] = $this->node($scope, $settings, $widgets, $scope === 'container');
        }
        return $out;
    }

    private function compatible_inputs(array $input): array|WP_Error
    {
        $type = $input['type'] ?? '';
        if (!is_string($type) || !isset(WidgetContracts::all()[$type])) return Support::error('widget', __('This widget has no reviewed classic authoring contract. Atomic and arbitrary widgets cannot be generated.', 'cort-ai-studio'));
        $status = $this->caps->status($type);
        $fallback = !$status['authoring'] || ($this->direction['motion'] === 'none' && in_array($type, ['animated-headline', 'flip-box'], true));
        $entries = ClassicWidgets::entries($input);
        if (is_wp_error($entries)) return $entries;
        if (in_array($type, ['gallery', 'image-gallery'], true) && (!is_array($input['items'] ?? null) || !array_is_list($input['items']) || !$input['items'] || count($input['items']) > 8 || count(array_filter($input['items'], 'is_string')) !== count($input['items']))) return $this->error();
        if ($type === 'slides' && !$this->caps->field($type, 'slides', 'background_image')) foreach ($entries as $entry) if ($entry['media_ref'] !== '' || $entry['image_subject'] !== '') $fallback = true;
        if (in_array($type, ['gallery', 'image-gallery'], true) && $status['authoring']) {
            // Native galleries require attachment IDs. A dry-run selector leaves the real assignments untouched.
            $picker = clone $this->media;
            foreach ($input['items'] ?? [] as $ref) {
                if (!is_string($ref)) return $this->error();
                if ($picker->choose($ref, Blueprint::plain($input['image_subject'] ?? $input['text'] ?? '', 180), 'gallery')['placeholder']) $fallback = true;
            }
        }
        if (!$fallback) return [$input];
        if (!isset(WidgetContracts::fallbacks()[$type])) return Support::error('widget_capability', sprintf(__('Widget %1$s cannot be generated: %2$s Configure a compatible component in Elementor.', 'cort-ai-studio'), $type, $status['reason']));
        $this->warnings[] = sprintf(__('Widget %1$s uses an editable classic alternative (%2$s): native controls, media or the selected motion style require it.', 'cort-ai-studio'), $type, WidgetContracts::fallbacks()[$type]);
        if ($type === 'animated-headline') return [array_replace($input, ['type' => 'heading'])];
        if (in_array($type, ['gallery', 'image-gallery'], true)) {
            if (in_array('image-carousel', $this->caps->widgets(), true)) return [array_replace($input, ['type' => 'image-carousel'])];
            $entries = array_map(static fn(string $ref): array => ['media_ref' => $ref, 'image_subject' => Blueprint::plain($input['image_subject'] ?? '', 180), 'title' => '', 'text' => '', 'button_text' => '', 'link' => ''], $input['items'] ?? []);
        } elseif (in_array($type, ['call-to-action', 'flip-box'], true)) {
            $entries = array_merge([['title' => Blueprint::plain($input['text'] ?? '', 180), 'text' => Blueprint::plain($input['description'] ?? '', 3500), 'button_text' => '', 'link' => '', 'media_ref' => Blueprint::plain($input['media_ref'] ?? '', 100), 'image_subject' => Blueprint::plain($input['image_subject'] ?? '', 180)]], $type === 'flip-box' ? array_slice($entries, 0, 1) : []);
            $entries[count($entries) - 1]['button_text'] = Blueprint::plain($input['button_text'] ?? '', 100);
            $entries[count($entries) - 1]['link'] = Blueprint::url($input['link'] ?? '');
            if ($entries[count($entries) - 1]['link'] === false) return $this->error();
        } elseif ($type === 'counter') {
            $value = $input['counter_value'] ?? null;
            if (!is_int($value) || $value < 0 || $value > 10000000) return $this->error();
            $entries = [['title' => Blueprint::plain($input['counter_prefix'] ?? '', 30) . $value . Blueprint::plain($input['counter_suffix'] ?? '', 30), 'text' => Blueprint::plain($input['text'] ?? '', 180)]];
        }
        if (!$entries) return $this->error();
        $out = [];
        foreach ($entries as $entry) {
            $base = ['link' => '', 'heading_tag' => 'h3', 'role' => 'card_title', 'align' => $input['align'] ?? 'left', 'animation' => 'none'];
            if (!empty($entry['media_ref']) || !empty($entry['image_subject'])) $out[] = $base + ['type' => 'image', 'text' => '', 'media_ref' => $entry['media_ref'], 'image_subject' => $entry['image_subject']];
            if (($entry['title'] ?? '') !== '') $out[] = array_replace($base, ['type' => 'heading', 'text' => $entry['title'], 'link' => $entry['link'] ?? '']);
            if (($entry['text'] ?? '') !== '') $out[] = array_replace($base, ['type' => 'text-editor', 'text' => $entry['text'], 'role' => 'body']);
            if (($entry['button_text'] ?? '') !== '') $out[] = array_replace($base, ['type' => 'button', 'text' => $entry['button_text'], 'link' => $entry['link'], 'role' => 'primary_button']);
        }
        return $out ?: $this->error();
    }

    private function widget(array $input, string $fg, string $bg, bool $hero, int $sectionIndex, int $index = 0): array|WP_Error
    {
        $type = $input['type'] ?? '';
        if (!is_string($type) || !in_array($type, $this->caps->widgets(), true)) return Support::error('widget', __('The design requested a widget not supported by the installed Elementor capabilities.', 'cort-ai-studio'));
        $text = Blueprint::plain($input['text'] ?? '', 7000);
        $description = Blueprint::plain($input['description'] ?? '', 3500);
        $role = $input['role'] ?? ($type === 'heading' ? ($hero && $index === 0 ? 'display' : 'title') : ($type === 'button' ? 'primary_button' : 'body'));
        if (!in_array($role, ['display', 'title', 'card_title', 'body', 'eyebrow', 'caption', 'primary_button', 'secondary_button'], true)) return $this->error();
        $settings = [];
        $tag = $input['heading_tag'] ?? ($role === 'display' && $hero ? 'h1' : 'h2');
        if (!in_array($tag, ['h1', 'h2', 'h3', 'h4'], true)) return $this->error();
        if ($hero && $role === 'display') $tag = 'h1';
        if ($tag === 'h1' && (!$hero || $role === 'eyebrow' || $this->primary_heading)) { $tag = 'h2'; $this->warnings[] = __('An extra H1 was normalized to H2 to preserve the heading hierarchy.', 'cort-ai-studio'); }
        if (in_array($role, ['eyebrow', 'caption'], true)) $tag = 'p';
        if (in_array($type, ['heading', 'animated-headline'], true) && $tag === 'h1') $this->primary_heading = true;
        $link = Blueprint::url($input['link'] ?? '');
        if ($link === false) return $this->error();
        $image = null;
        if (in_array($type, ['image', 'image-box'], true)) {
            $image = $this->media->choose((string) ($input['media_ref'] ?? ''), (string) ($input['image_subject'] ?? $text), $hero ? 'hero' : 'content');
        }
        switch ($type) {
            case 'heading': $settings += ['title' => esc_html($text), 'header_size' => $tag]; break;
            case 'text-editor': $settings['editor'] = implode('', array_map(static fn(string $line): string => '<p>' . esc_html($line) . '</p>', preg_split('/\r?\n+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [])); break;
            case 'button': $settings['text'] = $text; break;
            case 'image': case 'image-box':
                $placeholder = $image['placeholder']; unset($image['placeholder']);
                $settings['image'] = $image; $settings['image_size'] = 'large';
                if ($type === 'image-box') $settings += ['title_text' => esc_html($text), 'description_text' => esc_html($description), 'title_size' => 'h3'];
                else {
                    $this->put($settings, $type, 'width', self::slider(100, '%'), true);
                    $treatment = $input['image_treatment'] ?? 'landscape';
                    if (!in_array($treatment, ['landscape', 'portrait', 'square', 'natural'], true)) return $this->error();
                    if ($treatment !== 'natural') {
                        $height = match ($treatment) { 'portrait' => 540, 'square' => 350, default => $hero ? 470 : 360 };
                        $this->responsive($settings, $type, 'height', [self::slider($height), self::slider(min($height, 370)), self::slider($treatment === 'square' ? 300 : 280)], true);
                        $this->put($settings, $type, 'object-fit', 'cover', true);
                    }
                    $this->put($settings, $type, 'image_border_radius', self::edges($this->direction['spacing']['radius']), true);
                    if ($placeholder) { $this->put($settings, $type, 'caption_source', 'custom'); $this->put($settings, $type, 'caption', 'Image needed: ' . Text::plain($input['image_subject'] ?? $text, 120)); }
                }
                break;
            case 'icon': case 'icon-box':
                $icon = in_array($input['icon'] ?? '', Blueprint::ICONS, true) ? $input['icon'] : 'check-circle';
                $settings['selected_icon'] = ['value' => 'fas fa-' . $icon, 'library' => 'fa-solid'];
                if ($type === 'icon-box') $settings += ['title_text' => esc_html($text), 'description_text' => esc_html($description), 'title_size' => 'h3'];
                $this->put($settings, $type, 'primary_color', DesignDirection::contrast($this->direction['palette']['accent'], $bg) >= 3 ? $this->direction['palette']['accent'] : $fg);
                break;
            case 'image-carousel':
                $images = [];
                foreach (array_slice(is_array($input['items'] ?? null) ? $input['items'] : [], 0, 8) as $ref) {
                    if (!is_string($ref)) return $this->error();
                    $item = $this->media->choose($ref, (string) ($input['image_subject'] ?? $text), 'gallery');
                    unset($item['placeholder']); $images[] = $item;
                }
                if (!$images) return Support::error('gallery', __('A gallery needs image references. Use image widgets with placeholders when media is missing.', 'cort-ai-studio'));
                $settings['carousel'] = $images; $this->put($settings, $type, 'autoplay', ($this->caps->control($type, 'autoplay')['type'] ?? '') === 'switcher' ? '' : 'no', true);
                $this->put($settings, $type, 'thumbnail_size', 'large');
                $this->put($settings, $type, 'slides_to_show', '3'); $this->put($settings, $type, 'slides_to_show_tablet', '2'); $this->put($settings, $type, 'slides_to_show_mobile', '1');
                $this->put($settings, $type, 'navigation', 'both');
                $this->put($settings, $type, 'image_spacing', 'custom');
                $this->responsive($settings, $type, 'image_spacing_custom', [self::slider(24), self::slider(20), self::slider(16)]);
                $this->put($settings, $type, 'image_border_radius', self::edges($this->direction['spacing']['radius']));
                break;
            default:
                $classic = new ClassicWidgets($this->caps, $this->media, $this->direction);
                $settings = $classic->compile($input, $fg, $bg, $tag);
                if (is_wp_error($settings)) return $settings;
                $this->warnings = array_merge($this->warnings, $classic->warnings);
        }
        if ($link !== '' && in_array($type, ['heading', 'button', 'image', 'image-box', 'icon-box'], true)) {
            $settings['link'] = ['url' => $link, 'is_external' => '', 'nofollow' => ''];
            if ($type === 'image') $this->put($settings, $type, 'link_to', 'custom');
        }
        $align = in_array($input['align'] ?? '', ['left', 'center', 'right'], true) ? $input['align'] : 'left';
        $this->responsive($settings, $type, 'align', [$align, $align, $align]);
        $size = match ($role) { 'display' => $this->direction['typography']['display'], 'title' => $tag === 'h3' ? 26 : $this->direction['typography']['heading'], 'card_title' => 26, 'eyebrow', 'caption' => 14, default => $this->direction['typography']['body'] };
        $this->typography($settings, $type, $role, $size);
        $key = match ($type) { 'heading' => 'title_color', 'button' => 'button_text_color', 'icon', 'icon-box' => 'primary_color', 'image-box' => 'title_color', default => 'text_color' };
        if (!in_array($type, ['icon', 'icon-box'], true)) $this->put($settings, $type, $key, DesignDirection::contrast($fg, $bg) >= 4.5 ? $fg : DesignDirection::readable($bg));
        if (in_array($type, ['icon-box', 'image-box'], true)) {
            $this->put($settings, $type, 'title_color', $fg);
            $this->put($settings, $type, 'description_color', $fg);
            $this->typography($settings, $type, 'title', 25, 'title_typography');
            $this->typography($settings, $type, 'body', 17, 'description_typography');
        }
        if ($type === 'call-to-action') {
            $this->typography($settings, $type, 'title', 32, 'title_typography');
            $this->typography($settings, $type, 'body', 18, 'description_typography');
        }
        foreach (match ($type) {
            'icon-list' => [['body', 18, 'icon_typography']],
            'accordion', 'toggle' => [['card_title', 22, 'title_typography'], ['body', 18, 'content_typography']],
            'tabs' => [['card_title', 22, 'tab_typography'], ['body', 18, 'content_typography']],
            'counter' => [['display', 48, 'number_typography'], ['body', 18, 'title_typography']],
            'slides' => [['title', 36, 'heading_typography'], ['body', 18, 'description_typography']],
            'form' => [['body', 18, 'field_typography'], ['body', 16, 'label_typography']],
            'flip-box' => [['card_title', 26, 'title_typography_a'], ['card_title', 26, 'title_typography_b'], ['body', 18, 'description_typography_a'], ['body', 18, 'description_typography_b']],
            'animated-headline' => [[$role, $size, 'title_typography'], [$role, $size, 'words_typography']],
            default => [],
        } as [$visualRole, $visualSize, $group]) $this->typography($settings, $type, $visualRole, $visualSize, $group);
        if ($type === 'button') {
            $secondary = $role === 'secondary_button';
            $buttonBg = $secondary ? $bg : $this->direction['palette']['accent'];
            if (!$secondary && DesignDirection::contrast($buttonBg, $bg) < 3) $buttonBg = DesignDirection::contrast($this->direction['palette']['surface'], $bg) >= 3 ? $this->direction['palette']['surface'] : DesignDirection::readable($bg);
            $buttonFg = $secondary ? $fg : DesignDirection::readable($buttonBg);
            $this->put($settings, $type, 'background_color', $buttonBg, true);
            $this->put($settings, $type, 'button_text_color', $buttonFg, true);
            $this->put($settings, $type, 'border_border', 'solid'); $this->put($settings, $type, 'border_width', self::edges(1)); $this->put($settings, $type, 'border_color', $secondary ? $fg : $buttonBg);
            $this->put($settings, $type, 'border_radius', self::edges(match ($this->direction['button_style']) { 'pill' => 50, 'square' => 0, default => 10 }), true);
            $this->responsive($settings, $type, 'text_padding', [self::edges(16, 28), self::edges(15, 24), self::edges(15, 22)], true);
            $hoverBg = $this->direction['palette']['dark'];
            $this->put($settings, $type, 'button_background_hover_background', 'classic');
            $this->put($settings, $type, 'button_background_hover_color', $hoverBg, true);
            $this->put($settings, $type, 'hover_color', DesignDirection::readable($hoverBg), true);
            $this->put($settings, $type, 'button_hover_border_color', $hoverBg);
            $this->put($settings, $type, 'hover_transition_duration', self::slider(.2, 's'));
            $this->put($settings, $type, 'align_mobile', 'justify');
        }
        if ($type === 'image' && $this->direction['motion'] === 'subtle' && ($input['hover'] ?? 'none') === 'zoom') $this->put($settings, $type, 'hover_animation', 'grow');
        if ($this->direction['motion'] === 'subtle' && ($input['animation'] ?? 'none') !== 'none' && !in_array($type, ['image-carousel', 'animated-headline'], true)) $this->entrance($settings, $type, min(240, $index * 70));
        if ($this->layout === 'flexbox') $this->put($settings, $type, '_margin', self::edges(0));
        $node = $this->node('widget', $settings, []); unset($node['isInner']); $node['widgetType'] = $type;
        return $node;
    }

    private function typography(array &$settings, string $type, string $role, int $size, string $group = 'typography'): void
    {
        if (!$this->caps->control($type, $group . '_typography')) return;
        $heading = in_array($role, ['display', 'title', 'card_title', 'eyebrow'], true);
        $this->put($settings, $type, $group . '_typography', 'custom');
        $font = $this->direction['typography'][$heading ? 'heading_font' : 'body_font'];
        if ($font !== '' && !empty($this->kit['fonts'][$font]['family'])) $this->put($settings, $type, $group . '_font_family', $this->kit['fonts'][$font]['family']);
        $weight = $this->kit['fonts'][$font]['weight'] ?? '';
        $button = in_array($role, ['primary_button', 'secondary_button'], true);
        $this->put($settings, $type, $group . '_font_weight', $weight ?: ($heading ? '700' : ($button ? '600' : '400')));
        if ($this->caps->control($type, $group . '_font_size')) $this->responsive($settings, $type, $group . '_font_size', [self::slider($size), self::slider(min($size, $role === 'display' ? 48 : 34)), self::slider(min($size, $role === 'display' ? 38 : ($role === 'title' ? 29 : 18)))]);
        $this->put($settings, $type, $group . '_line_height', self::slider($heading || $button ? 1.18 : 1.65, 'em'));
        $this->put($settings, $type, $group . '_letter_spacing', self::slider($role === 'display' ? -.8 : ($role === 'eyebrow' ? 1.8 : ($heading ? -.35 : 0))));
    }

    private function background(array &$settings, string $type, string $color): void
    {
        $this->put($settings, $type, 'background_background', 'classic', true);
        $this->put($settings, $type, 'background_color', $color, true);
    }

    private function shadow(array &$settings, string $type, string $prefix, int $vertical, int $blur, float $opacity): void
    {
        $this->put($settings, $type, $prefix . '_box_shadow_type', 'yes');
        $this->put($settings, $type, $prefix . '_box_shadow', ['horizontal' => 0, 'vertical' => $vertical, 'blur' => $blur, 'spread' => -12, 'color' => 'rgba(17,35,28,' . $opacity . ')']);
    }

    private function hover(array &$settings, string $type, string $effect): void
    {
        if ($type !== 'container' || !$this->caps->control($type, '_transform_translateY_effect_hover')) return;
        $toggle = $this->caps->control($type, '_transform_translate_popover_hover')['return_value'] ?? '';
        if (!is_string($toggle) || $toggle === '') return;
        $this->put($settings, $type, '_transform_translate_popover_hover', $toggle);
        $this->put($settings, $type, '_transform_translateY_effect_hover', self::slider(-4));
        $this->put($settings, $type, '_transform_translateY_effect_hover_mobile', self::slider(0));
        $this->put($settings, $type, '_transform_transition_hover', self::slider(220));
    }

    private function entrance(array &$settings, string $type, int $delay): void
    {
        $element = in_array($type, ['container', 'section', 'column'], true);
        $this->put($settings, $type, $element ? 'animation' : '_animation', 'fadeInUp');
        $this->put($settings, $type, $element ? 'animation_duration' : '_animation_duration', 'fast');
        $this->put($settings, $type, $element ? 'animation_delay' : '_animation_delay', min(240, $delay));
    }

    private function responsive(array &$settings, string $type, string $key, array $values, bool $required = false): void
    {
        foreach (['', '_tablet', '_mobile'] as $i => $suffix) $this->put($settings, $type, $key . $suffix, $values[$i], $required);
    }

    private function put(array &$settings, string $type, string $key, mixed $value, bool $required = false): void
    {
        $this->caps->set($settings, $type, $key, $value, $required);
    }

    public static function slider(int|float|string $size, string $unit = 'px'): array { return ['unit' => $unit, 'size' => $size, 'sizes' => []]; }
    public static function gap(int $gap): array { return ['column' => (string) $gap, 'row' => (string) $gap, 'unit' => 'px', 'isLinked' => true]; }
    public static function edges(int $vertical, ?int $horizontal = null): array { return ['unit' => 'px', 'top' => (string) $vertical, 'right' => (string) ($horizontal ?? $vertical), 'bottom' => (string) $vertical, 'left' => (string) ($horizontal ?? $vertical), 'isLinked' => $horizontal === null || $horizontal === $vertical]; }
    private function node(string $type, array $settings, array $elements, bool $inner = false): array { return ['id' => bin2hex(random_bytes(4)), 'elType' => $type, 'settings' => $settings, 'elements' => $elements, 'isInner' => $inner]; }
    public function report(): array { return ['patterns' => $this->patterns, 'image_assignments' => $this->media->assignments, 'warnings' => array_values(array_unique(array_merge($this->warnings, $this->media->warnings, $this->direction['warnings'] ?? [])))]; }
    private function error(): WP_Error { return Support::error('visual_plan', __('The visual composition contains unsupported roles, settings or limits.', 'cort-ai-studio')); }
}
