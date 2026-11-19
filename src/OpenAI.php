<?php
namespace CortAIStudio;

use WP_Error;

defined('ABSPATH') || exit;

final class OpenAI
{
    private const ROOT = 'https://api.openai.com/v1';

    public function __construct(private Settings $settings)
    {
    }

    public function test(string $model): array|WP_Error
    {
        $body = $this->request('GET', '/models/' . rawurlencode($model));
        if (is_array($body) && ($body['id'] ?? '') !== $model) {
            return $this->error('unexpected', __('OpenAI returned an unexpected model response.', 'cort-ai-studio'));
        }
        return $body;
    }

    public function start(array $payload): array|WP_Error
    {
        return $this->request('POST', '/responses', $payload);
    }

    public function retrieve(string $id): array|WP_Error
    {
        return $this->request('GET', '/responses/' . rawurlencode($id));
    }

    public function cancel(string $id): array|WP_Error
    {
        return $this->request('POST', '/responses/' . rawurlencode($id) . '/cancel');
    }

    public function image(string $prompt, string $size, string $quality): array|WP_Error
    {
        return $this->request('POST', '/images/generations', [
            'model' => Settings::IMAGE_MODEL,
            'prompt' => $prompt,
            'n' => 1,
            'size' => $size,
            'quality' => $quality,
            'output_format' => 'jpeg',
            'output_compression' => 86,
        ], 100, 24 * MB_IN_BYTES);
    }

    public function response_error(array $body): WP_Error
    {
        $reason = $body['incomplete_details']['reason'] ?? '';
        if ($reason === 'max_output_tokens') {
            return $this->error('incomplete', __('OpenAI reached the output token limit. Try a shorter article or a lower reasoning level.', 'cort-ai-studio'));
        }
        if ($reason === 'content_filter') {
            return $this->error('filtered', __('OpenAI filtered the response. Review the topic and editorial instructions.', 'cort-ai-studio'));
        }
        if (($body['status'] ?? '') === 'cancelled') {
            return $this->error('cancelled', __('The OpenAI response was cancelled.', 'cort-ai-studio'));
        }
        return $this->provider_error(0, $body['error']['code'] ?? '');
    }

    private function request(
        string $method,
        string $path,
        ?array $payload = null,
        int $timeout = 15,
        int $max_bytes = 2 * MB_IN_BYTES
    ): array|WP_Error {
        $key = $this->settings->api_key();
        if ($key === '') {
            return $this->error('missing_key', __('Add an OpenAI API key in Settings before generating an article.', 'cort-ai-studio'));
        }
        // Reject a misconfigured wp-config.php value before it reaches an HTTP header.
        if (!preg_match('/^[A-Za-z0-9_.-]{12,512}$/D', $key)) {
            return $this->error('invalid_key', __('Check the format of the OpenAI API key in Settings or wp-config.php.', 'cort-ai-studio'));
        }
        $args = [
            'method' => $method,
            'timeout' => $timeout,
            'redirection' => 0,
            'sslverify' => true,
            'limit_response_size' => $max_bytes,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Accept' => 'application/json',
            ],
        ];
        if ($payload !== null) {
            $encoded = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) {
                return $this->error('encoding', __('The plugin could not prepare the OpenAI request.', 'cort-ai-studio'));
            }
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = $encoded;
        }
        $http = wp_remote_request(self::ROOT . $path, $args);
        if (is_wp_error($http)) {
            return $this->error('network', __('WordPress could not complete the request to OpenAI. Check the hosting connection and time limits.', 'cort-ai-studio'), true);
        }

        $code = (int) wp_remote_retrieve_response_code($http);
        $body = json_decode(wp_remote_retrieve_body($http), true, 48);
        if ($code < 200 || $code >= 300) {
            return $this->provider_error($code, $body['error']['code'] ?? $body['error']['type'] ?? '');
        }
        if (!is_array($body)) {
            return $this->error('unexpected', __('OpenAI returned an incomplete or unreadable response.', 'cort-ai-studio'), true);
        }
        return $body;
    }

    private function provider_error(int $status, mixed $code): WP_Error
    {
        if ($status === 401 || $code === 'invalid_api_key') {
            return $this->error('auth', __('OpenAI rejected the API key. Check that it is active and belongs to the intended project.', 'cort-ai-studio'));
        }
        if ($code === 'insufficient_quota' || $code === 'billing_hard_limit_reached' || $status === 402) {
            return $this->error('quota', __('OpenAI reported insufficient quota or a billing limit. Check API billing and project limits.', 'cort-ai-studio'));
        }
        if ($status === 429 || ($code === 'rate_limit_exceeded')) {
            return $this->error('rate', __('OpenAI rate limit reached. Wait briefly or check the project limits.', 'cort-ai-studio'));
        }
        if ($status === 403 || $code === 'permission_denied') {
            return $this->error('permission', __('OpenAI denied access. Check project key permissions, model access, and organization verification for images.', 'cort-ai-studio'));
        }
        if ($code === 'model_not_found') {
            return $this->error('model', __('The selected model is unavailable to this OpenAI project. Check model access in the API dashboard.', 'cort-ai-studio'));
        }
        if ($status === 404) {
            return $this->error('not_found', __('OpenAI could not find the requested resource. Check access and the age of the response.', 'cort-ai-studio'));
        }
        if (in_array($code, ['content_policy_violation', 'moderation_blocked', 'safety_violations'], true)) {
            return $this->error('filtered', __('OpenAI declined the content. Review the topic and instructions before trying again.', 'cort-ai-studio'));
        }
        if ($status >= 500 || $status === 408 || $code === 'server_error') {
            return $this->error('unavailable', __('OpenAI is temporarily unavailable or timed out.', 'cort-ai-studio'), true);
        }
        return $this->error('rejected', __('OpenAI could not complete this operation. Check that the project allows the selected model and features.', 'cort-ai-studio'));
    }

    private function error(string $code, string $message, bool $uncertain = false): WP_Error
    {
        // Provider bodies and HTTP debugging details can contain submitted content or secrets.
        return new WP_Error('cort_ai_' . $code, $message, ['uncertain' => $uncertain]);
    }
}
