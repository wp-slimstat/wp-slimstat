<?php

namespace SlimStat\Services\Admin\Notification;

use SlimStat\Services\Admin\HorizonFetcher;

class NotificationFetcher extends HorizonFetcher
{
	public const STATE = 'slimstat_notification_fetch_state';
	protected const LOCK = 'slimstat_notification_execution_lock';
	protected const OPTION = 'wp_slimstat_notifications';
	protected const ENDPOINT = 'notifications';
	protected const RETRY_HOOK = 'slimstat_initial_notification_fetch';
	protected const INTERVAL = HOUR_IN_SECONDS;

	public function fetchNotification($force = false)
	{
		return $this->fetch($force);
	}

	protected function prepareFeed(array $feed): array
	{
		foreach ($feed['data'] as $item) {
			if (!is_array($item) || empty($item['id']) || !is_numeric($item['id']) || !isset($item['title']) || !is_string($item['title'])) {
				throw new \RuntimeException('Malformed notification');
			}
			foreach (['description', 'icon', 'background_color', 'activated_at', 'expires_at', 'expired_at'] as $key) {
				if (isset($item[$key]) && !is_string($item[$key])) throw new \RuntimeException('Malformed notification field');
			}
			foreach (['description', 'icon'] as $key) {
				$decoded = json_decode($item[$key] ?? 'null', true);
				if (null !== $decoded && !is_string($decoded)) throw new \RuntimeException('Malformed notification content');
			}
			if (isset($item['tags']) && (!is_array($item['tags']) || count(array_filter($item['tags'], 'is_string')) !== count($item['tags']))) {
				throw new \RuntimeException('Malformed notification tags');
			}
			foreach (['primary_button', 'secondary_button'] as $key) {
				if (isset($item[$key]) && (!is_array($item[$key]) || count(array_filter($item[$key], 'is_string')) !== count($item[$key]))) {
					throw new \RuntimeException('Malformed notification button');
				}
			}
		}
		return NotificationProcessor::sortNotificationsByActivatedAt(NotificationProcessor::syncNotifications($feed));
	}
}
