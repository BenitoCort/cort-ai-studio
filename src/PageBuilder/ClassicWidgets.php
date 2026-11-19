<?php
namespace CortAIStudio\PageBuilder;

use WP_Error;

defined('ABSPATH') || exit;

/** Bounded content adapters for registered V3 widgets, irrespective of the provider. */
final class ClassicWidgets
{
    public array $warnings = [];

    public function __construct(private ElementorCapabilities $caps, private MediaSelection $media, private array $direction) {}

    public static function entries(array $input): array|WP_Error
    {
        $entries = $input['entries'] ?? [];
        if (!is_array($entries) || !array_is_list($entries) || count($entries) > 8) return self::error();
        $result = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) return self::error();
            $link = Blueprint::url($entry['link'] ?? '');
            if ($link === false) return self::error();
            $result[] = ['title' => Blueprint::plain($entry['title'] ?? '', 180), 'text' => Blueprint::plain($entry['text'] ?? '', 2000),
                'button_text' => Blueprint::plain($entry['button_text'] ?? '', 100), 'link' => $link,
                'icon' => in_array($entry['icon'] ?? '', Blueprint::ICONS, true) ? $entry['icon'] : 'check-circle',
                'media_ref' => Blueprint::plain($entry['media_ref'] ?? '', 100), 'image_subject' => Blueprint::plain($entry['image_subject'] ?? '', 180)];
        }
        return $result;
    }

    public function compile(array $input, string $fg, string $bg, string $tag): array|WP_Error
    {
        $type = $input['type']; $settings = [];
        $text = Blueprint::plain($input['text'] ?? '', 7000);
        $description = Blueprint::plain($input['description'] ?? '', 3500);
        $link = Blueprint::url($input['link'] ?? '');
        if ($link === false) return self::error();
        $entries = self::entries($input);
        if (is_wp_error($entries)) return $entries;
        $put = function (string $key, mixed $value, bool $required = false) use (&$settings, $type): void { $this->caps->set($settings, $type, $key, $value, $required); };
        switch ($type) {
            case 'icon-list':
                if (!$entries) return self::error();
                $rows = array_map(static fn(array $e): array => ['_id' => self::id(), 'text' => esc_html($e['title'] ?: $e['text']), 'selected_icon' => ['value' => 'fas fa-' . $e['icon'], 'library' => 'fa-solid'], 'link' => ['url' => $e['link']]], $entries);
                $this->caps->repeater($settings, $type, 'icon_list', $rows);
                $put('text_color', $fg); $put('icon_color', $fg);
                break;
            case 'accordion': case 'toggle': case 'tabs':
                if (!$entries) return self::error();
                $rows = array_map(static fn(array $e): array => ['_id' => self::id(), 'tab_title' => esc_html($e['title']), 'tab_content' => self::paragraphs($e['text'])], $entries);
                $this->caps->repeater($settings, $type, 'tabs', $rows);
                $put('title_html_tag', 'h3');
                foreach (['title_color', 'tab_color', 'tab_active_color', 'title_active_color', 'content_color', 'icon_color', 'icon_active_color'] as $key) $put($key, $fg);
                $put('background_color', $bg);
                break;
            case 'counter':
                $value = $input['counter_value'] ?? null;
                if (!is_int($value) || $value < 0 || $value > 10000000) return self::error();
                $put('starting_number', $this->direction['motion'] === 'none' ? $value : 0, true);
                $put('ending_number', $value, true); $put('title', $text, true);
                $put('prefix', Blueprint::plain($input['counter_prefix'] ?? '', 30), true);
                $put('suffix', Blueprint::plain($input['counter_suffix'] ?? '', 30), true);
                $put('duration', 1200); $put('number_color', $fg); $put('title_color', $fg);
                break;
            case 'gallery': case 'image-gallery':
                $items = $input['items'] ?? [];
                if (!is_array($items) || !array_is_list($items) || !$items || count($items) > 8) return self::error();
                $images = [];
                foreach ($items as $ref) {
                    if (!is_string($ref)) return self::error();
                    $image = $this->media->choose($ref, Blueprint::plain($input['image_subject'] ?? $text, 180), 'gallery');
                    unset($image['placeholder']); $images[] = $image;
                }
                $put($type === 'gallery' ? 'gallery' : 'wp_gallery', $images, true);
                if ($type === 'gallery') {
                    $put('gallery_type', 'single', true); $put('columns', 3, true); $put('columns_tablet', 2, true); $put('columns_mobile', 1, true);
                    $put('gallery_layout', 'grid'); $put('thumbnail_image_size', 'large'); $put('link_to', 'none');
                    foreach (['' => 24, '_tablet' => 20, '_mobile' => 16] as $suffix => $gap) $put('gap' . $suffix, VisualCompiler::slider($gap));
                } else { $put('gallery_columns', '3', true); $put('thumbnail_size', 'large'); $put('gallery_link', 'none'); $this->warnings[] = __('Core Gallery uses its native/theme responsive behavior; choose the advanced gallery or an image grid for explicit tablet columns.', 'cort-ai-studio'); }
                break;
            case 'call-to-action':
                $put('title', esc_html($text), true); $put('description', esc_html($description), true);
                $put('button', Blueprint::plain($input['button_text'] ?? '', 100), true); $put('link', ['url' => $link], true);
                $image = !empty($input['media_ref']) || !empty($input['image_subject']) ? $this->image($input, 'call to action') : ['id' => 0, 'url' => ''];
                unset($image['placeholder']);
                if ($image['url'] !== '') { $fg = '#FFFFFF'; $put('overlay_color', 'rgba(16,25,22,0.78)'); $put('overlay_color_hover', 'rgba(16,25,22,0.78)'); }
                $put('bg_image', $image, true); $put('title_tag', 'h3'); $put('title_color', $fg); $put('description_color', $fg);
                $put('button_text_color', DesignDirection::readable($this->direction['palette']['accent'])); $put('button_background_color', $this->direction['palette']['accent']);
                break;
            case 'flip-box':
                $back = $entries[0] ?? ['title' => $text, 'text' => $description];
                foreach (['title_text_a' => $text, 'description_text_a' => $description, 'title_text_b' => $back['title'], 'description_text_b' => $back['text']] as $key => $value) $put($key, esc_html($value), true);
                $put('button_text', Blueprint::plain($input['button_text'] ?? '', 100), true); $put('link', ['url' => $link], true);
                $put('flip_effect', 'flip', true); $put('title_tag', 'h3'); $put('graphic_element', 'none');
                foreach (['a', 'b'] as $side) { $put('background_' . $side . '_background', 'classic'); $put('background_' . $side . '_color', $bg); $put('title_color_' . $side, $fg); $put('description_color_' . $side, $fg); }
                break;
            case 'animated-headline':
                $put('headline_style', 'highlight', true); $put('before_text', '', true); $put('highlighted_text', esc_html($text), true); $put('after_text', '', true);
                $put('tag', $tag, true); $put('loop', ''); $put('title_color', $fg);
                break;
            case 'slides':
                if (!$entries) return self::error();
                $rows = [];
                foreach ($entries as $entry) {
                    $row = ['_id' => self::id(), 'heading' => esc_html($entry['title']), 'description' => esc_html($entry['text']), 'button_text' => $entry['button_text'], 'link' => ['url' => $entry['link']]];
                    if ($this->caps->field($type, 'slides', 'background_color')) $row['background_color'] = $this->direction['palette']['dark'];
                    if ($entry['media_ref'] !== '' || $entry['image_subject'] !== '') { $image = $this->image($entry, 'slide'); unset($image['placeholder']); $row['background_image'] = $image; if ($this->caps->field($type, 'slides', 'background_overlay')) $row['background_overlay'] = 'yes'; }
                    $rows[] = $row;
                }
                $this->caps->repeater($settings, $type, 'slides', $rows);
                $put('autoplay', '', true); $put('infinite', ''); $put('content_animation', ''); $put('slides_title_tag', 'h3');
                $put('heading_color', '#FFFFFF'); $put('description_color', '#FFFFFF');
                if (!$this->caps->field($type, 'slides', 'background_color')) { $put('heading_color', $fg); $put('description_color', $fg); }
                break;
            case 'form':
                $fields = $input['form_fields'] ?? [];
                if (!is_array($fields) || !array_is_list($fields) || !$fields || count($fields) > 8) return self::error();
                $rows = [];
                foreach ($fields as $index => $field) {
                    if (!is_array($field) || !in_array($field['type'] ?? '', ['text', 'email', 'tel', 'textarea'], true) || !is_bool($field['required'] ?? null)) return self::error();
                    $required = $this->caps->field($type, 'form_fields', 'required')['return_value'] ?? 'yes';
                    $row = ['_id' => self::id(), 'field_type' => $field['type'], 'field_label' => Blueprint::plain($field['label'] ?? '', 100), 'placeholder' => Blueprint::plain($field['placeholder'] ?? '', 140), 'required' => $field['required'] ? $required : '', 'custom_id' => 'cort_field_' . ($index + 1)];
                    if ($this->caps->field($type, 'form_fields', 'width')) $row['width'] = '100';
                    $rows[] = $row;
                }
                $this->caps->repeater($settings, $type, 'form_fields', $rows);
                $put('form_name', 'Cort AI: ' . Blueprint::plain($text, 80), true); $put('_css_classes', 'cort-ai-draft-form', true);
                $put('button_text', Blueprint::plain($input['button_text'] ?? '', 100) ?: __('Send message', 'cort-ai-studio'), true);
                $put('submit_actions', [], true); $put('show_labels', 'yes'); $put('button_width', '100'); $put('button_width_mobile', '100');
                $put('field_text_color', DesignDirection::readable($this->direction['palette']['surface'])); $put('field_background_color', $this->direction['palette']['surface']);
                $put('label_color', $fg);
                $this->warnings[] = __('Form fields are editable. Configure delivery/actions in Elementor before the form can accept submissions; no email or storage action was enabled.', 'cort-ai-studio');
                break;
            default: return self::error();
        }
        return $settings;
    }

    private function image(array $input, string $role): array
    {
        return $this->media->choose(Blueprint::plain($input['media_ref'] ?? '', 100), Blueprint::plain($input['image_subject'] ?? '', 180), $role);
    }

    public static function paragraphs(string $text): string { return implode('', array_map(static fn(string $line): string => '<p>' . esc_html($line) . '</p>', preg_split('/\r?\n+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [])); }
    private static function id(): string { return bin2hex(random_bytes(4)); }
    private static function error(): WP_Error { return Support::error('widget_content', __('The classic widget content, fields or limits are not supported. Nothing was saved.', 'cort-ai-studio')); }
}
