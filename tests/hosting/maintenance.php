<?php
/** wp eval-file tests/hosting/maintenance.php; isolated Docker database only. */
if (!defined('WP_CLI') || !WP_CLI || getenv('SLIMSTAT_HOSTING_DISPOSABLE') !== '1' || !preg_match('/^(wordpress|tests-[a-z0-9-]+)$/D', DB_NAME) || DB_HOST !== 'db') {
    throw new RuntimeException('Requires the explicitly armed disposable Docker stack.');
}
$sourceDatabase = DB_NAME;
$sourceDb = $GLOBALS['wpdb'];
$sourcePrefix = $sourceDb->prefix;
$database = 'test_slimstat_maintenance_' . bin2hex(random_bytes(4));
if (false === $sourceDb->query("CREATE DATABASE `$database`")) {
    throw new RuntimeException('Cannot create disposable maintenance database.');
}
$db = new wpdb(DB_USER, DB_PASSWORD, $database, DB_HOST);
$originalSettings = wp_slimstat::$settings;
$results = [];
$failures = [];
try {
    foreach (['empty', 'delete-off', 'delete-on', 'archive-fk', 'archive-no-fk', 'collision', 'probe-error'] as $index => $case) {
        $prefix = "hc{$index}_";
        $db->set_prefix($prefix);
        foreach (['options', 'slim_stats', 'slim_events', 'slim_stats_archive', 'slim_events_archive'] as $suffix) {
            if (false === $db->query("CREATE TABLE {$prefix}{$suffix} LIKE `{$sourceDatabase}`.{$sourcePrefix}{$suffix}")) {
                throw new RuntimeException($db->last_error);
            }
        }
        $GLOBALS['wpdb'] = wp_slimstat::$wpdb = $db;
        wp_cache_flush();
        wp_slimstat::$settings = array_merge($originalSettings, [
            'auto_purge' => 30,
            'auto_purge_delete' => strpos($case, 'archive') === 0 || $case === 'collision' ? 'on' : 'no',
            'auto_purge_optimize' => $case === 'delete-on' ? 'on' : 'no',
        ]);
        $old = wp_slimstat::now() - 40 * DAY_IN_SECONDS;
        $recent = wp_slimstat::now();
        if (!in_array($case, ['empty', 'probe-error'], true)) {
            $db->insert($prefix . 'slim_stats', ['id' => 1, 'dt' => $old, 'resource' => '/old']);
            $db->insert($prefix . 'slim_stats', ['id' => 2, 'dt' => $recent, 'resource' => '/recent']);
            $db->insert($prefix . 'slim_events', ['event_id' => 1, 'id' => 1, 'dt' => $recent, 'notes' => 'recent child of expired parent']);
            $db->insert($prefix . 'slim_events', ['event_id' => 2, 'id' => 2, 'dt' => $old, 'notes' => 'independently expired']);
            $db->insert($prefix . 'slim_events', ['event_id' => 3, 'id' => 2, 'dt' => $recent, 'notes' => 'retained']);
        }
        if ($case === 'archive-fk') {
            if (false === $db->query("ALTER TABLE {$prefix}slim_events ADD CONSTRAINT child_parent FOREIGN KEY (id) REFERENCES {$prefix}slim_stats(id) ON DELETE CASCADE")) {
                throw new RuntimeException($db->last_error);
            }
        }
        if ($case === 'collision') {
            $db->query("INSERT INTO {$prefix}slim_events_archive SELECT * FROM {$prefix}slim_events WHERE event_id = 1");
            $db->query("UPDATE {$prefix}slim_events_archive SET notes = 'different archived payload' WHERE event_id = 1");
        }
        update_option(wp_slimstat::LAST_PURGE_OK_OPTION, 1, false);
        if ($case === 'probe-error') {
            $db->query("DROP TABLE {$prefix}slim_stats");
        }
        $queries = [];
        $capture = static function ($sql) use (&$queries) { $queries[] = $sql; return $sql; };
        add_filter('query', $capture);
        $suppressed = $db->suppress_errors(true);
        $start = microtime(true);
        wp_slimstat::wp_slimstat_purge();
        $elapsed = (microtime(true) - $start) * 1000;
        $db->suppress_errors($suppressed);
        remove_filter('query', $capture);
        $optimize = array_values(array_filter($queries, static function ($sql) { return strpos($sql, 'OPTIMIZE TABLE') === 0; }));
        $success = (int) get_option(wp_slimstat::LAST_PURGE_OK_OPTION) > 1;
        $expectedSuccess = !in_array($case, ['collision', 'probe-error'], true);
        $checks = [
            'success_marker' => $success === $expectedSuccess,
            'optimization_control' => count($optimize) === ($case === 'delete-on' ? 2 : 0),
            'archives_not_optimized' => strpos(implode(' ', $optimize), '_archive') === false,
        ];
        if ($case === 'collision') {
            $checks['live_rows_preserved'] = (int) $db->get_var("SELECT COUNT(*) FROM {$prefix}slim_stats") === 2
                && (int) $db->get_var("SELECT COUNT(*) FROM {$prefix}slim_events") === 3;
        } elseif (!in_array($case, ['empty', 'probe-error'], true)) {
            $checks['recent_parent_survives'] = $db->get_col("SELECT id FROM {$prefix}slim_stats ORDER BY id") === ['2'];
            $checks['recent_event_survives'] = $db->get_col("SELECT event_id FROM {$prefix}slim_events ORDER BY event_id") === ['3'];
            if (strpos($case, 'archive') === 0) {
                $checks['both_eligible_events_archived'] = $db->get_col("SELECT event_id FROM {$prefix}slim_events_archive ORDER BY event_id") === ['1', '2'];
                $checks['expired_parent_archived'] = $db->get_col("SELECT id FROM {$prefix}slim_stats_archive") === ['1'];
            }
        }
        foreach ($checks as $name => $passed) {
            if (!$passed) { $failures[] = "$case/$name"; }
        }
        $results[$case] = ['milliseconds' => $elapsed, 'checks' => $checks, 'optimization_statements' => $optimize];
    }
    // A separate no-FK topology: more than two jobs of expired data with capture off.
    $db->set_prefix('hc_heatmap_');
    $db->query("CREATE TABLE hc_heatmap_options LIKE `{$sourceDatabase}`.{$sourcePrefix}options");
    $db->query("CREATE TABLE hc_heatmap_slim_stats LIKE `{$sourceDatabase}`.{$sourcePrefix}slim_stats");
    $db->query(\SlimStat\Schema\Schema::createTableSql('slim_heatmap', 'hc_heatmap_', 'utf8mb4_unicode_ci'));
    $db->query('ALTER TABLE hc_heatmap_slim_heatmap DROP FOREIGN KEY fk_hc_heatmap_slim_heatmap_id');
    wp_cache_flush();
    wp_slimstat::$settings = array_merge($originalSettings, ['auto_purge' => 30, 'heatmap_capture' => 'off']);
    update_option(\SlimStat\Heatmap\Store::STATE, ['schema' => \SlimStat\Heatmap\Store::SCHEMA], false);
    $old = wp_slimstat::now() - 40 * DAY_IN_SECONDS;
    for ($start = 1; $start <= 100001; $start += 1000) {
        $rows = [];
        for ($id = $start; $id <= min(100001, $start + 999); ++$id) {
            $rows[] = "($id, 0, 0, UNHEX('0000000000000001'), $old)";
        }
        if (false === $db->query('INSERT INTO hc_heatmap_slim_heatmap (id,kind,seq,page,dt) VALUES ' . implode(',', $rows))) {
            throw new RuntimeException($db->last_error);
        }
    }
    $db->query($db->prepare("INSERT INTO hc_heatmap_slim_heatmap (id,kind,seq,page,dt) VALUES (100002,0,0,UNHEX('0000000000000001'),%d)", wp_slimstat::now()));
    $checks = [];
    // Query this table specifically: an earlier case deliberately has an events FK.
    $checks['no_foreign_keys'] = 0 === (int) $db->get_var($db->prepare("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = %s AND TABLE_NAME = 'hc_heatmap_slim_heatmap' AND REFERENCED_TABLE_NAME IS NOT NULL", $database));
    $otherOwner = (time() + 900) . ':other-worker';
    update_option(\SlimStat\Heatmap\Store::PURGE_CLAIM, $otherOwner, false);
    \SlimStat\Heatmap\Store::purge();
    $checks['other_worker_preserved'] = $otherOwner === get_option(\SlimStat\Heatmap\Store::PURGE_CLAIM)
        && 100002 === (int) $db->get_var('SELECT COUNT(*) FROM hc_heatmap_slim_heatmap');
    delete_option(\SlimStat\Heatmap\Store::PURGE_CLAIM);
    update_option(\SlimStat\Heatmap\Store::PURGE_PENDING, true, false);
    $db->query('RENAME TABLE hc_heatmap_slim_heatmap TO hc_heatmap_saved');
    $suppressed = $db->suppress_errors(true);
    try {
        \SlimStat\Heatmap\Store::purge();
        $checks['query_error_visible'] = isset(wp_slimstat::get_degradations()['purge (heatmap rows)']);
        $checks['query_error_keeps_backlog'] = (bool) get_option(\SlimStat\Heatmap\Store::PURGE_PENDING);
        $checks['query_error_releases_claim'] = false === get_option(\SlimStat\Heatmap\Store::PURGE_CLAIM, false);
    } finally {
        $db->suppress_errors($suppressed);
        $db->query('RENAME TABLE hc_heatmap_saved TO hc_heatmap_slim_heatmap');
    }
    update_option(\SlimStat\Heatmap\Store::PURGE_CLAIM, (time() - 1) . ':expired-worker', false);
    $counts = [];
    $pending = [];
    for ($job = 0; $job < 4; ++$job) {
        wp_clear_scheduled_hook(\SlimStat\Heatmap\Store::PURGE_HOOK);
        \SlimStat\Heatmap\Store::purge();
        $counts[] = (int) $db->get_var('SELECT COUNT(*) FROM hc_heatmap_slim_heatmap');
        $pending[] = (bool) get_option(\SlimStat\Heatmap\Store::PURGE_PENDING, false);
        $checks['job_' . $job . '_schedule'] = (bool) wp_next_scheduled(\SlimStat\Heatmap\Store::PURGE_HOOK) === ($job < 2);
        $checks['job_' . $job . '_claim_released'] = false === get_option(\SlimStat\Heatmap\Store::PURGE_CLAIM, false);
    }
    $checks['bounded_convergence'] = [50002, 2, 1, 1] === $counts;
    $checks['backlog_visibility'] = [true, true, false, false] === $pending;
    $checks['recent_survives'] = ['100002'] === $db->get_col('SELECT id FROM hc_heatmap_slim_heatmap');
    foreach ($checks as $name => $passed) {
        if (!$passed) { $failures[] = 'heatmap/' . $name; }
    }
    $results['heatmap'] = ['checks' => $checks, 'remaining_per_job' => $counts];
} finally {
    $GLOBALS['wpdb'] = wp_slimstat::$wpdb = $sourceDb;
    wp_slimstat::$settings = $originalSettings;
    wp_cache_flush();
    // Only the database created by this invocation is removed.
    $sourceDb->query("DROP DATABASE `$database`");
}
echo json_encode(['php' => PHP_VERSION, 'database' => $sourceDb->db_version(), 'results' => $results, 'failures' => $failures], JSON_PRETTY_PRINT) . "\n";
if ($failures) { WP_CLI::error('Maintenance regressions: ' . implode(', ', $failures)); }
