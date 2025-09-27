<?php
// Notification sidebar component
?>
<div class="slimstat-notification-sidebar">
    <div class="slimstat-notification-sidebar__menu">
        <div class="slimstat-notification-sidebar__header">
            <div>
                <h2 class="slimstat-notification-sidebar__title"><?php esc_html_e('Notifications', 'wp-slimstat'); ?></h2>
                <span class="slimstat-notification-sidebar__close"></span>
            </div>
            <div>
                <ul class="slimstat-notification-sidebar__tabs">
                    <li class="slimstat-notification-sidebar__tab slimstat-notification-sidebar__tab--active"
                        data-tab="tab-1"><?php esc_html_e('Inbox', 'wp-slimstat'); ?></li>
                    <li class="slimstat-notification-sidebar__tab"
                        data-tab="tab-2"><?php esc_html_e('Dismissed', 'wp-slimstat'); ?></li>
                </ul>

                <?php if (!empty($notifications)) : ?>
                    <?php
                    $hasNotifications = false;
                    foreach ($notifications as $notification) {
                        if (!$notification->getDismiss()) {
                            $hasNotifications = true;
                            break;
                        }
                    }
                    ?>
                    <?php if ($hasNotifications) : ?>
                        <a href="#"
                           class="slimstat-notification-sidebar__dismiss-all"><?php esc_html_e('Dismiss all', 'wp-slimstat'); ?></a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="slimstat-notification-sidebar__content">
            <div class="slimstat-notification-sidebar__tab-pane slimstat-notification-sidebar__tab-pane--active" id="tab-1">
                <div class="slimstat-notification-sidebar__cards slimstat-notification-sidebar__cards--active">
                    <?php
                    $hasNotifications = false;
                    if (!empty($notifications)) :
                        foreach ($notifications as $notification) :
                            if ($notification->getDismiss()) continue;
                            $hasNotifications = true;
                            include __DIR__ . '/card.php';
                        endforeach;
                    endif;
                    if (!$hasNotifications) {
                        $tab = __('inbox', 'wp-slimstat');
                        include __DIR__ . '/no-data.php';
                    }
                    ?>
                </div>
            </div>
            <div class="slimstat-notification-sidebar__tab-pane" id="tab-2">
                <div class="slimstat-notification-sidebar__cards slimstat-notification-sidebar__cards--dismissed">
                    <?php
                    $hasDismissed = false;
                    if (!empty($notifications)) :
                        foreach ($notifications as $notification) :
                            if (!$notification->getDismiss()) continue;
                            $hasDismissed = true;
                            include __DIR__ . '/card.php';
                        endforeach;
                    endif;
                    if (!$hasDismissed) {
                        $tab = __('dismissed list', 'wp-slimstat');
                        include __DIR__ . '/no-data.php';
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
    <div class="slimstat-notification-sidebar__overlay"></div>
</div>
