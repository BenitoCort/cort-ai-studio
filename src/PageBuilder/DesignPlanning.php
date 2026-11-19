<?php
namespace CortAIStudio\PageBuilder;

use WP_Error;

defined('ABSPATH') || exit;

interface DesignPlanning
{
    public function design_request(array $state): array;
    public function decode_design(array $response, array $context): array|WP_Error;
}
