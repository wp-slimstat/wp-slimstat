<?php

namespace SlimStat\Service\Admin\Notification;

use SlimStat\Components\Event;

class NotificationManager
{
    /**
     * NotificationManager constructor.
     *
     * Initializes hooks for AJAX callbacks, cron schedules,
     * and schedules the notification fetch event.
     */
    public function __construct()
    {
        if (\wp_slimstat::$settings['display_notifications'] == 'on') {
            add_action('admin_init', [$this, 'registerActions']);
        }
    }

    /**
     * Registers notification actions.
     *
     * @return void
     */
    public function registerActions()
    {
        $notificationActions = new NotificationActions();

        $notificationActions->register();
    }
}