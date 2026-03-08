<?php

namespace SlimStat\Services\Admin\Notification;

class NotificationFactory
{
	public static function getRawNotificationsData()
	{
		return \get_option('wp_slimstat_notifications', []);
	}

	public static function getAllNotifications()
	{
		$rawNotifications = self::getRawNotificationsData();
		$notifications    = NotificationProcessor::filterNotificationsByTags($rawNotifications['data'] ?? []);

		return NotificationProcessor::decorateNotifications($notifications);
	}

	public static function hasUpdatedNotifications()
	{
		$rawNotifications = self::getRawNotificationsData();
		$notifications    = NotificationProcessor::filterNotificationsByTags($rawNotifications['data'] ?? []);

		foreach ($notifications as $notification) {
			if (empty($notification['dismiss'])) {
				return true;
			}
		}

		return false;
	}

	public static function getNewNotificationCount()
	{
		$rawNotifications = self::getRawNotificationsData();
		$notifications    = NotificationProcessor::filterNotificationsByTags($rawNotifications['data'] ?? []);

		$count = 0;

		foreach ($notifications as $notification) {
			if (empty($notification['dismiss'])) {
				$count++;
			}
		}

		return $count;
	}
}
