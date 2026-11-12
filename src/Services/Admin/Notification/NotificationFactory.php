<?php

namespace SlimStat\Services\Admin\Notification;

class NotificationFactory
{
	public static function hasPromoNotification(): bool
	{
		foreach (self::getAllNotifications() as $notification) {
			if ($notification->isPromo()) return true;
		}
		return false;
	}

	/** A danger badge wins when several unread notices carry a label. */
	public static function getBadge(): array
	{
		$badge = [];
		foreach (self::getAllNotifications() as $notification) {
			if ($notification->getDismiss() || '' === $notification->getBadge()) continue;
			$urgent = 'slimstat-notification-sidebar__danger' === $notification->backgroundColor();
			if (!$badge || $urgent) $badge = ['label' => $notification->getBadge(), 'urgent' => $urgent, 'ends_at' => $notification->getEndsAt()];
			if ($urgent) break;
		}
		return $badge;
	}

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
