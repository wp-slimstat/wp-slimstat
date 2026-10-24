<?php

namespace SlimStat\Services\Admin\Notification;

use SlimStat\Components\RemoteRequest;
use SlimStat\Utils\OptionClaim;

class NotificationFetcher
{
	public const STATE = 'slimstat_notification_fetch_state';
	private const LOCK = 'slimstat_notification_execution_lock';

	/** Daily cache; manual refresh bypasses freshness, but never the execution lock or backoff. */
	public function fetchNotification($force = false)
	{
		if ('on' !== (\wp_slimstat::$settings['display_notifications'] ?? 'on')) return false;
		$now = time();
		$state = \get_option(self::STATE, []);
		if ($now < ($state['next_attempt'] ?? 0) && (!$force || !empty($state['failures']))) {
			return empty($state['failures']);
		}
		$previous = \get_option(self::LOCK, false);
		$claim = ($now + MINUTE_IN_SECONDS) . ':' . \wp_generate_uuid4();
		if (false === $previous) {
			$won = OptionClaim::insert(self::LOCK, $claim);
		} else {
			$won = (int) $previous <= $now && OptionClaim::compareAndSwap(self::LOCK, $previous, $claim);
		}
		if (!$won) return false;

		try {
			$slug = basename(dirname(SLIMSTAT_FILE));
			$headers = ['Accept' => 'application/json', 'User-Agent' => $slug];
			if (!empty($state['etag'])) $headers['If-None-Match'] = $state['etag'];
			if (!empty($state['modified'])) $headers['If-Modified-Since'] = $state['modified'];
			$remote = new RemoteRequest('https://connect.wp-slimstat.com/api/v1/notifications', 'GET',
				['plugin_slug' => $slug, 'per_page' => 20, 'sortby' => 'activated_at-desc'],
				['timeout' => 10, 'redirection' => 2, 'limit_response_size' => 262144, 'headers' => $headers]);
			$remote->execute(false, false);
			$code = $remote->getResponseCode();
			if (304 === $code && !empty($state['last_success'])) {
				// A conditional response keeps the last valid feed and all local state.
			} elseif (200 === $code) {
				$feed = json_decode((string) $remote->getResponseBody(), true);
				if (!is_array($feed) || !isset($feed['data']) || !is_array($feed['data'])) {
					throw new \RuntimeException('Malformed notification feed');
				}
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
				$feed = NotificationProcessor::sortNotificationsByActivatedAt(NotificationProcessor::syncNotifications($feed));
				$old = NotificationFactory::getRawNotificationsData();
				if ($old !== $feed && !\update_option('wp_slimstat_notifications', $feed)) {
					throw new \RuntimeException('Could not save notification feed');
				}
				$state['etag'] = (string) \wp_remote_retrieve_header($remote->getResponse(), 'etag');
				$state['modified'] = (string) \wp_remote_retrieve_header($remote->getResponse(), 'last-modified');
			} else {
				throw new \RuntimeException('Notification service unavailable');
			}
			$state['last_success'] = $now;
			$state['next_attempt'] = $now + DAY_IN_SECONDS;
			$state['failures'] = 0;
			\update_option(self::STATE, $state, false);
			return true;
		} catch (\Throwable $error) {
			$state['failures'] = min(8, ($state['failures'] ?? 0) + 1);
			$delay = min(DAY_IN_SECONDS, 5 * MINUTE_IN_SECONDS * (2 ** ($state['failures'] - 1)));
			$state['next_attempt'] = $now + $delay;
			\update_option(self::STATE, $state, false);
			if ($state['failures'] <= 3 && !\wp_next_scheduled('slimstat_initial_notification_fetch')) {
				\wp_schedule_single_event($state['next_attempt'], 'slimstat_initial_notification_fetch');
			}
			return false;
		} finally {
			OptionClaim::delete(self::LOCK, $claim);
		}
	}
}
