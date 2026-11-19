<?php
namespace CortAIStudio;

use RuntimeException;
use Throwable;
use WP_Error;

defined('ABSPATH') || exit;

final class Writer
{
    private const SEARCH_ALLOWANCE = 16000;
    private const RESEARCH_CONTEXT_ALLOWANCE = 14500;
    private const RESPONSE_WAIT = 1800;

    public function __construct(
        private Settings $settings,
        private Jobs $jobs,
        private OpenAI $api,
        private Sources $sources,
        private Article $article,
        private Drafts $drafts,
        private Images $images
    ) {
    }

    public function start(array $input): array|WP_Error
    {
        $request = $input['request_id'] ?? '';
        if (!is_string($request) || !wp_is_uuid($request, 4)) {
            return $this->error('request', __('The request could not be identified. Reload the writer and try again.', 'cort-ai-studio'), 400);
        }
        $request = strtolower($request);
        $user = get_current_user_id();
        $existing = $this->jobs->by_request($user, $request);
        if ($existing) {
            return $this->view($existing);
        }
        if ($this->jobs->active()) {
            return $this->active_error();
        }
        $last = $this->jobs->latest($user);
        if ($last && time() - (int) strtotime($last['created_at'] . ' UTC') < 8) {
            return $this->error('cooldown', __('Wait a few seconds before starting another generation.', 'cort-ai-studio'), 429);
        }
        if ($this->settings->api_key() === '') {
            return $this->error('missing_key', __('Configure an OpenAI API key in Settings to generate articles.', 'cort-ai-studio'), 400);
        }

        $settings = $this->settings->read();
        $options = $this->article->options($input, $settings);
        if (is_wp_error($options)) {
            return new WP_Error($options->get_error_code(), $options->get_error_message(), ['status' => 400]);
        }
        if (Article::slots($options) && !current_user_can('upload_files')) {
            return $this->error('upload_permission', __('Your WordPress account needs media upload permission to generate images.', 'cort-ai-studio'), 403);
        }

        $job = $this->jobs->create($user, $request, $options['model'], [
            'phase' => 'prepare',
            'options' => $options,
            'profile' => $settings['profile'],
            'style' => $settings['style'],
            'rates' => $settings['rates'],
            'budget' => $settings['budget'],
            'warnings' => [],
            'unconfirmed_reserve' => 0.0,
        ]);
        if (!$job) {
            $job = $this->jobs->by_request($user, $request);
            if (!$job) {
                return $this->jobs->active() ? $this->active_error() : $this->storage_error();
            }
        }
        return $this->view($job);
    }

    public function current(): array|WP_Error
    {
        $job = $this->jobs->active();
        if ($job) {
            return $this->can_access($job) ? ['job' => $this->view($job)] : $this->active_error();
        }
        $job = $this->jobs->latest(get_current_user_id());
        return ['job' => $job ? $this->view($job) : null];
    }

    public function advance(int $id): array|WP_Error
    {
        $job = $this->allowed($id);
        if (is_wp_error($job)) {
            return $job;
        }
        if ($job['status'] !== 'running' || ($job['state']['retry_at'] ?? 0) > time()) {
            return $this->view($job);
        }
        $claimed = $this->jobs->claim($id);
        if (!$claimed) {
            return $this->view($this->jobs->find($id) ?? $job);
        }
        $job = $claimed;
        if (($job['state']['retry_at'] ?? 0) > time()) {
            return $this->commit($job);
        }
        $lease = $job['lease_token'];
        ignore_user_abort(true);

        try {
            unset($job['state']['paused'], $job['state']['retry_at']);
            if (!empty($job['state']['dispatch'])) {
                $this->recover($job);
            } else {
                switch ($job['state']['phase']) {
                    case 'prepare':
                        $this->prepare($job);
                        break;
                    case 'research_start':
                        $this->submit($job, true);
                        break;
                    case 'text_start':
                        $this->submit($job, false);
                        break;
                    case 'research_wait':
                        $this->poll($job, true);
                        break;
                    case 'text_wait':
                        $this->poll($job, false);
                        break;
                    case 'save':
                        $this->save($job);
                        break;
                    case 'image':
                        $this->image($job);
                        break;
                    case 'finish':
                        $this->finish($job);
                        break;
                    default:
                        $this->fail($job, __('The saved generation stage could not be read. Start a new article.', 'cort-ai-studio'));
                }
            }
        } catch (Throwable) {
            $durable = $this->jobs->find($id);
            if (!$durable || $durable['lease_token'] !== $lease) {
                return $this->storage_error();
            }
            $job = $durable;
            $job['state']['paused'] = __('WordPress interrupted this stage. Resume to recover the saved progress.', 'cort-ai-studio');
        }

        return $this->commit($job);
    }

    public function stop(int $id): array|WP_Error
    {
        $job = $this->allowed($id);
        if (is_wp_error($job) || $job['status'] !== 'running') {
            return is_wp_error($job) ? $job : $this->view($job);
        }
        if (!$this->can_stop($job)) {
            return $this->error('draft_pending', __('Save the generated draft before ending this operation.', 'cort-ai-studio'), 409);
        }
        $job = $this->jobs->claim($id);
        if (!$job) {
            return $this->error('busy', __('This stage is still running. Wait for it to finish before ending the operation.', 'cort-ai-studio'), 409);
        }
        // Check again after the claim in case the previous worker has just stored the article.
        if (!$this->can_stop($job)) {
            $this->jobs->store($job, true);
            return $this->error('draft_pending', __('Save the generated draft before ending this operation.', 'cort-ai-studio'), 409);
        }
        ignore_user_abort(true);

        if (!empty($job['state']['dispatch'])) {
            $this->recover($job);
        }
        if (!empty($job['state']['response_id'])) {
            $response = $this->api->cancel($job['state']['response_id']);
            if (is_wp_error($response)) {
                $this->uncertain($job, (float) ($job['state']['response_quote'] ?? 0));
                $this->warn($job, __('OpenAI did not confirm cancellation. Some API charges may be missing from this record.', 'cort-ai-studio'));
            } else {
                $research = $job['state']['phase'] === 'research_wait';
                if (!$research && ($response['status'] ?? '') === 'completed') {
                    $this->finish_response($job, false, $response);
                    if ($job['status'] === 'running') {
                        $job['state']['paused'] = __('The article finished before cancellation. Resume to save the draft.', 'cort-ai-studio');
                    }
                    return $this->commit($job);
                }
                $this->measure_response($job, $response, $research);
            }
        }
        if ($job['post_id']) {
            $this->warn($job, __('Remaining image steps were skipped.', 'cort-ai-studio'));
            $this->finish($job);
        } else {
            $this->close($job, 'cancelled');
        }
        return $this->commit($job);
    }

    private function prepare(array &$job): void
    {
        $state = &$job['state'];
        $options = $state['options'];
        $state['category_context'] = $this->drafts->category_context($options['category_id']);
        $state['internal'] = $options['internal'] ? $this->sources->internal($options['topic'], $options['keyword']) : [];

        $quote = Usage::quote(
            $this->article->request($state),
            $state['rates'],
            $options['external'] ? self::RESEARCH_CONTEXT_ALLOWANCE : 0
        );
        if ($options['external']) {
            $quote += Usage::quote($this->sources->research_request($options, $state['profile']), $state['rates'], self::SEARCH_ALLOWANCE, true);
        }
        $quote += count(Article::slots($options)) * Usage::image_estimate($state['rates'], $options['quality']);
        if ($this->over_budget($job, $quote)) {
            $this->fail($job, sprintf(
                /* translators: 1: estimated USD cost, 2: USD budget limit. */
                __('Planned cost %1$s exceeds the %2$s generation limit. Choose fewer images, lower image quality, or update the limit in Settings.', 'cort-ai-studio'),
                Usage::dollars($quote), Usage::dollars((float) $state['budget'])
            ));
            return;
        }
        $state['phase'] = $options['external'] ? 'research_start' : 'text_start';
    }

    private function submit(array &$job, bool $research): void
    {
        $state = &$job['state'];
        $payload = $research
            ? $this->sources->research_request($state['options'], $state['profile'])
            : $this->article->request($state);
        $quote = Usage::quote($payload, $state['rates'], $research ? self::SEARCH_ALLOWANCE : 0, $research);
        if ($this->over_budget($job, $quote)) {
            $this->response_problem($job, $research, __('The remaining generation budget is too low for this stage.', 'cort-ai-studio'));
            return;
        }

        $state['response_quote'] = $quote;
        $this->checkpoint($job, $research ? 'research_submit' : 'text_submit');
        $response = $this->api->start($payload);
        if (is_wp_error($response)) {
            if (!empty($response->get_error_data()['uncertain'])) {
                $this->uncertain($job, $quote);
            }
            $this->response_problem($job, $research, $response->get_error_message());
            return;
        }
        $id = $response['id'] ?? '';
        if (!is_string($id) || !preg_match('/^resp_[A-Za-z0-9_-]{1,240}$/D', $id)) {
            $this->measure_response($job, $response, $research);
            $this->response_problem($job, $research, __('OpenAI did not return a valid background response ID.', 'cort-ai-studio'));
            return;
        }

        $state['dispatch'] = '';
        $state['response_id'] = $id;
        $state['deadline'] = time() + self::RESPONSE_WAIT;
        $state['retry_at'] = time() + 2;
        $state['phase'] = $research ? 'research_wait' : 'text_wait';
        if (in_array($response['status'] ?? '', ['completed', 'incomplete', 'failed', 'cancelled'], true)) {
            $this->finish_response($job, $research, $response);
        }
    }

    private function poll(array &$job, bool $research): void
    {
        $state = &$job['state'];
        $response = $this->api->retrieve($state['response_id']);
        $expired = time() >= $state['deadline'];
        if (is_wp_error($response)) {
            $code = $response->get_error_code();
            if (!$expired && (in_array($code, ['cort_ai_auth', 'cort_ai_permission', 'cort_ai_missing_key', 'cort_ai_invalid_key'], true))) {
                $state['paused'] = $response->get_error_message();
                return;
            }
            if (!$expired && (!empty($response->get_error_data()['uncertain']) || $code === 'cort_ai_rate')) {
                $state['retry_at'] = time() + 8;
                return;
            }
            $this->uncertain($job, (float) $state['response_quote']);
            $this->response_problem($job, $research, $response->get_error_message());
            return;
        }

        $status = $response['status'] ?? '';
        if (in_array($status, ['queued', 'in_progress'], true)) {
            if (!$expired) {
                $state['retry_at'] = time() + 4;
                return;
            }
            $cancelled = $this->api->cancel($state['response_id']);
            if (!is_wp_error($cancelled) && ($cancelled['status'] ?? '') === 'completed') {
                $this->finish_response($job, $research, $cancelled);
                return;
            }
            if (is_wp_error($cancelled)) {
                $this->uncertain($job, (float) $state['response_quote']);
            } else {
                $this->measure_response($job, $cancelled, $research);
            }
            $this->response_problem($job, $research, __('The OpenAI response exceeded the waiting window.', 'cort-ai-studio'));
            return;
        }
        $this->finish_response($job, $research, $response);
    }

    private function finish_response(array &$job, bool $research, array $response): void
    {
        $state = &$job['state'];
        $this->measure_response($job, $response, $research);
        $this->clear_response($state);
        if (($response['status'] ?? '') !== 'completed') {
            $this->response_problem($job, $research, $this->api->response_error($response)->get_error_message());
            return;
        }

        if ($research) {
            $result = $this->sources->from_research($response);
            if (!$result['links'] || !$job['usage']['web_calls']) {
                $this->response_problem($job, true, __('Web search did not return usable source citations.', 'cort-ai-studio'));
                return;
            }
            $state['external'] = $result['links'];
            $state['research_notes'] = $result['notes'];
            $state['phase'] = 'text_start';
            return;
        }

        $article = $this->article->decode($response, $state['options']);
        if (is_wp_error($article)) {
            $this->fail($job, $article->get_error_message());
            return;
        }
        $state['article'] = $article;
        $state['phase'] = 'save';
    }

    private function save(array &$job): void
    {
        $state = &$job['state'];
        $article = $state['article'];
        $options = $state['options'];
        if (!$job['post_id']) {
            $allowlist = $this->sources->refresh($state['internal'], $state['external'] ?? []);
            $render = $this->article->render($article, $options, $allowlist, $job['request_id']);
            $post_id = $this->drafts->create($job, $article, $render['content']);
            if (is_wp_error($post_id)) {
                $state['paused'] = $post_id->get_error_message();
                return;
            }
            $job['post_id'] = $post_id;
            if ($options['internal'] && !$render['internal_count']) {
                $this->warn($job, __('No suitable published internal link was used in the article.', 'cort-ai-studio'));
            }
            if ($options['external'] && !empty($state['external']) && !$render['external_count']) {
                $this->warn($job, __('The article did not use any of the verified web citations.', 'cort-ai-studio'));
            }
            if ($render['words'] < $options['words'][0] * 0.75 || $render['words'] > $options['words'][1] * 1.25) {
                $this->warn($job, sprintf(
                    /* translators: %s: number of generated words. */
                    __('The draft has about %s words and may need an editorial length adjustment.', 'cort-ai-studio'),
                    number_format_i18n($render['words'])
                ));
            }
            $this->checkpoint($job, '');
        }
        foreach ($this->drafts->classify($job['post_id'], $article, $options, $job['request_id']) as $warning) {
            $this->warn($job, $warning);
        }

        $state['categories'] = $article['categories'];
        $state['tags'] = $article['tags'];
        $state['seo'] = ['title' => $article['seo_title'], 'description' => $article['meta_description']];
        $state['images'] = $article['images'];
        $state['attachments'] = [];
        $state['phase'] = $state['images'] ? 'image' : 'finish';
        unset($state['article'], $state['profile'], $state['internal'], $state['external'], $state['research_notes'], $state['category_context']);
    }

    private function image(array &$job): void
    {
        $state = &$job['state'];
        $slot = array_key_first($state['images']);
        if ($slot === null) {
            $state['phase'] = 'finish';
            return;
        }
        $plan = $state['images'][$slot];
        $options = $state['options'];
        if (!current_user_can('upload_files') || !current_user_can('edit_post', $job['post_id']) ||
            get_post_status($job['post_id']) !== 'draft') {
            $this->warn($job, __('The draft or permissions changed. Remaining images were skipped.', 'cort-ai-studio'));
            $state['phase'] = 'finish';
            return;
        }
        $existing = $this->images->existing($job['post_id'], $job['request_id'], $slot);
        if ($existing) {
            $this->images->ensure_alt($existing, $plan['alt']);
            $this->next_image($job, $slot, $existing);
            return;
        }
        $quote = Usage::image_estimate($state['rates'], $options['quality']);
        if ($this->over_budget($job, $quote)) {
            $this->warn($job, __('The remaining generation budget was too low for additional images.', 'cort-ai-studio'));
            $state['phase'] = 'finish';
            return;
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(150);
        }
        $this->checkpoint($job, 'image_request');
        $response = $this->api->image($this->images->prompt($plan, $state['style'], $options['size']), $options['size'], $options['quality']);
        if (is_wp_error($response)) {
            if (!empty($response->get_error_data()['uncertain'])) {
                $this->uncertain($job, $quote);
            }
            $this->image_warning($job, $slot, $response->get_error_message());
            $this->next_image($job, $slot);
            return;
        }

        $job['usage'] = Usage::add($job['usage'], Usage::from_image($response, $state['rates'], $options['quality']));
        $this->checkpoint($job, 'image_media');
        $attachment = $this->images->save(
            $job['post_id'], $job['request_id'], $slot, $plan, $response['data'][0]['b64_json'] ?? null
        );
        if (is_wp_error($attachment)) {
            $this->image_warning($job, $slot, $attachment->get_error_message());
            $attachment = 0;
        }
        $this->next_image($job, $slot, $attachment);
    }

    private function recover(array &$job): void
    {
        $state = &$job['state'];
        if (in_array($state['dispatch'], ['text_submit', 'research_submit'], true)) {
            $research = $state['dispatch'] === 'research_submit';
            $this->uncertain($job, (float) ($state['response_quote'] ?? 0));
            $this->response_problem($job, $research, __('A previous OpenAI request was interrupted and its result could not be confirmed.', 'cort-ai-studio'));
            return;
        }

        $slot = array_key_first($state['images'] ?? []);
        if ($slot === null) {
            $state['dispatch'] = '';
            $state['phase'] = 'finish';
            return;
        }
        $attachment = $this->images->existing($job['post_id'], $job['request_id'], $slot);
        if ($attachment) {
            $this->images->ensure_alt($attachment, $state['images'][$slot]['alt']);
        }
        if ($state['dispatch'] === 'image_request') {
            $this->uncertain($job, Usage::image_estimate($state['rates'], $state['options']['quality']));
        }
        if (!$attachment) {
            $this->image_warning($job, $slot, __('The request was interrupted. This image was skipped to avoid a possible duplicate charge.', 'cort-ai-studio'));
        }
        $this->next_image($job, $slot, $attachment);
    }

    private function next_image(array &$job, string $slot, int $attachment = 0): void
    {
        $state = &$job['state'];
        if ($attachment) {
            $state['attachments'][$slot] = $attachment;
        }
        unset($state['images'][$slot]);
        $state['dispatch'] = '';
        $state['phase'] = $state['images'] ? 'image' : 'finish';
    }

    private function finish(array &$job): void
    {
        foreach ($this->drafts->apply_images($job['post_id'], $job['request_id'], $job['state']['attachments'] ?? []) as $warning) {
            $this->warn($job, $warning);
        }
        if ($job['usage']['usage_incomplete']) {
            $this->warn($job, __('Some API usage could not be measured. Check the OpenAI dashboard for the billed amount.', 'cort-ai-studio'));
        }
        $this->close($job, $job['state']['warnings'] ? 'partial' : 'complete');
    }

    private function commit(array $job): array|WP_Error
    {
        if (!$this->jobs->store($job, true)) {
            return $this->storage_error();
        }
        $job['lease_until'] = 0;
        return $this->view($job);
    }

    private function checkpoint(array &$job, string $dispatch): void
    {
        $job['state']['dispatch'] = $dispatch;
        if (!$this->jobs->store($job, false)) {
            // Never send a chargeable request if its recovery marker is not durably stored.
            throw new RuntimeException('Could not checkpoint the generation.');
        }
    }

    private function measure_response(array &$job, array $response, bool $research): void
    {
        $state = $job['state'];
        $usage = Usage::from_response($response, $job['model'], $state['rates'], $research);
        $job['usage'] = Usage::add($job['usage'], $usage);
        if ($usage['usage_incomplete']) {
            $this->uncertain($job, max(0.0, (float) ($state['response_quote'] ?? 0) - $usage['estimated_usd']));
        }
    }

    private function response_problem(array &$job, bool $research, string $message): void
    {
        $this->clear_response($job['state']);
        if ($research) {
            $this->warn($job, sprintf(
                /* translators: %s: explanation of the web research failure. */
                __('Web research was skipped. %s', 'cort-ai-studio'), $message
            ));
            $job['state']['phase'] = 'text_start';
        } else {
            $this->fail($job, $message);
        }
    }

    private function clear_response(array &$state): void
    {
        unset($state['response_id'], $state['response_quote'], $state['deadline'], $state['retry_at']);
        $state['dispatch'] = '';
    }

    private function over_budget(array $job, float $quote): bool
    {
        $budget = (float) $job['state']['budget'];
        return $budget > 0 && $job['usage']['estimated_usd'] + $job['state']['unconfirmed_reserve'] + $quote > $budget + 0.000001;
    }

    private function uncertain(array &$job, float $quote): void
    {
        $job['usage']['usage_incomplete'] = 1;
        $job['state']['unconfirmed_reserve'] += $quote;
    }

    private function warn(array &$job, string $message): void
    {
        if (!in_array($message, $job['state']['warnings'], true)) {
            $job['state']['warnings'][] = $message;
        }
    }

    private function image_warning(array &$job, string $slot, string $message): void
    {
        $label = match ($slot) {
            'featured' => __('Featured image', 'cort-ai-studio'),
            'content_1' => __('Content image 1', 'cort-ai-studio'),
            default => __('Content image 2', 'cort-ai-studio'),
        };
        $this->warn($job, $label . ' — ' . $message);
    }

    private function fail(array &$job, string $message): void
    {
        $this->warn($job, $message);
        $this->close($job, 'failed');
    }

    private function close(array &$job, string $status): void
    {
        $job['status'] = $status;
        $job['state'] = array_intersect_key($job['state'], array_flip(['warnings', 'seo', 'categories', 'tags', 'attachments']));
    }

    private function view(array $job): array
    {
        $state = $job['state'];
        $active = $job['status'] === 'running';
        $phase = $state['phase'] ?? '';
        $slot = array_key_first($state['images'] ?? []);
        $stage = match ($phase) {
            'prepare' => __('Preparing the article and checking internal links…', 'cort-ai-studio'),
            'research_start', 'research_wait' => __('Researching external sources…', 'cort-ai-studio'),
            'text_start', 'text_wait' => __('Generating the article…', 'cort-ai-studio'),
            'save' => __('Saving the WordPress draft…', 'cort-ai-studio'),
            'image' => match ($slot) {
                'featured' => __('Generating the featured image…', 'cort-ai-studio'),
                'content_1' => __('Generating content image 1…', 'cort-ai-studio'),
                default => __('Generating content image 2…', 'cort-ai-studio'),
            },
            'finish' => __('Finishing the draft…', 'cort-ai-studio'),
            default => '',
        };

        $delay = max(0, (int) ($state['retry_at'] ?? 0) - time());
        $busy = $active && $job['lease_until'] > time();
        return [
            'id' => $job['id'],
            'status' => $job['status'],
            'stage' => $stage,
            'busy' => $busy,
            'pause' => $state['paused'] ?? '',
            'retry_ms' => max(150, min(10000, $delay * 1000), $busy ? 2500 : 0),
            'can_stop' => $active && $this->can_stop($job),
            'draft_saved' => $job['post_id'] > 0,
            'post' => !$active && $job['post_id'] ? $this->drafts->result($job['post_id']) : null,
            'warnings' => $state['warnings'] ?? [],
            'usage' => $job['usage'],
            'seo' => $state['seo'] ?? null,
            'categories' => $state['categories'] ?? [],
            'tags' => $state['tags'] ?? [],
        ];
    }

    private function can_stop(array $job): bool
    {
        return empty($job['state']['article']) && ($job['state']['phase'] ?? '') !== 'finish';
    }

    private function allowed(int $id): array|WP_Error
    {
        $job = $this->jobs->find($id);
        return $job && $this->can_access($job) ? $job : $this->error(
            'not_found', __('This generation could not be found.', 'cort-ai-studio'), 404
        );
    }

    private function can_access(array $job): bool
    {
        return $job['user_id'] === get_current_user_id() || current_user_can('manage_options');
    }

    private function active_error(): WP_Error
    {
        return $this->error('active', __('One generation is already active on this site. Resume it in the writer or ask a site administrator to review it.', 'cort-ai-studio'), 409);
    }

    private function storage_error(): WP_Error
    {
        return $this->error('storage', __('WordPress could not record the generation stage. Reload the writer to recover its saved state.', 'cort-ai-studio'), 503);
    }

    private function error(string $code, string $message, int $status): WP_Error
    {
        return new WP_Error('cort_ai_' . $code, $message, ['status' => $status]);
    }
}
