<?php

namespace SlimStat\Service\Admin\Notification;

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
                \error_log(
                    \sprintf(\__('No notifications were found. The API returned an empty response from the following URL: %s', 'wp-slimstat'), "{$this->apiUrl}/api/v1/notifications?plugin_slug={$pluginSlug}")
                );
            }

            $notifications = NotificationProcessor::syncNotifications($notifications);
            $notifications = NotificationProcessor::sortNotificationsByActivatedAt($notifications);

            $prevRawNotificationsData = NotificationFactory::getRawNotificationsData();

            if (!\update_option('wp_slimstat_notifications', $notifications)) {
                if ($prevRawNotificationsData !== $notifications) {
                    \error_log('Failed to update wp_slimstat_notifications option.');
                }
            }

            return true;

        } catch (Exception $e) {
            \error_log($e->getMessage());
            return false;
        }
    }
}