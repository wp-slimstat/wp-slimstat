<?php
/** Historical chart buckets must preserve counts independently of the database timezone. */
if (!defined('WP_CLI') || !WP_CLI || !preg_match('/^(tests?[-_]|wordpress$)/', DB_NAME)) {
    throw new RuntimeException('Disposable database required');
}
require_once WP_PLUGIN_DIR . '/wp-slimstat/admin/view/wp-slimstat-reports.php';
wp_slimstat_reports::init(false);
$db = wp_slimstat::$wpdb;
$prefix = $GLOBALS['wpdb']->prefix;
$filters = wp_slimstat_db::$filters_normalized;
$settings = wp_slimstat::$settings;
$timezone = $db->get_var('SELECT @@session.time_zone');
$tables = [];
try {
    wp_slimstat::$settings['comparison_chart'] = 'on';
    wp_slimstat_db::$filters_normalized['columns'] = [];
    wp_slimstat_db::$filters_normalized['utime'] = ['start' => strtotime('2026-06-30 UTC'), 'end' => strtotime('2026-09-28 UTC') - 1];
    foreach (['SYSTEM', '+00:00', '+05:30', '-07:00'] as $zone) {
        if (false === $db->query($db->prepare('SET SESSION time_zone = %s', $zone))) {
            throw new RuntimeException($db->last_error);
        }
        // Different tables force a real SQL query in every timezone, independent of the report cache.
        $GLOBALS['wpdb']->prefix = 'privacy_chart_' . bin2hex(random_bytes(6)) . '_';
        $table = $GLOBALS['wpdb']->prefix . 'slim_stats';
        $tables[] = $table;
        if (false === $db->query("CREATE TEMPORARY TABLE $table (id BIGINT PRIMARY KEY AUTO_INCREMENT, dt BIGINT, searchterms VARCHAR(32))")) {
            throw new RuntimeException($db->last_error);
        }
        for ($i = 0; $i < 468; ++$i) {
            if (!$db->insert($table, ['dt' => strtotime('2026-04-01 UTC') + ($i * 19731) % (90 * DAY_IN_SECONDS), 'searchterms' => 'term-' . ($i % 24)])) {
                throw new RuntimeException($db->last_error);
            }
        }
        foreach (['hourly', 'daily', 'weekly', 'monthly', 'yearly'] as $granularity) {
            $args = wp_slimstat_reports::$reports['slim_p1_19_01']['callback_args'];
            $args['granularity'] = $granularity;
            $data = (new SlimStat\Modules\Chart())->reportData($args)['data'];
            if (468 !== (int) array_sum($data['datasets_prev']['v1']) || 468 !== (int) $data['totals'][1]->v1
                || 24 !== (int) $data['totals'][1]->v2 || 0 !== (int) array_sum($data['datasets']['v1'])) {
                throw new RuntimeException("$zone $granularity lost historical views or changed distinct terms");
            }
            echo "PASS: $zone $granularity preserves 468 historical views and 24 distinct terms\n";
        }
    }
} finally {
    $db->query($db->prepare('SET SESSION time_zone = %s', $timezone));
    foreach ($tables as $table) { $db->query("DROP TEMPORARY TABLE IF EXISTS $table"); }
    $GLOBALS['wpdb']->prefix = $prefix;
    wp_slimstat_db::$filters_normalized = $filters;
    wp_slimstat::$settings = $settings;
}
