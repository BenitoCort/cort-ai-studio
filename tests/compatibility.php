<?php
/** Isolated feature-gate test, without WordPress or Elementor loaded. */
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__ . '/');
function __(string $message, string $domain = ''): string { return $message; }
$version = $argv[1] ?? 'absent';
if ($version !== 'absent') {
    define('ELEMENTOR_VERSION', $version);
    class ElementorPluginStub { public static object $instance; }
    ElementorPluginStub::$instance = (object) ['documents' => new stdClass()];
    class_alias(ElementorPluginStub::class, 'Elementor\\Plugin');
}
require dirname(__DIR__) . '/src/PageBuilder/Adapter.php';
require dirname(__DIR__) . '/src/PageBuilder/WidgetContracts.php';
require dirname(__DIR__) . '/src/PageBuilder/ElementorCapabilities.php';
require dirname(__DIR__) . '/src/PageBuilder/ElementorAdapter.php';
$state = (new CortAIStudio\PageBuilder\ElementorAdapter())->availability();
if ($state['supported'] || ($version === 'absent' && $state['available'])) { fwrite(STDERR, "Unsafe compatibility gate.\n"); exit(1); }
echo "PASS compatibility gate: $version disables writes without breaking module loading.\n";
