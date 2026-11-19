<?php
use CortAIStudio\Article;
use CortAIStudio\Settings;
use CortAIStudio\Usage;

defined('ABSPATH') || exit;
?>
<div class="wrap cort-ai" data-cort-ai-writer>
    <header class="cort-ai-page-heading">
        <div>
            <h1><?php esc_html_e('AI Blog Writer', 'cort-ai-studio'); ?></h1>
            <p><?php esc_html_e('Create a WordPress draft from a brief and your website content profile.', 'cort-ai-studio'); ?></p>
        </div>
        <a href="<?php echo esc_url($this->url('dashboard')); ?>"><?php esc_html_e('Dashboard', 'cort-ai-studio'); ?></a>
    </header>

    <?php if (!$configured) : ?>
        <p class="cort-ai-notice" role="status">
            <?php esc_html_e('An OpenAI API key is required to generate articles.', 'cort-ai-studio'); ?>
            <?php if (current_user_can('manage_options')) : ?>
                <a href="<?php echo esc_url($this->url('settings')); ?>"><?php esc_html_e('Open settings', 'cort-ai-studio'); ?></a>
            <?php endif; ?>
        </p>
    <?php endif; ?>
    <noscript><p class="cort-ai-notice"><?php esc_html_e('Enable JavaScript to use the article writer.', 'cort-ai-studio'); ?></p></noscript>

    <section class="cort-ai-card cort-ai-run" data-run hidden aria-label="<?php esc_attr_e('Generation status', 'cort-ai-studio'); ?>">
        <div class="cort-ai-run-head">
            <div class="cort-ai-status-line" role="status">
                <span class="cort-ai-spinner" data-spinner hidden aria-hidden="true"></span>
                <h2 data-stage></h2>
            </div>
            <div class="cort-ai-actions">
                <button type="button" class="button button-primary" data-resume hidden><?php esc_html_e('Resume generation', 'cort-ai-studio'); ?></button>
                <button type="button" class="button" data-stop hidden><?php esc_html_e('Cancel generation', 'cort-ai-studio'); ?></button>
            </div>
        </div>
        <p data-run-message hidden></p>
        <p data-error class="cort-ai-notice cort-ai-notice--error" role="alert" hidden></p>
        <div data-result hidden></div>
    </section>

    <form method="post" data-writer-form data-configured="<?php echo $configured ? 'yes' : 'no'; ?>" autocomplete="off">
        <div class="cort-ai-writer-grid">
            <section class="cort-ai-card">
                <div class="cort-ai-section-heading"><h2><?php esc_html_e('Article brief', 'cort-ai-studio'); ?></h2></div>
                <div class="cort-ai-fields">
                    <div class="cort-ai-field cort-ai-full">
                        <label for="cort-topic"><?php esc_html_e('Blog post topic', 'cort-ai-studio'); ?></label>
                        <input id="cort-topic" name="topic" maxlength="240" required placeholder="<?php esc_attr_e('10 Benefits of Professional Pool Maintenance', 'cort-ai-studio'); ?>">
                    </div>
                    <div class="cort-ai-field cort-ai-full">
                        <label for="cort-keyword"><?php esc_html_e('Primary SEO keyword', 'cort-ai-studio'); ?></label>
                        <input id="cort-keyword" name="keyword" maxlength="160" placeholder="<?php esc_attr_e('professional pool maintenance', 'cort-ai-studio'); ?>">
                    </div>
                    <div class="cort-ai-field cort-ai-full">
                        <label for="cort-instructions"><?php esc_html_e('Additional instructions', 'cort-ai-studio'); ?></label>
                        <textarea id="cort-instructions" name="instructions" rows="5" maxlength="5000" placeholder="<?php esc_attr_e('Write for homeowners in Oklahoma and encourage readers to request a consultation.', 'cort-ai-studio'); ?>"></textarea>
                    </div>
                    <div class="cort-ai-field">
                        <label for="cort-language"><?php esc_html_e('Language', 'cort-ai-studio'); ?></label>
                        <?php $this->select('cort-language', 'language', Settings::languages(), $settings['profile']['preferred_language']); ?>
                    </div>
                    <div class="cort-ai-field">
                        <label for="cort-tone"><?php esc_html_e('Writing tone', 'cort-ai-studio'); ?></label>
                        <?php $this->select('cort-tone', 'tone', Article::tones(), 'professional'); ?>
                    </div>
                    <div class="cort-ai-field">
                        <label for="cort-length"><?php esc_html_e('Article length', 'cort-ai-studio'); ?></label>
                        <?php $this->select('cort-length', 'length', Article::lengths(), 'standard'); ?>
                    </div>
                    <div class="cort-ai-field" data-custom-field hidden>
                        <label for="cort-custom"><?php esc_html_e('Target words', 'cort-ai-studio'); ?></label>
                        <input id="cort-custom" name="custom_words" type="number" value="1200"
                            min="<?php echo (int) Article::CUSTOM_MIN; ?>" max="<?php echo (int) Article::CUSTOM_MAX; ?>">
                    </div>
                    <div class="cort-ai-field cort-ai-full">
                        <label for="cort-category"><?php esc_html_e('WordPress category', 'cort-ai-studio'); ?></label>
                        <?php $this->select('cort-category', 'category_id', $categories, 0); ?>
                    </div>
                </div>
            </section>

            <section class="cort-ai-card">
                <div class="cort-ai-section-heading"><h2><?php esc_html_e('Generation options', 'cort-ai-studio'); ?></h2></div>
                <div class="cort-ai-fields">
                    <div class="cort-ai-field">
                        <label for="cort-model"><?php esc_html_e('AI model', 'cort-ai-studio'); ?></label>
                        <?php $this->select('cort-model', 'model', Settings::models(), $settings['model']); ?>
                    </div>
                    <div class="cort-ai-field">
                        <label for="cort-effort"><?php esc_html_e('Reasoning level', 'cort-ai-studio'); ?></label>
                        <?php $this->select('cort-effort', 'effort', Settings::efforts($settings['model']), $settings['effort']); ?>
                    </div>
                    <fieldset class="cort-ai-checks cort-ai-full">
                        <legend><?php esc_html_e('SEO and links', 'cort-ai-studio'); ?></legend>
                        <label><input type="checkbox" name="seo" checked> <?php esc_html_e('Generate SEO metadata', 'cort-ai-studio'); ?></label>
                        <label><input type="checkbox" name="internal" checked> <?php esc_html_e('Include internal links', 'cort-ai-studio'); ?></label>
                        <label><input type="checkbox" name="external"> <?php esc_html_e('Include external links with web research', 'cort-ai-studio'); ?></label>
                    </fieldset>
                    <fieldset class="cort-ai-checks cort-ai-full" <?php disabled(!$uploads); ?>>
                        <legend><?php esc_html_e('Images', 'cort-ai-studio'); ?></legend>
                        <label><input type="checkbox" name="featured"> <?php esc_html_e('Generate featured image', 'cort-ai-studio'); ?></label>
                        <label><input type="checkbox" name="content_images"> <?php esc_html_e('Generate content images', 'cort-ai-studio'); ?></label>
                        <div class="cort-ai-field" data-count-field hidden>
                            <label for="cort-image-count"><?php esc_html_e('Number of content images', 'cort-ai-studio'); ?></label>
                            <?php $this->select('cort-image-count', 'image_count', [0 => '0', 1 => '1', 2 => '2'], 1); ?>
                        </div>
                        <div class="cort-ai-fields" data-image-fields hidden>
                            <div class="cort-ai-field">
                                <label for="cort-size"><?php esc_html_e('Orientation', 'cort-ai-studio'); ?></label>
                                <?php $this->select('cort-size', 'size', Settings::sizes(), $settings['size']); ?>
                            </div>
                            <div class="cort-ai-field">
                                <label for="cort-quality"><?php esc_html_e('Quality', 'cort-ai-studio'); ?></label>
                                <?php $this->select('cort-quality', 'quality', Settings::qualities(), $settings['quality']); ?>
                            </div>
                        </div>
                    </fieldset>
                    <?php if (!$uploads) : ?><p class="cort-ai-full"><?php esc_html_e('Image generation requires media upload permission.', 'cort-ai-studio'); ?></p><?php endif; ?>
                </div>
            </section>
        </div>

        <div class="cort-ai-submit">
            <button type="submit" data-generate class="button button-primary button-hero" disabled>
                <?php esc_html_e('Generate Article', 'cort-ai-studio'); ?>
            </button>
            <p>
                <?php esc_html_e('Saved as Draft', 'cort-ai-studio'); ?>
                <?php if ($settings['budget'] > 0) : ?>
                    <span aria-hidden="true">·</span>
                    <?php
                    /* translators: %s: estimated maximum cost in US dollars. */
                    echo esc_html(sprintf(__('Estimated limit %s USD', 'cort-ai-studio'), Usage::dollars($settings['budget'])));
                    ?>
                <?php endif; ?>
            </p>
        </div>
    </form>
</div>
