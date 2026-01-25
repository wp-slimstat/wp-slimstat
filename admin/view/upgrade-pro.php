<?php
if (!function_exists('add_action')) {
    exit();
}

?>
<div class="backdrop-container">
    <?php
    // Load SlimStat Pro Modal
    wp_slimstat_admin::get_template('slimstat-pro-modal');
?>
    <div class="wrap slimstat upgrade-pro">
        <?php wp_slimstat_admin::get_template('header', ['is_pro' => wp_slimstat::pro_is_installed()]); ?>
        <img class="upgrade-pro-background" src="<?php echo esc_url(plugin_dir_url(__FILE__) . '../assets/images/pro-blur.jpg'); ?>">
    </div>
</div>