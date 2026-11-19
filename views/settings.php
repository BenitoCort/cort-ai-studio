<?php
use CortAIStudio\Settings;
use CortAIStudio\Usage;

defined('ABSPATH') || exit;
?>
<div class="wrap cort-ai">
    <header class="cort-ai-page-heading">
        <div>
            <h1><?php esc_html_e('Settings', 'cort-ai-studio'); ?></h1>
            <p><?php esc_html_e('Set up OpenAI and define your editorial preferences.', 'cort-ai-studio'); ?></p>
        </div>
        <a href="<?php echo esc_url($this->url('dashboard')); ?>"><?php esc_html_e('Dashboard', 'cort-ai-studio'); ?></a>
    </header>
    <?php if ($saved === 'success') : ?>
        <p class="cort-ai-notice cort-ai-notice--success" role="status"><?php esc_html_e('Settings saved.', 'cort-ai-studio'); ?></p>
    <?php elseif ($saved === 'key_error') : ?>
        <p class="cort-ai-notice cort-ai-notice--error" role="alert">
            <?php esc_html_e('Preferences saved. Check the format of the API key and enter it again.', 'cort-ai-studio'); ?>
        </p>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-cort-ai-settings>
        <input type="hidden" name="action" value="cort_ai_studio_save">
        <?php wp_nonce_field('cort_ai_studio_save'); ?>

        <div class="cort-ai-settings-grid">
            <section class="cort-ai-card cort-ai-settings-api">
                <div class="cort-ai-section-heading"><h2><?php esc_html_e('OpenAI connection', 'cort-ai-studio'); ?></h2></div>
                <div class="cort-ai-fields">
                    <div class="cort-ai-field cort-ai-full">
                        <label for="cort-api-key"><?php esc_html_e('OpenAI API key', 'cort-ai-studio'); ?></label>
                        <input id="cort-api-key" type="password" name="api_key" autocomplete="new-password" maxlength="512"
                            placeholder="<?php echo esc_attr($configured ? '••••••••••••••••' : 'sk-proj-…'); ?>"
                            <?php disabled($config_key); ?>>
                        <?php if ($config_key) : ?>
                            <p class="description"><?php esc_html_e('Using CORT_AI_STUDIO_OPENAI_API_KEY from wp-config.php.', 'cort-ai-studio'); ?></p>
                        <?php elseif ($configured) : ?>
                            <p class="description"><?php esc_html_e('Enter a new key to replace the saved one.', 'cort-ai-studio'); ?></p>
                            <label class="cort-ai-checkbox"><input type="checkbox" name="remove_key" value="1"> <?php esc_html_e('Remove saved key', 'cort-ai-studio'); ?></label>
                        <?php else : ?>
                            <p class="description"><?php esc_html_e('Use a project API key from the OpenAI developer platform.', 'cort-ai-studio'); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="cort-ai-field">
                        <label for="cort-model"><?php esc_html_e('Default text model', 'cort-ai-studio'); ?></label>
                        <?php $this->select('cort-model', 'settings[model]', Settings::models(), $settings['model']); ?>
                    </div>
                    <div class="cort-ai-field">
                        <label for="cort-effort"><?php esc_html_e('Default reasoning', 'cort-ai-studio'); ?></label>
                        <?php $this->select('cort-effort', 'settings[effort]', Settings::efforts($settings['model']), $settings['effort']); ?>
                    </div>
                </div>
                <div class="cort-ai-connection-test">
                    <button type="button" class="button" data-test <?php disabled(!$configured); ?>>
                        <?php esc_html_e('Test Connection', 'cort-ai-studio'); ?>
                    </button>
                    <p class="cort-ai-connection" data-connection data-state="<?php echo esc_attr($connection['state']); ?>" role="status">
                        <?php echo esc_html($connection['label']); ?>
                    </p>
                    <p class="description"><?php esc_html_e('Save changes before testing the selected model.', 'cort-ai-studio'); ?></p>
                </div>
            </section>

            <section class="cort-ai-card">
                <div class="cort-ai-section-heading">
                    <h2><?php esc_html_e('Images and spending', 'cort-ai-studio'); ?></h2>
                    <p><?php echo esc_html(Settings::IMAGE_MODEL); ?></p>
                </div>
                <div class="cort-ai-fields">
                    <div class="cort-ai-field">
                        <label for="cort-image-size"><?php esc_html_e('Default orientation', 'cort-ai-studio'); ?></label>
                        <?php $this->select('cort-image-size', 'settings[size]', Settings::sizes(), $settings['size']); ?>
                    </div>
                    <div class="cort-ai-field">
                        <label for="cort-image-quality"><?php esc_html_e('Default quality', 'cort-ai-studio'); ?></label>
                        <?php $this->select('cort-image-quality', 'settings[quality]', Settings::qualities(), $settings['quality']); ?>
                    </div>
                    <div class="cort-ai-field cort-ai-full">
                        <label for="cort-image-style"><?php esc_html_e('Visual style', 'cort-ai-studio'); ?></label>
                        <textarea id="cort-image-style" name="settings[style]" rows="3" maxlength="700"
                            placeholder="<?php esc_attr_e('Editorial photography, natural light, no visible text.', 'cort-ai-studio'); ?>"><?php echo esc_textarea($settings['style']); ?></textarea>
                    </div>
                    <div class="cort-ai-field cort-ai-full">
                        <label for="cort-budget"><?php esc_html_e('Estimated spending limit per generation (USD)', 'cort-ai-studio'); ?></label>
                        <input id="cort-budget" name="settings[budget]" type="number" min="0" max="1000" step="0.000001"
                            value="<?php echo esc_attr((string) $settings['budget']); ?>" aria-describedby="cort-budget-help">
                        <p id="cort-budget-help" class="description"><?php esc_html_e('Used by Blog Writer and Page Builder before billable stages. Enter 0 to disable this limit.', 'cort-ai-studio'); ?></p>
                    </div>
                </div>
            </section>
        </div>

        <section class="cort-ai-card">
            <div class="cort-ai-section-heading">
                <h2><?php esc_html_e('Website Content Profile', 'cort-ai-studio'); ?></h2>
                <p><?php esc_html_e('Use confirmed business details that can be sent to OpenAI when drafting an article.', 'cort-ai-studio'); ?></p>
            </div>
            <div class="cort-ai-fields">
                <?php foreach (Settings::profile_fields() as $field => [$label, $type, $limit]) :
                    $id = 'cort-profile-' . $field;
                    $name = 'settings[profile][' . $field . ']';
                    $value = $settings['profile'][$field];
                    ?>
                    <div class="cort-ai-field <?php echo in_array($field, ['business_description', 'editorial_instructions'], true) ? 'cort-ai-full' : ''; ?>">
                        <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
                        <?php if ($type === 'textarea') : ?>
                            <textarea id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" rows="3"
                                maxlength="<?php echo (int) $limit; ?>"><?php echo esc_textarea($value); ?></textarea>
                        <?php elseif ($type === 'language') : ?>
                            <?php $this->select($id, $name, Settings::languages(), $value); ?>
                        <?php else : ?>
                            <input id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" type="<?php echo esc_attr($type); ?>"
                                maxlength="<?php echo (int) $limit; ?>" value="<?php echo esc_attr($value); ?>">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <details class="cort-ai-card cort-ai-pricing">
            <summary><?php esc_html_e('Cost estimate rates', 'cort-ai-studio'); ?></summary>
            <p>
                <?php esc_html_e('Values are in USD. Image planning estimates are used before generation and when image usage is unavailable.', 'cort-ai-studio'); ?>
                <a href="https://developers.openai.com/api/docs/pricing" target="_blank" rel="noopener noreferrer"><?php esc_html_e('OpenAI pricing', 'cort-ai-studio'); ?></a>
            </p>
            <div class="cort-ai-fields">
                <?php foreach (Usage::labels() as $key => $label) :
                    $id = 'cort-rate-' . $key;
                    ?>
                    <div class="cort-ai-field">
                        <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
                        <input id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr('settings[rates][' . $key . ']'); ?>"
                            type="number" step="0.000001" min="0" max="<?php echo $key === 'web_call' ? '100' : '10000'; ?>"
                            value="<?php echo esc_attr((string) $settings['rates'][$key]); ?>">
                    </div>
                <?php endforeach; ?>
            </div>
        </details>

        <div class="cort-ai-submit"><?php submit_button(__('Save settings', 'cort-ai-studio'), 'primary', 'submit', false); ?></div>
    </form>
</div>
