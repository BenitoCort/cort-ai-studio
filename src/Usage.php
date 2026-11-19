<?php
namespace CortAIStudio;

defined('ABSPATH') || exit;

final class Usage
{
    public static function defaults(): array
    {
        return [
            'luna_input' => 0.10,
            'luna_cached' => 0.01,
            'luna_output' => 0.50,
            'terra_input' => 1.00,
            'terra_cached' => 0.05,
            'terra_output' => 5.00,
            'web_call' => 0.01,
            'image_text' => 5.00,
            'image_input' => 8.00,
            'image_output' => 30.00,
            'image_low' => 0.035,
            'image_medium' => 0.09,
            'image_high' => 0.23,
        ];
    }

    public static function labels(): array
    {
        return [
            'luna_input' => __('Luna input per 1M tokens', 'cort-ai-studio'),
            'luna_cached' => __('Luna cached input per 1M tokens', 'cort-ai-studio'),
            'luna_output' => __('Luna output per 1M tokens', 'cort-ai-studio'),
            'terra_input' => __('Terra input per 1M tokens', 'cort-ai-studio'),
            'terra_cached' => __('Terra cached input per 1M tokens', 'cort-ai-studio'),
            'terra_output' => __('Terra output per 1M tokens', 'cort-ai-studio'),
            'web_call' => __('Web search per call', 'cort-ai-studio'),
            'image_text' => __('Images API text input per 1M tokens', 'cort-ai-studio'),
            'image_input' => __('Images API image input per 1M tokens', 'cort-ai-studio'),
            'image_output' => __('Images API image output per 1M tokens', 'cort-ai-studio'),
            'image_low' => __('Low quality per-image planning estimate', 'cort-ai-studio'),
            'image_medium' => __('Medium quality per-image planning estimate', 'cort-ai-studio'),
            'image_high' => __('High quality per-image planning estimate', 'cort-ai-studio'),
        ];
    }

    public static function rates(array $input): array
    {
        $rates = self::defaults();
        foreach ($rates as $key => $default) {
            $rates[$key] = self::rate($input[$key] ?? null, $default, 'web_call' === $key ? 100 : 10000);
        }
        return $rates;
    }

    public static function rate(mixed $value, float $default, float $maximum): float
    {
        if (!is_numeric($value)) {
            return $default;
        }
        $value = (float) $value;
        return is_finite($value) && $value >= 0 && $value <= $maximum ? round($value, 6) : $default;
    }

    public static function empty(): array
    {
        return [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'web_calls' => 0,
            'images' => 0,
            'estimated_usd' => 0.0,
            'usage_incomplete' => 0,
        ];
    }

    public static function add(array $total, array $part): array
    {
        return [
            'input_tokens' => $total['input_tokens'] + $part['input_tokens'],
            'output_tokens' => $total['output_tokens'] + $part['output_tokens'],
            'web_calls' => $total['web_calls'] + $part['web_calls'],
            'images' => $total['images'] + $part['images'],
            'estimated_usd' => round($total['estimated_usd'] + $part['estimated_usd'], 6),
            'usage_incomplete' => max($total['usage_incomplete'], $part['usage_incomplete']),
        ];
    }

    public static function from_response(array $body, string $model, array $rates, bool $research): array
    {
        $usage = self::empty();
        $tokens = is_array($body['usage'] ?? null) ? $body['usage'] : [];
        $usage['usage_incomplete'] = !isset($tokens['input_tokens'], $tokens['output_tokens']) ? 1 : 0;
        $usage['input_tokens'] = self::count($tokens['input_tokens'] ?? 0);
        $usage['output_tokens'] = self::count($tokens['output_tokens'] ?? 0);
        $cached = min($usage['input_tokens'], self::count($tokens['input_tokens_details']['cached_tokens'] ?? 0));

        if ($research) {
            foreach (is_array($body['output'] ?? null) ? $body['output'] : [] as $item) {
                if (($item['type'] ?? '') === 'web_search_call') {
                    ++$usage['web_calls'];
                }
            }
        }
        $usage['estimated_usd'] = self::text_price(
            $model, $rates, $usage['input_tokens'], $usage['output_tokens'], $cached
        ) + $usage['web_calls'] * $rates['web_call'];

        return $usage;
    }

    public static function from_image(array $body, array $rates, string $quality): array
    {
        $usage = self::empty();
        $tokens = is_array($body['usage'] ?? null) ? $body['usage'] : [];
        $usage['images'] = !empty($body['data'][0]['b64_json']) ? 1 : 0;
        $usage['input_tokens'] = self::count($tokens['input_tokens'] ?? 0);
        $usage['output_tokens'] = self::count($tokens['output_tokens'] ?? 0);
        if (!isset($tokens['input_tokens'], $tokens['output_tokens'])) {
            $usage['usage_incomplete'] = 1;
            $usage['estimated_usd'] = self::image_estimate($rates, $quality);
            return $usage;
        }
        $image_input = min($usage['input_tokens'], self::count($tokens['input_tokens_details']['image_tokens'] ?? 0));
        $text_input = $usage['input_tokens'] - $image_input;
        $usage['estimated_usd'] = (
            $text_input * $rates['image_text'] + $image_input * $rates['image_input'] +
            $usage['output_tokens'] * $rates['image_output']
        ) / 1000000;
        return $usage;
    }

    public static function quote(array $payload, array $rates, int $extra_input = 0, bool $research = false): float
    {
        // Payload bytes are a deliberately conservative token proxy for our bounded prompts.
        // Web search context is a planning allowance and can vary independently.
        $input = strlen((string) wp_json_encode($payload, JSON_UNESCAPED_UNICODE)) + $extra_input;
        $amount = self::text_price($payload['model'], $rates, $input, $payload['max_output_tokens']);
        return $amount + ($research ? $rates['web_call'] : 0.0);
    }

    public static function image_estimate(array $rates, string $quality): float
    {
        return $rates['image_' . $quality];
    }

    public static function dollars(float $amount): string
    {
        return '$' . number_format($amount, $amount < 0.01 && $amount > 0 ? 6 : 4, '.', '');
    }

    private static function text_price(string $model, array $rates, int $input, int $output, int $cached = 0): float
    {
        $key = 'gpt-6.1-terra' === $model ? 'terra' : 'luna';
        return (
            ($input - $cached) * $rates[$key . '_input'] +
            $cached * $rates[$key . '_cached'] +
            $output * $rates[$key . '_output']
        ) / 1000000;
    }

    private static function count(mixed $value): int
    {
        return is_numeric($value) ? max(0, min(100000000, (int) $value)) : 0;
    }
}
