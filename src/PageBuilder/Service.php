<?php
namespace CortAIStudio\PageBuilder;

use CortAIStudio\Images;
use CortAIStudio\OpenAI;
use CortAIStudio\Settings;
use CortAIStudio\Text;
use CortAIStudio\Usage;
use Throwable;
use WP_Error;

defined('ABSPATH') || exit;

final class Service
{
    public const OPERATIONS = ['create', 'add', 'edit', 'remove', 'reorder', 'content'];

    public function __construct(
        private Settings $settings, private OpenAI $api, private Repository $repository,
        private Adapter $adapter, private PlanEngine $blueprint, private PreviewBackup $previews, private Images $images
    ) {}

    public function start(array $input): array|WP_Error
    {
        $settings = $this->settings->read();
        $options = $this->options($input, $settings);
        if (is_wp_error($options)) return $options;
        $existing = $this->repository->request(get_current_user_id(), $options['request_id']);
        if ($existing) return $this->view($existing);
        if (!$this->adapter->availability()['supported']) return Support::error('version', $this->adapter->availability()['message']);
        if ($this->repository->active(get_current_user_id())) return Support::error('active', __('Resume or discard your current Page Builder proposal first.', 'cort-ai-studio'), 409);
        $recent = $this->repository->history(get_current_user_id(), 1);
        if ($recent && time() - strtotime($recent[0]['created_at'] . ' UTC') < 8) return Support::error('rate', __('Wait a few seconds before creating another proposal.', 'cort-ai-studio'), 429);

        $source = null;
        $analysis = ['tree' => [], 'design' => $this->adapter->design(), 'layout' => $this->adapter->availability()['layout']];
        if ($options['page_id']) {
            $analysis = $this->adapter->inspect($options['page_id']);
            if (is_wp_error($analysis)) return $analysis;
            if (!$analysis['editable']) return Support::error('unsupported', implode(' ', $analysis['issues']) ?: __('This page is read-only.', 'cort-ai-studio'));
            $unlocked = $this->adapter->unlocked($options['page_id']);
            if (is_wp_error($unlocked)) return $unlocked;
            $source = $this->adapter->snapshot($options['page_id']);
            if (is_wp_error($source)) return $source;
            $options['title'] = $analysis['title'];
            $ids = array_column($source['elements'], 'id');
            if ($options['operation'] !== 'add' && !in_array($options['section_id'], $ids, true)) return Support::error('section', __('Select an existing top-level section.', 'cort-ai-studio'));
            if (in_array($options['position'], ['before', 'after'], true) && (!in_array($options['anchor_id'], $ids, true) || ($options['operation'] === 'reorder' && $options['anchor_id'] === $options['section_id']))) return Support::error('position', __('Select a different section as the insertion anchor.', 'cort-ai-studio'));
        }
        $media = $this->media($options['media_ids'], $source['elements'] ?? [], $options);
        if (is_wp_error($media)) return $media;
        for ($i = 1; $i <= $options['image_count']; ++$i) $media['generated_' . $i] = ['ref' => 'generated_' . $i, 'title' => 'Optional AI image', 'alt' => '', 'image' => ['id' => 0, 'url' => '']];
        $state = [
            'options' => $options, 'source' => $source, 'source_hash' => $source ? $this->adapter->fingerprint($source) : '',
            'analysis' => $analysis, 'profile' => $settings['profile'], 'rates' => $settings['rates'], 'budget' => $settings['budget'],
            'style' => $settings['style'], 'media' => $media, 'warnings' => [], 'stage' => 'submit', 'dispatch' => '',
            'reserve' => 0.0, 'adapter_version' => $this->adapter->availability()['version'],
            'visual_pipeline' => $this->blueprint instanceof DesignPlanning && in_array($options['operation'], ['create', 'add'], true),
        ];
        if ($state['visual_pipeline']) $state['stage'] = 'design_submit';
        if ($state['visual_pipeline'] && empty($analysis['design']['capabilities']['widgets'])) return Support::error('widgets', __('No compatible classic widgets are registered for page generation.', 'cort-ai-studio'));
        if (in_array($options['operation'], ['remove', 'reorder'], true)) {
            $state['plan'] = ['sections' => [], 'updates' => [], 'images' => [], 'summary' => $options['operation'] === 'remove' ? 'Remove the selected section and its children.' : 'Move the selected section to the chosen position.'];
            $state['stage'] = 'prepare_preview';
        } else {
            if ($this->settings->api_key() === '') return Support::error('key', __('Configure your OpenAI API key in Settings.', 'cort-ai-studio'));
            $payload = $this->blueprint->request($state);
            if (strlen((string) wp_json_encode($payload)) > 90000) return Support::error('context', __('The selected page context is too large. Choose one section or use the visual editor.', 'cort-ai-studio'));
            $quote = Usage::quote($payload, $state['rates']) + $options['image_count'] * Usage::image_estimate($state['rates'], $options['quality']);
            if ($state['visual_pipeline']) $quote += Usage::quote($this->blueprint->design_request($state), $state['rates'], 6000);
            if ($state['budget'] > 0 && $quote > $state['budget']) return Support::error('budget', sprintf(__('Planned cost %s exceeds the article/page spending limit. Adjust the options or Settings.', 'cort-ai-studio'), Usage::dollars($quote)));
        }
        $row = $this->repository->create($options, $state);
        return $row ? $this->view($row) : Support::error('storage', __('Could not record the proposal. Another proposal may already be active.', 'cort-ai-studio'), 409);
    }

    public function advance(int $id): array|WP_Error
    {
        $row = $this->allowed($id);
        if (is_wp_error($row)) return $row;
        if (!in_array($row['status'], ['generating', 'applying', 'restoring'], true) || ($row['state']['retry_at'] ?? 0) > time()) return $this->view($row);
        $row = $this->repository->claim($id);
        if (!$row) return $this->get($id);
        if (!in_array($row['status'], ['generating', 'applying', 'restoring'], true)) return $this->commit($row);
        ignore_user_abort(true);
        try {
            unset($row['state']['paused']);
            if (in_array($row['state']['dispatch'], ['apply', 'restore'], true)) {
                $this->recover_write($row);
            } elseif ($row['state']['dispatch'] === 'submit') {
                $row['usage']['usage_incomplete'] = 1;
                $this->fail($row, __('A previous OpenAI submission could not be confirmed. It was not repeated to avoid duplicate charges.', 'cort-ai-studio'));
            } elseif (str_starts_with($row['state']['dispatch'], 'image')) {
                $this->recover_image($row);
            } else {
                switch ($row['state']['stage']) {
                    case 'design_submit': case 'submit': $this->submit($row); break;
                    case 'poll': $this->poll($row); break;
                    case 'prepare_preview':
                        $preview = $this->previews->create($row);
                        if (is_wp_error($preview)) $this->fail($row, $preview->get_error_message());
                        else {
                            $row['preview_id'] = $preview;
                            $row['state']['stage'] = !empty($row['state']['plan']['images']) ? 'images' : 'save_preview';
                            $row['state']['image_index'] = 0;
                        }
                        break;
                    case 'images': $this->image($row); break;
                    case 'save_preview': $this->save_preview($row); break;
                    default: $this->fail($row, __('The saved proposal stage is invalid.', 'cort-ai-studio'));
                }
            }
        } catch (Throwable $error) {
            error_log('Cort AI Page Builder operation ' . $id . ': ' . get_class($error));
            $durable = $this->repository->find($id);
            if (!$durable || $durable['lease_token'] !== $row['lease_token']) return $this->storage_error();
            $row = $durable;
            $row['state']['paused'] = __('WordPress interrupted this stage. Resume to recover saved progress.', 'cort-ai-studio');
        }
        return $this->commit($row);
    }

    private function submit(array &$row): void
    {
        $planning = $row['state']['stage'] === 'design_submit' && $this->blueprint instanceof DesignPlanning;
        $payload = $planning ? $this->blueprint->design_request($row['state']) : $this->blueprint->request($row['state']);
        if (strlen((string) wp_json_encode($payload)) > 90000) { $this->fail($row, __('The planned page context exceeds the supported request size.', 'cort-ai-studio')); return; }
        $row['state']['response_kind'] = $planning ? 'design' : 'page';
        $quote = Usage::quote($payload, $row['state']['rates']);
        if ($this->over_budget($row, $quote)) { $this->fail($row, __('The remaining budget is too low for this proposal.', 'cort-ai-studio')); return; }
        $row['state']['quote'] = $quote;
        $this->checkpoint($row, 'submit');
        $response = $this->api->start($payload);
        if (is_wp_error($response)) {
            if (!empty($response->get_error_data()['uncertain'])) $row['usage']['usage_incomplete'] = 1;
            $this->fail($row, $response->get_error_message());
            return;
        }
        $id = $response['id'] ?? '';
        if (!is_string($id) || !preg_match('/^resp_[a-zA-Z0-9_-]{1,240}$/D', $id)) {
            $row['usage']['usage_incomplete'] = 1;
            $this->fail($row, __('OpenAI did not return a recoverable response ID.', 'cort-ai-studio'));
            return;
        }
        $row['state']['response_id'] = $id;
        $row['state']['deadline'] = time() + 1800;
        $row['state']['stage'] = 'poll';
        $row['state']['dispatch'] = '';
        $row['state']['retry_at'] = time() + 2;
        if (!in_array($response['status'] ?? '', ['queued', 'in_progress'], true)) $this->response($row, $response);
    }

    private function poll(array &$row): void
    {
        $response = $this->api->retrieve($row['state']['response_id']);
        if (is_wp_error($response)) {
            if (time() < $row['state']['deadline']) {
                if (!empty($response->get_error_data()['uncertain']) || $response->get_error_code() === 'cort_ai_rate') $row['state']['retry_at'] = time() + 8;
                else $row['state']['paused'] = $response->get_error_message();
                return;
            }
            $row['usage']['usage_incomplete'] = 1;
            $this->fail($row, $response->get_error_message());
            return;
        }
        if (in_array($response['status'] ?? '', ['queued', 'in_progress'], true)) {
            if (time() >= $row['state']['deadline']) {
                $cancelled = $this->api->cancel($row['state']['response_id']);
                if (!is_wp_error($cancelled) && ($cancelled['status'] ?? '') === 'completed') { $this->response($row, $cancelled); return; }
                $row['usage']['usage_incomplete'] = 1;
                $this->fail($row, __('The proposal exceeded its waiting window. Check OpenAI for any unconfirmed usage.', 'cort-ai-studio'));
            } else $row['state']['retry_at'] = time() + 4;
            return;
        }
        $this->response($row, $response);
    }

    private function response(array &$row, array $response): void
    {
        $usage = Usage::from_response($response, $row['model'], $row['state']['rates'], false);
        $row['usage'] = Usage::add($row['usage'], $usage);
        if ($usage['usage_incomplete']) $row['state']['reserve'] += $row['state']['quote'] ?? 0;
        unset($row['state']['response_id'], $row['state']['retry_at']);
        if (($response['status'] ?? '') !== 'completed') { $this->fail($row, $this->api->response_error($response)->get_error_message()); return; }
        if (($row['state']['response_kind'] ?? '') === 'design' && $this->blueprint instanceof DesignPlanning) {
            $direction = $this->blueprint->decode_design($response, $row['state']);
            if (is_wp_error($direction)) { $this->fail($row, $direction->get_error_message()); return; }
            $row['state']['design_plan'] = $direction;
            $row['state']['design_summary'] = ['art_direction' => $direction['art_direction'], 'palette' => $direction['palette'], 'responsive_strategy' => $direction['responsive_strategy'], 'image_strategy' => $direction['image_strategy']];
            if ($row['state']['options']['operation'] === 'create' && ($row['state']['options']['document_layout'] ?? 'auto') === 'auto') $row['state']['options']['document_layout'] = $direction['document_layout'];
            $row['state']['stage'] = 'submit';
            unset($row['state']['response_kind']);
            return;
        }
        $plan = $this->blueprint->decode($response);
        if (is_wp_error($plan)) { $this->fail($row, $plan->get_error_message()); return; }
        if (count($plan['images']) > $row['state']['options']['image_count']) { $this->fail($row, __('The proposal requested more images than authorized.', 'cort-ai-studio')); return; }
        $seen = [];
        foreach ($plan['images'] as &$image) {
            $ref = $image['ref'] ?? '';
            if (!is_string($ref) || !preg_match('/^generated_[12]$/D', $ref) || !isset($row['state']['media'][$ref]) || isset($seen[$ref])) { $this->fail($row, __('The proposal contains invalid image references.', 'cort-ai-studio')); return; }
            $seen[$ref] = true;
            $image = ['ref' => $ref, 'title' => Text::plain($image['title'] ?? '', 140), 'alt' => Text::plain($image['alt'] ?? '', 180), 'prompt' => Text::plain($image['prompt'] ?? '', 1400)];
            if ($image['prompt'] === '') { $this->fail($row, __('The image brief was empty.', 'cort-ai-studio')); return; }
            $row['state']['media'][$ref]['title'] = $image['title'];
            $row['state']['media'][$ref]['alt'] = $image['alt'];
            $row['state']['media'][$ref]['description'] = $image['prompt'];
        }
        unset($image);
        // Search again using concrete component subjects, which may not appear in the initial brief.
        if (!empty($row['state']['visual_pipeline']) && !empty($row['state']['options']['reuse_media'])) {
            $subjects = [];
            $readSubjects = function (array $values) use (&$readSubjects, &$subjects): void {
                foreach ($values as $key => $value) {
                    if ($key === 'image_subject' && is_string($value) && trim($value) !== '') $subjects[] = Text::plain($value, 180);
                    elseif (is_array($value)) $readSubjects($value);
                }
            };
            $readSubjects($plan['sections']);
            if ($subjects) {
                $available = $this->media($row['state']['options']['media_ids'], $row['state']['source']['elements'] ?? [], $row['state']['options'], array_slice(array_unique($subjects), 0, 24));
                if (is_wp_error($available)) { $this->fail($row, $available->get_error_message()); return; }
                $row['state']['media'] = array_replace($row['state']['media'], $available);
            }
        }
        // Validate the scope before any optional image charge or draft creation.
        $candidate = $this->blueprint->apply($row['state']['source']['elements'] ?? [], $plan, $row['state']['options'], $row['state']['media'], $this->design_context($row['state']), $row['state']['analysis']['layout']);
        if (is_wp_error($candidate)) { $this->fail($row, $candidate->get_error_message()); return; }
        $valid = $this->adapter->validate($candidate['elements']);
        if (is_wp_error($valid)) { $this->fail($row, $valid->get_error_message()); return; }
        // Bill only images used by the validated composition, including background/gallery slots.
        $refs = [];
        $collect = function (array $values) use (&$collect, &$refs): void {
            foreach ($values as $key => $value) {
                if ($key === 'media_ref' && is_string($value)) $refs[] = $value;
                elseif ($key === 'items' && is_array($value)) $refs = array_merge($refs, array_filter($value, 'is_string'));
                elseif (is_array($value)) $collect($value);
            }
        };
        if (!empty($candidate['design_quality'])) $refs = array_column($candidate['design_quality']['image_assignments'], 'ref');
        else $collect(['sections' => $plan['sections'], 'updates' => $plan['updates']]);
        $plan['images'] = array_values(array_filter($plan['images'], static fn(array $image): bool => in_array($image['ref'], $refs, true)));
        foreach (array_diff(array_keys($seen), $refs) as $unused) unset($row['state']['media'][$unused]);
        $row['state']['plan'] = $plan;
        $row['state']['stage'] = 'prepare_preview';
    }

    private function image(array &$row): void
    {
        $state = &$row['state'];
        $plan = $state['plan']['images'][$state['image_index']] ?? null;
        if (!$plan) { $state['stage'] = 'save_preview'; return; }
        if (!current_user_can('upload_files')) { $state['warnings'][] = __('Images were skipped because upload permission changed.', 'cort-ai-studio'); $state['stage'] = 'save_preview'; return; }
        $quote = Usage::image_estimate($state['rates'], $state['options']['quality']);
        if ($this->over_budget($row, $quote)) { $state['warnings'][] = __('Remaining images were skipped because of the estimated spending limit.', 'cort-ai-studio'); $state['stage'] = 'save_preview'; return; }
        if (function_exists('set_time_limit')) @set_time_limit(150);
        $this->checkpoint($row, 'image_request');
        $response = $this->api->image($this->images->prompt($plan, $state['style'], $state['options']['size']), $state['options']['size'], $state['options']['quality']);
        if (is_wp_error($response)) {
            if (!empty($response->get_error_data()['uncertain'])) { $row['usage']['usage_incomplete'] = 1; $state['reserve'] += $quote; }
            $state['warnings'][] = $response->get_error_message();
        } else {
            $row['usage'] = Usage::add($row['usage'], Usage::from_image($response, $state['rates'], $state['options']['quality']));
            $this->checkpoint($row, 'image_media');
            $attachment = $this->images->save($row['preview_id'], $row['request_id'], $plan['ref'], $plan, $response['data'][0]['b64_json'] ?? null);
            if (is_wp_error($attachment)) $state['warnings'][] = $attachment->get_error_message();
            else $this->record_image($row, $plan, $attachment);
        }
        $state['dispatch'] = '';
        ++$state['image_index'];
    }

    private function recover_image(array &$row): void
    {
        $state = &$row['state'];
        $plan = $state['plan']['images'][$state['image_index']] ?? null;
        if ($plan) {
            $attachment = $this->images->existing($row['preview_id'], $row['request_id'], $plan['ref']);
            if ($attachment) $this->record_image($row, $plan, $attachment);
            else $state['warnings'][] = __('An interrupted image was skipped to avoid a duplicate charge. The page proposal is preserved.', 'cort-ai-studio');
            if ($state['dispatch'] === 'image_request') {
                $row['usage']['usage_incomplete'] = 1;
                $state['reserve'] += Usage::image_estimate($state['rates'], $state['options']['quality']);
            }
            ++$state['image_index'];
        }
        $state['dispatch'] = '';
    }

    private function record_image(array &$row, array $plan, int $id): void
    {
        $row['state']['media'][$plan['ref']] = ['ref' => $plan['ref'], 'title' => $plan['title'], 'alt' => $plan['alt'], 'description' => $plan['prompt'], 'image' => ['id' => $id, 'url' => wp_get_attachment_url($id)]];
    }

    private function save_preview(array &$row): void
    {
        $state = &$row['state'];
        $candidate = $this->blueprint->apply($state['source']['elements'] ?? [], $state['plan'], $state['options'], $state['media'], $this->design_context($state), $state['analysis']['layout']);
        if (is_wp_error($candidate)) { $this->fail($row, $candidate->get_error_message()); return; }
        $saved = $this->adapter->save($row['preview_id'], $candidate['elements']);
        if (is_wp_error($saved)) { $this->fail($row, $saved->get_error_message()); return; }
        $rendered = $this->adapter->render($row['preview_id']);
        if (is_wp_error($rendered)) { $this->fail($row, $rendered->get_error_message()); return; }
        $snapshot = $this->adapter->snapshot($row['preview_id']);
        if (is_wp_error($snapshot)) { $this->fail($row, $snapshot->get_error_message()); return; }
        $state['candidate'] = $candidate['elements'];
        $state['changes'] = $candidate['changes'];
        $state['summary'] = $state['plan']['summary'];
        $state['design_quality'] = $candidate['design_quality'] ?? null;
        foreach ($state['design_quality']['warnings'] ?? [] as $warning) $state['warnings'][] = $warning;
        $state['preview_hash'] = $this->adapter->fingerprint($snapshot);
        $state['preview_content'] = $snapshot['post']['post_content'];
        $state['design_hash'] = Support::hash($this->adapter->design());
        $state['stage'] = 'ready';
        $row['status'] = 'preview';
        unset($state['profile']);
    }

    public function approve(int $id, array $input): array|WP_Error
    {
        $row = $this->allowed($id);
        if (is_wp_error($row)) return $row;
        if ($row['status'] === 'applied') return $this->view($row);
        if ($row['status'] !== 'preview' || ($input['approved'] ?? null) !== true || ($input['preview_hash'] ?? '') !== ($row['state']['preview_hash'] ?? '') || ($row['state']['reviewed_hash'] ?? '') !== ($row['state']['preview_hash'] ?? '')) return Support::error('approval', __('Open the real preview, review it, and explicitly approve this version before applying.', 'cort-ai-studio'), 409);
        $row = $this->repository->claim($id);
        if (!$row) return Support::error('busy', __('This operation is busy. Try again shortly.', 'cort-ai-studio'), 409);
        // Recheck after claiming: another request may have applied this exact version.
        if ($row['status'] !== 'preview') return $this->commit($row);
        try {
            $state = &$row['state'];
            if ($state['adapter_version'] !== $this->adapter->availability()['version'] || $state['design_hash'] !== Support::hash($this->adapter->design())) {
                $this->fail($row, __('The builder version or design system changed. Generate a fresh proposal.', 'cort-ai-studio'));
                return $this->commit($row);
            }
            $preview = $this->adapter->snapshot($row['preview_id']);
            if (is_wp_error($preview) || $this->adapter->fingerprint($preview) !== $state['preview_hash']) {
                $row['status'] = 'conflict'; $state['warnings'][] = __('The preview changed. Generate a new proposal.', 'cort-ai-studio');
                return $this->commit($row);
            }
            if ($row['page_id']) { $state['backup'] = $state['source']; $state['backup_id'] = 'PB-' . $row['id']; }
            $state['approved_by'] = get_current_user_id(); $state['approved_at'] = gmdate('c');
            $row['status'] = 'applying';
            $this->checkpoint($row, 'apply');
            $result = $this->previews->apply($row);
            $state['dispatch'] = '';
            if (is_wp_error($result)) {
                $state['warnings'][] = $result->get_error_message();
                $row['status'] = $result->get_error_code() === 'cort_ai_pb_conflict' ? 'conflict' : 'preview';
            } else {
                $row['page_id'] = $result['page_id']; $state['after_hash'] = $result['after_hash']; $row['status'] = 'applied';
            }
        } catch (Throwable $error) {
            error_log('Cort AI Page Builder apply ' . $id . ': ' . get_class($error));
            $row = $this->repository->find($id) ?? $row;
            $row['state']['paused'] = __('Application was interrupted. Resume to verify the saved document before retrying.', 'cort-ai-studio');
        }
        return $this->commit($row);
    }

    public function restore(int $id, array $input): array|WP_Error
    {
        $row = $this->allowed($id);
        if (is_wp_error($row)) return $row;
        if ($row['status'] === 'restored') return $this->view($row);
        if ($row['status'] !== 'applied' || empty($row['state']['backup']) || ($input['approved'] ?? null) !== true) return Support::error('restore', __('Approve restoration of an applied operation with a backup.', 'cort-ai-studio'), 409);
        $row = $this->repository->claim($id);
        if (!$row) return Support::error('busy', __('This operation is busy.', 'cort-ai-studio'), 409);
        if ($row['status'] !== 'applied') return $this->commit($row);
        try {
            $row['status'] = 'restoring';
            $this->checkpoint($row, 'restore');
            $result = $this->previews->restore($row);
            $row['state']['dispatch'] = '';
            if (is_wp_error($result)) { $row['status'] = 'applied'; $row['state']['warnings'][] = $result->get_error_message(); }
            else { $row['status'] = 'restored'; $row['state']['restored_at'] = gmdate('c'); $row['state']['restored_by'] = get_current_user_id(); }
        } catch (Throwable $error) {
            error_log('Cort AI Page Builder restore ' . $id . ': ' . get_class($error));
            $row = $this->repository->find($id) ?? $row;
            $row['state']['paused'] = __('Restoration was interrupted. Resume to check the document.', 'cort-ai-studio');
        }
        return $this->commit($row);
    }

    private function recover_write(array &$row): void
    {
        $state = &$row['state'];
        $id = $row['page_id'] ?: $row['preview_id'];
        $snapshot = $this->adapter->snapshot($id);
        if (is_wp_error($snapshot)) { $row['status'] = 'conflict'; $state['warnings'][] = $snapshot->get_error_message(); }
        elseif ($state['dispatch'] === 'restore') {
            if (Support::hash($snapshot['elements']) === Support::hash($state['backup']['elements']) && Support::hash($snapshot['meta']) === Support::hash($state['backup']['meta'])) $row['status'] = 'restored';
            elseif ($this->adapter->fingerprint($snapshot) === $state['after_hash']) $row['status'] = 'applied';
            else $row['status'] = 'conflict';
        } elseif ($this->adapter->fingerprint($snapshot) === ($state['source_hash'] ?: $state['preview_hash'])) $row['status'] = 'preview';
        elseif (Support::hash($snapshot['elements']) === Support::hash($state['candidate']) && $this->reconciles($snapshot, $row)) {
            $row['status'] = 'applied'; $row['page_id'] = $id; $state['after_hash'] = $this->adapter->fingerprint($snapshot);
        } else $row['status'] = 'conflict';
        $state['dispatch'] = '';
        $state['warnings'][] = __('An interrupted save was reconciled against the stored document. Review its status before continuing.', 'cort-ai-studio');
    }

    private function reconciles(array $snapshot, array $row): bool
    {
        $state = $row['state'];
        if ($snapshot['post']['post_content'] !== ($state['preview_content'] ?? null)) return false;
        if (!$row['page_id']) {
            return !get_post_meta($row['preview_id'], '_cort_ai_pb_preview', true) &&
                $snapshot['post']['post_title'] === $state['options']['title'] && $snapshot['post']['post_status'] === 'draft';
        }
        if ($this->adapter->metadata_fingerprint($snapshot['meta']) !== $this->adapter->metadata_fingerprint($state['source']['meta'])) return false;
        foreach (['post_title', 'post_name', 'post_status', 'post_excerpt', 'post_password', 'post_parent', 'menu_order'] as $field) {
            if ($snapshot['post'][$field] !== $state['source']['post'][$field]) return false;
        }
        return true;
    }

    public function discard(int $id): array|WP_Error
    {
        $row = $this->allowed($id);
        if (is_wp_error($row)) return $row;
        if (in_array($row['status'], ['applied', 'restored', 'cancelled'], true)) return $this->view($row);
        if (in_array($row['status'], ['applying', 'restoring'], true)) return Support::error('busy', __('Resume verification of the interrupted save first.', 'cort-ai-studio'), 409);
        $row = $this->repository->claim($id);
        if (!$row) return Support::error('busy', __('Wait for the current request before discarding.', 'cort-ai-studio'), 409);
        if (in_array($row['status'], ['applied', 'restored', 'cancelled', 'applying', 'restoring'], true)) return $this->commit($row);
        if (!empty($row['state']['response_id'])) {
            $response = $this->api->cancel($row['state']['response_id']);
            if (is_wp_error($response)) $row['usage']['usage_incomplete'] = 1;
            else $row['usage'] = Usage::add($row['usage'], Usage::from_response($response, $row['model'], $row['state']['rates'], false));
        }
        if (!empty($row['state']['dispatch'])) $row['usage']['usage_incomplete'] = 1;
        $row['status'] = 'cancelled'; $row['state']['dispatch'] = '';
        if ($row['preview_id'] && get_post_meta($row['preview_id'], '_cort_ai_pb_preview', true) && get_post_status($row['preview_id']) === 'draft') wp_trash_post($row['preview_id']);
        return $this->commit($row);
    }

    public function mark_reviewed(int $id, string $hash): bool
    {
        $row = $this->allowed($id);
        if (is_wp_error($row) || $row['status'] !== 'preview' || ($row['state']['preview_hash'] ?? '') !== $hash) return false;
        $row = $this->repository->claim($id);
        if (!$row) return false;
        if ($row['status'] !== 'preview') { $this->repository->store($row); return false; }
        $snapshot = $this->adapter->snapshot($row['preview_id']);
        if (is_wp_error($snapshot) || $this->adapter->fingerprint($snapshot) !== $hash) { $this->repository->store($row); return false; }
        $row['state']['reviewed_hash'] = $hash;
        return $this->repository->store($row);
    }

    public function get(int $id): array|WP_Error
    {
        $row = $this->allowed($id);
        return is_wp_error($row) ? $row : $this->view($row);
    }

    public function history(): array
    {
        return array_map([$this, 'view'], $this->repository->history(current_user_can('manage_options') ? null : get_current_user_id()));
    }

    public function current(): ?array
    {
        $row = $this->repository->active(get_current_user_id());
        return $row ? $this->view($row) : null;
    }

    private function allowed(int $id): array|WP_Error
    {
        $row = $this->repository->find($id);
        if (!$row || ($row['user_id'] !== get_current_user_id() && !current_user_can('manage_options')) || ($row['page_id'] && !current_user_can('edit_post', $row['page_id']))) return Support::error('access', __('This page operation is unavailable to your account.', 'cort-ai-studio'), 404);
        return $row;
    }

    private function view(array $row): array
    {
        $state = $row['state'];
        $preview = $row['preview_id'] && current_user_can('edit_post', $row['preview_id']) && get_post_status($row['preview_id']) === 'draft';
        $url = $preview ? add_query_arg(['cort_ai_pb_operation' => $row['id'], 'cort_ai_pb_nonce' => wp_create_nonce('cort_ai_pb_preview_' . $row['id'])], get_preview_post_link($row['preview_id'])) : '';
        return [
            'id' => $row['id'], 'page_id' => $row['page_id'], 'preview_id' => $row['preview_id'], 'title' => $state['options']['title'] ?? '',
            'operation' => $row['operation'], 'status' => $row['status'], 'stage' => $state['stage'] ?? '', 'created_at' => $row['created_at'],
            'stage_label' => ($state['stage'] ?? '') === 'design_submit' || ($state['response_kind'] ?? '') === 'design' ? __('Planning visual direction…', 'cort-ai-studio') : (($state['stage'] ?? '') === 'submit' || ($state['stage'] ?? '') === 'poll' ? __('Composing native page content…', 'cort-ai-studio') : ''),
            'model' => $row['model'], 'usage' => $row['usage'], 'warnings' => array_values(array_unique($state['warnings'] ?? [])),
            'design_summary' => $state['design_summary'] ?? null, 'design_quality' => $state['design_quality'] ?? null,
            'document_layout' => $state['options']['document_layout'] ?? 'preserve',
            'pause' => $state['paused'] ?? '', 'summary' => $state['summary'] ?? '', 'changes' => $state['changes'] ?? [],
            'preview_hash' => $state['preview_hash'] ?? '', 'preview_url' => $url,
            'reviewed' => !empty($state['reviewed_hash']) && ($state['reviewed_hash'] === ($state['preview_hash'] ?? '')),
            'backup_id' => $state['backup_id'] ?? '', 'can_restore' => $row['status'] === 'applied' && !empty($state['backup']),
            'edit_url' => $row['page_id'] && current_user_can('edit_post', $row['page_id']) ? get_edit_post_link($row['page_id'], 'raw') : '',
            'elementor_url' => $row['page_id'] && current_user_can('edit_post', $row['page_id']) ? admin_url('post.php?post=' . $row['page_id'] . '&action=elementor') : '',
            'retry_ms' => max(800, min(10000, (($state['retry_at'] ?? time()) - time()) * 1000), $row['lease_until'] > time() ? 2500 : 0),
        ];
    }

    private function checkpoint(array &$row, string $dispatch): void
    {
        $row['state']['dispatch'] = $dispatch;
        if (!$this->repository->store($row, false)) throw new \RuntimeException('checkpoint_failed');
    }

    private function commit(array $row): array|WP_Error
    {
        if (in_array($row['status'], ['applied', 'restored', 'failed', 'cancelled'], true)) {
            unset($row['state']['source'], $row['state']['analysis'], $row['state']['plan'], $row['state']['profile'], $row['state']['media'], $row['state']['candidate'], $row['state']['preview_content']);
            unset($row['state']['options']['instructions']);
        }
        if (!$this->repository->store($row)) return $this->storage_error();
        $row['lease_until'] = 0;
        return $this->view($row);
    }

    private function fail(array &$row, string $message): void
    {
        $row['status'] = 'failed'; $row['state']['dispatch'] = ''; $row['state']['warnings'][] = $message;
    }

    private function over_budget(array $row, float $quote): bool
    {
        return $row['state']['budget'] > 0 && $row['usage']['estimated_usd'] + $row['state']['reserve'] + $quote > $row['state']['budget'] + 0.000001;
    }

    private function design_context(array $state): array
    {
        return array_replace($state['analysis']['design'], isset($state['design_plan']) ? ['planning' => $state['design_plan']] : []);
    }

    private function storage_error(): WP_Error
    {
        return Support::error('storage', __('Could not persist the operation. Reload to recover its recorded state.', 'cort-ai-studio'), 503);
    }

    private function options(array $input, array $settings): array|WP_Error
    {
        $request = $input['request_id'] ?? '';
        $op = $input['operation'] ?? '';
        $page = filter_var($input['page_id'] ?? 0, FILTER_VALIDATE_INT);
        $images = filter_var($input['image_count'] ?? 0, FILTER_VALIDATE_INT);
        if (!is_string($request) || !wp_is_uuid($request, 4) || !in_array($op, self::OPERATIONS, true) || $page === false || $page < 0 || (($op === 'create') !== ($page === 0)) || $images === false || $images < 0 || $images > 2) return Support::error('input', __('Check the page, operation, and image options.', 'cort-ai-studio'));
        $model = Text::choice($input['model'] ?? $settings['model'], Settings::models(), '');
        $effort = Text::choice($input['effort'] ?? $settings['effort'], Settings::efforts($model), '');
        $instructions = Text::field($input['instructions'] ?? '', 7000, true);
        $title = Text::field($input['title'] ?? '', 180);
        if ($model === '' || $effort === '' || (!in_array($op, ['remove', 'reorder'], true) && $instructions === '') || ($op === 'create' && $title === '')) return Support::error('input', __('Provide instructions, a title for new pages, and a supported AI configuration.', 'cort-ai-studio'));
        if ($images && !current_user_can('upload_files')) return Support::error('upload', __('Media upload permission is required for image generation.', 'cort-ai-studio'), 403);
        if (!is_array($input['media_ids'] ?? []) || count($input['media_ids'] ?? []) > 20) return Support::error('media', __('Select at most 20 media files.', 'cort-ai-studio'));
        foreach (['reuse_media', 'allow_image_reuse'] as $flag) if (isset($input[$flag]) && !is_bool($input[$flag])) return Support::error('media_options', __('Check the media selection options.', 'cort-ai-studio'));
        foreach ($input['media_ids'] ?? [] as $media_id) {
            if (filter_var($media_id, FILTER_VALIDATE_INT) === false || (int) $media_id < 1) return Support::error('media', __('Select valid Media Library image IDs.', 'cort-ai-studio'));
        }
        if (in_array($op, ['remove', 'reorder'], true)) $images = 0;
        return [
            'request_id' => strtolower($request), 'operation' => $op, 'page_id' => $page, 'model' => $model, 'effort' => $effort,
            'instructions' => $instructions, 'title' => $title, 'slug' => sanitize_title(Text::field($input['slug'] ?? '', 180) ?: $title),
            'section_id' => Text::field($input['section_id'] ?? '', 40), 'anchor_id' => Text::field($input['anchor_id'] ?? '', 40),
            'position' => Text::choice($input['position'] ?? 'end', array_fill_keys(['start', 'end', 'before', 'after'], ''), 'end'),
            'media_ids' => array_values(array_unique(array_map('absint', $input['media_ids'] ?? []))),
            'image_count' => $images, 'quality' => Text::choice($input['quality'] ?? $settings['quality'], Settings::qualities(), $settings['quality']),
            'document_layout' => $op === 'create' ? Text::choice($input['document_layout'] ?? 'auto', array_fill_keys(['auto', 'default', 'elementor_header_footer', 'elementor_canvas'], ''), 'default') : 'preserve',
            'reuse_media' => $input['reuse_media'] ?? true, 'allow_image_reuse' => $input['allow_image_reuse'] ?? false,
            'size' => Text::choice($input['size'] ?? $settings['size'], Settings::sizes(), $settings['size']),
        ];
    }

    private function media(array $selected, array $elements, array $options, array $subjects = []): array|WP_Error
    {
        $ids = $selected;
        $readMedia = function (array $values) use (&$readMedia, &$ids): void {
            if (isset($values['id'], $values['url']) && is_numeric($values['id']) && is_string($values['url'])) $ids[] = (int) $values['id'];
            foreach ($values as $value) if (is_array($value)) $readMedia($value);
        };
        $walk = function (array $nodes) use (&$walk, &$ids, $readMedia): void {
            foreach ($nodes as $node) {
                foreach (['image', 'background_image'] as $field) if (!empty($node['settings'][$field]['id'])) $ids[] = (int) $node['settings'][$field]['id'];
                $readMedia($node['settings'] ?? []);
                $walk($node['elements'] ?? []);
            }
        };
        $walk($elements);
        $explicit = array_unique($ids);
        if ($options['reuse_media']) {
            $tokenize = static function (string $text): array {
                $terms = preg_split('/[^\pL\pN]+/u', strtolower(remove_accents($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                return array_values(array_filter(array_unique($terms), static fn(string $s): bool => strlen($s) > 3 && !in_array($s, ['with', 'create', 'page', 'section', 'about', 'using', 'image', 'photo', 'photograph', 'para', 'crear', 'pagina', 'seccion', 'imagen', 'foto'], true)));
            };
            $terms = []; $groups = array_map($tokenize, $subjects);
            // One useful term from every subject before considering a second term from any subject.
            foreach ([0, 1] as $position) foreach ($groups as $group) if (isset($group[$position])) $terms[] = $group[$position];
            $terms = array_slice(array_values(array_unique(array_merge($terms, $tokenize($options['title'] . ' ' . $options['instructions'])))), 0, $subjects ? 8 : 4);
            foreach ($terms as $term) $ids = array_merge($ids, get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image', 's' => $term, 'posts_per_page' => 6, 'fields' => 'ids']));
        }
        $media = [];
        foreach (array_slice(array_unique($ids), 0, 100) as $id) {
            if (!wp_attachment_is_image($id) || !current_user_can('read_post', $id) || !in_array(get_post_mime_type($id), ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'], true)) {
                if (in_array($id, $selected, true)) return Support::error('media', __('A selected media file is unavailable or is not a supported image.', 'cort-ai-studio'));
                continue;
            }
            $info = wp_get_attachment_metadata($id);
            $media['media_' . $id] = ['ref' => 'media_' . $id, 'title' => Text::plain(get_the_title($id), 120), 'alt' => Text::plain((string) get_post_meta($id, '_wp_attachment_image_alt', true), 180), 'description' => Text::plain((string) get_post_field('post_content', $id), 200), 'width' => (int) ($info['width'] ?? 0), 'height' => (int) ($info['height'] ?? 0), 'automatic' => !in_array($id, $explicit, true), 'image' => ['id' => $id, 'url' => wp_get_attachment_url($id)]];
        }
        return $media;
    }
}
