<?php
namespace CortAIStudio\PageBuilder;

defined('ABSPATH') || exit;

/** Probe actual controls by ID: recent Elementor versions keep style controls separately. */
final class ElementorCapabilities
{
    public const CORE_WIDGETS = ['heading', 'text-editor', 'button', 'image', 'icon', 'icon-box', 'image-box', 'icon-list', 'accordion', 'toggle', 'tabs', 'counter', 'image-carousel', 'image-gallery'];
    private array $types = [];
    private array $statuses = [];

    public static function is_classic(mixed $object): bool
    {
        return is_object($object) && !method_exists($object, 'get_props_schema')
            && !is_a($object, 'Elementor\\Modules\\AtomicWidgets\\Elements\\Base\\Atomic_Widget_Base')
            && !is_a($object, 'Elementor\\Modules\\AtomicWidgets\\Elements\\Base\\Atomic_Element_Base');
    }

    public function type(string $name): mixed
    {
        if (!array_key_exists($name, $this->types)) {
            $this->types[$name] = in_array($name, ['container', 'section', 'column'], true)
                ? \Elementor\Plugin::$instance->elements_manager->get_element_types($name)
                : \Elementor\Plugin::$instance->widgets_manager->get_widget_types($name);
        }
        return $this->types[$name];
    }

    public function control(string $type, string $key): ?array
    {
        $object = $this->type($type);
        $control = self::is_classic($object) && method_exists($object, 'get_controls') ? $object->get_controls($key) : null;
        return is_array($control) ? $control : null;
    }

    public function set(array &$settings, string $type, string $key, mixed $value, bool $required = false): void
    {
        $control = $this->control($type, $key);
        // Responsive clones may be generated only in the editor; the base control owns them.
        if (!$control && preg_match('/_(tablet|mobile)$/D', $key)) {
            $base = preg_replace('/_(tablet|mobile)$/D', '', $key);
            $candidate = $this->control($type, $base);
            if (!empty($candidate['responsive']) || !empty($candidate['is_responsive'])) $control = $candidate;
        }
        if (!$control) {
            if ($required) throw new \DomainException('Unsupported native control ' . $type . ':' . $key);
            return;
        }
        if (is_string($value) && !empty($control['options']) && !array_key_exists($value, $control['options'])) {
            if ($required) throw new \DomainException('Unsupported native control value');
            return;
        }
        if (in_array($control['type'] ?? '', ['popover_toggle', 'switcher'], true) && $value !== '' && $value !== ($control['return_value'] ?? 'yes')) {
            if ($required) throw new \DomainException('Unsupported native toggle value');
            return;
        }
        $settings[$key] = $value;
    }

    public function widgets(): array
    {
        return array_values(array_filter(array_keys(WidgetContracts::all()), fn(string $type): bool => $this->status($type)['authoring']));
    }

    public function field(string $type, string $repeater, string $key): ?array
    {
        foreach ($this->control($type, $repeater)['fields'] ?? [] as $id => $field) if (($field['name'] ?? $id) === $key) return $field;
        return null;
    }

    public function repeater(array &$settings, string $type, string $key, array $rows): void
    {
        foreach ($rows as $row) foreach ($row as $field => $value) {
            if ($field === '_id') continue; // Server-generated native repeater identity.
            $control = $this->field($type, $key, $field);
            if (!$control || (is_string($value) && !empty($control['options']) && !array_key_exists($value, $control['options']))) throw new \DomainException('Unsupported repeater field ' . $type . ':' . $key . '.' . $field);
        }
        $this->set($settings, $type, $key, $rows, true);
    }

    public function status(string $type): array
    {
        if (isset($this->statuses[$type])) return $this->statuses[$type];
        $object = $this->type($type); $reason = '';
        $contract = WidgetContracts::all()[$type] ?? null;
        if (!$object) $reason = 'Not registered by Elementor.';
        elseif (!self::is_classic($object) || str_starts_with($type, 'e-')) $reason = 'Atomic elements are excluded.';
        elseif (!$contract) $reason = 'Registered classic widget; no reviewed authoring contract yet.';
        else {
            foreach ($contract as $key => $types) if (!in_array($this->control($type, $key)['type'] ?? '', explode('|', $types), true)) { $reason = 'Missing or incompatible native control: ' . $key; break; }
            foreach (WidgetContracts::repeaters()[$type] ?? [] as $key => $fields) foreach ($fields as $field => $types) if (!in_array($this->field($type, $key, $field)['type'] ?? '', explode('|', $types), true)) $reason = 'Missing or incompatible repeater field: ' . $key . '.' . $field;
            $mode = $type === 'animated-headline' ? $this->control($type, 'headline_style') : null;
            if ($mode && ((!empty($mode['options']) && !isset($mode['options']['highlight'])) || (empty($mode['options']) && ($mode['default'] ?? '') !== 'highlight'))) $reason = 'Native highlight mode could not be confirmed.';
        }
        $limitations = match ($type) {
            'form' => 'Generated forms require manual submission-action configuration in Elementor.',
            'image-gallery' => 'Tablet column count follows native/theme behavior; advanced Gallery or image grids have explicit responsive controls.',
            'slides' => $object && !$this->field($type, 'slides', 'background_image') ? 'Native repeater image fields are unavailable; slides with imagery use a static alternative.' : '',
            default => '',
        };
        return $this->statuses[$type] = ['name' => $type, 'title' => $object && method_exists($object, 'get_title') ? wp_strip_all_tags($object->get_title()) : $type,
            'registered' => (bool) $object, 'classic' => $object && self::is_classic($object) && !str_starts_with($type, 'e-'), 'authoring' => $reason === '',
            'reason' => $reason, 'fallback' => WidgetContracts::fallbacks()[$type] ?? '', 'limitations' => $limitations];
    }

    public function catalog(): array
    {
        $names = array_unique(array_merge(array_keys(\Elementor\Plugin::$instance->widgets_manager->get_widget_types()), array_keys(WidgetContracts::all())));
        sort($names);
        return array_map(fn(string $name): array => $this->status($name), $names);
    }

    /** Informational branding only; never consulted by widget eligibility. */
    public static function providers(): array
    {
        $result = ['pro_elements' => ['detected' => false, 'version' => ''], 'elementor_pro' => ['detected' => false, 'version' => '']];
        if (!function_exists('get_option') || !defined('WP_PLUGIN_DIR')) return $result;
        if (!function_exists('get_plugin_data')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $active = array_unique(array_merge((array) get_option('active_plugins', []), array_keys((array) get_site_option('active_sitewide_plugins', []))));
        foreach ($active as $plugin) {
            $path = realpath(WP_PLUGIN_DIR . '/' . $plugin);
            if (!$path || !str_starts_with(wp_normalize_path($path), trailingslashit(wp_normalize_path(realpath(WP_PLUGIN_DIR))))) continue;
            $data = get_plugin_data($path, false, false);
            $name = strtolower(trim(wp_strip_all_tags($data['Name'] ?? '')));
            $key = $name === 'pro elements' ? 'pro_elements' : ($name === 'elementor pro' ? 'elementor_pro' : '');
            if ($key !== '') $result[$key] = ['detected' => true, 'version' => sanitize_text_field($data['Version'] ?? '')];
        }
        return $result;
    }

    public function summary(): array
    {
        $widgets = $this->widgets();
        return ['core_version' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '',
            'widgets' => $widgets, 'widget_briefs' => array_intersect_key(WidgetContracts::briefs(), array_flip($widgets)),
            'fallbacks' => WidgetContracts::fallbacks(), 'atomic_authoring' => false,
            'hover_transform' => (bool) $this->control('container', '_transform_translateY_effect_hover'),
            'advanced_motion' => (bool) $this->control('heading', 'motion_fx_motion_fx_scrolling'),
            'advanced_motion_authoring' => false];
    }
}
