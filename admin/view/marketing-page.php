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
                    <div class="slimstat-header-buttons">
                        <a class="noslimstat refresh" title="<?php echo esc_attr__('Refresh', 'wp-slimstat'); ?>" href="#">
                            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M2.44215 9.33359C2.50187 5.19973 5.89666 1.875 10.0656 1.875C12.8226 1.875 15.239 3.32856 16.5777 5.50601C16.7584 5.80006 16.6666 6.18499 16.3726 6.36576C16.0785 6.54654 15.6936 6.45471 15.5128 6.16066C14.3937 4.34037 12.3735 3.125 10.0656 3.125C6.57859 3.125 3.75293 5.89808 3.69234 9.33181L4.02599 9.00077C4.27102 8.75765 4.66675 8.75921 4.90986 9.00424C5.15298 9.24928 5.15143 9.645 4.90639 9.88812L3.50655 11.277C3.26288 11.5188 2.86982 11.5188 2.62614 11.277L1.2263 9.88812C0.981267 9.645 0.979713 9.24928 1.22283 9.00424C1.46595 8.75921 1.86167 8.75765 2.10671 9.00077L2.44215 9.33359ZM16.4885 8.72215C16.732 8.4815 17.1238 8.4815 17.3672 8.72215L18.7724 10.111C19.0179 10.3537 19.0202 10.7494 18.7776 10.9949C18.5349 11.2404 18.1392 11.2427 17.8937 11.0001L17.5521 10.6624C17.4943 14.8003 14.0846 18.125 9.90191 18.125C7.13633 18.125 4.71134 16.6725 3.3675 14.4949C3.18622 14.2012 3.2774 13.8161 3.57114 13.6348C3.86489 13.4535 4.24997 13.5447 4.43125 13.8384C5.5545 15.6586 7.58316 16.875 9.90191 16.875C13.4071 16.875 16.2433 14.0976 16.302 10.6641L15.962 11.0001C15.7165 11.2427 15.3208 11.2404 15.0782 10.9949C14.8355 10.7494 14.8378 10.3537 15.0833 10.111L16.4885 8.72215Z" fill="#676E74"/></svg>
                        </a>
                    </div>
                    <h3 class="hndle">
                        <span><?php echo esc_html($report_info['title']); ?></span>
                        <?php if (!empty($report_info['tooltip'])): ?>
                            <span class="slimstat-tooltip-trigger" title="<?php echo esc_attr($report_info['tooltip']); ?>"><i class="dashicons dashicons-info-outline"></i></span>
                        <?php endif; ?>
                    </h3>
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
