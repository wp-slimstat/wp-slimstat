<!-- Header File-->
<?php

use SlimStat\Components\View;

$hasUpdatedNotifications = false;
$displayNotifications    = (wp_slimstat::$settings['display_notifications'] == 'on') ? true : false;

// Check if notification classes are available
if ($displayNotifications && class_exists('SlimStat\\Services\\Admin\\Notification\\NotificationFactory')) {
    $hasUpdatedNotifications = \SlimStat\Services\Admin\Notification\NotificationFactory::hasUpdatedNotifications();
}
?>

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

    <?php if ($displayNotifications): ?>
        <div class="slimstat-header-notifications">
            <a href="#" title="<?php esc_html_e('Notifications', 'wp-slimstat'); ?>" class="slimstat-notifications js-slimstat-open-notification <?php echo $hasUpdatedNotifications ? esc_attr('slimstat-notifications--has-items') : ''; ?>">
                <span class="dashicons dashicons-bell"></span>
                <?php if ($hasUpdatedNotifications): ?>
                    <span class="notification-badge"></span>
                <?php endif; ?>
            </a>
        </div>
    <?php endif; ?>

</div>

<?php
if ($displayNotifications && class_exists('SlimStat\\Services\\Admin\\Notification\\NotificationFactory')) {
    $notifications = \SlimStat\Services\Admin\Notification\NotificationFactory::getAllNotifications();
    View::load('components/notification/side-bar', ['notifications' => $notifications]);
}
?>