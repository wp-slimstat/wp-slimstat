<!-- Header File-->

<div class="slimstat-header">
    <img src="<?php echo plugin_dir_url(__FILE__) . '../../assets/images/white-slimstat-logo.png'; ?>" class="logo"/>

    <?php if (isset($is_pro) && !$is_pro): ?>
        <div class="vr-line"></div>
        <div class="go-pro">
            <a target="_blank" href="<?php echo wp_slimstat_admin::SLIMSTAT_PRO_WEB; ?>"><?php _e('Go PRO', 'wp-slimstat'); ?><span class="icon"></span></a>
            <p><?php _e('Upgrade to Pro to unlock more features', 'wp-slimstat'); ?></p>
        </div>
    <?php endif; ?>

    <?php if (isset($is_pro) && $is_pro): ?>
        <div class="pro-badge">
            <span class="icon"></span>
            <p><?php _e('Pro is activated!', 'wp-slimstat'); ?></p>
        </div>
    <?php endif; ?>

</div>