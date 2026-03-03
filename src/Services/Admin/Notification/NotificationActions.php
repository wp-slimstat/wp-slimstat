<?php

namespace SlimStat\Services\Admin\Notification;

use SlimStat\Utils\Request;
use SlimStat\Components\Ajax;

class NotificationActions
{
	public function register()
	{
		Ajax::registerAdmin('dismiss_notification', [$this, 'dismissNotification']);
		Ajax::registerAdmin('update_notifications_status', [$this, 'updateNotificationsStatus']);
	}

	public function dismissNotification()
	{
		\check_ajax_referer('wp_rest', 'slimstat_nonce');

		$required_cap = \wp_slimstat::$settings['capability_can_admin'] ?? 'manage_options';
		if (!\current_user_can($required_cap)) {
			\wp_send_json_error(['message' => \__('Permission denied.', 'wp-slimstat')], 403);
			exit();
		}

		$notificationId = Request::get('notification_id');

		if ($notificationId === 'all') {
			NotificationProcessor::dismissAllNotifications();
			$message = \__('All notifications have been dismissed.', 'wp-slimstat');
		} else {
			NotificationProcessor::dismissNotification($notificationId);
			$message = \__('Notification has been dismissed.', 'wp-slimstat');
		}

		\wp_send_json_success(['message' => $message]);
		exit();
	}

	public function updateNotificationsStatus()
	{
		\check_ajax_referer('wp_rest', 'slimstat_nonce');

		$required_cap = \wp_slimstat::$settings['capability_can_admin'] ?? 'manage_options';
		if (!\current_user_can($required_cap)) {
			\wp_send_json_error(['message' => \__('Permission denied.', 'wp-slimstat')], 403);
			exit();
		}

		$hasUpdatedNotifications = NotificationProcessor::updateNotificationsStatus();

		if ($hasUpdatedNotifications) {
			$message = \__('Notifications status has been updated.', 'wp-slimstat');
		} else {
			$message = \__('Notifications status has not been updated.', 'wp-slimstat');
		}

		\wp_send_json_success(['message' => $message]);
		exit();
	}
}
