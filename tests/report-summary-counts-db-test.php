<?php
/** Run with wp eval-file. Temporary tables exist only on a separate analytics connection. */
if (!defined('WP_CLI') || !WP_CLI) { exit("Run with wp eval-file.\n"); }
require_once dirname(__DIR__) . '/admin/view/wp-slimstat-db.php';

use SlimStat\Tracker\Acquisition;

$core = $GLOBALS['wpdb'];
$originalDb = wp_slimstat::$wpdb;
$prefix = $core->prefix;
$filters = wp_slimstat_db::$filters_normalized;
$settings = wp_slimstat::$settings;
$pageviews = wp_slimstat_db::$pageviews;
$db = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$testPrefix = 'summary_' . bin2hex(random_bytes(5)) . '_';
$table = $testPrefix . 'slim_stats';
$transients = [];
$remember = static function ($key) use (&$transients) { $transients[$key] = true; };
add_action('setted_transient', $remember);
$clear = static function () use (&$transients) {
    foreach (array_keys($transients) as $key) { delete_transient($key); }
    $transients = [];
};
$checks = 0;
$check = static function ($ok, $message) use (&$checks) {
    ++$checks;
    if (!$ok) { throw new RuntimeException($message); }
};
$selects = [];
$capture = static function ($sql) use (&$selects, $table) {
    if (preg_match('/^\s*SELECT/i', $sql) && strpos($sql, $table) !== false) { $selects[] = $sql; }
    return $sql;
};
$readyKey = null;
$network = null;
try {
    $check(false !== $db->query("CREATE TEMPORARY TABLE `$table` LIKE `{$prefix}slim_stats`"), 'Create isolated analytics table');
    $core->prefix = $testPrefix;
    wp_slimstat::$wpdb = $db;
    wp_slimstat_db::init();
    $readyKey = Acquisition::readinessKey();
    $now = wp_slimstat::now();
    $start = $now - 10 * DAY_IN_SECONDS;
    $fixture = [
        ['ip' => '192.0.2.1', 'username' => 'alice', 'visit_id' => 1, 'referer' => null, 'traffic_channel' => 'direct'],
        ['ip' => '192.0.2.1', 'username' => 'alice', 'visit_id' => 1, 'referer' => 'https://search.example/?q=%_', 'searchterms' => 'alpha', 'traffic_channel' => 'organic_search'],
        ['ip' => '192.0.2.2', 'username' => '', 'visit_id' => 2, 'referer' => 'https://search.example/?q=%_', 'searchterms' => '', 'traffic_channel' => null],
        ['ip' => null, 'username' => null, 'visit_id' => 0, 'browser_type' => null, 'resource' => null, 'referer' => null, 'traffic_channel' => null],
        ['ip' => '192.0.2.3', 'username' => 'bob', 'visit_id' => 3, 'browser_type' => 1, 'traffic_channel' => 'bot'],
        ['ip' => '192.0.2.4', 'username' => 'Bob', 'visit_id' => 4, 'resource' => '/same', 'referer' => home_url('/internal'), 'traffic_channel' => 'internal'],
        ['ip' => '192.0.2.5', 'username' => '0', 'visit_id' => 0, 'resource' => '/same', 'referer' => '', 'traffic_channel' => 'email'],
    ];
    foreach ([$start, $now] as $dt) {
        foreach ($fixture as $i => $row) {
            $row += ['dt' => $dt + $i, 'resource' => '/page-' . $i, 'browser_type' => 0, 'content_type' => 'post', 'author' => $i % 2 ? 'alice' : 'bob', 'country' => $i % 2 ? 'us' : 'de'];
            $check(false !== $db->insert($table, $row), 'Insert NULL/empty/bot/duplicate fixture');
        }
    }
    add_filter('query', $capture);
    $cases = [
        'historical' => [$start, $start + 6, []],
        'live' => [$now, $now + 6, []],
        'author' => [$start, $start + 6, ['author' => ['equals', 'alice']]],
        'country' => [$start, $start + 6, ['country' => ['equals', 'de']]],
        'empty' => [$start + 100, $start + 200, []],
        'boundary' => [$start + 1, $start + 1, []],
        'mixed' => [$start, $now + 6, []],
    ];
    foreach (['1', '0'] as $ready) {
        update_option($readyKey, $ready, true);
        foreach ($cases as $label => [$from, $to, $columns]) {
            $clear();
            wp_slimstat_db::$filters_normalized['utime'] = ['start' => $from, 'end' => $to];
            wp_slimstat_db::$filters_normalized['columns'] = $columns;
            wp_slimstat_db::$pageviews = wp_slimstat_db::count_records();
            $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
            $search = 'searchterms IS NOT NULL AND referer IS NOT NULL AND referer NOT LIKE %s';
            $direct = 'referer IS NULL';
            if ('1' === $ready) {
                $search = "(traffic_channel = 'organic_search' OR (traffic_channel IS NULL AND {$search}))";
                $direct = "(traffic_channel = 'direct' OR (traffic_channel IS NULL AND {$direct}))";
            }
            // The unchanged scalar API is the independent oracle for the combined path.
            $traffic = [
                1 => wp_slimstat_db::count_records('referer', 'referer NOT LIKE %s', true, ['%' . $core->esc_like($host) . '%']),
                2 => wp_slimstat_db::count_records('id', $direct),
                3 => wp_slimstat_db::count_records('id', $search, true, ['%' . $core->esc_like(home_url()) . '%']),
                4 => wp_slimstat_db::count_records('resource'),
            ];
            $visitors = [
                0 => wp_slimstat_db::count_records('visit_id', 'visit_id > 0 AND browser_type <> 1'),
                1 => wp_slimstat_db::count_records('ip', 'visit_id > 0 AND browser_type <> 1'),
                3 => wp_slimstat_db::count_records('username'),
                5 => wp_slimstat_db::count_records('id', 'browser_type = 1'),
            ];
            $overview = [3 => wp_slimstat_db::count_records('id', 'searchterms IS NOT NULL'), 4 => wp_slimstat_db::count_records('ip')];
            foreach (['traffic_sources' => [$traffic, 4], 'visitors' => [$visitors, 3], 'overview' => [$overview, 5]] as $name => [$expected, $budget]) {
                $clear();
                $selects = [];
                $method = 'get_' . $name . '_summary';
                $actual = wp_slimstat_db::$method();
                foreach ($expected as $index => $value) {
                    $check(number_format_i18n($value) === $actual[$index]['value'], "$ready/$label/$name metric $index differs from scalar oracle");
                }
                $check(count($selects) <= $budget, "$ready/$label/$name scanned stats " . count($selects) . " times; budget $budget");
                $warm = wp_slimstat_db::$method();
                $check($actual === $warm, "$ready/$label/$name warm cache changed report output");
            }
        }
    }

    // Keep both historical caches alive while switching filters and table prefixes.
    wp_slimstat_db::$filters_normalized['utime'] = ['start' => $start, 'end' => $start + 6];
    $cached = [];
    foreach (['alice', 'bob', 'alice'] as $author) {
        wp_slimstat_db::$filters_normalized['columns'] = ['author' => ['equals', $author]];
        $expected = wp_slimstat_db::count_records('ip', 'visit_id > 0 AND browser_type <> 1');
        $actual = wp_slimstat_db::get_visitors_summary();
        $check(number_format_i18n($expected) === $actual[1]['value'], "Cached author $author stays isolated");
        if (isset($cached[$author])) { $check($cached[$author] === $actual, 'Switching filters preserves the cached answer'); }
        $cached[$author] = $actual;
    }
    wp_slimstat_db::$filters_normalized['columns'] = [];
    $otherTable = $testPrefix . 'other_slim_stats';
    $check(false !== $db->query("CREATE TEMPORARY TABLE `$otherTable` LIKE `$table`"), 'Create second site table');
    $check(false !== $db->insert($otherTable, ['dt' => $start, 'visit_id' => 1, 'ip' => '192.0.2.1', 'browser_type' => 0]), 'Insert overlapping network identity');
    $core->prefix = $testPrefix . 'other_';
    $other = wp_slimstat_db::get_visitors_summary();
    $check('1' === $other[0]['value'] && '1' === $other[1]['value'], 'A second site cannot reuse the first site cache');
    $core->prefix = $testPrefix;

    // Exercise the existing per-blog DISTINCT seam with two real SQL tables.
    $network = static function ($sql, $aggregate) use ($table, $otherTable) {
        $first = preg_replace('/^(SELECT\s+(?:DISTINCT\s+)?)/i', '$1 1 AS blog_id, ', $sql);
        $second = preg_replace('/^(SELECT\s+(?:DISTINCT\s+)?)/i', '$1 2 AS blog_id, ', str_replace($table, $otherTable, $sql));
        return "SELECT $aggregate FROM ($first UNION ALL $second) AS t_union_all";
    };
    add_filter('slimstat_network_merge_active', '__return_true');
    add_filter('slimstat_get_var_sql', $network, 10, 2);
    $method = new ReflectionMethod(wp_slimstat_db::class, 'summary_counts');
    $method->setAccessible(true);
    $networkCounts = $method->invoke(null, ['visits' => ['visit_id', 'visit_id > 0 AND browser_type <> 1'], 'ips' => ['ip', 'visit_id > 0 AND browser_type <> 1']]);
    $check(4 === $networkCounts['visits'], 'Network visit IDs remain distinct per blog');
    $check(3 === $networkCounts['ips'], 'Network IPs deduplicate across blogs');
    remove_filter('slimstat_network_merge_active', '__return_true');
    remove_filter('slimstat_get_var_sql', $network, 10);

    // A new request can reuse the same end second while tracking inserts arrive.
    $summaries = static function () {
        wp_slimstat_db::$pageviews = wp_slimstat_db::count_records();
        return [wp_slimstat_db::get_visitors_summary(), wp_slimstat_db::get_traffic_sources_summary(), wp_slimstat_db::get_overview_summary()];
    };
    foreach ([$now, $start] as $i => $from) {
        $clear();
        wp_slimstat_db::$filters_normalized['utime'] = ['start' => $from, 'end' => $now];
        wp_slimstat_db::$filters_normalized['columns'] = [];
        $before = $summaries();
        $row = ['dt' => $now, 'visit_id' => 999 + $i, 'ip' => '192.0.2.' . (98 + $i), 'browser_type' => 0,
            'referer' => 'https://search.example/', 'searchterms' => 'fresh', 'resource' => '/fresh', 'traffic_channel' => 'organic_search'];
        $check(false !== $db->insert($table, $row), 'Insert live visitor');
        $after = $summaries();
        $check((int) $after[0][0]['value'] === (int) $before[0][0]['value'] + 1, 'Live visitor total stays fresh');
        $check((int) $after[0][1]['value'] === (int) $before[0][1]['value'] + 1, 'Live distinct IP total stays fresh');
        $clear();
        $check($after === $summaries(), 'Live/mixed summaries including ratios and pageviews agree with fresh SQL');
        $check(false !== $db->insert($table, $row), 'Insert second pageview in the same visit');
        $after = $summaries();
        $clear();
        $check($after === $summaries(), 'Live/mixed bounce numerators update when a visit stops bouncing');
    }
    // The current-search metric remains live even when the report range is historical.
    wp_slimstat_db::$filters_normalized['utime'] = ['start' => $start, 'end' => $start + 6];
    $before = wp_slimstat_db::get_traffic_sources_summary();
    $check(false !== $db->insert($table, $row), 'Insert current search in a historical report');
    $after = wp_slimstat_db::get_traffic_sources_summary();
    $check((int) $after[7]['value'] === (int) $before[7]['value'] + 1, 'Current-search count never inherits historical caching');
    $after = wp_slimstat_db::get_visitors_summary();

    // Preserve the existing all-time API used by total-count shortcodes and Pro Whois.
    foreach (['id', 'ip'] as $column) {
        $clear();
        $selects = [];
        $allTime = wp_slimstat_db::count_records($column, '', false);
        $check(1 === count($selects), 'Cold all-time count reads the analytics table once');
        $selects = [];
        $check($allTime === wp_slimstat_db::count_records($column, '', false), 'Warm all-time count preserves its value');
        $check([] === $selects, 'Warm all-time count retains its existing cache budget');
    }

    // A damaged optional column must not hide unrelated valid metrics in a combined query.
    $clear();
    $check(false !== $db->query("ALTER TABLE `$table` DROP COLUMN username"), 'Emulate partially restored schema');
    $suppressed = $db->suppress_errors(true);
    $degraded = wp_slimstat_db::get_visitors_summary();
    $db->suppress_errors($suppressed);
    $check($after[0]['value'] === $degraded[0]['value'] && $after[1]['value'] === $degraded[1]['value'], 'Missing optional field preserves other counts');
    echo "PASS: $checks summary accuracy, scan-budget, cache, live-data and schema assertions\n";
} finally {
    remove_filter('slimstat_network_merge_active', '__return_true');
    if (null !== $network) { remove_filter('slimstat_get_var_sql', $network, 10); }
    remove_filter('query', $capture);
    $clear();
    remove_action('setted_transient', $remember);
    if (null !== $readyKey) { delete_option($readyKey); }
    $core->prefix = $prefix;
    wp_slimstat::$wpdb = $originalDb;
    wp_slimstat::$settings = $settings;
    wp_slimstat_db::$filters_normalized = $filters;
    wp_slimstat_db::$pageviews = $pageviews;
    $db->close();
}
