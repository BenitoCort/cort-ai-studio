<?php
namespace CortAIStudio;

defined('ABSPATH') || exit;

final class Text
{
    public static function cut(string $value, int $limit): string
    {
        if (strlen($value) <= $limit) {
            return $value;
        }

        preg_match('/^.{0,' . $limit . '}/us', $value, $match);
        return rtrim($match[0] ?? '');
    }

    public static function field(mixed $value, int $limit, bool $multiline = false): string
    {
        if (!is_string($value)) {
            return '';
        }

        $clean = $multiline ? sanitize_textarea_field($value) : sanitize_text_field($value);
        return self::cut($clean, $limit);
    }

    public static function plain(mixed $value, int $limit): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = html_entity_decode(wp_check_invalid_utf8($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/!?\[([^\]\r\n]+)\]\([^\r\n]*?\)/u', '$1', $value);
        $value = wp_strip_all_tags($value ?? '', true);
        $value = preg_replace('/\bhttps?:\/\/[^\s<>]+/iu', '', $value);
        $value = preg_replace('/^\s*(?:#{1,6}\s+|>\s+|[-*+]\s+|\d+\.\s+)/u', '', $value ?? '');
        $value = preg_replace('/(\*\*\*|\*\*|___|__|~~|\x60{1,3})(.+?)\1/u', '$2', $value ?? '');
        $value = preg_replace('/(?<![\pL\pN])([*_])([^*_]+)\1(?![\pL\pN])/u', '$2', $value ?? '');
        $value = preg_replace('/[\s\p{Z}\x{0000}-\x{001F}]+/u', ' ', $value ?? '');

        return self::cut(trim($value ?? ''), $limit);
    }

    public static function choice(mixed $value, array $options, string $fallback): string
    {
        return is_string($value) && array_key_exists($value, $options) ? $value : $fallback;
    }
}
