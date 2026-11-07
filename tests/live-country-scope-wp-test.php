<?php
/** @license GPL-2.0-or-later
 * Run with wp eval-file; isolated tables, no existing analytics changes.
 */
if (!defined('WP_CLI') || !WP_CLI) { exit(1); }
$db = $GLOBALS['wpdb'];
$prefix = $db->prefix;
$settings = wp_slimstat::$settings;
$user = wp_get_current_user();
$tablePrefix = 'test_live_' . bin2hex(random_bytes(4)) . '_';
try {
    if (false === $db->query("CREATE TABLE {$tablePrefix}slim_stats LIKE {$prefix}slim_stats")) { throw new RuntimeException($db->last_error); }
    $db->prefix = $tablePrefix;
    wp_set_current_user(1);
    wp_slimstat::$settings['is_tracking'] = 'on';
    wp_slimstat::$settings['restrict_authors_view'] = 'off';
    $now = wp_slimstat::now();
    $window = (int) floor($now / 60) * 60 - 29 * 60;
    // Repeated known country, private/unknown, stale visit, recent exit, bot,
    // malformed country and a record without a visit. Only eligible visits count.
    $rows = [
        [1, 'us', $now - 60, 0, 0], [2, 'us', $now - 90, 0, 0],
        [3, '', $now - 30, 0, 0], [4, 'de', $window - 1, 0, 0],
        [5, 'gb', $window - 3600, $now - 10, 0], [6, 'fr', $now - 10, 0, 1],
        [7, 'xx', $now - 10, 0, 0], [8, '0', $now - 10, 0, 0], [0, 'it', $now - 10, 0, 0],
    ];
    foreach ($rows as $i => [$visit, $country, $dt, $out, $bot]) {
        if (false === $db->insert($tablePrefix . 'slim_stats', ['id' => $i + 1, 'visit_id' => $visit, 'country' => $country, 'dt' => $dt, 'dt_out' => $out, 'browser_type' => $bot, 'resource' => '/page-' . $visit])) { throw new RuntimeException($db->last_error); }
    }
    $report = new \SlimStat\Reports\Types\Analytics\LiveAnalyticsReport();
    $actual = $report->get_all_live_counts();
    if ($actual !== ['users' => 6, 'pages' => 6, 'countries' => 2]) { throw new RuntimeException(wp_json_encode($actual)); }
    WP_CLI::success('Live countries, pages and visitors share eligible visits and activity window; unknown locations do not become countries.');
} finally {
    $db->prefix = $prefix;
    wp_slimstat::$settings = $settings;
    wp_set_current_user($user->ID);
    $db->query("DROP TABLE IF EXISTS {$tablePrefix}slim_stats");
}
