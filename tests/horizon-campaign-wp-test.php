<?php
/** Exercise both real option caches and HTTP transport on a disposable WordPress site. */
namespace SlimStat\Services\Admin;

use SlimStat\Services\Admin\Notification\NotificationFetcher;
use SlimStat\Services\Admin\Notification\NotificationFactory;
use SlimStat\Services\Admin\Notification\NotificationProcessor;
use SlimStat\Components\View;

function time() { return $GLOBALS['horizon_test_now'] ?? \time(); }

if (!defined('WP_CLI') || !WP_CLI || (!preg_match('/^(test_|tests-)/', DB_NAME) && DB_NAME !== 'wordpress')) {
    throw new \RuntimeException('Horizon fixtures require a disposable database.');
}
$same = static function ($expected, $actual, $label) {
    if ($expected !== $actual) throw new \RuntimeException($label . ': ' . var_export($actual, true));
};
$options = ['wp_slimstat_notifications', NotificationFetcher::STATE, 'slimstat_notification_execution_lock', 'slimstat_notification_dismissed', 'wp_slimstat_marketing_campaigns', MarketingCampaign::STATE, 'slimstat_marketing_campaign_execution_lock', 'cron'];
$before = [];
foreach ($options as $key) { $before[$key] = get_option($key); delete_option($key); }
$settings = \wp_slimstat::$settings;
$user = get_current_user_id();
$GLOBALS['horizon_test_now'] = strtotime('2026-11-20T00:00:00Z');
$notice = ['id' => 876501, 'title' => 'Black Friday: 30% off SlimStat Pro', 'icon' => '"🏷️"', 'description' => '"<p>30% off your first payment.</p>"', 'background_color' => 'promo', 'extra_data' => ['badge' => '🔥 -30%', 'code' => 'BFCM2026'], 'primary_button' => ['title' => 'Get the deal', 'url' => 'https://example.test/pricing/'], 'secondary_button' => ['title' => 'Must not appear', 'url' => 'https://example.test/'], 'tags' => ['is-admin', 'no-premium'], 'starts_at' => '2026-11-20T00:00:00Z', 'ends_at' => '2026-12-01T23:59:00Z'];
$campaign = ['id' => 876601, 'type' => 'promo_banner', 'starts_at' => $notice['starts_at'], 'ends_at' => $notice['ends_at'], 'tags' => $notice['tags'], 'data' => ['title' => '🔥 30% off SlimStat Pro', 'url' => 'https://example.test/pricing/', 'icon' => '"🏷️"', 'tooltip' => '30% off the first payment', 'background_color' => 'danger', 'text_color' => 'light']];
$feeds = ['notifications' => ['data' => [$notice]], 'marketing-campaigns' => ['data' => [$campaign]]];
$requests = []; $status = 200; $retry = '7200'; $during = null;
$http = static function ($pre, $args, $url) use (&$requests, &$feeds, &$status, &$retry, &$during, $same) {
    if (strpos($url, 'https://connect.wp-slimstat.com/api/v1/') !== 0) return $pre;
    $endpoint = basename(parse_url($url, PHP_URL_PATH));
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    $same('wp-slimstat', $query['plugin_slug'], 'Only the Free slug is sent');
    $same('1', $query['include_upcoming'], 'Upcoming data is requested');
    if ('marketing-campaigns' === $endpoint) $same('promo_banner', $query['type'], 'Only banners requested');
    $requests[] = $endpoint;
    if ($during) { $callback = $during; $during = null; $callback(); }
    return ['response' => ['code' => $status], 'body' => wp_json_encode($feeds[$endpoint]), 'headers' => ['etag' => 'horizon-fixture', 'retry-after' => $retry]];
};
add_filter('pre_http_request', $http, 10, 3);
try {
    wp_set_current_user(get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0]);
    \wp_slimstat::$settings['display_notifications'] = 'on';
    $fetcher = new NotificationFetcher(); $marketing = new MarketingCampaign();
    $same(true, $fetcher->fetchNotification(), 'Fetch promo notification');
    $same(true, $marketing->fetch(), 'Fetch promo banner');
    $same(3600, get_option(NotificationFetcher::STATE)['next_attempt'] - time(), 'Hourly notification freshness');
    $same(3600, get_option(MarketingCampaign::STATE)['next_attempt'] - time(), 'Hourly banner freshness');
    update_option(NotificationFetcher::STATE, ['last_success' => time() - 7200, 'next_attempt' => time() + 79200, 'failures' => 0], false);
    $beforeUpgrade = count($requests);
    $same(true, $fetcher->fetchNotification(), 'An old daily cache adopts hourly freshness');
    $same($beforeUpgrade + 1, count($requests), 'Old daily deadline does not delay the first hourly fetch');
    update_option(MarketingCampaign::STATE, ['last_success' => time() - 7200, 'next_attempt' => time() + 79200, 'failures' => 0], false);
    $same(true, $marketing->fetch(), 'An old daily campaign cache adopts hourly freshness');
    $same($beforeUpgrade + 2, count($requests), 'Old campaign deadline does not delay hourly freshness');
    foreach ([NotificationFetcher::STATE, MarketingCampaign::STATE] as $stateKey) {
        update_option($stateKey, ['last_success' => time() - 3601, 'next_attempt' => time() - 1, 'failures' => 0], false);
    }
    $beforeHourly = count($requests);
    do_action('slimstat_notifications_cron_hook');
    $same(['notifications', 'marketing-campaigns'], array_slice($requests, $beforeHourly), 'Hourly cron refreshes both feeds');
    $count = count($requests);
    $same('🔥 -30%', NotificationFactory::getBadge()['label'], 'Badge is exposed');
    $same('BFCM2026', NotificationFactory::getAllNotifications()[0]->getCode(), 'Coupon exposed');
    $same('🏷️', MarketingCampaign::banner()->getIcon(), 'Icon decoded once');
    $same('#991b1b', MarketingCampaign::banner()->getBackgroundColor(), 'Opaque semantic color');
    foreach (['', 'inherit', 'invalid;color'] as $textColor) {
        $warning = new \SlimStat\Decorators\MarketingCampaignDecorator(['background_color' => 'warning', 'text_color' => $textColor]);
        $same('#202224', $warning->getTextColor(), 'Warning defaults to dark text');
    }
    $explicit = new \SlimStat\Decorators\MarketingCampaignDecorator(['background_color' => 'warning', 'text_color' => '#123456']);
    $same('#123456', $explicit->getTextColor(), 'Explicit valid text color is preserved');
    $same($count, count($requests), 'Render performs no HTTP requests');
    $same(true, $marketing->fetch(), 'Warm marketing cache');
    $same($count, count($requests), 'Warm marketing cache avoids request');
    ob_start(); View::load('components/notification/card', ['notification' => NotificationFactory::getAllNotifications()[0]]); $html = ob_get_clean();
    $same(1, substr_count($html, 'class="slimstat-notification-sidebar__button"'), 'Exactly one promo CTA');
    $same(false, strpos($html, 'Must not appear') !== false, 'Secondary promo button omitted');
    $same(true, strpos($html, 'dir="ltr">BFCM2026') !== false, 'Coupon direction is stable');
    $GLOBALS['horizon_test_now']--;
    $same(0, NotificationFactory::getNewNotificationCount(), 'Upcoming notification hidden from cache');
    $same(null, MarketingCampaign::banner(), 'Upcoming banner hidden');
    $GLOBALS['horizon_test_now']++;
    $same(1, NotificationFactory::getNewNotificationCount(), 'Cached notice appears at exact start without fetch');
    $same(true, MarketingCampaign::banner() !== null, 'Cached banner appears at start');
    $GLOBALS['horizon_test_now'] = strtotime($notice['ends_at']);
    $same(0, NotificationFactory::getNewNotificationCount(), 'Notice hides at exact expiry');
    $same([], NotificationFactory::getBadge(), 'Expired badge hides');
    $same(null, MarketingCampaign::banner(), 'Expired campaign hides');
    $GLOBALS['horizon_test_now'] = strtotime($notice['starts_at']);
    $feeds['notifications']['data'][] = array_merge($notice, ['id' => 876502, 'extra_data' => ['badge' => [], 'code' => []], 'ends_at' => []]);
    $feeds['notifications']['data'][] = ['id' => 876503, 'title' => 'Legacy', 'activated_at' => '2099-01-01', 'expires_at' => '2020-01-01'];
    $same(true, $fetcher->fetchNotification(true), 'Malformed optional fields do not poison the feed');
    $same(2, NotificationFactory::getNewNotificationCount(), 'Bad date is hidden and legacy item remains compatible');
    $same(true, NotificationProcessor::dismissNotification($notice['id']), 'Promo can be dismissed');
    $same([], NotificationFactory::getBadge(), 'Dismissal clears badge');
    $same(true, $fetcher->fetchNotification(true), 'Refresh after dismissal');
    $same([], NotificationFactory::getBadge(), 'Refresh preserves dismissal');
    $during = static function () use ($same, $marketing) { $same(false, $marketing->fetch(true), 'Concurrent campaign fetch loses lock'); };
    $same(true, $marketing->fetch(true), 'Campaign lock owner succeeds');
    $cached = get_option('wp_slimstat_marketing_campaigns');
    $status = 304;
    $same(true, $marketing->fetch(true), '304 retains campaign');
    $same($cached, get_option('wp_slimstat_marketing_campaigns'), '304 preserves cache');
    $status = 429;
    $same(false, $marketing->fetch(true), '429 backs off');
    $same(time() + 7200, get_option(MarketingCampaign::STATE)['next_attempt'], 'Retry-After honored');
    $count = count($requests);
    $same(false, $marketing->fetch(true), 'Manual refresh respects backoff');
    $same($count, count($requests), 'Backoff makes no request');
    $same($cached, get_option('wp_slimstat_marketing_campaigns'), 'Failure keeps previous campaign');
    $GLOBALS['horizon_test_now'] += 7201; $status = 200;
    $feeds['marketing-campaigns'] = ['data' => [['id' => 1, 'type' => 'promo_banner', 'data' => ['title' => [], 'url' => 'javascript:alert(1)']], $campaign]];
    $same(true, $marketing->fetch(true), 'Invalid campaign skipped without hiding good campaign');
    $same(1, count(get_option('wp_slimstat_marketing_campaigns')['data']), 'Only valid campaign cached');
    $unsafe = new \SlimStat\Decorators\MarketingCampaignDecorator(['title' => 'Unsafe', 'url' => 'javascript:alert(1)', 'icon' => '{}', 'background_color' => 'red;position:fixed', 'text_color' => []]);
    $same('', $unsafe->getUrl(), 'Unsafe URL rejected');
    $same('', $unsafe->getIcon(), 'Non-string icon ignored');
    $same('#b91c32', $unsafe->getBackgroundColor(), 'CSS injection rejected');
    wp_set_current_user(0);
    $same(null, MarketingCampaign::banner(), 'Non-admin sees no campaign');
    \wp_slimstat::$settings['display_notifications'] = 'off';
    $count = count($requests);
    $same(false, $marketing->fetch(true), 'Opt-out stops campaigns');
    $same(false, $fetcher->fetchNotification(true), 'Opt-out stops notifications');
    $same($count, count($requests), 'Opt-out makes no requests');
    echo "PASS: Horizon timing, backward compatibility, malformed fields, rendering, dismissals, concurrency, opt-out, cache and Retry-After\n";
} finally {
    remove_filter('pre_http_request', $http, 10);
    \wp_slimstat::$settings = $settings;
    wp_set_current_user($user);
    foreach ($before as $key => $value) $value === false ? delete_option($key) : update_option($key, $value);
    unset($GLOBALS['horizon_test_now']);
}
