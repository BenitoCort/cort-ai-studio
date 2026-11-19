<?php
namespace CortAIStudio\PageBuilder;

use WP_Error;

defined('ABSPATH') || exit;

interface Adapter
{
    public function availability(): array;
    public function inspect(int $id): array|WP_Error;
    public function snapshot(int $id): array|WP_Error;
    public function fingerprint(array $snapshot): string;
    public function validate(array $elements): bool|WP_Error;
    public function save(int $id, array $elements): bool|WP_Error;
    public function render(int $id): string|WP_Error;
    public function unlocked(int $id): bool|WP_Error;
    public function invalidate(int $id): void;
    public function design(): array;
    public function create_preview(array $operation): int|WP_Error;
    public function restore_snapshot(int $id, array $snapshot): bool|WP_Error;
    public function metadata_fingerprint(array $meta): string;
}
