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

		// Register handler for initial fetch event
		\add_action('slimstat_initial_notification_fetch', [$this, 'handleInitialFetch']);

		// Schedule initial fetch if needed (non-blocking)
		$this->maybeScheduleInitialFetch();
	}

	/**
	 * Schedule a one-time event to fetch notifications if none exist.
	 * Uses a transient lock to prevent repeated scheduling and avoid race conditions.
	 * This defers the HTTP request to avoid blocking the current page load.
	 */
	private function maybeScheduleInitialFetch()
	{
		if ('on' !== \wp_slimstat::$settings['display_notifications']) {
			return;
		}

		$existingNotifications = NotificationFactory::getRawNotificationsData();

		if (empty($existingNotifications) || empty($existingNotifications['data'])) {
			// Prevent repeated scheduling if already scheduled or recently attempted
			if (\get_transient('slimstat_notification_fetch_lock')) {
				return;
			}
			\set_transient('slimstat_notification_fetch_lock', true, 5 * MINUTE_IN_SECONDS);
			\wp_schedule_single_event(time(), 'slimstat_initial_notification_fetch');
		}
	}

	/**
	 * Handle the initial notification fetch event.
	 */
	public function handleInitialFetch()
	{
		$this->fetchNotification();
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
