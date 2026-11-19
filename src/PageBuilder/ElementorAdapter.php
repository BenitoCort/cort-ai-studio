<?php
namespace CortAIStudio\PageBuilder;

use CortAIStudio\Text;
use WP_Error;

defined('ABSPATH') || exit;

final class ElementorAdapter implements Adapter
{
    public const MAX_BYTES = 900000;
    public const MAX_NODES = 650;
    public const WIDGETS = ['heading', 'text-editor', 'button', 'image', 'icon'];
    public const CACHE_KEYS = ['_elementor_css', '_elementor_element_cache', '_elementor_page_assets', '_elementor_controls_usage'];
    // Elementor records the current editor when _elementor_data is written.
    private const LAST_EDITOR_META = '_elementor_data_last_editor';

    public function availability(): array
    {
        $version = defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '';
        $runtime = $version !== '' && class_exists('Elementor\\Plugin') ? (\Elementor\Plugin::$instance ?? null) : null;
        $available = isset($runtime->documents, $runtime->elements_manager, $runtime->widgets_manager, $runtime->frontend)
            && is_callable([$runtime->documents, 'get']) && is_callable([$runtime->documents, 'create'])
            && is_callable([$runtime->elements_manager, 'get_element_types']) && is_callable([$runtime->widgets_manager, 'get_widget_types'])
            && is_callable([$runtime->frontend, 'get_builder_content_for_display']);
        $reviewed = $available && version_compare($version, '3.21', '>=') && version_compare($version, '4.5', '<');
        $classic = $reviewed && ElementorCapabilities::is_classic($runtime->elements_manager->get_element_types('section')) && ElementorCapabilities::is_classic($runtime->elements_manager->get_element_types('column'));
        $containers = $reviewed && ElementorCapabilities::is_classic($runtime->elements_manager->get_element_types('container'));
        $supported = $reviewed && ($classic || $containers);
        $catalog = $supported ? (new ElementorCapabilities())->catalog() : [];
        return [
            'available' => $available, 'supported' => $supported, 'version' => $version,
            'providers' => ElementorCapabilities::providers(), 'widget_catalog' => $catalog,
            'classic' => $classic, 'layout' => $containers ? 'flexbox' : 'classic', 'containers' => $containers, 'atomic' => false,
            'unsupported_features' => ['Atomic V4 generation/editing and automatic conversion.', 'Unmapped classic widgets: retained when possible, not generated automatically.', 'Form delivery actions/integrations: configure in Elementor.', 'Advanced scrolling/parallax effects and arbitrary code.'],
            'message' => !$available ? ($version
                ? __('The Elementor document, widget, or frontend runtime has not initialized. Check Elementor and reload this page.', 'cort-ai-studio')
                : __('Activate Elementor to use AI Page Builder.', 'cort-ai-studio')) : (!$supported
                ? ($reviewed ? __('No compatible classic/Flexbox element runtime was found.', 'cort-ai-studio') : __('This Elementor version is outside the reviewed range (3.21–4.4). Page writes are disabled.', 'cort-ai-studio'))
                : __('Classic sections and Flexbox are supported. Atomic V4 documents are read-only in this release.', 'cort-ai-studio')),
        ];
    }

    public function inspect(int $id): array|WP_Error
    {
        $snapshot = $this->snapshot($id);
        if (is_wp_error($snapshot)) return $snapshot;
        $elements = $snapshot['elements'];
        $ids = [];
        $issues = [];
        $tree = $this->outline($elements, $ids, $issues);
        $valid = $this->validate($elements);
        if (is_wp_error($valid)) $issues[] = $valid->get_error_message();
        $doc = $this->document($id);
        if (!$doc || !$doc->is_built_with_elementor()) $issues[] = __('This page is not an Elementor document. Create a new page instead of converting existing content.', 'cort-ai-studio');
        if (get_post_meta($id, '_cort_ai_pb_preview', true)) $issues[] = __('Temporary proposals cannot be used as source pages.', 'cort-ai-studio');
        if (($elements[0]['elType'] ?? '') === 'container' && !$this->availability()['containers']) $issues[] = __('Enable Flexbox Containers in Elementor before editing this document.', 'cort-ai-studio');
        $type = (string) get_post_meta($id, '_elementor_template_type', true);
        if ($type !== '' && !in_array($type, ['wp-page', 'page'], true)) $issues[] = __('Theme Builder and template documents cannot be edited here.', 'cort-ai-studio');
        if ($snapshot['post']['post_password'] !== '') $issues[] = __('Password-protected pages are not supported.', 'cort-ai-studio');
        return [
            'id' => $id, 'title' => $snapshot['post']['post_title'], 'status' => $snapshot['post']['post_status'],
            'fingerprint' => $this->fingerprint($snapshot), 'tree' => $tree,
            'layout' => ($elements[0]['elType'] ?? 'container') === 'section' ? 'classic' : 'flexbox',
            'editable' => !$issues && $this->availability()['supported'], 'issues' => array_values(array_unique($issues)),
            'design' => $this->design(),
            'document_layout' => (string) get_post_meta($id, '_wp_page_template', true) ?: 'default',
        ];
    }

    public function snapshot(int $id): array|WP_Error
    {
        $post = get_post($id);
        if (!$post || $post->post_type !== 'page' || !in_array($post->post_status, ['draft', 'publish', 'private', 'pending', 'future'], true) || !current_user_can('edit_post', $id)) {
            return self::error('page', __('Select a page you are allowed to edit.', 'cort-ai-studio'), 403);
        }
        $doc = $this->document($id);
        if (!$doc) return self::error('elementor', __('Elementor could not load this document.', 'cort-ai-studio'));
        $raw = get_post_meta($id, '_elementor_data', true);
        if (is_string($raw) && strlen($raw) > self::MAX_BYTES) return self::error('size', __('This page is too large for this release. Edit it in Elementor.', 'cort-ai-studio'));
        $elements = $doc->get_elements_data();
        if (!is_array($elements) || (is_string($raw) && $raw !== '' && !is_array(json_decode($raw, true, 64)))) {
            return self::error('structure', __('The Elementor data is unreadable. Nothing was changed.', 'cort-ai-studio'));
        }
        $meta = [];
        foreach (get_post_meta($id) as $key => $values) {
            if (self::protected_key($key) && !in_array($key, self::CACHE_KEYS, true)) {
                $meta[$key] = array_map('maybe_unserialize', $values);
            }
        }
        ksort($meta);
        $fields = ['post_title', 'post_name', 'post_status', 'post_content', 'post_excerpt', 'post_password', 'post_parent', 'menu_order', 'post_modified_gmt'];
        $snapshot = ['elements' => $elements, 'meta' => $meta, 'post' => array_intersect_key(get_object_vars($post), array_flip($fields))];
        return strlen((string) wp_json_encode($snapshot)) <= self::MAX_BYTES * 3 ? $snapshot : self::error('size', __('The page backup exceeds the supported size.', 'cort-ai-studio'));
    }

    public static function protected_key(string $key): bool
    {
        return str_starts_with($key, '_elementor_') || in_array($key, ['_wp_page_template', '_thumbnail_id'], true);
    }

    public function fingerprint(array $snapshot): string
    {
        return self::hash($snapshot);
    }

    public static function hash(mixed $value): string
    {
        return Support::hash($value);
    }

    public function validate(array $elements): bool|WP_Error
    {
        $seen = [];
        $count = 0;
        $walk = function (array $nodes, string $parent = '', int $depth = 0) use (&$walk, &$seen, &$count): bool {
            if (!array_is_list($nodes) || $depth > 18) return false;
            foreach ($nodes as $node) {
                if (!is_array($node) || !is_string($node['id'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]{1,40}$/D', $node['id']) || isset($seen[$node['id']]) || ++$count > self::MAX_NODES) return false;
                $seen[$node['id']] = true;
                $type = $node['elType'] ?? '';
                if (!in_array($type, ['container', 'section', 'column', 'widget'], true) || array_intersect(['styles', 'version', 'props', 'interactions'], array_keys($node))) return false;
                if ($parent === '' && !in_array($type, ['section', 'container'], true)) return false;
                if ($type === 'column' && $parent !== 'section') return false;
                if (!is_array($node['settings'] ?? null) || !is_array($node['elements'] ?? null)) return false;
                if (self::atomic_values($node['settings'])) return false;
                if ($type === 'widget') {
                    $widget = $node['widgetType'] ?? '';
                    if (!is_string($widget) || str_starts_with($widget, 'e-') || !ElementorCapabilities::is_classic(\Elementor\Plugin::$instance->widgets_manager->get_widget_types($widget))) return false;
                }
                if (!$walk($node['elements'], $type, $depth + 1)) return false;
            }
            return true;
        };
        return $walk($elements) ? true : self::error('unsupported', __('Atomic, missing widgets, duplicate IDs, or unsupported hierarchy detected. Editing is disabled to protect this page.', 'cort-ai-studio'));
    }

    private static function atomic_values(array $values, int $depth = 0): bool
    {
        if ($depth > 32 || array_key_exists('$$type', $values)) return true;
        foreach ($values as $value) if (is_array($value) && self::atomic_values($value, $depth + 1)) return true;
        return false;
    }

    public function save(int $id, array $elements): bool|WP_Error
    {
        if (!$this->availability()['supported']) return self::error('version', $this->availability()['message']);
        $valid = $this->validate($elements);
        if (is_wp_error($valid)) return $valid;
        $doc = $this->document($id);
        if (!$doc || !current_user_can('edit_post', $id)) return self::error('permission', __('You cannot save this Elementor document.', 'cort-ai-studio'), 403);
        $doc->set_is_built_with_elementor(true);
        if (!$doc->save(['elements' => $elements])) return self::error('save', __('Elementor refused to save this document.', 'cort-ai-studio'));
        clean_post_cache($id);
        $saved = json_decode((string) get_post_meta($id, '_elementor_data', true), true, 64);
        if (self::hash($saved) !== self::hash($elements)) return self::error('roundtrip', __('Elementor changed or discarded settings during save. The proposal was not accepted.', 'cort-ai-studio'));
        return true;
    }

    public function render(int $id): string|WP_Error
    {
        $html = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display($id, true);
        return is_string($html) && str_contains($html, 'data-elementor-id="' . $id . '"') ? $html : self::error('render', __('Elementor did not render the proposal. Applying changes is disabled.', 'cort-ai-studio'));
    }

    public function unlocked(int $id): bool|WP_Error
    {
        $lock = explode(':', (string) get_post_meta($id, '_edit_lock', true));
        if ((int) ($lock[0] ?? 0) > time() - (int) apply_filters('wp_check_post_lock_window', 150)) {
            return self::error('locked', __('Close the WordPress/Elementor editor for this page and wait for its editing lock to expire.', 'cort-ai-studio'), 409);
        }
        global $wpdb;
        $post = get_post($id);
        $autosave = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'revision' AND post_name LIKE %s AND post_modified_gmt > %s LIMIT 1", $id, '%-autosave-%', $post->post_modified_gmt));
        return !$autosave ? true : self::error('autosave', __('This page has a newer autosave. Resolve it in Elementor before generating changes.', 'cort-ai-studio'), 409);
    }

    public function invalidate(int $id): void
    {
        clean_post_cache($id);
        // Document::save() clears its own cache. Restoring a snapshot also invalidates
        // the known generated cache metadata; Document::delete_cache() is protected.
        delete_post_meta($id, '_elementor_element_cache');
        if (class_exists('Elementor\\Core\\Files\\CSS\\Post')) \Elementor\Core\Files\CSS\Post::create($id)->delete();
    }

    public function design(): array
    {
        // Read the active kit ID directly: never create or change a global kit as a side effect.
        $kit = (int) get_option('elementor_active_kit');
        $settings = $kit ? get_post_meta($kit, '_elementor_page_settings', true) : [];
        $settings = is_array($settings) ? $settings : [];
        $result = ['colors' => [], 'fonts' => []];
        foreach (['system_colors', 'custom_colors'] as $group) {
            foreach (array_slice($settings[$group] ?? [], 0, 25) as $color) {
                if (is_array($color) && preg_match('/^[a-zA-Z0-9_-]+$/D', $color['_id'] ?? '')) {
                    $result['colors'][$color['_id']] = ['title' => Text::plain($color['title'] ?? '', 60), 'color' => Text::field($color['color'] ?? '', 40)];
                }
            }
        }
        foreach (['system_typography', 'custom_typography'] as $group) {
            foreach (array_slice($settings[$group] ?? [], 0, 20) as $font) {
                if (is_array($font) && preg_match('/^[a-zA-Z0-9_-]+$/D', $font['_id'] ?? '')) {
                    $result['fonts'][$font['_id']] = ['title' => Text::plain($font['title'] ?? '', 60), 'family' => Text::field($font['typography_font_family'] ?? '', 100), 'weight' => Text::field($font['typography_font_weight'] ?? '', 10)];
                }
            }
        }
        $result['capabilities'] = $this->availability()['supported'] ? (new ElementorCapabilities())->summary() : [];
        return $result;
    }

    private function outline(array $nodes, array &$ids, array &$issues, int $depth = 0): array
    {
        if ($depth > 18 || count($ids) > self::MAX_NODES) return [];
        $tree = [];
        foreach (array_slice($nodes, 0, self::MAX_NODES) as $node) {
            if (count($ids) >= self::MAX_NODES) break;
            if (!is_array($node)) continue;
            $id = (string) ($node['id'] ?? '');
            $ids[$id] = true;
            $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
            $kind = $node['widgetType'] ?? $node['elType'] ?? '';
            $text = $settings['title'] ?? $settings['editor'] ?? $settings['text'] ?? '';
            $children = $this->outline(is_array($node['elements'] ?? null) ? $node['elements'] : [], $ids, $issues, $depth + 1);
            $label = Text::plain($settings['_title'] ?? '', 100) ?: Text::plain($text, 100) ?: $this->first_heading($children) ?: (string) $kind;
            $safe = [];
            foreach ($settings as $key => $value) {
                if (preg_match('/(?:color|background|typography|padding|margin|gap|width|align|direction|animation|css_classes|css_id|_element_id|__globals__|image|link)/', $key) && !str_contains($key, 'custom_css')) $safe[$key] = $value;
            }
            $tree[] = ['id' => $id, 'type' => $kind, 'label' => $label, 'text' => Text::plain($text, 1600),
                'editable_content' => in_array($kind, ['heading', 'text-editor', 'button', 'image'], true) && empty($settings['__dynamic__']),
                'settings' => strlen((string) wp_json_encode($safe)) < 5000 ? $safe : [],
                'children' => $children];
        }
        return $tree;
    }

    private function first_heading(array $nodes): string
    {
        foreach ($nodes as $node) {
            if ($node['type'] === 'heading' && $node['text'] !== '') return Text::cut($node['text'], 100);
            $heading = $this->first_heading($node['children']);
            if ($heading !== '') return $heading;
        }
        return '';
    }

    public function create_preview(array $row): int|WP_Error
    {
        global $wpdb;
        if (!$this->availability()['supported']) return self::error('version', $this->availability()['message']);
        $guid = home_url('/?cort_ai_pb_preview=' . $row['request_id']);
        $existing = (int) $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE guid = %s AND post_type = 'page' LIMIT 1", $guid));
        if ($existing) return current_user_can('edit_post', $existing) && get_post_meta($existing, '_cort_ai_pb_preview', true) ? $existing : self::error('preview', __('The saved preview is unavailable.', 'cort-ai-studio'));
        $settings = $row['state']['options'];
        $document = \Elementor\Plugin::$instance->documents->create('wp-page', wp_slash([
            'post_type' => 'page', 'post_status' => 'draft', 'post_author' => $row['user_id'],
            'post_title' => $settings['title'] ?: __('AI Page Builder preview', 'cort-ai-studio'), 'guid' => $guid,
        ]), ['_cort_ai_pb_preview' => $row['id']]);
        if (is_wp_error($document) || !$document) return self::error('preview', __('Could not create the preview draft.', 'cort-ai-studio'));
        $id = (int) $document->get_post()->ID;
        foreach ($row['state']['source']['meta'] ?? [] as $key => $values) {
            if (in_array($key, ['_elementor_data', self::LAST_EDITOR_META], true) || in_array($key, self::CACHE_KEYS, true)) continue;
            delete_post_meta($id, $key);
            foreach ($values as $value) add_post_meta($id, $key, wp_slash($value));
        }
        if (!$row['page_id']) {
            $template = $settings['document_layout'] ?? 'default';
            if ($template === 'auto') $template = 'default';
            if (!in_array($template, ['default', 'elementor_header_footer', 'elementor_canvas'], true)) return self::error('template', __('The new page layout is unsupported.', 'cort-ai-studio'));
            $document = $this->document($id);
            if (!$document || !$document->save(['settings' => ['template' => $template, 'hide_title' => $template === 'elementor_canvas' ? 'yes' : '']])) return self::error('template', __('Elementor could not save the new page layout.', 'cort-ai-studio'));
            if ((string) get_post_meta($id, '_wp_page_template', true) !== $template) return self::error('template', __('The page layout did not persist. The proposal was not accepted.', 'cort-ai-studio'));
        }
        return $id;
    }

    public function restore_snapshot(int $id, array $backup): bool|WP_Error
    {
        $saved = $this->save($id, $backup['elements']);
        if (is_wp_error($saved)) return $saved;
        // Exact server-owned snapshot only; no client/AI metadata is accepted.
        foreach (get_post_meta($id) as $key => $values) {
            if (self::protected_key($key) && $key !== self::LAST_EDITOR_META) delete_post_meta($id, $key);
        }
        foreach ($backup['meta'] as $key => $values) {
            if (!self::protected_key($key) || $key === self::LAST_EDITOR_META || in_array($key, self::CACHE_KEYS, true)) continue;
            foreach ($values as $value) add_post_meta($id, $key, wp_slash($value));
        }
        $fields = array_intersect_key($backup['post'], array_flip(['post_content', 'post_excerpt']));
        $result = wp_update_post(wp_slash(['ID' => $id] + $fields), true);
        if (is_wp_error($result)) return $result;
        $this->invalidate($id);
        $after = $this->snapshot($id);
        if (is_wp_error($after) || !$this->matches_backup($after, $backup)) return self::error('restore', __('The restored page did not match its backup. Restoration was rolled back.', 'cort-ai-studio'));
        return true;
    }

    public function matches_backup(array $snapshot, array $backup): bool
    {
        unset($snapshot['meta'][self::LAST_EDITOR_META], $backup['meta'][self::LAST_EDITOR_META]);
        unset($snapshot['post']['post_modified_gmt'], $backup['post']['post_modified_gmt']);
        return self::hash($snapshot) === self::hash($backup);
    }

    public function metadata_fingerprint(array $meta): string
    {
        unset($meta['_elementor_data'], $meta['_elementor_version'], $meta['_elementor_pro_version'], $meta[self::LAST_EDITOR_META]);
        return self::hash($meta);
    }

    private function document(int $id): mixed
    {
        return $this->availability()['available'] ? \Elementor\Plugin::$instance->documents->get($id, false) : null;
    }

    public static function error(string $code, string $message, int $status = 400): WP_Error
    {
        return Support::error($code, $message, $status);
    }
}
