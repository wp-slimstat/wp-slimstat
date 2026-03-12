<?php
if (!function_exists('add_action')) {
    exit();
}

$is_pro = wp_slimstat::pro_is_installed();

if (!$is_pro) {
    // For free users: show blurred content with modal
    ?>
    <style>
        .slimstat-pro-modal,
        .slimstat-pro-modal-backdrop {
            display: block !important;
        }
    </style>
    <div class="backdrop-container">
        <?php wp_slimstat_admin::get_template('slimstat-pro-modal'); ?>
        <div class="wrap-slimstat upgrade-pro email-report-locked">
            <?php wp_slimstat_admin::get_template('header', ['is_pro' => false]); ?>
            <img class="upgrade-pro-background" src="<?php echo esc_url(plugin_dir_url(__FILE__) . '../assets/images/email report.PNG'); ?>">
        </div>
    </div>
    <?php
} else {
    // For premium users: show the actual email report content
    ?>
    <div class="backdrop-container">
        <div class="wrap-slimstat slimstat-email-report">
            <?php wp_slimstat_admin::get_template('header', ['is_pro' => true]); ?>
            <h2><?php _e('Email Report Configuration', 'wp-slimstat'); ?></h2>

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

