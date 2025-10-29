<?php

namespace SlimStat\Services\Admin\Notification;

use SlimStat\Decorators\NotificationDecorator;
use SlimStat\Services\Admin\ConditionTagEvaluator;

class NotificationProcessor
{
	public static function filterNotificationsByTags($notifications)
	{
		if (!empty($notifications) && is_array($notifications)) {
			foreach ($notifications as $key => $notification) {
				if (!empty($notification['tags']) && is_array($notification['tags'])) {
					$condition = true;
					foreach ($notification['tags'] as $tag) {
						if (!ConditionTagEvaluator::checkConditions($tag)) {
							$condition = false;
							break;
						}
					}
				} else {
					$condition = true;
				}

				if (!$condition) {
					unset($notifications[$key]);
				}
			}

			$notifications = \array_values($notifications);
		}

		return $notifications;
	}

	public static function decorateNotifications($notifications)
	{
		if (empty($notifications) || !is_array($notifications)) {
			return [];
		}

		return \array_map(function ($notification) {
			return new NotificationDecorator((object) $notification);
		}, $notifications);
	}

	public static function dismissNotification($notificationId)
	{
		$notifications  = NotificationFactory::getRawNotificationsData();
		$notificationId = intval($notificationId);

		if (!empty($notifications['data']) && is_array($notifications['data'])) {
			foreach ($notifications['data'] as &$notification) {
				if ($notificationId === $notification['id']) {
					$notification['dismiss'] = true;
					break;
				}
			}

			\update_option('wp_slimstat_notifications', $notifications);
		}

		return true;
	}

	public static function dismissAllNotifications()
	{
		$notifications = NotificationFactory::getRawNotificationsData();

		if (!empty($notifications['data']) && is_array($notifications['data'])) {
			foreach ($notifications['data'] as &$notification) {
				$notification['dismiss'] = true;
			}

			\update_option('wp_slimstat_notifications', $notifications);
		}

		return true;
	}

	public static function syncNotifications($newNotifications)
	{
		$oldNotifications = NotificationFactory::getRawNotificationsData();

		$dismissedNotifications = [];

		if (!empty($oldNotifications['data']) && is_array($oldNotifications['data'])) {
			foreach ($oldNotifications['data'] as $oldNotification) {
				if (!empty($oldNotification['dismiss']) && !empty($oldNotification['id'])) {
					$dismissedNotifications[$oldNotification['id']] = true;
				}
			}
		}

		if (!empty($newNotifications['data']) && is_array($newNotifications['data'])) {
			foreach ($newNotifications['data'] as &$newNotification) {
				if (isset($dismissedNotifications[$newNotification['id']])) {
					$newNotification['dismiss'] = true;
				}
			}
		}

		return $newNotifications;
	}

	public static function checkUpdatedNotifications($rawNewNotifications)
	{
		$rawOldNotifications = NotificationFactory::getRawNotificationsData();
		$oldNotifications    = self::filterNotificationsByTags($rawOldNotifications['data'] ?? []);
		$oldNotificationIds  = [];

		foreach ($oldNotifications as $oldNotification) {
			if (!empty($oldNotification['id'])) {
				$oldNotificationIds[$oldNotification['id']] = true;
			}
		}

		$newNotifications               = self::filterNotificationsByTags($rawNewNotifications['data'] ?? []);
		$rawNewNotifications['updated'] = $rawOldNotifications['updated'] ?? false;

		if (!$rawNewNotifications['updated']) {
			foreach ($newNotifications as $newNotification) {
				if (!empty($newNotification['id']) && !isset($oldNotificationIds[$newNotification['id']])) {
					$rawNewNotifications['updated'] = true;
					break;
				}
			}
		}

		return $rawNewNotifications;
	}

	public static function annotateNewNotificationCount($rawNewNotifications)
	{
		$rawOldNotifications = NotificationFactory::getRawNotificationsData();
		$oldNotifications    = self::filterNotificationsByTags($rawOldNotifications['data'] ?? []);
		$oldNotificationIds  = [];

		foreach ($oldNotifications as $oldNotification) {
			if (!empty($oldNotification['id'])) {
				$oldNotificationIds[$oldNotification['id']] = true;
			}
		}

		$newNotifications             = self::filterNotificationsByTags($rawNewNotifications['data'] ?? []);
		$updated                      = $rawNewNotifications['updated'] ?? false;
		$rawNewNotifications['count'] = $rawOldNotifications['count'] ?? 0;

		if ($updated) {
			$newCount = 0;

			foreach ($newNotifications as $newNotification) {
				if (!empty($newNotification['id']) && !isset($oldNotificationIds[$newNotification['id']])) {
					$newCount++;
				}
			}

			$rawNewNotifications['count'] += $newCount;
		} else {
			$rawNewNotifications['count'] = 0;
		}

		return $rawNewNotifications;
	}

	public static function updateNotificationsStatus()
	{
		$notifications = NotificationFactory::getRawNotificationsData();

		if (!$notifications) {
			return false;
		}

		if (isset($notifications['updated']) && !empty($notifications['updated'])) {
			$notifications['updated'] = false;

			\update_option('wp_slimstat_notifications', $notifications);

			return true;
		}

		return false;
	}

	public static function sortNotificationsByActivatedAt($notifications)
	{
		if (!empty($notifications['data']) && is_array($notifications['data'])) {
			\usort($notifications['data'], function ($a, $b) {
				return \strtotime($b['activated_at']) - \strtotime($a['activated_at']);
			});
		}

		return $notifications;
	}
}
