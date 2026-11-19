<?php
/** Isolated feature-gate test, without WordPress or Elementor loaded. */
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__ . '/');
function __(string $message, string $domain = ''): string { return $message; }
$version = $argv[1] ?? 'absent';
if ($version !== 'absent') {
    define('ELEMENTOR_VERSION', $version === 'uninitialized' ? '4.4.1' : $version);
    class ElementorPluginStub { public static object $instance; }
    ElementorPluginStub::$instance = (object) ['documents' => new class {
        public function get(): void {}
        public function create(): void {}
    }];
    if ($version !== 'uninitialized') {
        ElementorPluginStub::$instance->elements_manager = new class {
            public function get_element_types(string $name): object { return new stdClass(); }
        };
        ElementorPluginStub::$instance->widgets_manager = new class {
            public function get_widget_types(?string $name = null): ?array { return $name === null ? [] : null; }
        };
        ElementorPluginStub::$instance->frontend = new class {
            public function get_builder_content_for_display(): string { return ''; }
        };
    }
    class_alias(ElementorPluginStub::class, 'Elementor\\Plugin');
}
require dirname(__DIR__) . '/src/PageBuilder/Adapter.php';
require dirname(__DIR__) . '/src/PageBuilder/WidgetContracts.php';
require dirname(__DIR__) . '/src/PageBuilder/ElementorCapabilities.php';
require dirname(__DIR__) . '/src/PageBuilder/ElementorAdapter.php';
$state = (new CortAIStudio\PageBuilder\ElementorAdapter())->availability();
$available = !in_array($version, ['absent', 'uninitialized'], true);
$supported = $version === '4.4.1';
if ($state['available'] !== $available || $state['supported'] !== $supported || $state['containers'] !== $supported) {
    fwrite(STDERR, "Unexpected compatibility gate.\n"); exit(1);
}
echo "PASS compatibility gate for $version.\n";
