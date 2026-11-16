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

    // Retained personal data must remain exportable after it leaves the live tables.
    foreach ([$wpdb, $db] as $handle) {
        $run($handle, "CREATE TABLE {$prefix}slim_stats_archive LIKE {$prefix}slim_stats");
        $run($handle, "CREATE TABLE {$prefix}slim_events_archive LIKE {$prefix}slim_events");
    }
    for ($id = 1; $id <= 501; ++$id) {
        $run($db, "INSERT INTO {$prefix}slim_stats_archive VALUES ({$id},'visitor@example.test','/archived-owned-{$id}',1600000000)");
        $run($db, "INSERT INTO {$prefix}slim_events_archive VALUES ({$id},1,'archived-event-{$id}',1600000000)");
    }
    $run($db, "INSERT INTO {$prefix}slim_stats VALUES (1000,'other@example.test','/live-collision-canary',1700000000)");
    $run($db, "INSERT INTO {$prefix}slim_events VALUES (1000,1000,'live-collision-canary',1700000000)");
    $run($db, "INSERT INTO {$prefix}slim_stats_archive VALUES (1000,'archived@example.test','/archived-only',1600000000)");
    $run($db, "INSERT INTO {$prefix}slim_events_archive VALUES (1000,1000,'archived-only-event',1600000000)");
    $run($db, "INSERT INTO {$prefix}slim_stats_archive VALUES (900,'other@example.test','/archived-private-canary',1600000000)");
    $run($db, "INSERT INTO {$prefix}slim_events_archive VALUES (900,900,'archived-private-canary',1600000000)");
    $run($wpdb, "INSERT INTO {$prefix}slim_stats_archive VALUES (1,'visitor@example.test','/archive-wrong-database',1600000000)");
    $run($wpdb, "INSERT INTO {$prefix}slim_events_archive VALUES (1,1,'archive-wrong-database',1600000000)");
    foreach (['exportPageviews', 'exportEvents'] as $method) {
        $archived = \SlimStat\Services\Privacy\DataExporter::$method('archived@example.test', 1);
        $check(!is_wp_error($archived) && count($archived['data']) === 1 && $archived['done'], 'Archive-only identity must export its retained personal data');
        $check(strpos(wp_json_encode($archived), 'archived-only') !== false && strpos(wp_json_encode($archived), 'canary') === false, 'Archived events must join archived parents, not colliding live IDs');
        $rows = []; $page = 1;
        do {
            $part = \SlimStat\Services\Privacy\DataExporter::$method('visitor@example.test', $page++);
            $check(!is_wp_error($part) && $page <= 5, 'Mixed export must finish in bounded pages');
            $rows = array_merge($rows, $part['data']);
        } while (!$part['done']);
        $check(count($rows) === 1002 && count(array_unique(array_column($rows, 'item_id'))) === 1002, 'Live/archive IDs must remain distinct across every export page');
        $json = wp_json_encode($rows);
        $check(strpos($json, 'canary') === false && strpos($json, 'wrong-database') === false, 'Archived export must isolate the email and analytics connection');
        $check(strpos($json, $method === 'exportPageviews' ? '/archived-owned-501' : 'archived-event-501') !== false, 'Archived row values must appear in the export');
    }
    $run($db, "UPDATE {$prefix}slim_stats_archive SET resource='/fresh-archive-value' WHERE id=501");
    $check(strpos(wp_json_encode(\SlimStat\Services\Privacy\DataExporter::exportPageviews('visitor@example.test')), '/fresh-archive-value') !== false, 'Archived export must read fresh values');
    foreach (['slim_stats', 'slim_stats_archive'] as $legacyTable) {
        $run($db, "ALTER TABLE {$prefix}{$legacyTable} CHANGE email legacy_email VARCHAR(100)");
        foreach (['exportPageviews', 'exportEvents'] as $method) {
            $legacy = \SlimStat\Services\Privacy\DataExporter::$method('visitor@example.test');
            $check(!is_wp_error($legacy) && count($legacy['data']) === 500 && !$legacy['done'], 'A legacy table without email must not break exports from the other source');
        }
        $run($db, "ALTER TABLE {$prefix}{$legacyTable} CHANGE legacy_email email VARCHAR(100)");
    }
    $db->suppress_errors(true);
    $run($db, "RENAME TABLE {$prefix}slim_stats_archive TO {$prefix}saved_archive");
    $check(is_wp_error(\SlimStat\Services\Privacy\DataExporter::exportEvents('visitor@example.test')), 'An archived event table without its parent table must refuse an incomplete export');
    $run($db, "RENAME TABLE {$prefix}saved_archive TO {$prefix}slim_stats_archive");
    $run($db, "ALTER TABLE {$prefix}slim_events_archive CHANGE dt invalid_dt BIGINT");
    $check(is_wp_error(\SlimStat\Services\Privacy\DataExporter::exportEvents('visitor@example.test')), 'An archived query failure must refuse an incomplete export');
    $run($db, "ALTER TABLE {$prefix}slim_events_archive CHANGE invalid_dt dt BIGINT");
    foreach (['slim_events' => 'exportEvents', 'slim_stats' => 'exportPageviews'] as $table => $method) {
        $run($db, "DROP TABLE {$prefix}{$table}"); $db->suppress_errors(true);
        $check(is_wp_error(\SlimStat\Services\Privacy\DataExporter::$method('visitor@example.test')), 'Database failure must refuse incomplete export');
    }
    echo "PASS: live/archive exports, 501 tied rows per source, unique IDs, fresh values, separate database, canaries and error refusal\n";
} finally {
    wp_slimstat::$wpdb = $originalDb; $wpdb->prefix = $originalPrefix;
    foreach (['slim_events', 'slim_stats', 'slim_events_archive', 'slim_stats_archive'] as $table) { $wpdb->query("DROP TABLE IF EXISTS {$prefix}{$table}"); }
    if ($db) { $db->close(); }
    $wpdb->query("DROP DATABASE IF EXISTS {$database}");
}
