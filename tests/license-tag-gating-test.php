<?php

declare(strict_types=1);

namespace {
	$assertions = 0;

	function assert_true($condition, $message)
	{
		global $assertions;
		$assertions++;

		if (!$condition) {
			fwrite(STDERR, "FAIL: {$message}\n");
			exit(1);
		}
	}

	function assert_count_is(array $items, $expectedCount, $message)
	{
		assert_true(count($items) === $expectedCount, $message . " (expected {$expectedCount}, got " . count($items) . ')');
	}

	if (!defined('SLIMSTAT_ANALYTICS_VERSION')) {
		define('SLIMSTAT_ANALYTICS_VERSION', '5.4.0');
	}

	class wp_slimstat
	{
		public static $settings = [];
		public static $proInstalled = false;

		public static function pro_is_installed()
		{
			return self::$proInstalled;
		}
	}

	$GLOBALS['slimstat_test_actions'] = [];

	function do_action($tag)
	{
		$GLOBALS['slimstat_test_actions'][] = $tag;
	}

	function current_user_can($capability)
	{
		return 'administrator' === $capability;
	}

	function is_user_logged_in()
	{
		return true;
	}

	function wp_get_current_user()
	{
		return (object) ['user_email' => 'admin@example.com'];
	}

	function get_locale()
	{
		return 'en_US';
	}

	function get_option($key)
	{
		return '';
	}
}

namespace SlimStat\Services\Admin\Notification {
	class NotificationFetcher
	{
		public static $fetchCalls = 0;

		public function fetchNotification()
		{
			self::$fetchCalls++;
		}
	}
}

namespace {
	require_once __DIR__ . '/../src/Services/Admin/ConditionTagEvaluator.php';
	require_once __DIR__ . '/../src/Services/Admin/Notification/NotificationProcessor.php';
	require_once __DIR__ . '/../src/Services/CronEventManager.php';

	use SlimStat\Services\Admin\Notification\NotificationFetcher;
	use SlimStat\Services\Admin\Notification\NotificationProcessor;
	use SlimStat\Services\CronEventManager;

	\wp_slimstat::$proInstalled = true;
	\wp_slimstat::$settings = [
		'slimstat_pro_license_key' => 'abc123',
		'slimstat_pro_license_status' => true,
	];

	$out = NotificationProcessor::filterNotificationsByTags([
		['id' => 1, 'tags' => ['is-license-active']],
	]);
	assert_count_is($out, 0, 'license tags without an is-version-* gate must be filtered');

	$out = NotificationProcessor::filterNotificationsByTags([
		['id' => 2, 'tags' => ['is-license-active', 'is-version-5.4.0']],
	]);
	assert_count_is($out, 1, 'valid is-version gate should allow license-active tag');

	$out = NotificationProcessor::filterNotificationsByTags([
		['id' => 3, 'tags' => ['is-license-active', 'is-version-foo']],
	]);
	assert_count_is($out, 0, 'malformed is-version gate should be rejected');

	$out = NotificationProcessor::filterNotificationsByTags([
		['id' => 4, 'tags' => ['is-license-active', 'is-version-5.3.9']],
	]);
	assert_count_is($out, 0, 'is-version below minimum supported version should be rejected');

	\wp_slimstat::$proInstalled = false;
	\wp_slimstat::$settings['slimstat_pro_license_status'] = false;
	$out = NotificationProcessor::filterNotificationsByTags([
		['id' => 5, 'tags' => ['is-license-inactive', 'is-version-5.4.0']],
	]);
	assert_count_is($out, 1, 'license-inactive should match when a key exists and Pro is not installed');

	\wp_slimstat::$proInstalled = true;
	$out = NotificationProcessor::filterNotificationsByTags([
		['id' => 6, 'tags' => ['is-premium']],
	]);
	assert_count_is($out, 1, 'existing non-license tags should remain unchanged');

	\wp_slimstat::$proInstalled = false;
	\wp_slimstat::$settings['slimstat_pro_license_key'] = '';
	$out = NotificationProcessor::filterNotificationsByTags([
		['id' => 7, 'tags' => ['no-license', 'is-version-5.4.0']],
	]);
	assert_count_is($out, 1, 'no-license should match when no key has ever been stored');

	\wp_slimstat::$settings['slimstat_pro_license_key'] = 'abc123';
	$out = NotificationProcessor::filterNotificationsByTags([
		['id' => 8, 'tags' => ['no-license', 'is-version-5.4.0']],
	]);
	assert_count_is($out, 0, 'no-license should not match if a license key exists');

	// Stale status=true after Pro uninstall (key edge case from design)
	\wp_slimstat::$proInstalled = false;
	\wp_slimstat::$settings['slimstat_pro_license_key'] = 'abc123';
	\wp_slimstat::$settings['slimstat_pro_license_status'] = true;
	$out = NotificationProcessor::filterNotificationsByTags([
		['id' => 9, 'tags' => ['is-license-active', 'is-version-5.4.0']],
	]);
	assert_count_is($out, 0, 'is-license-active must be false when Pro is uninstalled even with stale status=true');

	$out = NotificationProcessor::filterNotificationsByTags([
		['id' => 10, 'tags' => ['is-license-inactive', 'is-version-5.4.0']],
	]);
	assert_count_is($out, 1, 'is-license-inactive must be true for lapsed user with stale status');

	$manager = (new \ReflectionClass(CronEventManager::class))->newInstanceWithoutConstructor();

	$GLOBALS['slimstat_test_actions'] = [];
	NotificationFetcher::$fetchCalls = 0;
	\wp_slimstat::$settings['display_notifications'] = 'off';

	$manager->handleDailyTasks();

	assert_true(in_array('slimstat_daily_license_check', $GLOBALS['slimstat_test_actions'], true), 'daily license hook must fire even when notifications are off');
	assert_true(NotificationFetcher::$fetchCalls === 0, 'daily notification fetch must not run when notifications are off');

	$GLOBALS['slimstat_test_actions'] = [];
	NotificationFetcher::$fetchCalls = 0;
	\wp_slimstat::$settings['display_notifications'] = 'on';

	$manager->handleDailyTasks();

	assert_true(in_array('slimstat_daily_license_check', $GLOBALS['slimstat_test_actions'], true), 'daily license hook must fire when notifications are on');
	assert_true(NotificationFetcher::$fetchCalls === 1, 'daily notification fetch should run when notifications are on');

	global $assertions;
	echo "All {$assertions} assertions passed in license-tag-gating-test.php\n";
}
