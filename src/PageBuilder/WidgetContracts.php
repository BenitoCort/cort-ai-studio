<?php
namespace CortAIStudio\PageBuilder;

defined('ABSPATH') || exit;

/** Reviewed classic control contracts. Provider branding never grants authoring capability. */
final class WidgetContracts
{
    public static function all(): array
    {
        return [
            'heading' => ['title' => 'text|textarea', 'header_size' => 'select'],
            'text-editor' => ['editor' => 'wysiwyg'],
            'button' => ['text' => 'text', 'link' => 'url'],
            'image' => ['image' => 'media', 'image_size' => 'select'],
            'icon' => ['selected_icon' => 'icons'],
            'icon-box' => ['selected_icon' => 'icons', 'title_text' => 'text', 'description_text' => 'textarea', 'title_size' => 'select'],
            'image-box' => ['image' => 'media', 'title_text' => 'text', 'description_text' => 'textarea', 'title_size' => 'select'],
            'icon-list' => ['icon_list' => 'repeater'],
            'accordion' => ['tabs' => 'repeater'], 'toggle' => ['tabs' => 'repeater'], 'tabs' => ['tabs' => 'repeater'],
            'counter' => ['starting_number' => 'number', 'ending_number' => 'number', 'prefix' => 'text', 'suffix' => 'text', 'title' => 'text'],
            'image-carousel' => ['carousel' => 'gallery', 'autoplay' => 'select|switcher', 'slides_to_show' => 'select'],
            'image-gallery' => ['wp_gallery' => 'gallery', 'gallery_columns' => 'select'],
            'gallery' => ['gallery_type' => 'select', 'gallery' => 'gallery', 'columns' => 'number'],
            'call-to-action' => ['title' => 'text', 'description' => 'textarea', 'button' => 'text', 'link' => 'url', 'bg_image' => 'media'],
            'flip-box' => ['title_text_a' => 'text', 'description_text_a' => 'textarea', 'title_text_b' => 'text', 'description_text_b' => 'textarea', 'button_text' => 'text', 'link' => 'url', 'flip_effect' => 'select'],
            'animated-headline' => ['headline_style' => 'select', 'highlighted_text' => 'text', 'before_text' => 'text', 'after_text' => 'text', 'tag' => 'select'],
            'slides' => ['slides' => 'repeater', 'autoplay' => 'switcher'],
            'form' => ['form_fields' => 'repeater|form-fields-repeater', 'form_name' => 'text', 'button_text' => 'text', 'submit_actions' => 'select2', '_css_classes' => 'text'],
        ];
    }

    public static function repeaters(): array
    {
        $tabs = ['tab_title' => 'text', 'tab_content' => 'wysiwyg'];
        return [
            'icon-list' => ['icon_list' => ['text' => 'text', 'selected_icon' => 'icons', 'link' => 'url']],
            'accordion' => ['tabs' => $tabs], 'toggle' => ['tabs' => $tabs], 'tabs' => ['tabs' => $tabs],
            'slides' => ['slides' => ['heading' => 'text', 'description' => 'textarea', 'button_text' => 'text', 'link' => 'url']],
            'form' => ['form_fields' => ['field_type' => 'select', 'field_label' => 'text', 'placeholder' => 'text', 'required' => 'switcher', 'custom_id' => 'text']],
        ];
    }

    public static function fallbacks(): array
    {
        return [
            'icon-list' => 'heading + text-editor', 'accordion' => 'heading + text-editor', 'toggle' => 'heading + text-editor', 'tabs' => 'heading + text-editor',
            'counter' => 'heading + text-editor', 'image-gallery' => 'image-carousel or image sequence', 'gallery' => 'image-carousel or image sequence',
            'call-to-action' => 'heading + text-editor + button', 'flip-box' => 'static heading + text-editor + button',
            'animated-headline' => 'heading', 'slides' => 'static image + heading + text-editor + button',
        ];
    }

    public static function briefs(): array
    {
        return [
            'icon-list' => 'entries: title/text/icon/link. Short benefits or checklists.',
            'accordion' => 'entries: title/text. Classic FAQ, never nested/Atomic structures.',
            'toggle' => 'entries: title/text. Independently expandable classic content.',
            'tabs' => 'entries: title/text. Related classic content panels.',
            'counter' => 'counter_value, counter_prefix, counter_suffix; text is label. Only confirmed numbers from the brief/profile.',
            'image-carousel' => 'items: distinct media references. No autoplay.',
            'image-gallery' => 'items: media references. Basic Core gallery; prefer gallery or image-grid pattern for explicit tablet columns.',
            'gallery' => 'items: distinct media references. Native advanced single gallery with responsive columns.',
            'call-to-action' => 'text/description/button_text/link/media_ref/image_subject.',
            'flip-box' => 'text/description are front; first entries item title/text is back; button_text/link. Use static content when motion=none.',
            'animated-headline' => 'text is highlighted heading; avoid when motion=none.',
            'slides' => 'entries: title/text/button_text/link/media_ref/image_subject. No autoplay; static fallback if native image fields are unavailable.',
            'form' => 'form_fields: label/type(text,email,tel,textarea)/placeholder/required. Draft form only: actions configured manually in Elementor before submissions work. Never generate email recipients, API keys, upload/payment fields or integrations.',
        ];
    }
}
