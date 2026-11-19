<?php
use CortAIStudio\Settings;
use CortAIStudio\Usage;

defined('ABSPATH') || exit;
?>
<div class="wrap cort-ai cort-pb" data-page-builder>
    <header class="cort-ai-page-heading">
        <div><h1>AI Page Builder</h1><p><?php esc_html_e('Describe a change. Review a real Elementor preview. Apply it when you approve.', 'cort-ai-studio'); ?></p></div>
        <a href="<?php echo esc_url(admin_url('admin.php?page=cort-ai-studio')); ?>"><?php esc_html_e('Dashboard', 'cort-ai-studio'); ?></a>
    </header>
    <p class="cort-ai-notice" data-availability role="status"><?php echo esc_html($availability['message']); ?></p>
    <details class="cort-ai-card cort-pb-compatibility" data-compatibility-panel hidden>
        <summary><?php esc_html_e('Elementor compatibility and available widgets', 'cort-ai-studio'); ?></summary>
        <dl class="cort-pb-compatibility-facts" data-compatibility-facts></dl>
        <p class="description"><?php esc_html_e('Generation depends on registered classic widgets and their native controls. Provider names are informational; Atomic is excluded.', 'cort-ai-studio'); ?></p>
        <h3><?php esc_html_e('Available for generation', 'cort-ai-studio'); ?></h3>
        <ul class="cort-pb-widget-list" data-compatible-widgets></ul>
        <h3><?php esc_html_e('Unsupported features and limitations', 'cort-ai-studio'); ?></h3>
        <ul class="cort-pb-widget-list" data-unsupported-features></ul>
        <details><summary><?php esc_html_e('Other widgets and unavailable contracts', 'cort-ai-studio'); ?></summary><ul class="cort-pb-widget-list" data-other-widgets></ul></details>
    </details>
    <p class="cort-ai-notice cort-ai-notice--error" data-error role="alert" hidden></p>
    <noscript><p><?php esc_html_e('Enable JavaScript to use AI Page Builder.', 'cort-ai-studio'); ?></p></noscript>
    <form data-pb-form>
        <fieldset data-form-fields disabled class="cort-pb-fieldset">
            <div class="cort-ai-writer-grid">
                <section class="cort-ai-card">
                    <div class="cort-ai-section-heading"><h2><?php esc_html_e('Page and instructions', 'cort-ai-studio'); ?></h2></div>
                    <div class="cort-ai-fields">
                        <div class="cort-ai-field cort-ai-full">
                            <label for="pb-page-search"><?php esc_html_e('Find a page', 'cort-ai-studio'); ?></label>
                            <div class="cort-pb-search"><input id="pb-page-search" type="search" maxlength="100" data-page-search><button type="button" class="button" data-search><?php esc_html_e('Search', 'cort-ai-studio'); ?></button></div>
                        </div>
                        <div class="cort-ai-field cort-ai-full">
                            <label for="pb-page"><?php esc_html_e('Page selection', 'cort-ai-studio'); ?></label>
                            <select id="pb-page" name="page_id"><option value="0"><?php esc_html_e('Create New Page', 'cort-ai-studio'); ?></option></select>
                            <div class="cort-ai-actions"><button type="button" class="button-link" data-pages-prev disabled><?php esc_html_e('Previous', 'cort-ai-studio'); ?></button><span data-page-number></span><button type="button" class="button-link" data-pages-next disabled><?php esc_html_e('Next', 'cort-ai-studio'); ?></button></div>
                        </div>
                        <div class="cort-ai-field" data-new-page><label for="pb-title"><?php esc_html_e('Page title', 'cort-ai-studio'); ?></label><input id="pb-title" name="title" maxlength="180" required></div>
                        <div class="cort-ai-field" data-new-page><label for="pb-slug"><?php esc_html_e('Slug', 'cort-ai-studio'); ?></label><input id="pb-slug" name="slug" maxlength="180" placeholder="inventory-management"></div>
                        <div class="cort-ai-field cort-ai-full" data-new-page>
                            <label for="pb-layout"><?php esc_html_e('Page layout', 'cort-ai-studio'); ?></label>
                            <select id="pb-layout" name="document_layout"><option value="auto"><?php esc_html_e('Plan from brief · new page only', 'cort-ai-studio'); ?></option><option value="default"><?php esc_html_e('Default · theme layout', 'cort-ai-studio'); ?></option><option value="elementor_header_footer"><?php esc_html_e('Elementor Full Width · keep header and footer', 'cort-ai-studio'); ?></option><option value="elementor_canvas"><?php esc_html_e('Elementor Canvas · standalone landing page', 'cort-ai-studio'); ?></option></select>
                            <p class="description"><?php esc_html_e('Existing pages keep their current template. Canvas is used automatically only when a new-page brief explicitly requests no header or footer.', 'cort-ai-studio'); ?></p>
                        </div>
                        <div class="cort-ai-field cort-ai-full">
                            <label for="pb-operation"><?php esc_html_e('Operation type', 'cort-ai-studio'); ?></label>
                            <select id="pb-operation" name="operation">
                                <option value="create"><?php esc_html_e('Create New Page', 'cort-ai-studio'); ?></option>
                                <option value="add"><?php esc_html_e('Add Section', 'cort-ai-studio'); ?></option>
                                <option value="edit"><?php esc_html_e('Edit Existing Section · content', 'cort-ai-studio'); ?></option>
                                <option value="content"><?php esc_html_e('Update Content', 'cort-ai-studio'); ?></option>
                                <option value="remove"><?php esc_html_e('Remove Section', 'cort-ai-studio'); ?></option>
                                <option value="reorder"><?php esc_html_e('Reorder Sections', 'cort-ai-studio'); ?></option>
                                <option value="redesign" disabled><?php esc_html_e('Advanced Redesign · not supported in this release', 'cort-ai-studio'); ?></option>
                            </select>
                        </div>
                        <div class="cort-ai-field cort-ai-full" data-section-field hidden><label for="pb-section"><?php esc_html_e('Section to change', 'cort-ai-studio'); ?></label><select id="pb-section" name="section_id"></select></div>
                        <div class="cort-ai-field" data-position-field hidden>
                            <label for="pb-position"><?php esc_html_e('Position', 'cort-ai-studio'); ?></label><select id="pb-position" name="position"><option value="end"><?php esc_html_e('At the end', 'cort-ai-studio'); ?></option><option value="start"><?php esc_html_e('At the start', 'cort-ai-studio'); ?></option><option value="before"><?php esc_html_e('Before section', 'cort-ai-studio'); ?></option><option value="after"><?php esc_html_e('After section', 'cort-ai-studio'); ?></option></select>
                        </div>
                        <div class="cort-ai-field" data-anchor-field hidden><label for="pb-anchor"><?php esc_html_e('Anchor section', 'cort-ai-studio'); ?></label><select id="pb-anchor" name="anchor_id"></select></div>
                        <div class="cort-ai-field cort-ai-full"><label for="pb-instructions"><?php esc_html_e('AI instructions', 'cort-ai-studio'); ?></label><textarea id="pb-instructions" name="instructions" rows="8" maxlength="7000" required placeholder="<?php esc_attr_e('Add a services section with three cards and a clear call to action, using our brand colors.', 'cort-ai-studio'); ?>"></textarea><p class="description" data-scope-note></p></div>
                    </div>
                </section>
                <section class="cort-ai-card">
                    <div class="cort-ai-section-heading"><h2><?php esc_html_e('AI and media', 'cort-ai-studio'); ?></h2><p><?php esc_html_e('New compositions plan art direction before generating content. Both AI stages use your existing connection, profile and spending limit.', 'cort-ai-studio'); ?></p></div>
                    <div class="cort-ai-fields">
                        <div class="cort-ai-field"><label for="pb-model"><?php esc_html_e('Model', 'cort-ai-studio'); ?></label><select id="pb-model" name="model"><?php foreach (Settings::models() as $id => $label) : ?><option value="<?php echo esc_attr($id); ?>" <?php selected($id, $settings['model']); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></div>
                        <div class="cort-ai-field"><label for="pb-effort"><?php esc_html_e('Reasoning', 'cort-ai-studio'); ?></label><select id="pb-effort" name="effort"><?php foreach (Settings::efforts($settings['model']) as $id => $label) : ?><option value="<?php echo esc_attr($id); ?>" <?php selected($id, $settings['effort']); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></div>
                        <div class="cort-ai-field cort-ai-full">
                            <button type="button" class="button" data-media <?php disabled(!$uploads); ?>><?php esc_html_e('Select / upload images', 'cort-ai-studio'); ?></button><ul data-media-list class="cort-pb-media-list"></ul>
                            <p class="description"><?php esc_html_e('Reference selected images by media_ID in your instructions. Existing image alt text is managed in Media Library; shared files are not silently modified.', 'cort-ai-studio'); ?></p>
                        </div>
                        <div class="cort-ai-field cort-ai-full"><label><input type="checkbox" name="reuse_media" checked> <?php esc_html_e('Also find relevant images in Media Library', 'cort-ai-studio'); ?></label><label><input type="checkbox" name="allow_image_reuse"> <?php esc_html_e('Allow intentionally reusing an image', 'cort-ai-studio'); ?></label><p class="description"><?php esc_html_e('Image matching uses titles, alt text and dimensions. Review the actual photos in the preview.', 'cort-ai-studio'); ?></p></div>
                        <div class="cort-ai-field cort-ai-full"><label for="pb-image-count"><?php esc_html_e('Optional AI images', 'cort-ai-studio'); ?></label><select id="pb-image-count" name="image_count" <?php disabled(!$uploads); ?>><option value="0"><?php esc_html_e('Do not generate images', 'cort-ai-studio'); ?></option><option value="1"><?php esc_html_e('Allow up to one image', 'cort-ai-studio'); ?></option><option value="2"><?php esc_html_e('Allow up to two images', 'cort-ai-studio'); ?></option></select></div>
                        <div class="cort-ai-field"><label for="pb-quality"><?php esc_html_e('Image quality', 'cort-ai-studio'); ?></label><select id="pb-quality" name="quality"><?php foreach (Settings::qualities() as $id => $label) : ?><option value="<?php echo esc_attr($id); ?>" <?php selected($id, $settings['quality']); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></div>
                        <div class="cort-ai-field"><label for="pb-size"><?php esc_html_e('Image format', 'cort-ai-studio'); ?></label><select id="pb-size" name="size"><?php foreach (Settings::sizes() as $id => $label) : ?><option value="<?php echo esc_attr($id); ?>" <?php selected($id, $settings['size']); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></div>
                    </div>
                    <p class="cort-pb-budget"><?php echo esc_html($settings['budget'] > 0 ? sprintf(__('Estimated spending limit: %s USD per proposal.', 'cort-ai-studio'), Usage::dollars($settings['budget'])) : __('Estimated spending limit is disabled in Settings.', 'cort-ai-studio')); ?></p>
                    <p class="description"><?php esc_html_e('New pages stay in Draft. Existing pages keep their publication status and are only changed after approval. Remove and reorder use the selected controls without an AI charge.', 'cort-ai-studio'); ?></p>
                </section>
            </div>
            <section class="cort-ai-card cort-pb-analysis" data-analysis hidden><h2><?php esc_html_e('Current page structure', 'cort-ai-studio'); ?></h2><p data-page-info></p><p data-page-issues class="cort-pb-issues"></p><div data-tree></div></section>
            <div class="cort-ai-submit"><button type="submit" class="button button-primary button-hero" data-generate><?php esc_html_e('Generate / Preview Proposal', 'cort-ai-studio'); ?></button></div>
        </fieldset>
    </form>
    <section class="cort-ai-card cort-pb-result" data-result hidden aria-live="polite">
        <div class="cort-ai-section-heading"><h2 data-stage></h2><p data-summary></p></div>
        <div data-design-report class="cort-pb-design-report" hidden></div>
        <div data-usage class="cort-ai-result-details"></div><ul data-warnings class="cort-ai-warnings" hidden></ul>
        <ol data-changes class="cort-pb-changes"></ol>
        <div class="cort-ai-actions"><button type="button" class="button button-primary" data-resume hidden><?php esc_html_e('Resume / Verify', 'cort-ai-studio'); ?></button><button type="button" class="button" data-discard hidden><?php esc_html_e('Discard proposal', 'cort-ai-studio'); ?></button><button type="button" class="button" data-refresh><?php esc_html_e('Refresh status', 'cort-ai-studio'); ?></button><a class="button" data-edit hidden><?php esc_html_e('Edit in Elementor', 'cort-ai-studio'); ?></a></div>
        <div data-preview-panel hidden>
            <div class="cort-ai-actions cort-pb-preview-tools"><button type="button" class="button" data-load-preview><?php esc_html_e('Load real Elementor preview', 'cort-ai-studio'); ?></button><a data-preview-link target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open preview in new tab', 'cort-ai-studio'); ?></a><label for="pb-device"><?php esc_html_e('Preview width', 'cort-ai-studio'); ?></label><select id="pb-device" data-device><option value="1280"><?php esc_html_e('Desktop', 'cort-ai-studio'); ?></option><option value="768"><?php esc_html_e('Tablet', 'cort-ai-studio'); ?></option><option value="390"><?php esc_html_e('Mobile', 'cort-ai-studio'); ?></option></select></div>
            <div class="cort-pb-preview-scroll" data-preview-scroll hidden><iframe title="<?php esc_attr_e('Real Elementor proposal preview', 'cort-ai-studio'); ?>" data-preview-frame width="1280" height="720"></iframe></div>
            <p class="cort-pb-review-message" data-review-message></p>
            <label class="cort-pb-approval"><input type="checkbox" data-approval disabled> <?php esc_html_e('I reviewed this preview and approve the listed changes, including any removals.', 'cort-ai-studio'); ?></label>
            <button type="button" class="button button-primary" data-apply disabled><?php esc_html_e('Apply approved changes', 'cort-ai-studio'); ?></button>
        </div>
        <div data-restore-panel hidden class="cort-pb-restore"><p data-backup></p><label><input type="checkbox" data-restore-approval> <?php esc_html_e('Restore the page structure saved before this operation.', 'cort-ai-studio'); ?></label><button type="button" class="button" data-restore disabled><?php esc_html_e('Restore previous version', 'cort-ai-studio'); ?></button><p class="description"><?php esc_html_e('Restoration is blocked if the page has changed since this operation. Media files remain in Media Library.', 'cort-ai-studio'); ?></p></div>
    </section>
    <section class="cort-ai-card cort-pb-history"><div class="cort-ai-section-heading"><h2><?php esc_html_e('Page Builder operation history', 'cort-ai-studio'); ?></h2></div><div class="cort-ai-table-wrap"><table class="widefat striped"><thead><tr><th><?php esc_html_e('Date / page', 'cort-ai-studio'); ?></th><th><?php esc_html_e('Operation / status', 'cort-ai-studio'); ?></th><th><?php esc_html_e('Model / tokens', 'cort-ai-studio'); ?></th><th><?php esc_html_e('Images / USD', 'cort-ai-studio'); ?></th><th><?php esc_html_e('Backup / review', 'cort-ai-studio'); ?></th></tr></thead><tbody data-history></tbody></table></div></section>
</div>
