<!-- Header File-->

<div class="slimstat-header">
    <img src="<?php echo esc_url(plugin_dir_url(__FILE__) . '../../assets/images/white-slimstat-logo.png'); ?>" class="logo"/>

    <?php if (isset($is_pro) && !$is_pro): ?>
        <div class="vr-line"></div>
        <div class="go-pro slimstat-upgrade-pro">
            <a href="<?php echo admin_url('admin.php?page=slimpro'); ?>"><?php esc_html_e('Go PRO', 'wp-slimstat'); ?><span class="icon"></span></a>
            <p><?php esc_html_e('Upgrade to Pro to unlock more features', 'wp-slimstat'); ?></p>
        </div>
    <?php endif; ?>

    <?php if (isset($is_pro) && $is_pro): ?>
        <div class="pro-badge">
            <span class="icon"></span>
            <p><?php esc_html_e('Pro is activated!', 'wp-slimstat'); ?></p>
        </div>
    <?php endif; ?>

</div>