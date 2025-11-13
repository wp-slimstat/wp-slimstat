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

    <div class="slimstat-widgets-container" id="slimview_marketing">
        <div class="slimstat-widgets-row">
            <!-- Widgets will be dynamically registered here via wp_slimstat_reports::$reports -->
            <?php
            // Widget rendering will be implemented in T048-T050
            echo '<p class="slimstat-placeholder">';
            echo esc_html__('Channel widgets will appear here after widget registration is complete.', 'wp-slimstat');
            echo '</p>';
            ?>
        </div>
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
