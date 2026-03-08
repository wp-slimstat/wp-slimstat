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
		Ajax::registerAdmin('refresh_notifications', [$this, 'refreshNotifications']);
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

	public function refreshNotifications()
	{
		\check_ajax_referer('wp_rest', 'slimstat_nonce');

		$required_cap = \wp_slimstat::$settings['capability_can_admin'] ?? 'manage_options';
		if (!\current_user_can($required_cap)) {
			\wp_send_json_error(['message' => \__('Permission denied.', 'wp-slimstat')], 403);
			exit();
		}

		$fetcher = new NotificationFetcher();
		$fetcher->fetchNotification();

		$notifications = NotificationFactory::getAllNotifications();

		\ob_start();
		$this->renderNotificationCards($notifications, false);
		$inboxHtml = \ob_get_clean();

		\ob_start();
		$this->renderNotificationCards($notifications, true);
		$dismissedHtml = \ob_get_clean();

		$inboxCount = 0;
		foreach ($notifications as $notification) {
			if (!$notification->getDismiss()) {
				$inboxCount++;
			}
		}

		\wp_send_json_success([
			'inbox_html'     => $inboxHtml,
			'dismissed_html' => $dismissedHtml,
			'inbox_count'    => $inboxCount,
			'message'        => \__('Notifications refreshed.', 'wp-slimstat')
		]);
		exit();
	}

	private function renderNotificationCards($notifications, $dismissed = false)
	{
		$hasCards = false;

		foreach ($notifications as $notification) {
			if ($dismissed && !$notification->getDismiss()) {
				continue;
			}
			if (!$dismissed && $notification->getDismiss()) {
				continue;
			}
			$hasCards = true;
			\SlimStat\Components\View::load('components/notification/card', ['notification' => $notification]);
		}

		if (!$hasCards) {
			$tab = $dismissed ? \__('dismissed list', 'wp-slimstat') : \__('inbox', 'wp-slimstat');
			\SlimStat\Components\View::load('components/notification/no-data', ['tab' => $tab]);
		}
	}
}
