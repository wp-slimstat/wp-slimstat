<?php
if (!function_exists('add_action')) exit();

// Load header
wp_slimstat_admin::get_template('header', ['is_pro' => wp_slimstat::pro_is_installed()]);
?>

<div class="wrap slimstat slimstat-layout">
    <img class="upgrade-pro-background" src="<?php echo esc_url(plugin_dir_url(__FILE__) . '../assets/images/pro-blur.jpg'); ?>">
</div>

<!-- Include JS code to open modal -->
<script type="text/javascript">
    jQuery(document).ready(function ($) {
        // Simulate click event on .slimstat-upgrade-pro element
        $('.slimstat-upgrade-pro').trigger('click');
    });
</script>