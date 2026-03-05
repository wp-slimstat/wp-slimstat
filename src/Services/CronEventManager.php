<?php

namespace SlimStat\Services;

use SlimStat\Components\Event;
use SlimStat\Services\Admin\Notification\NotificationFetcher;
use SlimStat\Services\Admin\Notification\NotificationFactory;

class CronEventManager
{
	public function __construct()
	{
		Event::schedule('slimstat_daily_cron_hook', time(), 'daily', [$this, 'handleDailyTasks']);

		// Fetch notifications immediately if none exist (first load or empty)
		$this->maybeInitialFetch();
	}

	/**
	 * Fetch notifications on first load if the option is empty
	 */
	private function maybeInitialFetch()
	{
		if (\wp_slimstat::$settings['display_notifications'] != 'on') {
			return;
		}

		$existingNotifications = NotificationFactory::getRawNotificationsData();

		if (empty($existingNotifications) || empty($existingNotifications['data'])) {
			$this->fetchNotification();
		}
	}

	public function handleDailyTasks()
	{
		if (\wp_slimstat::$settings['display_notifications'] == 'on') {
			$this->fetchNotification();
		}
	}

	private function fetchNotification()
	{
		$notificationFetcher = new NotificationFetcher();
		$notificationFetcher->fetchNotification();
	}
}
