<?php
namespace CortAIStudio\PageBuilder;

use Throwable;
use WP_Error;

defined('ABSPATH') || exit;

final class PreviewBackup
{
    public function __construct(private Adapter $adapter) {}

    public function create(array $row): int|WP_Error
    {
        return $this->adapter->create_preview($row);
    }

    /** Transaction holds document rows while comparing and saving through the adapter. */
    public function apply(array $row): array|WP_Error
    {
        $id = $row['page_id'];
        $state = $row['state'];
        if (!$id) return $this->accept_new($row);
        return $this->transaction($id, function () use ($row, $state, $id): array|WP_Error {
            $current = $this->adapter->snapshot($id);
            if (is_wp_error($current)) return $current;
            if ($this->adapter->fingerprint($current) !== $state['source_hash']) return $this->conflict();
            $saved = $this->adapter->save($id, $state['candidate']);
            if (is_wp_error($saved)) return $saved;
            // Settings, media references and template metadata outside the element tree must survive.
            $after = $this->adapter->snapshot($id);
            if (is_wp_error($after) || $this->adapter->metadata_fingerprint($current['meta']) !== $this->adapter->metadata_fingerprint($after['meta'])) {
                return Support::error('metadata', __('The builder changed document metadata outside the approved scope. The save was rolled back.', 'cort-ai-studio'));
            }
            foreach (['post_title', 'post_name', 'post_status', 'post_excerpt', 'post_password', 'post_parent', 'menu_order'] as $field) {
                if ($current['post'][$field] !== $after['post'][$field]) return Support::error('scope', __('The page save changed a field outside the approved scope and was rolled back.', 'cort-ai-studio'));
            }
            return ['page_id' => $id, 'after_hash' => $this->adapter->fingerprint($after)];
        });
    }

    private function accept_new(array $row): array|WP_Error
    {
        $id = $row['preview_id'];
        $options = $row['state']['options'];
        return $this->transaction($id, function () use ($row, $id, $options): array|WP_Error {
            $current = $this->adapter->snapshot($id);
            if (is_wp_error($current) || $this->adapter->fingerprint($current) !== $row['state']['preview_hash']) return $this->conflict();
            $result = wp_update_post(wp_slash(['ID' => $id, 'post_title' => $options['title'], 'post_name' => $options['slug'], 'post_status' => 'draft']), true);
            if (is_wp_error($result)) return $result;
            delete_post_meta($id, '_cort_ai_pb_preview');
            $after = $this->adapter->snapshot($id);
            if (!is_wp_error($after) && ($after['post']['post_status'] !== 'draft' || $after['post']['post_title'] !== $options['title'])) return Support::error('draft', __('The new page did not remain a correctly saved draft. Application was rolled back.', 'cort-ai-studio'));
            return is_wp_error($after) ? $after : ['page_id' => $id, 'after_hash' => $this->adapter->fingerprint($after)];
        });
    }

    public function restore(array $row): array|WP_Error
    {
        if (empty($row['state']['backup'])) return Support::error('backup', __('There is no original page backup for this operation.', 'cort-ai-studio'));
        $id = $row['page_id'];
        return $this->transaction($id, function () use ($row, $id): array|WP_Error {
            $current = $this->adapter->snapshot($id);
            if (is_wp_error($current) || $this->adapter->fingerprint($current) !== $row['state']['after_hash']) return $this->conflict();
            $saved = $this->adapter->restore_snapshot($id, $row['state']['backup']);
            if (is_wp_error($saved)) return $saved;
            return ['page_id' => $id];
        });
    }

    private function transaction(int $id, callable $callback): array|WP_Error
    {
        global $wpdb;
        foreach ([$wpdb->posts, $wpdb->postmeta] as $table) {
            $info = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table)), ARRAY_A);
            if (strtolower($info['Engine'] ?? '') !== 'innodb') return Support::error('engine', __('Safe application requires InnoDB for WordPress posts and metadata. The preview remains available.', 'cort-ai-studio'));
        }
        if ($wpdb->query('START TRANSACTION') === false) return Support::error('transaction', __('Could not lock the document for a safe save.', 'cort-ai-studio'));
        try {
            $locked = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", $id));
            $wpdb->get_col($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d FOR UPDATE", $id));
            clean_post_cache($id);
            if (!$locked) throw new \RuntimeException('missing_document');
            $unlocked = $this->adapter->unlocked($id);
            $result = is_wp_error($unlocked) ? $unlocked : $callback();
            if (is_wp_error($result)) {
                $wpdb->query('ROLLBACK');
            } elseif ($wpdb->query('COMMIT') === false) {
                $wpdb->query('ROLLBACK');
                $result = Support::error('commit', __('The database could not confirm the page save.', 'cort-ai-studio'));
            }
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            error_log('Cort AI Page Builder: document transaction failed (' . get_class($error) . ').');
            $result = Support::error('save', __('The document save was interrupted and rolled back.', 'cort-ai-studio'));
        }
        clean_post_cache($id);
        $this->adapter->invalidate($id);
        return $result;
    }

    private function conflict(): WP_Error
    {
        return Support::error('conflict', __('The page or preview changed after analysis. Generate a new proposal; no content was overwritten.', 'cort-ai-studio'), 409);
    }
}
