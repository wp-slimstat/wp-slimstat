<?php
/** Run with wp eval-file; uses a disposable copy of the analytics table. */
use SlimStat\Tracker\Storage;
use SlimStat\Reports\Types\Analytics\LiveAnalyticsReport;

if (!defined('WP_CLI') || !WP_CLI) { exit(1); }
$db = $GLOBALS['wpdb'];
$prefix = $db->prefix;
$analytics = wp_slimstat::$wpdb;
$settings = wp_slimstat::$settings;
$testPrefix = 'test_session_live_' . bin2hex(random_bytes(4)) . '_';
$table = $testPrefix . 'slim_stats';
$check = static function ($ok, $message) { if (!$ok) { throw new RuntimeException($message); } };
try {
    $check(false !== $db->query("CREATE TABLE {$table} LIKE {$prefix}slim_stats"), $db->last_error);
    $db->prefix = $testPrefix;
    wp_slimstat::$wpdb = $db;
    wp_slimstat::$settings['is_tracking'] = 'on';
    wp_slimstat::$settings['restrict_authors_view'] = 'off';
    $check(false !== $db->insert($table, ['visit_id' => 41, 'dt' => 100, 'resource' => '/session']), 'Seed session');
    $id = (int) $db->insert_id;
    $write = Storage::updateRow(['id' => $id, 'visit_id' => 99, 'dt_out' => 200]);
    $row = $db->get_row("SELECT visit_id, dt_out FROM {$table} WHERE id = {$id}");
    $check(!$write->isFailed() && (int) $row->visit_id === 41 && (int) $row->dt_out === 200, 'Enrichment must retain a positive session and write activity');
    $db->update($table, ['visit_id' => 0], ['id' => $id]);
    Storage::updateRow(['id' => $id, 'visit_id' => 42]);
    Storage::updateRow(['id' => $id, 'visit_id' => 43]);
    $check(42 === (int) $db->get_var("SELECT visit_id FROM {$table} WHERE id = {$id}"), 'Only the first assignment may win');
    // Simulate a consent merge between the ordinary caller's read and write.
    $db->update($table, ['visit_id' => 44], ['id' => $id]);
    Storage::updateRow(['id' => $id, 'visit_id' => 42, 'dt_out' => 300]);
    $check(44 === (int) $db->get_var("SELECT visit_id FROM {$table} WHERE id = {$id}"), 'An in-flight update must not undo a consent merge');
    $db->query("DELETE FROM {$table}");

    // Reseed only if the clock crosses a minute while constructing boundary rows.
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $db->query("DELETE FROM {$table}");
        $start = (int) floor(wp_slimstat::now() / 60) * 60 - 29 * 60;
        // [visit, resource, country, start offset, exit offset or null, browser type]
        $fixtures = [
            [1, '/recent', 'US', 1, null, 0],
            [1, '/recent', 'us', 2, 3, 0],
            [2, '/long-read', 'DE', -3600, 1, 0],
            [3, '/invalid-exit', 'fr', -3600, -3601, 0],
            [4, '/expired', 'fr', -10, -1, 0],
            [5, '/bot', 'fr', 1, 2, 1],
            [0, '/unassigned', 'fr', 1, 2, 0],
            [6, '', 'CA', 0, null, 0],
            [7, '/unknown-country', 'xx', 1, 2, 0],
            [8, '/bad-country', '123', 1, -100, 0],
            [9, '/boundary', 'de', -100, 0, 0],
        ];
        foreach ($fixtures as [$visit, $resource, $country, $dt, $out, $browser]) {
            $check(false !== $db->insert($table, [
                'visit_id' => $visit, 'resource' => $resource, 'country' => $country,
                'dt' => $start + $dt, 'dt_out' => null === $out ? null : $start + $out, 'browser_type' => $browser,
            ]), 'Seed live activity: ' . $db->last_error);
        }
        $report = new LiveAnalyticsReport();
        $counts = $report->get_all_live_counts();
        // The chart shares the activity predicate and must still exclude future rows.
        $check(false !== $db->insert($table, ['visit_id' => 10, 'dt' => $start + 1800, 'resource' => '/future']), 'Seed future activity: ' . $db->last_error);
        LiveAnalyticsReport::clear_cache();
        $chart = $report->get_users_chart_data();
        if ($start === (int) floor(wp_slimstat::now() / 60) * 60 - 29 * 60) { break; }
    }
    $check($attempt < 3, "Clock rolled over during all three fixture attempts");
    $check(['users' => 6, 'pages' => 5, 'countries' => 3] === $counts, 'Live counts must retain long reads, boundaries, human and country rules: ' . json_encode($counts));
    $check(array_merge([6], array_fill(0, 29, 0)) === $chart['data'], 'Live chart must retain activity boundaries and exclude future rows');
    echo "PASS: existing-session writes, concurrent assignment/merge, and live activity counts\n";
} finally {
    LiveAnalyticsReport::clear_cache();
    $db->prefix = $prefix;
    wp_slimstat::$wpdb = $analytics;
    wp_slimstat::$settings = $settings;
    $db->query("DROP TABLE IF EXISTS {$table}");
}
