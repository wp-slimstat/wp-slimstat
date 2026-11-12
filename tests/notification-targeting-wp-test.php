<?php
/** Run with wp eval-file on a disposable database. No license-server requests are made. */
use SlimStat\Services\ProLicense;
use SlimStat\Services\Admin\ConditionTagEvaluator;
use SlimStat\Services\Admin\Notification\NotificationFactory;
use SlimStat\Services\Admin\Notification\NotificationProcessor;

if (!defined('WP_CLI') || !WP_CLI || (!preg_match('/^(test_|tests-)/', DB_NAME) && DB_NAME !== 'wordpress')) {
    throw new RuntimeException('Notification targeting tests require a disposable database.');
}
$assertions = 0;
$same = static function ($expected, $actual, $label) use (&$assertions) {
    $assertions++;
    if ($expected !== $actual) throw new RuntimeException($label . ': ' . var_export($actual, true));
};
$savedSettings = wp_slimstat::$settings;
$savedVerdict = get_option(ProLicense::OPTION, null);
$savedUser = get_current_user_id();
$key = 'notification-test-key';
$proActive = true;
$options = static function ($value) use (&$key) { return ['slimstat_pro_license_key' => $key] + (array) $value; };
$plugins = static function ($value) use (&$proActive) {
    $value = array_values(array_diff((array) $value, ['wp-slimstat-pro/wp-slimstat-pro.php']));
    if ($proActive) $value[] = 'wp-slimstat-pro/wp-slimstat-pro.php';
    return $value;
};
$http = static function () { throw new RuntimeException('Audience evaluation must not make HTTP requests.'); };
$otherDomain = static function () { return 'https://other-site.example.test'; };
$noManageOptions = static function ($caps) { $caps['manage_options'] = false; return $caps; };
$feed = ['data' => []];
$feedOption = static function () use (&$feed) { return $feed; };
$dismissals = static function () { return []; };
$audiences = [
    1 => ['no-premium'],
    2 => ['no-addon-wp-slimstat-pro'],
    3 => ['has-addon-wp-slimstat-pro', 'no-premium'],
    4 => ['has-addon-wp-slimstat-pro', 'no-license'],
    5 => ['has-addon-wp-slimstat-pro', 'is-license-invalid'],
    6 => ['is-license-active'],
    7 => ['is-license-inactive'],
    8 => ['has-addon-wp-slimstat-pro'],
    9 => ['no-license'],
    10 => ['is-free'],
    11 => ['has-addon'],
];
foreach ($audiences as $id => $tags) {
    $feed['data'][] = ['id' => $id, 'title' => 'Audience ' . $id, 'tags' => array_merge(['is-admin', 'is-version-6.1.1'], $tags)];
}
$expectAudience = static function (array $expected, string $label) use ($same, &$feed) {
    $same($expected, array_column(NotificationProcessor::filterNotificationsByTags($feed['data']), 'id'), $label . ' filtered IDs');
    $same(count($expected), NotificationFactory::getNewNotificationCount(), $label . ' bell count');
    $same(count($expected), count(NotificationFactory::getAllNotifications()), $label . ' card count');
};
try {
    add_filter('option_slimstat_options', $options);
    add_filter('option_active_plugins', $plugins);
    add_filter('pre_http_request', $http, PHP_INT_MAX);
    add_filter('pre_option_wp_slimstat_notifications', $feedOption);
    add_filter('pre_option_slimstat_notification_dismissed', $dismissals);
    wp_set_current_user(get_users(['role' => 'administrator', 'number' => 1])[0]->ID);
    ProLicense::record($key, 'valid');
    wp_slimstat::$settings['slimstat_pro_license_key'] = $key;
    wp_slimstat::$settings['slimstat_pro_license_status'] = false;
    $same(true, ConditionTagEvaluator::isLicenseActive(), 'The verified verdict wins over the obsolete false status');
    $same(false, ConditionTagEvaluator::isLicenseInactive(), 'A valid customer must not get inactive-license notices');

    // The same cached feed follows plugin/license changes without another HTTP fetch.
    $cases = [
        'Free, no key' => [false, '', 'none', [1, 2, 9, 10]],
        'Pro inactive, old valid key' => [false, 'notification-test-key', 'valid', [1, 2, 7, 10]],
        'Pro inactive, old invalid key' => [false, 'notification-test-key', 'invalid', [1, 2, 7, 10]],
        'Pro active, no key' => [true, '', 'none', [1, 3, 4, 8, 9, 10, 11]],
        'Pro active, whitespace key' => [true, '  ', 'none', [1, 3, 4, 8, 9, 10, 11]],
        'Pro active, invalid key' => [true, 'notification-test-key', 'invalid', [1, 3, 5, 7, 8, 10, 11]],
        'Pro active, site not activated' => [true, 'notification-test-key', 'domain_not_activated', [1, 3, 7, 8, 10, 11]],
        'Pro active, not yet verified' => [true, 'notification-test-key', 'unreachable', [1, 3, 7, 8, 10, 11]],
        'Pro active, valid key' => [true, 'notification-test-key', 'valid', [6, 8, 11]],
    ];
    foreach ($cases as $label => [$proActive, $key, $state, $expected]) {
        delete_option(ProLicense::OPTION);
        ProLicense::record($key, $state);
        // Deliberately stale legacy settings must not affect the canonical key or verdict.
        wp_slimstat::$settings['slimstat_pro_license_key'] = 'obsolete-key';
        wp_slimstat::$settings['slimstat_pro_license_status'] = 'valid' !== $state;
        $expectAudience($expected, $label);
    }
    $verdict = get_option(ProLicense::OPTION);
    $verdict['checked'] = time() - 13 * HOUR_IN_SECONDS;
    update_option(ProLicense::OPTION, $verdict);
    ProLicense::record($key, 'unreachable');
    $expectAudience([6, 8, 11], 'A verified customer stays licensed during outage grace');
    $verdict['checked'] = time() - ProLicense::TTL;
    update_option(ProLicense::OPTION, $verdict);
    $expectAudience([1, 3, 7, 8, 10, 11], 'Expired grace is unverified, not an invalid license');
    ProLicense::record($key, 'invalid');
    $key = 'replacement-test-key';
    $expectAudience([1, 3, 7, 8, 10, 11], 'A replacement key does not inherit the old invalid verdict');
    ProLicense::record($key, 'valid');
    $expectAudience([6, 8, 11], 'Renewal immediately removes inactive-license notices');
    add_filter('home_url', $otherDomain);
    $expectAudience([1, 3, 7, 8, 10, 11], 'A moved site cannot inherit a verdict for another domain');
    remove_filter('home_url', $otherDomain);
    $expectAudience([6, 8, 11], 'Returning to the licensed domain restores the correct audience');
    add_filter('user_has_cap', $noManageOptions);
    $expectAudience([], 'Report access alone does not grant the admin audience');
    remove_filter('user_has_cap', $noManageOptions);
    wp_set_current_user(0);
    $expectAudience([], 'Admin audiences never match signed-out users');
} finally {
    remove_filter('home_url', $otherDomain);
    remove_filter('user_has_cap', $noManageOptions);
    remove_filter('pre_option_wp_slimstat_notifications', $feedOption);
    remove_filter('pre_option_slimstat_notification_dismissed', $dismissals);
    remove_filter('pre_http_request', $http, PHP_INT_MAX);
    remove_filter('option_active_plugins', $plugins);
    remove_filter('option_slimstat_options', $options);
    if (null === $savedVerdict) delete_option(ProLicense::OPTION); else update_option(ProLicense::OPTION, $savedVerdict);
    wp_slimstat::$settings = $savedSettings;
    wp_set_current_user($savedUser);
}
WP_CLI::success("All {$assertions} notification targeting assertions passed.");
