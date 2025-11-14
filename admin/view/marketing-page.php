<?php
/**
 * Marketing page template for Traffic Channel Report.
 *
 * Displays channel widgets in a grid layout consistent with SlimStat's design.
 *
 * @package SlimStat
 * @since 5.1.0
 */

// Security check
defined('ABSPATH') || exit;
?>

<div class="wrap slimstat-page">
    <h1><?php echo esc_html__('Marketing - Traffic Channel Report', 'wp-slimstat'); ?></h1>

    <div class="slimstat-widgets-container" id="slimstat-marketing">
        <?php
        // BUG-008 fix: Render widgets using SlimStat's meta-box system
        if (class_exists('wp_slimstat_reports') && !empty(\wp_slimstat_reports::$reports)) {
            $screen_id = 'slimstat-marketing';

            // Filter widgets for this screen location
            foreach (\wp_slimstat_reports::$reports as $report_id => $report_info) {
                if (empty($report_info['locations']) || !in_array($screen_id, $report_info['locations'])) {
                    continue;
                }

                $classes = isset($report_info['classes']) ? implode(' ', $report_info['classes']) : 'normal';
                ?>
                <div class="postbox <?php echo esc_attr($classes); ?>" id="<?php echo esc_attr($report_id); ?>">
                    <h2 class="hndle">
                        <span><?php echo esc_html($report_info['title']); ?></span>
                        <?php if (!empty($report_info['tooltip'])): ?>
                            <span class="dashicons dashicons-info-outline" title="<?php echo esc_attr($report_info['tooltip']); ?>"></span>
                        <?php endif; ?>
                    </h2>
                    <div class="inside">
                        <?php
                        if (isset($report_info['callback']) && is_callable($report_info['callback'])) {
                            $args = $report_info['callback_args'] ?? [];
                            echo call_user_func($report_info['callback'], $args);
                        } else {
                            echo '<p>' . esc_html__('Widget callback not found.', 'wp-slimstat') . '</p>';
                        }
                        ?>
                    </div>
                </div>
                <?php
            }
        } else {
            echo '<p class="slimstat-placeholder">';
            echo esc_html__('No channel widgets registered. Please ensure the plugin is properly installed.', 'wp-slimstat');
            echo '</p>';
        }
        ?>
    </div>

    <div class="slimstat-marketing-help">
        <h2><?php echo esc_html__('About Traffic Channels', 'wp-slimstat'); ?></h2>
        <p><?php echo esc_html__('Traffic channels help you understand where your visitors come from:', 'wp-slimstat'); ?></p>
        <ul>
            <li><strong><?php echo esc_html__('Direct:', 'wp-slimstat'); ?></strong> <?php echo esc_html__('Visitors who typed your URL directly or used a bookmark.', 'wp-slimstat'); ?></li>
            <li><strong><?php echo esc_html__('Organic Search:', 'wp-slimstat'); ?></strong> <?php echo esc_html__('Visitors from search engines (Google, Bing, etc.).', 'wp-slimstat'); ?></li>
            <li><strong><?php echo esc_html__('Paid Search:', 'wp-slimstat'); ?></strong> <?php echo esc_html__('Visitors from paid search ads (Google Ads, Microsoft Advertising).', 'wp-slimstat'); ?></li>
            <li><strong><?php echo esc_html__('Social:', 'wp-slimstat'); ?></strong> <?php echo esc_html__('Visitors from social media platforms (Facebook, Twitter, LinkedIn).', 'wp-slimstat'); ?></li>
            <li><strong><?php echo esc_html__('Email:', 'wp-slimstat'); ?></strong> <?php echo esc_html__('Visitors from email campaigns or webmail links.', 'wp-slimstat'); ?></li>
            <li><strong><?php echo esc_html__('AI:', 'wp-slimstat'); ?></strong> <?php echo esc_html__('Visitors from AI assistants (ChatGPT, Claude, Perplexity, etc.).', 'wp-slimstat'); ?></li>
            <li><strong><?php echo esc_html__('Referral:', 'wp-slimstat'); ?></strong> <?php echo esc_html__('Visitors from other websites, blogs, or directories.', 'wp-slimstat'); ?></li>
            <li><strong><?php echo esc_html__('Other:', 'wp-slimstat'); ?></strong> <?php echo esc_html__('Visitors from unclassified sources.', 'wp-slimstat'); ?></li>
        </ul>
    </div>
</div>
