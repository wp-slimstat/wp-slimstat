<?php

namespace SlimStat\Services\Admin\Notification;

use SlimStat\Decorators\NotificationDecorator;
use SlimStat\Utils\OptionClaim;
use SlimStat\Services\Admin\ConditionTagEvaluator;

class NotificationProcessor
{
	public static function filterNotificationsByTags($notifications)
	{
		$dismissed = \get_option('slimstat_notification_dismissed', []);
		if (!empty($notifications) && is_array($notifications)) {
			foreach ($notifications as $key => $notification) {
				$active = !empty($notification['activated_at']) ? strtotime($notification['activated_at']) : 0;
				$expiry = $notification['expires_at'] ?? $notification['expired_at'] ?? '';
				if ($active > time() || ($expiry && strtotime($expiry) <= time())) {
					unset($notifications[$key]);
					continue;
				}
				if (!empty($dismissed[$notification['id'] ?? 0])) $notifications[$key]['dismiss'] = true;
				if (!empty($notification['tags']) && is_array($notification['tags'])) {
					// Enforce version gating for license-related tags
					if (self::requiresVersionGate($notification['tags']) && !self::hasValidVersionGate($notification['tags'])) {
						unset($notifications[$key]);
						continue;
					}

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

	/**
	 * Check if a notification's tags include any version-gated tag.
	 *
	 * @param array $tags
	 * @return bool
	 */
	private static function requiresVersionGate(array $tags)
	{
		return !empty(\array_intersect($tags, ConditionTagEvaluator::getVersionGatedTags()));
	}

	/**
	 * Check if a notification includes a valid is-version-* tag at or above the minimum floor.
	 *
	 * Validates:
	 * 1. At least one is-version-* tag is present
	 * 2. The version string is well-formed (digits and dots only)
	 * 3. The version is >= LICENSE_TAGS_MIN_VERSION
	 *
	 * @param array $tags
	 * @return bool
	 */
	private static function hasValidVersionGate(array $tags)
	{
		foreach ($tags as $tag) {
			if (\strpos($tag, 'is-version-') !== 0) {
				continue;
			}

			$version = \substr($tag, \strlen('is-version-'));

			if (!\preg_match('/^\d+\.\d+(\.\d+)?$/', $version)) {
				continue;
			}

			if (\version_compare($version, ConditionTagEvaluator::LICENSE_TAGS_MIN_VERSION, '>=')) {
				return true;
			}
		}

		return false;
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
		$notificationId = intval($notificationId);
		// Do not overwrite a newer feed with a snapshot read before a concurrent fetch.
		return $notificationId > 0 && self::rememberDismissed([$notificationId]);
	}

	public static function dismissAllNotifications()
	{
		$notifications = NotificationFactory::getRawNotificationsData();
		return self::rememberDismissed(array_column($notifications['data'] ?? [], 'id'));
	}

	/** Keep site-wide dismissals even when a feed temporarily omits an item. */
	private static function rememberDismissed(array $ids): bool
	{
		if (!$ids) return true;
		$key = 'slimstat_notification_dismissed';
		for ($attempt = 0; $attempt < 3; $attempt++) {
			$old = \get_option($key, false);
			$new = (is_array($old) ? $old : []) + array_fill_keys($ids, true);
			if ($old === $new) return true;
			$won = false === $old ? OptionClaim::insert($key, serialize($new))
				: OptionClaim::compareAndSwap($key, serialize($old), serialize($new));
			if ($won) return true;
			\wp_cache_delete($key, 'options');
		}
		return false;
	}

	public static function syncNotifications($newNotifications)
	{
		$oldNotifications = NotificationFactory::getRawNotificationsData();

		$dismissedNotifications = \get_option('slimstat_notification_dismissed', []);

		if (!empty($oldNotifications['data']) && is_array($oldNotifications['data'])) {
			foreach ($oldNotifications['data'] as $oldNotification) {
				if (!empty($oldNotification['dismiss']) && !empty($oldNotification['id'])) {
					$dismissedNotifications[$oldNotification['id']] = true;
				}
			}
		}

		if (!self::rememberDismissed(array_keys($dismissedNotifications))) {
			throw new \RuntimeException('Could not preserve notification dismissals');
		}
		if (!empty($newNotifications['data']) && is_array($newNotifications['data'])) {
			foreach ($newNotifications['data'] as &$newNotification) {
				unset($newNotification['dismiss']);
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
				return \strtotime($b['activated_at'] ?? '') - \strtotime($a['activated_at'] ?? '');
			});
		}

		return $notifications;
	}
}
