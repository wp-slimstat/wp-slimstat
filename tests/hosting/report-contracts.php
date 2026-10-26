<?php
/** Real-DB assertions for changed report queries; creates and drops only its own database. */
use SlimStat\Utils\Query;
use SlimStat\Modules\Chart;
use SlimStat\Reports\Types\Analytics\LiveAnalyticsReport;

if (!defined('WP_CLI') || !WP_CLI || getenv('SLIMSTAT_HOSTING_DISPOSABLE') !== '1' || DB_NAME !== 'wordpress' || DB_HOST !== 'db') {
    throw new RuntimeException('Requires the explicitly armed disposable Docker stack.');
}
require_once WP_PLUGIN_DIR . '/wp-slimstat/admin/index.php';
require_once WP_PLUGIN_DIR . '/wp-slimstat/admin/view/wp-slimstat-db.php';
wp_set_current_user(1);
$source = $GLOBALS['wpdb'];
$settings = wp_slimstat::$settings;
$database = 'test_slimstat_reports_' . bin2hex(random_bytes(4));
if (false === $source->query("CREATE DATABASE `$database`")) { throw new RuntimeException($source->last_error); }
$checks = [];
try {
    $db = new wpdb(DB_USER, DB_PASSWORD, $database, DB_HOST);
    $db->set_prefix('hc_');
    foreach (['options', 'slim_stats'] as $suffix) {
        if (false === $db->query("CREATE TABLE hc_$suffix LIKE wordpress.{$source->prefix}$suffix")) { throw new RuntimeException($db->last_error); }
    }
    $GLOBALS['wpdb'] = wp_slimstat::$wpdb = $db;
    wp_cache_flush();
    update_option('active_plugins', ['wp-slimstat/wp-slimstat.php', 'wp-slimstat-pro/wp-slimstat-pro.php']);
    wp_slimstat::$settings['is_tracking'] = 'on';
    wp_slimstat::$settings['session_duration'] = 1800;
    wp_slimstat::$settings['restrict_authors_view'] = 'off';
    $now = wp_slimstat::now();
    foreach ([0, 1, 2, 3, null] as $index => $type) {
        $db->insert('hc_slim_stats', ['id' => $index + 1, 'visit_id' => $index + 1, 'browser_type' => $type,
            'dt' => $now - 120, 'resource' => '/page-' . $index, 'country' => ['us','gb','de','fr','it'][$index], 'author' => 'author-a']);
    }
    $checks['in_keeps_zero'] = 4 === count(Query::select('id')->from('hc_slim_stats')->where('browser_type', 'IN', [0,1,2,3])->getAll());
    $checks['null_comparison'] = 1 === count(Query::select('id')->from('hc_slim_stats')->where('browser_type', 'IS', null)->getAll());
    $checks['not_null_comparison'] = 4 === count(Query::select('id')->from('hc_slim_stats')->where('browser_type', 'IS NOT', null)->getAll());
    $report = new LiveAnalyticsReport();
    $counts = $report->get_all_live_counts();
    $checks['online_humans'] = ['users' => 4, 'pages' => 4, 'countries' => 4] === $counts;
    $checks['adminbar_humans'] = 4 === wp_slimstat_admin::online_count();
    $series = $report->get_users_chart_data();
    $checks['online_chart_humans'] = 4 === max($series['data'] ?? []);

    // A populated historical current/previous pair and an excluded browser.
    $start = strtotime('2026-01-10 00:00:00 UTC');
    $end = $start + 3 * DAY_IN_SECONDS - 1;
    foreach ([[$start + 3600, 'Chrome'], [$start + DAY_IN_SECONDS, 'Chrome'], [$start - DAY_IN_SECONDS, 'Chrome'], [$start + 3600, 'Firefox']] as $i => [$dt, $browser]) {
        $db->insert('hc_slim_stats', ['id' => 10 + $i, 'visit_id' => 10 + $i, 'ip' => '192.0.2.' . ($i + 1), 'dt' => $dt, 'browser' => $browser]);
    }
    wp_slimstat_db::init();
    wp_slimstat_db::$filters_normalized['columns'] = ['browser' => ['equals', 'Chrome']];
    $fetch = new ReflectionMethod(Chart::class, 'fetchChartData');
    $fetch->setAccessible(true);
    $args = ['start' => $start, 'end' => $end, 'granularity' => 'daily', 'chart_data' => ['data1' => 'COUNT(id)', 'data2' => 'COUNT( DISTINCT ip )']];
    foreach (['on', 'no'] as $comparison) {
        wp_slimstat::$settings['comparison_chart'] = $comparison;
        $queries = [];
        $capture = static function ($sql) use (&$queries) { $queries[] = $sql; return $sql; };
        add_filter('query', $capture);
        $data = $fetch->invoke(new Chart(), $args);
        remove_filter('query', $capture);
        $checks['chart_' . $comparison . '_current'] = 2 === (int) $data['totals'][0]->v1 && 2 === array_sum($data['datasets']['v1']);
        if ('on' === $comparison) {
            $checks['chart_previous'] = 1 === (int) $data['totals'][1]->v1 && 1 === array_sum($data['datasets_prev']['v1']);
        } else {
            $checks['chart_no_previous'] = [] === $data['datasets_prev'] && !isset($data['totals'][1]);
            $checks['chart_current_only_scan'] = false === strpos(implode("\n", $queries), 'OR (dt BETWEEN');
        }
    }
    echo json_encode(['checks' => $checks, 'live_counts' => $counts, 'series' => $series], JSON_PRETTY_PRINT) . "\n";
} finally {
    $GLOBALS['wpdb'] = wp_slimstat::$wpdb = $source;
    wp_slimstat::$settings = $settings;
    wp_cache_flush();
    $source->query("DROP DATABASE `$database`");
}
if (in_array(false, $checks, true)) { WP_CLI::error('Report contract failures: ' . implode(', ', array_keys($checks, false, true))); }
