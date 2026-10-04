<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included by a report/admin rendering method; these are local template variables, not plugin globals.
if (!defined('ABSPATH')) {
    exit;
}

$is_pro = wp_slimstat::pro_is_installed();

if (!$is_pro) {
    // Free: one inline panel, no blur and no modal (audit F1).
    ?>
    <div class="wrap-slimstat">
        <?php
        wp_slimstat_admin::get_template('header', ['is_pro' => false, 'title' => __('Email Report', 'wp-slimstat')]);
        wp_slimstat_admin::get_template('pro-adds', [
            'text'     => __('Pro sends a daily, weekly or monthly summary of your top pages, sources and goals to your team.', 'wp-slimstat'),
            'campaign' => 'email-report',
        ]);
        ?>
    </div>
    <?php
} else {
    // For premium users: show the actual email report content
    ?>
    <div class="backdrop-container">
        <div class="wrap-slimstat slimstat-email-report">
            <?php wp_slimstat_admin::get_template('header', ['is_pro' => true, 'title' => __('Email Report', 'wp-slimstat')]); ?>

            <div class="slimstat-email-report-content">
                <?php
                // Allow pro plugin to inject its email report settings here
                // The pro plugin should hook into this action to display its email report settings
                do_action('slimstat_email_report_content');
                ?>
            </div>
        </div>
    </div>
    <?php
}
?>
