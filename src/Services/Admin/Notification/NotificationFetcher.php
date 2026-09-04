<?php

namespace SlimStat\Services\Admin\Notification;

use Exception;
use SlimStat\Components\RemoteRequest;

class NotificationFetcher
{
	private $apiUrl = 'https://connect.wp-slimstat.com';

	public function fetchNotification()
	{
		try {
			$pluginSlug = basename(dirname(SLIMSTAT_FILE));
			$url        = $this->apiUrl . '/api/v1/notifications';
			$method     = 'GET';
			$params     = ['plugin_slug' => $pluginSlug, 'per_page' => 20, 'sortby' => 'activated_at-desc'];
			$args       = [
				'timeout'     => 45,
				'redirection' => 5,
				'headers'     => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json; charset=utf-8',
					'user-agent'   => $pluginSlug,
				),
				'cookies'     => array(),
			];

			$remoteRequest = new RemoteRequest($url, $method, $params, $args);
			$remoteRequest->execute(false, false);
			$response     = $remoteRequest->getResponseBody();
			$responseCode = $remoteRequest->getResponseCode();

			if ($responseCode !== 200) {
				return false;
			}

			$notifications = \json_decode($response, true);

			if (empty($notifications) || !\is_array($notifications)) {
				// Untranslated on purpose: a log line is not user-facing copy, and the URL
				// it carries has no business in a production debug.log. The helper owns the
				// WP_DEBUG guard and the [WP SLIMSTAT] prefix.
				\wp_slimstat::log("no notifications returned by {$this->apiUrl}/api/v1/notifications?plugin_slug={$pluginSlug}", 'error');
			}

			$notifications = NotificationProcessor::syncNotifications($notifications);
			$notifications = NotificationProcessor::sortNotificationsByActivatedAt($notifications);

			$prevRawNotificationsData = NotificationFactory::getRawNotificationsData();

			// update_option() returns false both for "write failed" and for "value did not
			// change", so the second clause is what separates them.
			if (!\update_option('wp_slimstat_notifications', $notifications) && $prevRawNotificationsData !== $notifications) {
				\wp_slimstat::log('failed to update the wp_slimstat_notifications option', 'error');
			}

			return true;

		} catch (Exception $e) {
			\wp_slimstat::log('notification fetch failed — ' . $e->getMessage(), 'error');
			return false;
		}
	}
}
