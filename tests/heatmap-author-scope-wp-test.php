<?php
/** @license GPL-2.0-or-later
 * Run with wp eval-file. Uses disposable tables; never changes existing analytics.
 */
use SlimStat\Heatmap\Query;
use SlimStat\Heatmap\Store;
use SlimStat\Schema\Schema;
use SlimStat\Schema\SurrogateKey;

if (!defined('WP_CLI') || !WP_CLI) { exit(1); }
$db = $GLOBALS['wpdb'];
$prefix = $db->prefix;
$analytics = wp_slimstat::$wpdb;
$settings = wp_slimstat::$settings;
$user = wp_get_current_user();
$testPrefix = 'test_hm_scope_' . bin2hex(random_bytes(4)) . '_';
$tables = ['slim_stats', 'slim_events', 'slim_heatmap_elements', 'slim_heatmap'];
$cache = [];
$cacheHook = version_compare($GLOBALS['wp_version'], '6.8', '>=') ? 'set_transient' : 'setted_transient';
$state = static function () { return ['schema' => Store::SCHEMA, 'since' => 1]; };
$check = static function ($ok, $message) { if (!$ok) { throw new RuntimeException($message); } };
$cacheRead = static function ($value, $option) use (&$cache) {
    return 0 === strpos($option, '_transient_slimstat_hm') ? ($cache[$option] ?? false) : $value;
};
$cacheWrite = static function ($value, $expiration, $name) use (&$cache) { $cache['_transient_' . $name] = $value; };
$denyAdmin = static function ($caps) { $caps['manage_options'] = false; return $caps; };
try {
    foreach ($tables as $suffix) {
        $sql = in_array($suffix, ['slim_stats', 'slim_events'], true)
            ? "CREATE TABLE {$testPrefix}{$suffix} LIKE {$prefix}{$suffix}"
            : Schema::createTableSql($suffix, $testPrefix, Schema::targetCollation($db));
        $check(false !== $db->query($sql), 'Create ' . $suffix . ': ' . $db->last_error);
    }
    $db->prefix = $testPrefix;
    wp_slimstat::$wpdb = $db;
    add_filter('pre_option_' . Store::STATE, $state);
    add_filter('pre_option', $cacheRead, 10, 2);
    // Remember transient keys so this test removes its cache entries on exit.
    add_action($cacheHook, $cacheWrite, 10, 3);
    foreach (['alice', 'bob'] as $i => $author) {
        $id = $i + 1;
        $check(false !== $db->insert($testPrefix . 'slim_stats', ['id' => $id, 'author' => $author, 'dt' => 100, 'resource' => '/' . $author, 'resolution' => '1440x900']), 'Seed pageview');
        $check(false !== $db->insert($testPrefix . 'slim_events', ['id' => $id, 'dt' => 100, 'position' => '12,34', 'notes' => '{"id":"buy","text":"Buy"}']), 'Seed legacy click');
        $check(false !== $db->query($db->prepare("INSERT INTO {$testPrefix}slim_heatmap (id, kind, page, dt, device, vw, vh, dh, y) VALUES (%d, 0, UNHEX(%s), 100, 1, 1440, 900, 1000, 250), (%d, 1, UNHEX(%s), 100, 1, 1440, 900, 1000, 250)", $id, SurrogateKey::hex('/' . $author), $id, SurrogateKey::hex('/' . $author))), 'Seed capture');
    }
    wp_slimstat::$settings['restrict_authors_view'] = 'off';
    $check(2 === count(Query::cachedPages(1, 200)['rows']), 'Unrestricted cache contains both authors');
    wp_slimstat::$settings['restrict_authors_view'] = 'on';
    add_filter('user_has_cap', $denyAdmin);
    foreach (['alice', 'bob'] as $author) {
        $current = new WP_User();
        $current->data = (object) ['ID' => 12345, 'user_login' => $author];
        $current->ID = 12345;
        $GLOBALS['current_user'] = $current;
        $other = 'alice' === $author ? 'bob' : 'alice';
        $rows = Query::cachedPages(1, 200)['rows'];
        $check(['/' . $author] === array_column($rows, 'page'), 'List and cache isolate ' . $author);
        $check(1 === $rows[0]['pageviews'] && 25 === $rows[0]['scroll'], 'Scoped denominator and scroll');
        $check([] === Query::clickBins('/' . $other, '', 1, 200), 'Capture points exclude another author');
        $check(0 === Query::scrollReach('/' . $other, '', 1, 200)['views'], 'Scroll excludes another author');
        $check(0 === Query::deviceViews('/' . $other, 1, 200)['views'], 'Device totals exclude another author');
        if (class_exists('WpSlimstatPro\\Heatmap\\Viewer')) {
            $viewer = (new ReflectionClass('WpSlimstatPro\\Heatmap\\Viewer'))->newInstanceWithoutConstructor();
            $request = new WP_REST_Request('GET');
            $request->set_query_params(['page' => '/alice', 'device' => 'desktop', 'from' => '1970-01-01', 'to' => '1970-01-01', 'fs' => '', 'goal' => 0, 'converted' => '']);
            $result = $viewer->view($request);
            $check(!is_wp_error($result), 'Pro viewer responds');
            $check(('alice' === $author ? 1 : 0) === $result->get_data()['views']['views'], 'Pro viewer cache isolates ' . $author);
        }
        remove_filter('pre_option_' . Store::STATE, $state);
        $notReady = static function () { return ['schema' => 0]; };
        add_filter('pre_option_' . Store::STATE, $notReady);
        $check([] === Query::legacyPoints('/' . $other, ''), 'Legacy points exclude another author');
        $check(1 === count(Query::legacyPoints('/' . $author, '')), 'Own legacy points remain available');
        $check(['/' . $author] === array_column(Query::pages(1, 200), 'page'), 'Legacy list is scoped');
        remove_filter('pre_option_' . Store::STATE, $notReady);
        add_filter('pre_option_' . Store::STATE, $state);
    }
    echo "PASS: heatmap authors isolated across list/cache, capture, legacy, scroll and denominators\n";
} finally {
    remove_filter('pre_option_' . Store::STATE, $state);
    remove_filter('pre_option', $cacheRead, 10);
    remove_action($cacheHook, $cacheWrite, 10);
    remove_filter('user_has_cap', $denyAdmin);
    $db->prefix = $prefix;
    wp_slimstat::$wpdb = $analytics;
    wp_slimstat::$settings = $settings;
    $GLOBALS['current_user'] = $user;
    foreach (array_keys($cache) as $key) { delete_transient(substr($key, strlen('_transient_'))); }
    foreach (array_reverse($tables) as $suffix) { $db->query("DROP TABLE IF EXISTS {$testPrefix}{$suffix}"); }
}
