<?php

namespace SlimStat\Services\Admin;

use SlimStat\Components\RemoteRequest;
use SlimStat\Utils\OptionClaim;

abstract class HorizonFetcher
{
	public const STATE = '';
	protected const LOCK = '';
	protected const OPTION = '';
	protected const ENDPOINT = '';
	protected const RETRY_HOOK = '';
	protected const INTERVAL = DAY_IN_SECONDS;
	protected const TYPE = '';

	abstract protected function prepareFeed(array $feed): array;

	/** Shared bounded transport for the two Horizon feeds; manual refresh bypasses freshness, but never the execution lock or backoff. */
	public function fetch($force = false)
	{
		if ('on' !== (\wp_slimstat::$settings['display_notifications'] ?? 'on')) return false;
		$now = time();
		$state = \get_option(static::STATE, []);
		$nextAttempt = (int) ($state['next_attempt'] ?? 0);
		// An upgrade from daily to hourly freshness must not retain yesterday's daily deadline.
		if (empty($state['failures']) && !empty($state['last_success'])) {
			$nextAttempt = min($nextAttempt, (int) $state['last_success'] + static::INTERVAL);
		}
		if ($now < $nextAttempt && (!$force || !empty($state['failures']))) {
			return empty($state['failures']);
		}
		$previous = \get_option(static::LOCK, false);
		$claim = ($now + MINUTE_IN_SECONDS) . ':' . \wp_generate_uuid4();
		if (false === $previous) {
			$won = OptionClaim::insert(static::LOCK, $claim);
		} else {
			$won = (int) $previous <= $now && OptionClaim::compareAndSwap(static::LOCK, $previous, $claim);
		}
		if (!$won) return false;

		$remote = null;
		try {
			$slug = 'wp-slimstat';
			$headers = ['Accept' => 'application/json', 'User-Agent' => $slug];
			if (!empty($state['etag'])) $headers['If-None-Match'] = $state['etag'];
			if (!empty($state['modified'])) $headers['If-Modified-Since'] = $state['modified'];
			$remote = new RemoteRequest('https://connect.wp-slimstat.com/api/v1/' . static::ENDPOINT, 'GET',
				['plugin_slug' => $slug, 'per_page' => 100, 'sortby' => 'activated_at-desc', 'include_upcoming' => 1] + (static::TYPE ? ['type' => static::TYPE] : []),
				['timeout' => 10, 'redirection' => 2, 'limit_response_size' => 262144, 'headers' => $headers]);
			$remote->execute(false, false);
			$code = $remote->getResponseCode();
			if (304 === $code && !empty($state['last_success'])) {
				// A conditional response keeps the last valid feed and all local state.
			} elseif (200 === $code) {
				$feed = json_decode((string) $remote->getResponseBody(), true);
				if (!is_array($feed) || !isset($feed['data']) || !is_array($feed['data'])) {
					throw new \RuntimeException('Malformed Horizon feed');
				}
				$feed = $this->prepareFeed($feed);
				$old = \get_option(static::OPTION, []);
				if ($old !== $feed && !\update_option(static::OPTION, $feed, false)) {
					throw new \RuntimeException('Could not save Horizon feed');
				}
				$state['etag'] = (string) \wp_remote_retrieve_header($remote->getResponse(), 'etag');
				$state['modified'] = (string) \wp_remote_retrieve_header($remote->getResponse(), 'last-modified');
			} else {
				throw new \RuntimeException('Horizon service unavailable');
			}
			$state['last_success'] = $now;
			$state['next_attempt'] = $now + static::INTERVAL;
			$state['failures'] = 0;
			\update_option(static::STATE, $state, false);
			return true;
		} catch (\Throwable $error) {
			$state['failures'] = min(8, ($state['failures'] ?? 0) + 1);
			$delay = min(DAY_IN_SECONDS, 5 * MINUTE_IN_SECONDS * (2 ** ($state['failures'] - 1)));
			if ($remote && 429 === $remote->getResponseCode()) {
				$retry = (string) \wp_remote_retrieve_header($remote->getResponse(), 'retry-after');
				$retry = ctype_digit($retry) ? (int) $retry : max(0, (int) strtotime($retry) - $now);
				$delay = max($delay, min(WEEK_IN_SECONDS, $retry));
			}
			$state['next_attempt'] = $now + $delay;
			\update_option(static::STATE, $state, false);
			if ($state['failures'] <= 3 && !\wp_next_scheduled(static::RETRY_HOOK)) {
				\wp_schedule_single_event($state['next_attempt'], static::RETRY_HOOK);
			}
			return false;
		} finally {
			OptionClaim::delete(static::LOCK, $claim);
		}
	}
}
