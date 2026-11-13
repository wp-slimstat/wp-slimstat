<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included by a report/admin rendering method; these are local template variables, not plugin globals.

if (!defined('ABSPATH')) {
    exit;
}

use SlimStat\Components\View;

// Notification sidebar component
?>
<div class="slimstat-notification-sidebar" aria-hidden="true">
    <div class="slimstat-notification-sidebar__menu" id="slimstat-notification-panel" role="dialog" aria-modal="true" aria-labelledby="slimstat-notification-title">
        <div class="slimstat-notification-sidebar__header">
            <div>
                <h2 class="slimstat-notification-sidebar__title" id="slimstat-notification-title"><?php esc_html_e('Notifications', 'wp-slimstat'); ?></h2>
                <div class="slimstat-notification-sidebar__actions">
                    <button type="button" class="slimstat-notification-sidebar__reload" aria-label="<?php esc_attr_e('Refresh notifications', 'wp-slimstat'); ?>">
                        <span class="screen-reader-text"><?php esc_html_e('Refresh notifications', 'wp-slimstat'); ?></span>
                    </button>
                    <button type="button" class="slimstat-notification-sidebar__close" aria-label="<?php esc_attr_e('Close notifications', 'wp-slimstat'); ?>">
                        <span class="screen-reader-text"><?php esc_html_e('Close notifications', 'wp-slimstat'); ?></span>
                    </button>
                </div>
            </div>
            <div>
                <ul class="slimstat-notification-sidebar__tabs" role="tablist">
                    <li class="slimstat-notification-sidebar__tab slimstat-notification-sidebar__tab--active"
                        data-tab="tab-1" role="tab" id="slimstat-inbox-tab" aria-controls="tab-1" aria-selected="true" tabindex="0"><?php esc_html_e('Inbox', 'wp-slimstat'); ?></li>
                    <li class="slimstat-notification-sidebar__tab"
                        data-tab="tab-2" role="tab" id="slimstat-dismissed-tab" aria-controls="tab-2" aria-selected="false" tabindex="-1"><?php esc_html_e('Dismissed', 'wp-slimstat'); ?></li>
                </ul>

                <a href="#" class="slimstat-notification-sidebar__dismiss-all" hidden><?php esc_html_e('Dismiss all', 'wp-slimstat'); ?></a>
            </div>
        </div>
        <p class="slimstat-notification-sidebar__message" role="status"></p>
        <div class="slimstat-notification-sidebar__content">
            <div class="slimstat-notification-sidebar__tab-pane slimstat-notification-sidebar__tab-pane--active" id="tab-1" role="tabpanel" aria-labelledby="slimstat-inbox-tab" aria-hidden="false">
                <div class="slimstat-notification-sidebar__cards slimstat-notification-sidebar__cards--active">
                    <?php
                    $hasNotifications = false;
                    if (!empty($notifications)) :
                        foreach ($notifications as $notification) :
                            if ($notification->getDismiss()) continue;
                            $hasNotifications = true;
                            View::load('components/notification/card', ['notification' => $notification]);
                        endforeach;
                    endif;
                    if (!$hasNotifications) {
                        View::load('components/notification/no-data', ['tab' => __('inbox', 'wp-slimstat')]);
                    }
                    ?>
                </div>
            </div>
            <div class="slimstat-notification-sidebar__tab-pane" id="tab-2" role="tabpanel" aria-labelledby="slimstat-dismissed-tab" aria-hidden="true">
                <div class="slimstat-notification-sidebar__cards slimstat-notification-sidebar__cards--dismissed">
                    <?php
                    $hasDismissed = false;
                    if (!empty($notifications)) :
                        foreach ($notifications as $notification) :
                            if (!$notification->getDismiss()) continue;
                            $hasDismissed = true;
                            View::load('components/notification/card', ['notification' => $notification]);
                        endforeach;
                    endif;
                    if (!$hasDismissed) {
                        View::load('components/notification/no-data', ['tab' => __('dismissed list', 'wp-slimstat')]);
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
    <div class="slimstat-notification-sidebar__overlay"></div>
</div>
