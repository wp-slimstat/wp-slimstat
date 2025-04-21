<?php
if (!function_exists('add_action')) exit();

// Load header
wp_slimstat_admin::get_template('header', ['is_pro' => wp_slimstat::pro_is_installed()]);
?>
<style>
    /* Hide all non-Slimstat notices in the admin */
    .notice:not(.slimstat-notice),
    .update-nag:not(.slimstat-notice),
    .error:not(.slimstat-notice) {
        display: none !important;
    }
</style>
<div class="backdrop-container">
    <?php
    // Load SlimStat Pro Modal
    wp_slimstat_admin::get_template('slimstat-pro-modal');
    ?>
    <div class="wrap slimstat upgrade-pro">
        <img class="upgrade-pro-background" src="<?php echo esc_url(plugin_dir_url(__FILE__) . '../assets/images/pro-blur.jpg'); ?>">
    </div>
</div>