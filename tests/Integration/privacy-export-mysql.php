<?php
/** Privacy export must read fresh analytics rows and produce WordPress's object-based fields. */
global $wpdb;
if (!defined('WP_CLI') || !WP_CLI || !preg_match('/^(tests?[-_]|wordpress$)/', DB_NAME)) { throw new RuntimeException('Disposable database required'); }
$prefix = 'slimstat_export_' . bin2hex(random_bytes(6)) . '_';
$database = 'test_slimstat_export_' . bin2hex(random_bytes(6));
$originalPrefix = $wpdb->prefix;
$originalDb = wp_slimstat::$wpdb;
$db = null;
$check = static function ($ok, $message) { if (!$ok) { throw new RuntimeException($message); } };
$run = static function ($handle, $sql) { if (false === $handle->query($sql)) { throw new RuntimeException($handle->last_error); } };
try {
    $run($wpdb, "CREATE DATABASE {$database}");
    $db = new wpdb(DB_USER, DB_PASSWORD, $database, DB_HOST);
    foreach ([$wpdb, $db] as $handle) {
        $run($handle, "CREATE TABLE {$prefix}slim_stats (id BIGINT PRIMARY KEY, email VARCHAR(100), resource VARCHAR(100), dt BIGINT)");
        $run($handle, "CREATE TABLE {$prefix}slim_events (event_id BIGINT PRIMARY KEY, id BIGINT, notes VARCHAR(100), dt BIGINT)");
    }
    $wpdb->prefix = $prefix; wp_slimstat::$wpdb = $db; $db->prefix = 'wrong_';
    foreach (['exportPageviews', 'exportEvents'] as $method) {
        $result = \SlimStat\Services\Privacy\DataExporter::$method('nobody@example.test');
        $check($result === ['data' => [], 'done' => true], 'Empty export must finish without fatal or data');
    }
    for ($id = 1; $id <= 501; ++$id) {
        $run($db, "INSERT INTO {$prefix}slim_stats VALUES ({$id},'visitor@example.test','/owned-{$id}',1700000000)");
        // Multiple events on the same pageview at the same second must retain separate export IDs.
        $run($db, "INSERT INTO {$prefix}slim_events VALUES ({$id},1,'event-{$id}',1700000000)");
    }
    $run($db, "INSERT INTO {$prefix}slim_stats VALUES (900,'other@example.test','/private-canary',1700000000)");
    $run($db, "INSERT INTO {$prefix}slim_events VALUES (900,900,'private-canary',1700000000)");
    $run($wpdb, "INSERT INTO {$prefix}slim_stats VALUES (1,'visitor@example.test','/wrong-database',1700000000)");
    $run($wpdb, "INSERT INTO {$prefix}slim_events VALUES (1,1,'wrong-database',1700000000)");
    foreach (['exportPageviews', 'exportEvents'] as $method) {
        $first = \SlimStat\Services\Privacy\DataExporter::$method('visitor@example.test', 1);
        $second = \SlimStat\Services\Privacy\DataExporter::$method('visitor@example.test', 2);
        $check(!is_wp_error($first) && !is_wp_error($second), 'Populated export must succeed');
        $rows = array_merge($first['data'], $second['data']);
        $check(count($first['data']) === 500 && !$first['done'] && count($second['data']) === 1 && $second['done'], '501 rows must export in two pages');
        $check(count(array_unique(array_column($rows, 'item_id'))) === 501, 'Export pagination must preserve unique event/pageview IDs on tied timestamps');
        $json = wp_json_encode($rows);
        $check(strpos($json, 'private-canary') === false && strpos($json, 'wrong-database') === false, 'Export must isolate email and analytics database');
        $check(strpos($json, $method === 'exportPageviews' ? '/owned-501' : 'event-501') !== false, 'Export contains actual row values');
        $check(\SlimStat\Services\Privacy\DataExporter::$method('visitor@example.test', 0) === $first, 'Invalid low page number is clamped');
    }
    $run($db, "UPDATE {$prefix}slim_stats SET resource='/fresh-value' WHERE id=501");
    $result = \SlimStat\Services\Privacy\DataExporter::exportPageviews('visitor@example.test');
    $check(strpos(wp_json_encode($result), '/fresh-value') !== false, 'Privacy export must see a fresh write without cache invalidation');
    foreach (['slim_events' => 'exportEvents', 'slim_stats' => 'exportPageviews'] as $table => $method) {
        $run($db, "DROP TABLE {$prefix}{$table}"); $db->suppress_errors(true);
        $check(is_wp_error(\SlimStat\Services\Privacy\DataExporter::$method('visitor@example.test')), 'Database failure must refuse incomplete export');
    }
    echo "PASS: empty/populated exports, 501 tied rows, unique event IDs, fresh values, separate database, canaries and error refusal\n";
} finally {
    wp_slimstat::$wpdb = $originalDb; $wpdb->prefix = $originalPrefix;
    foreach (['slim_events', 'slim_stats'] as $table) { $wpdb->query("DROP TABLE IF EXISTS {$prefix}{$table}"); }
    if ($db) { $db->close(); }
    $wpdb->query("DROP DATABASE IF EXISTS {$database}");
}
