<?php

namespace SlimStat\Service;

use SlimStat\Components\Event;
use SlimStat\Service\Admin\Notification\NotificationFetcher;

class CronEventManager
{
    /**
     * CronEventManager constructor.
     */
    public function __construct()
    {
        Event::schedule('slimstat_daily_cron_hook', time(), 'daily', [$this, 'handleDailyTasks']);
    }

    /**
     * Handle daily tasks triggered by the scheduled cron event.
     *
     * Calls notification fetchers.
     */
    public function handleDailyTasks()
    {

        if (\wp_slimstat::$settings['display_notifications'] == 'on') {
            $this->fetchNotification();
        }
    }

    /**
     * Fetches new notifications.
     *
     * This method is triggered by the scheduled cron event
     * and retrieves new notifications.
     */
    private function fetchNotification()
    {
        $notificationFetcher = new NotificationFetcher();
        $notificationFetcher->fetchNotification();
    }
}