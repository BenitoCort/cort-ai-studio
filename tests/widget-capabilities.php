<?php
/** Capability-contract test independent of WordPress or any provider constant. */
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__ . '/');
function wp_strip_all_tags(string $value): string { return strip_tags($value); }
class ClassicWidgetFixture {
    public function __construct(private array $controls) {}
    public function get_controls(string $key): ?array { return $this->controls[$key] ?? null; }
    public function get_title(): string { return 'Fixture widget'; }
}
class AtomicWidgetFixture extends ClassicWidgetFixture { public static function get_props_schema(): array { return []; } }
class WidgetManagerFixture {
    public array $widgets = [];
    public function get_widget_types(?string $key = null): mixed { return $key === null ? $this->widgets : ($this->widgets[$key] ?? null); }
}
class ElementorPluginFixture { public static object $instance; }
class_alias(ElementorPluginFixture::class, 'Elementor\\Plugin');
require dirname(__DIR__) . '/src/PageBuilder/WidgetContracts.php';
require dirname(__DIR__) . '/src/PageBuilder/ElementorCapabilities.php';
$manager = new WidgetManagerFixture();
ElementorPluginFixture::$instance = (object) ['widgets_manager' => $manager];
$controls = [];
foreach (CortAIStudio\PageBuilder\WidgetContracts::all()['call-to-action'] as $key => $type) $controls[$key] = ['type' => $type];
$manager->widgets['call-to-action'] = new ClassicWidgetFixture($controls);
$manager->widgets['counter'] = new AtomicWidgetFixture([]); // Even a classic-looking name must not admit Atomic objects.
$manager->widgets['icon-list'] = new ClassicWidgetFixture(['icon_list' => ['type' => 'repeater', 'fields' => ['text' => ['type' => 'text', 'name' => 'text'], 'selected_icon' => ['type' => 'icons', 'name' => 'selected_icon'], 'link' => ['type' => 'textarea', 'name' => 'link']]]]);
$caps = new CortAIStudio\PageBuilder\ElementorCapabilities();
if (defined('ELEMENTOR_PRO_VERSION') || !in_array('call-to-action', $caps->widgets(), true)) exit("FAIL: Registered compatible CTA needs no provider constant.\n");
if ($caps->status('counter')['authoring'] || $caps->status('icon-list')['authoring']) exit("FAIL: Atomic widgets or incompatible repeater fields were accepted.\n");
$controls['link']['type'] = 'text';
$manager->widgets['call-to-action'] = new ClassicWidgetFixture($controls);
if ((new CortAIStudio\PageBuilder\ElementorCapabilities())->status('call-to-action')['authoring']) exit("FAIL: Changed native control contract was accepted.\n");
echo "PASS registered advanced widgets without official Pro constants; incompatible controls/repeater fields and Atomic classes fail closed.\n";
