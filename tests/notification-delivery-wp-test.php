<?php
/** Run with wp eval-file on a disposable test_* database; never changes a real site's feed. */
namespace SlimStat\Services\Admin\Notification;

function time() { return $GLOBALS['notification_test_now'] ?? \time(); }

use SlimStat\Services\CronEventManager;
use SlimStat\Utils\OptionClaim;

if (strpos(DB_NAME, 'test_') !== 0 && DB_NAME !== 'wordpress') {
    throw new \RuntimeException('Notification fixtures require a disposable database.');
}
$same = static function ($expected, $actual, $label) {
    if ($expected !== $actual) {
        throw new \RuntimeException($label . ': ' . var_export($actual, true));
    }
};
$same(true, (bool) has_action('slimstat_daily_cron_hook'), 'Cron callback exists without an authenticated admin');
$same(true, (bool) has_action('slimstat_initial_notification_fetch'), 'Initial callback exists in cron');
$saved = \wp_slimstat::$settings;
$options = ['wp_slimstat_notifications', 'slimstat_notification_fetch_state', 'slimstat_notification_execution_lock', 'slimstat_notification_dismissed'];
$before = [];
foreach ($options as $key) { $before[$key] = get_option($key); delete_option($key); }
$GLOBALS['notification_test_now'] = 1800000000;
$requests = 0;
$payload = ['data' => [['id' => 987654, 'title' => 'Fixture', 'activated_at' => '2020-01-01', 'description' => '"Fixture"', 'icon' => 'null']]];
$responseCode = 200;
$duringFetch = null;
$headers = [];
$http = static function ($pre, $args, $url) use (&$requests, &$payload, &$responseCode, &$duringFetch, &$headers, $same) {
    if (strpos($url, 'connect.wp-slimstat.com/api/v1/notifications') === false) return $pre;
    $requests++;
    $headers = $args['headers'];
    $same(true, $args['timeout'] <= 10, 'Bounded timeout');
    $same(true, $args['limit_response_size'] <= 262144, 'Bounded response size');
    if ($duringFetch) { $callback = $duringFetch; $duringFetch = null; $callback(); }
    return ['response' => ['code' => $responseCode], 'body' => is_string($payload) ? $payload : wp_json_encode($payload), 'headers' => ['etag' => 'fixture-v1']];
};
add_filter('pre_http_request', $http, 10, 3);
try {
    \wp_slimstat::$settings['display_notifications'] = 'on';
    // Simulate a separate worker inserting after our cached option miss.
    $race = static function ($sql) use (&$race) {
        global $wpdb;
        if (strpos($sql, "INSERT INTO `{$wpdb->options}`") === 0 && strpos($sql, "'slimstat_notification_dismissed'") !== false) {
            remove_filter('query', $race);
            $wpdb->insert($wpdb->options, ['option_name'=>'slimstat_notification_dismissed', 'option_value'=>serialize([987650=>true]), 'autoload'=>'no']);
        }
        return $sql;
    };
    add_filter('query', $race);
    $same(true, NotificationProcessor::dismissNotification(987651), 'First dismissal retries a competing insert despite cached absence');
    $same([987650=>true,987651=>true], get_option('slimstat_notification_dismissed'), 'Concurrent first dismissals are both preserved');
    remove_filter('query', $race);
    $next = wp_next_scheduled('slimstat_daily_cron_hook');
    wp_schedule_event($next + 1000, 'daily', 'slimstat_daily_cron_hook');
    remove_all_actions('slimstat_daily_cron_hook'); remove_all_actions('slimstat_initial_notification_fetch');
    new CronEventManager();
    $jobs = 0;
    foreach (_get_cron_array() as $events) $jobs += count($events['slimstat_daily_cron_hook'] ?? []);
    $same(1, $jobs, 'Duplicate daily schedules repaired');
    $same($next, wp_next_scheduled('slimstat_daily_cron_hook'), 'Repair preserves next due time');
    wp_clear_scheduled_hook('slimstat_daily_cron_hook');
    remove_all_actions('slimstat_daily_cron_hook'); remove_all_actions('slimstat_initial_notification_fetch');
    new CronEventManager();
    $same(true, (bool) wp_next_scheduled('slimstat_daily_cron_hook'), 'Missing daily schedule repaired');
    $fetcher = new NotificationFetcher();
    do_action('slimstat_initial_notification_fetch');
    $same(1, $requests, 'Automatic initial fetch');
    $same(1, NotificationFactory::getNewNotificationCount(), 'Automatic unread badge');
    $same(true, NotificationFactory::hasUpdatedNotifications(), 'Header uses the same state');
    $started = microtime(true); $queries = $GLOBALS['wpdb']->num_queries;
    NotificationFactory::getAllNotifications();
    $renderMetrics = ['ms' => round((microtime(true) - $started) * 1000, 3), 'queries' => $GLOBALS['wpdb']->num_queries - $queries, 'http' => $requests - 1];
    $same(1, $requests, 'Rendering uses only local data');
    $same(true, $fetcher->fetchNotification(), 'Warm cache succeeds');
    $same(1, $requests, 'Warm cache makes no request');
    $same(0, did_action('update_option_wp_slimstat_notifications'), 'Initial insert only');
    $same(true, $fetcher->fetchNotification(true), 'Manual unchanged payload');
    $same(0, did_action('update_option_wp_slimstat_notifications'), 'Unchanged payload is not written');
    $duringFetch = static function () use ($fetcher, $same) {
        $same(false, $fetcher->fetchNotification(true), 'Concurrent worker loses the execution claim');
        NotificationProcessor::dismissNotification(987654);
    };
    $same(true, $fetcher->fetchNotification(true), 'Owner finishes after concurrent dismissal');
    $same(3, $requests, 'Concurrent fetch made no second request');
    $same(0, NotificationFactory::getNewNotificationCount(), 'Dismissal survives in-flight fetch');
    wp_set_current_user(2);
    $same(0, NotificationFactory::getNewNotificationCount(), 'Existing dismissal scope remains site-wide');
    $payload = ['data' => []];
    $same(true, $fetcher->fetchNotification(true), 'Empty feed is successful');
    wp_clear_scheduled_hook('slimstat_initial_notification_fetch');
    remove_all_actions('slimstat_daily_cron_hook'); remove_all_actions('slimstat_initial_notification_fetch');
    new CronEventManager();
    $same(false, wp_next_scheduled('slimstat_initial_notification_fetch'), 'Empty successful feed does not reschedule initialization');
    $payload = ['data' => [['id' => 987654, 'title' => 'Fixture', 'activated_at' => '2020-01-01']]];
    $same(true, $fetcher->fetchNotification(true), 'Previously dismissed item returns');
    $same(0, NotificationFactory::getNewNotificationCount(), 'Returning item stays dismissed');
    $responseCode = 304;
    $same(true, $fetcher->fetchNotification(true), 'Conditional unchanged feed');
    $same('fixture-v1', $headers['If-None-Match'], 'Conditional request reuses ETag');
    $same(0, NotificationFactory::getNewNotificationCount(), 'Conditional response preserves dismissal');
    $responseCode = 200;
    $payload['data'][] = ['id' => 987655, 'title' => 'New', 'activated_at' => '2020-01-01'];
    $GLOBALS['notification_test_now'] += DAY_IN_SECONDS - HOUR_IN_SECONDS;
    $started = microtime(true); $queries = $GLOBALS['wpdb']->num_queries; $attempts = $requests;
    do_action('slimstat_daily_cron_hook');
    $cronMetrics = ['ms' => round((microtime(true) - $started) * 1000, 3), 'queries' => $GLOBALS['wpdb']->num_queries - $queries, 'http' => $requests - $attempts];
    $same(1, NotificationFactory::getNewNotificationCount(), 'A due daily check fetches even when yesterday ran an hour late');
    $valid = get_option('wp_slimstat_notifications');
    foreach (['invalid-json', ['unexpected' => []], ['data' => [['id' => []]]], ['data' => [['id'=>9,'title'=>'Bad','background_color'=>[]]]], ['data' => [['id'=>9,'title'=>'Bad','description'=>'[1,2]']]]] as $bad) {
        $payload = $bad;
        $GLOBALS['notification_test_now'] += DAY_IN_SECONDS;
        $same(false, $fetcher->fetchNotification(true), 'Malformed feed fails');
        $same($valid, get_option('wp_slimstat_notifications'), 'Failure retains last valid feed');
        $same(false, get_option('slimstat_notification_execution_lock'), 'Failure releases claim');
    }
    $responseCode = 429;
    $GLOBALS['notification_test_now'] += DAY_IN_SECONDS;
    $same(false, $fetcher->fetchNotification(true), 'Rate limit fails');
    $attempts = $requests;
    $same(false, $fetcher->fetchNotification(true), 'Manual requests respect failure backoff');
    $same($attempts, $requests, 'Backoff bounds requests');
    $responseCode = 200; $payload = $valid;
    $GLOBALS['notification_test_now'] += DAY_IN_SECONDS;
    update_option('slimstat_notification_execution_lock', '1:abandoned', false);
    $same(true, $fetcher->fetchNotification(true), 'Expired claim recovers');
    $valid['data'][] = ['id' => 987656, 'title' => 'Expired', 'expires_at' => '2020-01-01'];
    $valid['data'][] = ['id' => 987657, 'title' => 'Future', 'activated_at' => '2099-01-01'];
    update_option('wp_slimstat_notifications', $valid);
    $same(1, NotificationFactory::getNewNotificationCount(), 'Expiry and activation re-evaluated from cached data');
    \wp_slimstat::$settings['display_notifications'] = 'off';
    $attempts = $requests;
    do_action('slimstat_initial_notification_fetch'); do_action('slimstat_daily_cron_hook');
    $same($attempts, $requests, 'Opt-out prevents both scheduled fetch paths');
    echo "PASS: automatic delivery, cache, empty feed, unchanged writes, scope, concurrency, expiry, backoff and recovery\n";
    echo wp_json_encode(['cached_render'=>$renderMetrics, 'due_job_mocked_http'=>$cronMetrics]) . "\n";
} finally {
    if (isset($race)) remove_filter('query', $race);
    remove_filter('pre_http_request', $http, 10);
    \wp_slimstat::$settings = $saved;
    foreach ($before as $key => $value) { $value === false ? delete_option($key) : update_option($key, $value); }
    wp_clear_scheduled_hook('slimstat_initial_notification_fetch');
    unset($GLOBALS['notification_test_now']);
}
