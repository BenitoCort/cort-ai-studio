<?php
namespace CortAIStudio;

use WP_Error;

defined('ABSPATH') || exit;

final class Images
{
    public function prompt(array $plan, string $style, string $size): string
    {
        return 'Create a professional editorial blog image. Render an original, realistic visual with a clean composition in ' .
            $size . ' format. No text, watermarks or invented logos. Do not depict the scene as proof of real company work. ' .
            $plan['prompt'] . ($style === '' ? '' : "\nPreferred visual direction: " . $style);
    }

    public function existing(int $post, string $request, string $slot): int
    {
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_parent' => $post,
            'name' => $this->slug($request, $slot),
            'posts_per_page' => 1,
            'fields' => 'ids',
        ]);
        return (int) ($ids[0] ?? 0);
    }

    public function save(int $post, string $request, string $slot, array $plan, mixed $encoded): int|WP_Error
    {
        if (!current_user_can('upload_files') || !current_user_can('edit_post', $post) || get_post_status($post) !== 'draft') {
            return $this->error(__('WordPress permissions or draft status prevent saving the image.', 'cort-ai-studio'));
        }
        $existing = $this->existing($post, $request, $slot);
        if ($existing) {
            $this->ensure_alt($existing, $plan['alt']);
            return $existing;
        }

        $bytes = is_string($encoded) && strlen($encoded) <= 16 * MB_IN_BYTES ? base64_decode($encoded, true) : false;
        $info = is_string($bytes) ? @getimagesizefromstring($bytes) : false;
        if (!$info || $info['mime'] !== 'image/jpeg' || max($info[0], $info[1]) > 4096 || strlen($bytes) > 12 * MB_IN_BYTES) {
            return $this->error(__('OpenAI did not return a valid JPEG within the supported file size.', 'cort-ai-studio'));
        }
        unset($encoded);

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $slug = $this->slug($request, $slot);
        $name = $slug . '.jpg';
        $temp = wp_tempnam($name);
        if (!$temp) {
            return $this->error(__('WordPress could not create a temporary image file.', 'cort-ai-studio'));
        }
        try {
            if (@file_put_contents($temp, $bytes) !== strlen($bytes)) {
                return $this->error(__('WordPress could not write the generated image to disk.', 'cort-ai-studio'));
            }
            $file_size = strlen($bytes);
            unset($bytes);
            $attachment = media_handle_sideload([
                'name' => $name, 'tmp_name' => $temp, 'error' => 0, 'size' => $file_size,
            ], $post, null, wp_slash(['post_title' => $plan['title'], 'post_name' => $slug]));
            if (is_wp_error($attachment) || !$attachment) {
                return $this->error(__('WordPress could not save the image in the Media Library. Check the uploads directory and image settings.', 'cort-ai-studio'));
            }
            $this->ensure_alt((int) $attachment, $plan['alt']);
            return (int) $attachment;
        } finally {
            if (is_file($temp)) {
                wp_delete_file($temp);
            }
        }
    }

    public function ensure_alt(int $id, string $alt): void
    {
        add_post_meta($id, '_wp_attachment_image_alt', wp_slash($alt), true);
    }

    private function slug(string $request, string $slot): string
    {
        return 'cort-ai-' . $request . '-' . str_replace('_', '-', $slot);
    }

    private function error(string $message): WP_Error
    {
        return new WP_Error('cort_ai_image_media', $message);
    }
}
