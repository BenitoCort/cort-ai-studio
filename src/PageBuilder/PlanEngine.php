<?php
namespace CortAIStudio\PageBuilder;

use WP_Error;

defined('ABSPATH') || exit;

/** A builder-specific plan generator/compiler, injected into the shared workflow. */
interface PlanEngine
{
    public function request(array $state): array;
    public function decode(array $response): array|WP_Error;
    public function apply(array $original, array $plan, array $options, array $media, array $design, string $layout): array|WP_Error;
}
