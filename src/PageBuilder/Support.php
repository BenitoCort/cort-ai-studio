<?php
namespace CortAIStudio\PageBuilder;

use WP_Error;

defined('ABSPATH') || exit;

final class Support
{
    public static function hash(mixed $value): string
    {
        $sort = static function (mixed $item) use (&$sort): mixed {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item);
            return array_map($sort, $item);
        };
        return hash('sha256', (string) wp_json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function error(string $code, string $message, int $status = 400): WP_Error
    {
        return new WP_Error('cort_ai_pb_' . $code, $message, ['status' => $status]);
    }
}
