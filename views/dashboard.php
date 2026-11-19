<?php
use CortAIStudio\Settings;
use CortAIStudio\Usage;

defined('ABSPATH') || exit;

$statuses = [
    'running' => __('In progress', 'cort-ai-studio'),
    'complete' => __('Complete', 'cort-ai-studio'),
    'partial' => __('Complete with notices', 'cort-ai-studio'),
    'failed' => __('Failed', 'cort-ai-studio'),
    'cancelled' => __('Cancelled', 'cort-ai-studio'),
];
?>
<div class="wrap cort-ai">
    <header class="cort-ai-hero">
        <div>
            <h1>Cort AI Studio</h1>
            <p><?php esc_html_e('Create content and native Elementor pages with AI.', 'cort-ai-studio'); ?></p>
        </div>
        <a class="button cort-ai-hero-button" href="<?php echo esc_url($this->url('writer')); ?>">
            <?php esc_html_e('Generate an article', 'cort-ai-studio'); ?>
        </a>
    </header>

    <section class="cort-ai-card" style="margin-bottom:15px">
        <div class="cort-ai-section-heading"><h2><?php esc_html_e('Your AI tools', 'cort-ai-studio'); ?></h2></div>
        <div class="cort-ai-actions">
            <a class="button" href="<?php echo esc_url($this->url('writer')); ?>">AI Blog Writer</a>
            <?php if (\CortAIStudio\PageBuilder\Module::can_edit()) : ?>
                <a class="button" href="<?php echo esc_url($this->url('page_builder')); ?>">AI Page Builder</a>
            <?php endif; ?>
            <a href="<?php echo esc_url($this->url('coming_soon')); ?>"><?php esc_html_e('Coming Soon', 'cort-ai-studio'); ?></a>
        </div>
    </section>

    <?php if (!$ready) : ?>
        <p class="cort-ai-notice cort-ai-notice--error" role="alert">
            <?php esc_html_e('The generation table is unavailable. Check the site database permissions.', 'cort-ai-studio'); ?>
        </p>
    <?php endif; ?>
    <div class="cort-ai-section-heading"><h2><?php esc_html_e('AI Blog Writer activity', 'cort-ai-studio'); ?></h2></div>
    <div class="cort-ai-metrics">
        <section class="cort-ai-card cort-ai-metric">
            <h2><?php esc_html_e('OpenAI connection', 'cort-ai-studio'); ?></h2>
            <p class="cort-ai-connection" data-state="<?php echo esc_attr($connection['state']); ?>">
                <?php echo esc_html($connection['label']); ?>
            </p>
            <p><?php echo esc_html(Settings::models()[$settings['model']]); ?></p>
            <?php if ($admin) : ?>
                <a href="<?php echo esc_url($this->url('settings')); ?>"><?php esc_html_e('Open settings', 'cort-ai-studio'); ?></a>
            <?php endif; ?>
        </section>
        <section class="cort-ai-card cort-ai-metric">
            <h2><?php esc_html_e('Articles generated', 'cort-ai-studio'); ?></h2>
            <p class="cort-ai-metric-value"><?php echo esc_html(number_format_i18n((int) ($metrics['articles'] ?? 0))); ?></p>
        </section>
        <section class="cort-ai-card cort-ai-metric">
            <h2><?php esc_html_e('Measured tokens', 'cort-ai-studio'); ?></h2>
            <p class="cort-ai-metric-value"><?php echo esc_html(number_format_i18n((int) ($metrics['tokens'] ?? 0))); ?></p>
        </section>
        <section class="cort-ai-card cort-ai-metric">
            <h2><?php esc_html_e('Estimated cost (USD)', 'cort-ai-studio'); ?></h2>
            <p class="cort-ai-metric-value"><?php echo esc_html(Usage::dollars((float) ($metrics['cost'] ?? 0))); ?></p>
            <?php if (!empty($metrics['incomplete'])) : ?>
                <p><?php esc_html_e('Some generations have incomplete usage data.', 'cort-ai-studio'); ?></p>
            <?php endif; ?>
        </section>
    </div>

    <section class="cort-ai-card">
        <div class="cort-ai-section-heading">
            <h2><?php esc_html_e('Recent generations', 'cort-ai-studio'); ?></h2>
            <?php if (!$admin) : ?><p><?php esc_html_e('Activity for your WordPress account.', 'cort-ai-studio'); ?></p><?php endif; ?>
        </div>
        <?php if (!$history) : ?>
            <p><?php esc_html_e('Generation activity will appear here.', 'cort-ai-studio'); ?></p>
        <?php else : ?>
            <div class="cort-ai-table-wrap" tabindex="0" role="region" aria-label="<?php esc_attr_e('Recent generations', 'cort-ai-studio'); ?>">
                <table class="widefat striped">
                    <thead><tr>
                        <th scope="col"><?php esc_html_e('Date', 'cort-ai-studio'); ?></th>
                        <?php if ($admin) : ?><th scope="col"><?php esc_html_e('User', 'cort-ai-studio'); ?></th><?php endif; ?>
                        <th scope="col"><?php esc_html_e('Article', 'cort-ai-studio'); ?></th>
                        <th scope="col"><?php esc_html_e('Model', 'cort-ai-studio'); ?></th>
                        <th scope="col"><?php esc_html_e('Input / output tokens', 'cort-ai-studio'); ?></th>
                        <th scope="col"><?php esc_html_e('Images', 'cort-ai-studio'); ?></th>
                        <th scope="col"><?php esc_html_e('Web calls', 'cort-ai-studio'); ?></th>
                        <th scope="col"><?php esc_html_e('Estimated USD', 'cort-ai-studio'); ?></th>
                        <th scope="col"><?php esc_html_e('Status', 'cort-ai-studio'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($history as $entry) :
                        $post_id = (int) $entry['post_id'];
                        $link = $post_id && current_user_can('edit_post', $post_id) ? get_edit_post_link($post_id) : '';
                        $user = $admin ? get_userdata((int) $entry['user_id']) : null;
                        ?>
                        <tr>
                            <td><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) strtotime($entry['created_at'] . ' UTC'))); ?></td>
                            <?php if ($admin) : ?><td><?php echo esc_html($user ? $user->display_name : (string) $entry['user_id']); ?></td><?php endif; ?>
                            <td>
                                <?php if ($link) : ?>
                                    <a href="<?php echo esc_url($link); ?>"><?php echo esc_html(get_the_title($post_id)); ?></a>
                                <?php else : ?>
                                    <span aria-hidden="true">—</span><span class="screen-reader-text"><?php esc_html_e('Unavailable', 'cort-ai-studio'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(Settings::models()[$entry['model']] ?? $entry['model']); ?></td>
                            <td><?php echo esc_html(number_format_i18n((int) $entry['input_tokens']) . ' / ' . number_format_i18n((int) $entry['output_tokens'])); ?></td>
                            <td><?php echo esc_html(number_format_i18n((int) $entry['images'])); ?></td>
                            <td><?php echo esc_html(number_format_i18n((int) $entry['web_calls'])); ?></td>
                            <td>
                                <?php echo esc_html(Usage::dollars((float) $entry['estimated_usd'])); ?>
                                <?php if ($entry['usage_incomplete']) : ?>
                                    <br><small><?php esc_html_e('Partial usage', 'cort-ai-studio'); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($statuses[$entry['status']] ?? $entry['status']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <?php do_action('cort_ai_studio_dashboard_page_builder'); ?>
</div>
