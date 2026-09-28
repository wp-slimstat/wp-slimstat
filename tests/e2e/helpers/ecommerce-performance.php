<?php
/** Synthetic reporting-load benchmark. Never WC accounting fixtures or live store data. */
use SlimStat\Ecommerce\Integration;
use SlimStat\Ecommerce\Report;
if ('tests-wordpress' !== DB_NAME) { throw new RuntimeException('Disposable wp-env database required.'); }
require_once WP_PLUGIN_DIR . '/wp-slimstat/admin/view/wp-slimstat-db.php';
Integration::setup();
$state = get_option(Integration::STATE); $state['complete'] = true; update_option(Integration::STATE, $state, false);
if (!defined('SAVEQUERIES')) { define('SAVEQUERIES', true); }
$db = Integration::db(); $table = Integration::table(); $stats = $GLOBALS['wpdb']->prefix . 'slim_stats';
$days = isset($args[1]) ? (int) $args[1] : 1;
if (!in_array($days, [1, 30], true)) { throw new RuntimeException('Use a one-day or 30-day benchmark window.'); }
$start = (int) floor((wp_slimstat::now() - max(7, $days + 1) * DAY_IN_SECONDS) / DAY_IN_SECONDS) * DAY_IN_SECONDS;
$count = isset($args[0]) ? (int) $args[0] : 100000;
if (!in_array($count, [1000, 100000], true)) { throw new RuntimeException('Use a bounded benchmark size.'); }
$base = 3000000000;
$cleanup = static function () use ($db, $table, $stats, $base) {
    $db->query("DELETE FROM {$table} WHERE order_id >= {$base}");
    $db->query("DELETE FROM {$stats} WHERE visit_id >= {$base}");
    Integration::invalidate();
};
$cleanup();
$insertStart = microtime(true);
try {
    for ($offset = 0; $offset < $count; $offset += 1000) {
        $visits = $orders = [];
        for ($i = $offset; $i < min($count, $offset + 1000); ++$i) {
            $id = $base + $i; $dt = $start + ($i % $days) * DAY_IN_SECONDS + ($i % 86400);
            $visits[] = "({$id},{$dt},0,'/benchmark/product','[ec:eligible][ec:product]','paid_search','google','benchmark')";
            $orders[] = "({$id},0,0,{$dt},'USD','completed','10.000000','paid_search','google','benchmark')";
        }
        if (false === $db->query("INSERT INTO {$stats} (visit_id,dt,browser_type,resource,notes,traffic_channel,traffic_source,utm_campaign) VALUES " . implode(',', $visits))
            || false === $db->query("INSERT INTO {$table} (order_id,item_id,kind,dt,currency,status,net,channel,source,campaign) VALUES " . implode(',', $orders))) { throw new RuntimeException('Benchmark insertion failed.'); }
    }
    // All synthetic orders deliberately have a known visit, exercising the expensive join path.
    $db->query("UPDATE {$table} e INNER JOIN {$stats} s ON s.visit_id = e.order_id SET e.stat_id = s.id WHERE e.order_id >= {$base}");
    $seedSeconds = microtime(true) - $insertStart;
    wp_slimstat_db::init();
    wp_slimstat_db::$filters_normalized['utime'] = ['start' => $start, 'end' => $start + $days * DAY_IN_SECONDS - 1];
    wp_slimstat_db::$filters_normalized['columns'] = [Report::CURRENCY_FILTER => ['equals', 'USD']];
    $runs = [];
    $memoryBefore = memory_get_usage(true);
    $counterSql = "SHOW SESSION STATUS WHERE Variable_name IN ('Handler_read_key','Handler_read_next','Handler_read_rnd_next','Created_tmp_disk_tables','Sort_rows')";
    $repeats = isset($args[2]) && 'profile' === $args[2] ? 1 : 3;
    for ($repeat = 0; $repeat < $repeats; ++$repeat) {
    wp_slimstat_db::$filters_normalized['columns'] = [Report::CURRENCY_FILTER => ['equals', 'USD']];
    foreach (['cold', 'warm', 'filtered'] as $mode) {
        if ('filtered' === $mode) { wp_slimstat_db::$filters_normalized['columns']['utm_campaign'] = ['equals', 'benchmark']; }
        if ('warm' !== $mode) { Integration::invalidate(); }
        $before = array_column($db->get_results($counterSql, ARRAY_A), 'Value', 'Variable_name');
        $queries = $db->num_queries; $time = microtime(true);
        $db->queries = [];
        $data = (new Report())->data();
        if (0 === $repeat && 'cold' === $mode) { $profile = array_map(static function ($query) { return ['sql' => $query[0], 'ms' => round($query[1] * 1000, 2)]; }, $db->queries); }
        $runs[$repeat][$mode] = ['milliseconds' => round((microtime(true) - $time) * 1000, 2), 'queries' => $db->num_queries - $queries, 'orders' => (int) $data['current']['orders'], 'net' => $data['current']['net'], 'visits' => $data['journey']['visits']];
        $after = array_column($db->get_results($counterSql, ARRAY_A), 'Value', 'Variable_name');
        $runs[$repeat][$mode]['counters'] = array_map(static function ($key) use ($before, $after) { return (int) $after[$key] - (int) $before[$key]; }, array_keys($before));
        $runs[$repeat][$mode]['counter_names'] = array_keys($before);
        if ((int) $data['current']['orders'] !== $count || (float) $data['current']['net'] !== (float) ($count * 10) || $data['journey']['visits'] !== $count) { throw new RuntimeException('Benchmark reconciliation failed: ' . wp_json_encode($runs)); }
        if (array_sum(array_column($data['series']['current'], 'orders')) !== $count || array_sum(array_column($data['series']['current'], 'buyers')) !== $count) { throw new RuntimeException('Chart reconciliation failed.'); }
    }
    }
    $medians = [];
    foreach (['cold', 'warm', 'filtered'] as $mode) {
        $times = array_map(static function ($run) use ($mode) { return $run[$mode]['milliseconds']; }, $runs); sort($times); $medians[$mode] = $times[(int) floor(count($times) / 2)];
    }
    $narrow = $db->get_results("EXPLAIN SELECT SUM(net) FROM {$table} WHERE kind=0 AND currency='USD' AND dt BETWEEN {$start} AND " . ($start + 100), ARRAY_A);
    $plans = $db->get_results("EXPLAIN SELECT SUM(net) FROM {$table} WHERE kind=0 AND currency='USD' AND dt BETWEEN {$start} AND " . ($start + DAY_IN_SECONDS - 1), ARRAY_A);
    echo wp_json_encode(['dataset' => ['orders' => $count, 'pageviews' => $count, 'days' => $days], 'query_profile' => $profile ?? [], 'seed_seconds' => round($seedSeconds, 2), 'peak_php_mb' => round(memory_get_peak_usage(true) / 1048576, 1), 'report_memory_delta_mb' => round((memory_get_usage(true) - $memoryBefore) / 1048576, 1), 'runs' => $runs, 'median_ms' => $medians, 'aggregate_plan' => $plans, 'narrow_plan' => $narrow]);
} finally { $cleanup(); }
