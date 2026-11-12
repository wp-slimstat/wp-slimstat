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
		define('SLIMSTAT_ANALYTICS_VERSION', '6.1.1');
	}

	class wp_slimstat
	{
		public static $settings = [];
		public static $proInstalled = false;

		public static function pro_is_installed()
		{
			return self::$proInstalled && 'valid' === \SlimStat\Services\ProLicense::state();
		}

		public static function pro_plugin_active()
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
		return 'manage_options' === $capability;
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

	function get_option($_key)
	{
		return '';
	}
}

namespace SlimStat\Services {
    class ProLicense
    {
        public static $verdict = 'valid';
        public static function key(): string { return trim(\wp_slimstat::$settings['slimstat_pro_license_key'] ?? ''); }
        public static function state(): string { return '' === self::key() ? 'none' : self::$verdict; }
    }
}

namespace SlimStat\Services\Admin {
    class MarketingCampaign
    {
        public static $fetchCalls = 0;
        public function fetch() { self::$fetchCalls++; }
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
	require_once __DIR__ . '/../src/Services/Admin/FeedWindow.php';
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

	$modernTags = ['is-free', 'has-addon', 'has-addon-wp-slimstat-pro', 'no-addon-wp-slimstat-pro', 'is-license-invalid'];
	foreach ($modernTags as $tag) {
		foreach ([[], ['is-version-5.4.0'], ['is-version-6.1.0'], ['is-version-bad']] as $gate) {
			assert_count_is(NotificationProcessor::filterNotificationsByTags([['id' => 20, 'tags' => array_merge([$tag], $gate)]]), 0, $tag . ' requires its introduction version');
		}
	}
	foreach ([['unknown-audience'], ['is-version-'], ['is-version-garbage'], ['is-version-99.0'], ['is-locale-'], [null], [[]], [42], 'is-admin'] as $tags) {
		assert_count_is(NotificationProcessor::filterNotificationsByTags([['id' => 21, 'tags' => $tags], ['id' => 22]]), 1, 'Unsupported or malformed tags hide only their own notification');
	}
	assert_count_is(NotificationProcessor::filterNotificationsByTags([['id' => 23, 'tags' => ['no-premium', 'is-premium']]]), 0, 'Tags combine with AND, not OR');
	\wp_slimstat::$proInstalled = true;
	\SlimStat\Services\ProLicense::$verdict = 'invalid';
	assert_count_is(NotificationProcessor::filterNotificationsByTags([['id' => 24, 'tags' => ['has-addon-wp-slimstat-pro', 'is-license-invalid', 'is-version-6.1.1']]]), version_compare(SLIMSTAT_ANALYTICS_VERSION, '6.1.1', '>=') ? 1 : 0, 'New audiences require a current plugin as well as a valid version tag');

	// Bypass constructor to avoid Event::schedule and add_action side-effects
	// that require WordPress globals not available in this isolated test.
	$manager = (new \ReflectionClass(CronEventManager::class))->newInstanceWithoutConstructor();

	$GLOBALS['slimstat_test_actions'] = [];
	NotificationFetcher::$fetchCalls = 0;
	\wp_slimstat::$settings['display_notifications'] = 'off';

	$manager->handleDailyTasks();

	assert_true(in_array('slimstat_daily_license_check', $GLOBALS['slimstat_test_actions'], true), 'daily license hook must fire even when notifications are off');
	assert_true(NotificationFetcher::$fetchCalls === 0, 'daily notification fetch must not run when notifications are off');
	assert_true(\SlimStat\Services\Admin\MarketingCampaign::$fetchCalls === 0, 'campaign fetch respects opt-out');

	$GLOBALS['slimstat_test_actions'] = [];
	NotificationFetcher::$fetchCalls = 0;
	\wp_slimstat::$settings['display_notifications'] = 'on';

	$manager->handleDailyTasks();

	assert_true(in_array('slimstat_daily_license_check', $GLOBALS['slimstat_test_actions'], true), 'daily license hook must fire when notifications are on');
	assert_true(NotificationFetcher::$fetchCalls === 1, 'daily notification fetch should run when notifications are on');
	assert_true(\SlimStat\Services\Admin\MarketingCampaign::$fetchCalls === 1, 'campaign fetch runs daily');

	global $assertions;
	echo "All {$assertions} assertions passed in license-tag-gating-test.php\n";
}
