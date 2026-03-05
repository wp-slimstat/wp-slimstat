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
	 * Fetch notifications on first load if the option is empty.
	 * Uses a transient lock to prevent repeated fetch attempts if API is down
	 * and to avoid race conditions on concurrent requests.
	 */
	private function maybeInitialFetch()
	{
		if ('on' !== \wp_slimstat::$settings['display_notifications']) {
			return;
		}

		$existingNotifications = NotificationFactory::getRawNotificationsData();

		if (empty($existingNotifications) || empty($existingNotifications['data'])) {
			// Prevent repeated fetch attempts if API is down and avoid race conditions
			$lastAttempt = \get_transient('slimstat_notification_fetch_lock');
			if ($lastAttempt) {
				return;
			}
			\set_transient('slimstat_notification_fetch_lock', true, 5 * MINUTE_IN_SECONDS);
			$this->fetchNotification();
		}
	}

	public function handleDailyTasks()
	{
		if ('on' === \wp_slimstat::$settings['display_notifications']) {
			$this->fetchNotification();
		}
	}

	private function fetchNotification()
	{
		$notificationFetcher = new NotificationFetcher();
		$notificationFetcher->fetchNotification();
	}
}
