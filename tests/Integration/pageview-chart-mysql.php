<?php
/** The shipped Pageviews chart and its default must count visits without an IP. */
global $wpdb;
if (!defined('WP_CLI') || !WP_CLI || !preg_match('/^(tests?[-_]|wordpress$)/', DB_NAME)) { throw new RuntimeException('Disposable database required'); }
require_once WP_PLUGIN_DIR . '/wp-slimstat/admin/view/wp-slimstat-reports.php';
wp_slimstat_reports::init(false);
$db = wp_slimstat::$wpdb;
$originalPrefix = $wpdb->prefix;
$originalFilters = wp_slimstat_db::$filters_normalized;
$originalSettings = wp_slimstat::$settings;
$prefix = 'slimstat_chart_' . bin2hex(random_bytes(6)) . '_';
$table = $prefix . 'slim_stats';
$run = static function ($sql) use ($db) { if (false === $db->query($sql)) { throw new RuntimeException($db->last_error); } };
$start = (int) floor(wp_slimstat::now() / DAY_IN_SECONDS) * DAY_IN_SECONDS - 3 * DAY_IN_SECONDS;
try {
    $run("CREATE TABLE {$table} (id BIGINT PRIMARY KEY AUTO_INCREMENT, ip VARCHAR(39) NULL, browser_type INT, dt BIGINT)");
    foreach ([null, null, '', '192.0.2.1', '192.0.2.1'] as $i => $ip) {
        if (!$db->insert($table, ['ip'=>$ip, 'browser_type'=>$i === 1 ? 1 : 0, 'dt'=>$start + 100 + $i])) { throw new RuntimeException($db->last_error); }
    }
    // Previous-period values also retain their addressless pageviews.
    foreach ([null, '', '192.0.2.2'] as $i => $ip) {
        if (!$db->insert($table, ['ip'=>$ip, 'browser_type'=>0, 'dt'=>$start - DAY_IN_SECONDS + 100 + $i])) { throw new RuntimeException($db->last_error); }
    }
    $wpdb->prefix = $prefix;
    wp_slimstat::$settings['comparison_chart'] = 'on';
    wp_slimstat_db::$filters_normalized['utime'] = ['start'=>$start, 'end'=>$start + DAY_IN_SECONDS - 1, 'range'=>DAY_IN_SECONDS - 1];
    $chart = new \SlimStat\Modules\Chart();
    $normalize = new ReflectionMethod($chart, 'normalizeArgs'); $normalize->setAccessible(true);
    $fetch = new ReflectionMethod($chart, 'fetchChartData'); $fetch->setAccessible(true);
    foreach ([false, true] as $filtered) {
        wp_slimstat_db::$filters_normalized['columns'] = $filtered ? ['browser_type'=>['equals', '0']] : [];
        foreach (['registered'=>wp_slimstat_reports::$reports['slim_p1_01']['callback_args'], 'default'=>[]] as $source=>$args) {
            foreach (['hourly', 'daily', 'weekly', 'monthly', 'yearly'] as $granularity) {
                $args['granularity'] = $granularity;
                $result = $fetch->invoke($chart, $normalize->invoke($chart, $args));
                $expected = $filtered ? 4 : 5;
                if ((int) $result['totals'][0]->v1 !== $expected || (int) $result['totals'][1]->v1 !== 3) {
                    throw new RuntimeException("{$source} {$granularity}: Pageviews must include NULL IP rows in current and previous periods");
                }
                if ((int) $result['totals'][0]->v2 !== 2 || (int) $result['totals'][1]->v2 !== 2) {
                    throw new RuntimeException('Unique IP counts must retain their distinct non-NULL semantics');
                }
            }
        }
    }
    echo "PASS: registered/default Pageviews charts count NULL IP rows across five granularities, filters and previous periods\n";
} finally {
    $wpdb->prefix = $originalPrefix;
    wp_slimstat_db::$filters_normalized = $originalFilters;
    wp_slimstat::$settings = $originalSettings;
    $db->query("DROP TABLE IF EXISTS {$table}");
}
