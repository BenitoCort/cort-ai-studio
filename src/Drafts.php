<?php
namespace CortAIStudio;

use WP_Error;
use WP_Post;

defined('ABSPATH') || exit;

final class Drafts
{
    private const ORIGIN = '_cort_ai_studio_job';
    private const SEO_TITLE = '_cort_ai_studio_seo_title';
    private const SEO_DESCRIPTION = '_cort_ai_studio_seo_description';

    public function category_context(int $explicit): array
    {
        $terms = get_terms([
            'taxonomy' => 'category',
            'hide_empty' => false,
            'number' => 45,
            'fields' => 'names',
        ]);
        $names = is_array($terms) ? array_map(static fn(string $name): string => Text::plain($name, 75), $terms) : [];
        if ($explicit) {
            $term = get_term($explicit, 'category');
            if ($term instanceof \WP_Term) {
                array_unshift($names, Text::plain($term->name, 75));
            }
        }
        return array_values(array_unique(array_filter($names)));
    }

    public function create(array $job, array $article, string $content): int|WP_Error
    {
        global $wpdb;

        // A stable GUID is written with the initial post row, so a retry can recover the same
        // draft even if WordPress stopped before inserting its metadata.
        $guid = home_url('/?cort_ai_draft=' . $job['id'] . '-' . $job['request_id']);
        $found = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE guid = %s AND post_type = 'post' ORDER BY ID LIMIT 1", $guid
        ));
        if ($found) {
            return current_user_can('edit_post', $found) ? $found : $this->save_error();
        }

        $author = get_userdata($job['user_id']) ? $job['user_id'] : get_current_user_id();
        $post_id = wp_insert_post(wp_slash([
            'post_type' => 'post',
            'post_status' => 'draft',
            'post_author' => $author,
            'post_title' => $article['title'],
            'post_name' => $article['slug'],
            'post_content' => $content,
            'guid' => $guid,
            'meta_input' => [self::ORIGIN => $job['request_id']],
        ]), true);

        return is_wp_error($post_id) || !$post_id ? $this->save_error() : (int) $post_id;
    }

    public function classify(int $id, array $article, array $options, string $request_id): array
    {
        $warnings = [];
        $post = get_post($id);
        if (!$post || $post->post_status !== 'draft' || !current_user_can('edit_post', $id)) {
            return [__('The draft changed before its categories, tags, and SEO could be applied. Review them in the editor.', 'cort-ai-studio')];
        }
        add_post_meta($id, self::ORIGIN, $request_id, true);

        $categories = [];
        $taxonomy = get_taxonomy('category');
        if ($taxonomy && current_user_can($taxonomy->cap->assign_terms)) {
            if ($options['category_id']) {
                if (term_exists($options['category_id'], 'category')) {
                    $categories[] = $options['category_id'];
                } else {
                    $warnings[] = __('The selected category was removed. Review the draft’s category in the editor.', 'cort-ai-studio');
                }
            } else {
                foreach ($article['categories'] as $name) {
                    $term = get_term_by('name', $name, 'category');
                    if ($term) {
                        $categories[] = $term->term_id;
                    }
                }
            }
            if ($categories) {
                $assigned = wp_get_post_categories($id);
                if (is_array($assigned) && array_diff($assigned, [(int) get_option('default_category')])) {
                    $categories = array_merge($categories, $assigned);
                }
                if (is_wp_error(wp_set_post_categories($id, array_values(array_unique($categories))))) {
                    $warnings[] = __('WordPress could not assign the selected categories.', 'cort-ai-studio');
                }
            }
        }

        $tags = get_taxonomy('post_tag');
        if ($article['tags'] && $tags && current_user_can($tags->cap->assign_terms)) {
            // Pass term names as strings and append so a recovery does not remove other tags.
            if (is_wp_error(wp_set_object_terms($id, $article['tags'], 'post_tag', true))) {
                $warnings[] = __('WordPress could not assign one or more tags.', 'cort-ai-studio');
            }
        } elseif ($article['tags']) {
            $warnings[] = __('This account could not assign tags. Review the suggested tags in the result.', 'cort-ai-studio');
        }

        if ($options['seo']) {
            $this->add_meta($id, self::SEO_TITLE, $article['seo_title']);
            $this->add_meta($id, self::SEO_DESCRIPTION, $article['meta_description']);
            if (defined('WPSEO_VERSION') || class_exists('WPSEO_Meta')) {
                $this->add_meta($id, '_yoast_wpseo_title', $article['seo_title']);
                $this->add_meta($id, '_yoast_wpseo_metadesc', $article['meta_description']);
            }
            if (defined('RANK_MATH_VERSION') || class_exists('RankMath\Helper')) {
                $this->add_meta($id, 'rank_math_title', $article['seo_title']);
                $this->add_meta($id, 'rank_math_description', $article['meta_description']);
            }
            if (!metadata_exists('post', $id, self::SEO_TITLE) || !metadata_exists('post', $id, self::SEO_DESCRIPTION)) {
                $warnings[] = __('WordPress could not store all of the generated SEO metadata.', 'cort-ai-studio');
            }
        }

        return $warnings;
    }

    public function apply_images(int $id, string $request_id, array $attachments): array
    {
        global $wpdb;

        $post = get_post($id);
        if (!$post || $post->post_status !== 'draft' || !current_user_can('edit_post', $id)) {
            return $attachments ? [__('The post changed or is no longer a draft. Generated files are available in the Media Library.', 'cort-ai-studio')] : [];
        }
        $lock = explode(':', (string) get_post_meta($id, '_edit_lock', true));
        if ((int) ($lock[0] ?? 0) > time() - (int) apply_filters('wp_check_post_lock_window', 150)) {
            return $attachments ? [__('The draft was open for editing. Add the generated images from the Media Library when ready.', 'cort-ai-studio')] : [];
        }

        $warnings = [];
        $original = $post->post_content;
        $content = $original;
        foreach (['content_1', 'content_2'] as $slot) {
            $marker = Article::marker($request_id, $slot);
            $attachment = $attachments[$slot] ?? 0;
            $image = $attachment ? $this->image_block($attachment) : '';
            $already_inserted = $attachment && str_contains($content, 'class="wp-image-' . $attachment . '"');
            if ($attachment && !$already_inserted && ($image === '' || !str_contains($content, $marker))) {
                $warnings[] = __('A content image could not be placed automatically. It is available in the Media Library.', 'cort-ai-studio');
            }
            $content = str_replace($marker, $already_inserted ? '' : $image, $content);
        }

        if ($content !== $original) {
            // Guard the additive block update against simultaneous editor saves. HEX makes the
            // comparison byte-exact, including on case-insensitive WordPress collations.
            $changed = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->posts} SET post_content = %s, post_modified = %s, post_modified_gmt = %s
                WHERE ID = %d AND post_status = 'draft' AND HEX(post_content) = %s",
                $content, current_time('mysql'), current_time('mysql', true), $id, strtoupper(bin2hex($original))
            ));
            if ($changed === 1) {
                clean_post_cache($id);
                wp_after_insert_post($id, true, $post);
            } elseif (!empty($attachments['content_1']) || !empty($attachments['content_2'])) {
                $warnings[] = __('The draft was updated concurrently. Add any missing content images from the Media Library.', 'cort-ai-studio');
            }
        }

        $featured = $attachments['featured'] ?? 0;
        if ($featured && get_post_status($id) === 'draft' && (int) get_post_thumbnail_id($id) !== $featured) {
            if ((int) get_post_thumbnail_id($id) > 0 || !set_post_thumbnail($id, $featured)) {
                $warnings[] = __('The generated featured image is in the Media Library. Review the featured image in the editor.', 'cort-ai-studio');
            }
        }

        return array_values(array_unique($warnings));
    }

    public function result(int $id): ?array
    {
        $post = get_post($id);
        if (!$post || !current_user_can('edit_post', $id)) {
            return null;
        }
        $status = get_post_status_object($post->post_status);
        return [
            'id' => $id,
            'title' => Text::plain($post->post_title, 180),
            'status' => $status ? $status->label : $post->post_status,
            'edit_url' => esc_url_raw((string) get_edit_post_link($id, 'raw')),
            'preview_url' => esc_url_raw((string) get_preview_post_link($post)),
        ];
    }

    public function register_editor(): void
    {
        add_action('add_meta_boxes_post', [$this, 'add_seo_box']);
        add_action('save_post_post', [$this, 'save_seo_box'], 10, 2);
    }

    public function add_seo_box(WP_Post $post): void
    {
        if (!get_post_meta($post->ID, self::ORIGIN, true) || !metadata_exists('post', $post->ID, self::SEO_TITLE) ||
            defined('WPSEO_VERSION') || class_exists('WPSEO_Meta') ||
            defined('RANK_MATH_VERSION') || class_exists('RankMath\Helper')) {
            return;
        }
        add_meta_box('cort-ai-studio-seo', __('Cort AI Studio SEO metadata', 'cort-ai-studio'), [$this, 'render_seo_box'], 'post');
    }

    public function render_seo_box(WP_Post $post): void
    {
        wp_nonce_field('cort_ai_studio_edit_seo_' . $post->ID, 'cort_ai_studio_seo_nonce');
        ?>
        <p><?php esc_html_e('Save these fields with the draft or copy them into your SEO setup.', 'cort-ai-studio'); ?></p>
        <p>
            <label for="cort-ai-studio-seo-title"><?php esc_html_e('SEO title', 'cort-ai-studio'); ?></label><br>
            <input id="cort-ai-studio-seo-title" name="cort_ai_studio_seo_title" class="widefat" maxlength="50"
                value="<?php echo esc_attr((string) get_post_meta($post->ID, self::SEO_TITLE, true)); ?>">
        </p>
        <p>
            <label for="cort-ai-studio-seo-description"><?php esc_html_e('Meta description', 'cort-ai-studio'); ?></label><br>
            <textarea id="cort-ai-studio-seo-description" name="cort_ai_studio_seo_description" class="widefat" rows="3"
                maxlength="150"><?php echo esc_textarea((string) get_post_meta($post->ID, self::SEO_DESCRIPTION, true)); ?></textarea>
        </p>
        <?php
    }

    public function save_seo_box(int $id, WP_Post $post): void
    {
        $nonce = $_POST['cort_ai_studio_seo_nonce'] ?? '';
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($id) ||
            !current_user_can('edit_post', $id) || !get_post_meta($id, self::ORIGIN, true) ||
            !is_string($nonce) || !wp_verify_nonce(wp_unslash($nonce), 'cort_ai_studio_edit_seo_' . $post->ID)) {
            return;
        }
        foreach ([self::SEO_TITLE => 50, self::SEO_DESCRIPTION => 150] as $key => $limit) {
            $field = substr($key, 1);
            if (isset($_POST[$field])) {
                $value = Text::plain(wp_unslash($_POST[$field]), $limit);
                update_post_meta($id, $key, wp_slash($value));
            }
        }
    }

    private function image_block(int $id): string
    {
        $image = wp_get_attachment_image_src($id, 'large');
        if (!$image || !$image[0]) {
            return '';
        }
        $alt = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
        $html = '<figure class="wp-block-image size-large"><img src="' . esc_url($image[0]) .
            '" alt="' . esc_attr($alt) . '" class="wp-image-' . $id . '"/></figure>';
        return get_comment_delimited_block_content('core/image', [
            'id' => $id, 'sizeSlug' => 'large', 'linkDestination' => 'none',
        ], $html);
    }

    private function add_meta(int $id, string $key, string $value): void
    {
        if ($value !== '') {
            add_post_meta($id, $key, wp_slash($value), true);
        }
    }

    private function save_error(): WP_Error
    {
        return new WP_Error('cort_ai_save', __('WordPress could not save the draft. The generated article is retained. Retry saving after checking post permissions and database health.', 'cort-ai-studio'));
    }
}
