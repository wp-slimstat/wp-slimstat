<?php

namespace SlimStat\Services;

use SlimStat\Components\Event;
use SlimStat\Services\Admin\Notification\NotificationFetcher;

class CronEventManager
{
	public function __construct()
	{
		Event::schedule('slimstat_daily_cron_hook', time(), 'daily', [$this, 'handleDailyTasks']);
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
