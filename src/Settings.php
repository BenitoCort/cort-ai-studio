<?php
namespace CortAIStudio;

defined('ABSPATH') || exit;

final class Settings
{
    private const OPTIONS = 'cort_ai_studio_settings';
    private const KEY_OPTION = 'cort_ai_studio_api_key';
    private const CONNECTION = 'cort_ai_studio_connection';

    public const DEFAULT_MODEL = 'gpt-6-luna';
    public const IMAGE_MODEL = 'gpt-image-2.6-flare';

    public static function models(): array
    {
        return ['gpt-6-luna' => 'GPT-6 Luna', 'gpt-6.1-terra' => 'GPT-6.1 Terra'];
    }

    public static function efforts(string $model): array
    {
        // Both supported models expose these three OpenAI reasoning levels.
        return isset(self::models()[$model]) ? [
            'medium' => __('Medium', 'cort-ai-studio'),
            'high' => __('High', 'cort-ai-studio'),
            'max' => __('Max', 'cort-ai-studio'),
        ] : [];
    }

    public static function languages(): array
    {
        return array_column(self::language_data(), 'label', 'code');
    }

    public static function language(string $code): array
    {
        $languages = self::language_data();
        return $languages[$code] ?? $languages['en'];
    }

    private static function language_data(): array
    {
        return [
            'en' => ['code' => 'en', 'label' => __('English', 'cort-ai-studio'), 'name' => 'English', 'conclusion' => 'Conclusion'],
            'es' => ['code' => 'es', 'label' => __('Spanish', 'cort-ai-studio'), 'name' => 'Spanish', 'conclusion' => 'Conclusión'],
        ];
    }

    public static function qualities(): array
    {
        return [
            'low' => __('Low', 'cort-ai-studio'),
            'medium' => __('Medium', 'cort-ai-studio'),
            'high' => __('High', 'cort-ai-studio'),
        ];
    }

    public static function sizes(): array
    {
        return [
            '1536x1024' => __('Landscape (1536 × 1024)', 'cort-ai-studio'),
            '1024x1024' => __('Square (1024 × 1024)', 'cort-ai-studio'),
            '1024x1536' => __('Portrait (1024 × 1536)', 'cort-ai-studio'),
        ];
    }

    public static function profile_fields(): array
    {
        return [
            'business_name' => [__('Business name', 'cort-ai-studio'), 'text', 160],
            'website_url' => [__('Website URL', 'cort-ai-studio'), 'url', 250],
            'business_description' => [__('Business description', 'cort-ai-studio'), 'textarea', 1800],
            'main_services' => [__('Main services', 'cort-ai-studio'), 'textarea', 1000],
            'target_audience' => [__('Target audience', 'cort-ai-studio'), 'textarea', 800],
            'service_areas' => [__('Service areas / locations', 'cort-ai-studio'), 'textarea', 800],
            'brand_voice' => [__('Brand voice', 'cort-ai-studio'), 'textarea', 800],
            'preferred_language' => [__('Preferred language', 'cort-ai-studio'), 'language', 2],
            'default_cta' => [__('Default call to action', 'cort-ai-studio'), 'textarea', 800],
            'editorial_instructions' => [__('Additional editorial instructions', 'cort-ai-studio'), 'textarea', 2200],
        ];
    }

    public function read(): array
    {
        $value = get_option(self::OPTIONS, []);
        $value = is_array($value) ? $value : [];
        $value['profile'] = array_replace([
            'business_name' => get_bloginfo('name'),
            'website_url' => home_url('/'),
            'preferred_language' => 'en',
        ], is_array($value['profile'] ?? null) ? $value['profile'] : []);

        return $this->clean($value);
    }

    public function save(array $submitted): void
    {
        update_option(self::OPTIONS, $this->clean($submitted), false);
    }

    public function api_key(): string
    {
        if ($this->has_config_key()) {
            return trim(CORT_AI_STUDIO_OPENAI_API_KEY);
        }
        return (string) get_option(self::KEY_OPTION, '');
    }

    public function has_config_key(): bool
    {
        return defined('CORT_AI_STUDIO_OPENAI_API_KEY') &&
            is_string(CORT_AI_STUDIO_OPENAI_API_KEY) &&
            trim(CORT_AI_STUDIO_OPENAI_API_KEY) !== '';
    }

    public function save_key(mixed $candidate, bool $remove): bool
    {
        if ($this->has_config_key()) {
            return true;
        }
        $candidate = is_string($candidate) ? trim($candidate) : '';
        if ($candidate !== '') {
            if (!preg_match('/^[A-Za-z0-9_.-]{12,512}$/D', $candidate)) {
                return false;
            }
            update_option(self::KEY_OPTION, $candidate, false);
        } elseif ($remove) {
            delete_option(self::KEY_OPTION);
        }
        return true;
    }

    public function connection(string $model): array
    {
        if ($this->api_key() === '') {
            return ['state' => 'missing', 'label' => __('API key required', 'cort-ai-studio')];
        }
        $entry = get_option(self::CONNECTION, []);
        if (is_array($entry) && ($entry['model'] ?? '') === $model &&
            hash_equals($this->key_fingerprint(), (string) ($entry['fingerprint'] ?? ''))) {
            return $entry['ok']
                ? ['state' => 'ok', 'label' => __('Connection verified', 'cort-ai-studio'), 'time' => (int) $entry['time']]
                : ['state' => 'error', 'label' => __('Last connection test failed', 'cort-ai-studio')];
        }
        return ['state' => 'ready', 'label' => __('API key configured. Run Test Connection.', 'cort-ai-studio')];
    }

    public function record_connection(string $model, bool $ok, string $fingerprint): void
    {
        update_option(self::CONNECTION, [
            'model' => $model,
            'fingerprint' => $fingerprint,
            'ok' => $ok,
            'time' => time(),
        ], false);
    }

    public function key_fingerprint(): string
    {
        return hash_hmac('sha256', $this->api_key(), wp_salt('auth'));
    }

    private function website_url(mixed $input): string
    {
        $url = is_string($input) ? esc_url_raw(Text::cut(trim($input), 250), ['https', 'http']) : '';
        $parts = wp_parse_url($url);
        return isset($parts['scheme'], $parts['host']) && !isset($parts['user']) && !isset($parts['pass']) ? $url : '';
    }

    private function clean(array $input): array
    {
        $model = Text::choice($input['model'] ?? null, self::models(), self::DEFAULT_MODEL);
        $profile_input = is_array($input['profile'] ?? null) ? $input['profile'] : [];
        $profile = [];
        foreach (self::profile_fields() as $field => [, $type, $limit]) {
            $value = Text::field($profile_input[$field] ?? '', $limit, $type === 'textarea');
            $profile[$field] = match ($type) {
                'url' => $this->website_url($profile_input[$field] ?? ''),
                'language' => Text::choice($value, self::languages(), 'en'),
                default => $value,
            };
        }

        return [
            'model' => $model,
            'effort' => Text::choice($input['effort'] ?? null, self::efforts($model), 'medium'),
            'profile' => $profile,
            'quality' => Text::choice($input['quality'] ?? null, self::qualities(), 'medium'),
            'size' => Text::choice($input['size'] ?? null, self::sizes(), '1536x1024'),
            'style' => Text::field($input['style'] ?? '', 700, true),
            'budget' => Usage::rate($input['budget'] ?? null, 0.75, 1000),
            'rates' => Usage::rates(is_array($input['rates'] ?? null) ? $input['rates'] : []),
        ];
    }
}
