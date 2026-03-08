<?php

namespace SlimStat\Services\Admin\Notification;

use SlimStat\Components\Event;

class NotificationManager
{
	public function __construct()
	{
		if (\wp_slimstat::$settings['display_notifications'] == 'on') {
			add_action('admin_init', [$this, 'registerActions']);
		}
	}

	public function registerActions()
	{
		$notificationActions = new NotificationActions();
		$notificationActions->register();
	}
}
