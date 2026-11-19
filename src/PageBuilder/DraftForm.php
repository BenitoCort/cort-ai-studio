<?php
namespace CortAIStudio\PageBuilder;

defined('ABSPATH') || exit;

final class DraftForm
{
    public static function validate(mixed $record, mixed $handler): void
    {
        if (!is_object($record) || !method_exists($record, 'get_form_settings') || !is_object($handler) || !method_exists($handler, 'add_error')) return;
        $classes = $record->get_form_settings('_css_classes');
        if (!is_string($classes) || !preg_match('/(?:^|\s)cort-ai-draft-form(?:\s|$)/', $classes) || $record->get_form_settings('submit_actions')) return;
        $message = __('This form needs submission actions configured in Elementor before it can accept messages.', 'cort-ai-studio');
        $handler->add_error('cort_ai_configuration', $message);
        if (method_exists($handler, 'add_error_message')) $handler->add_error_message($message);
    }
}
