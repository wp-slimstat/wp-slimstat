<?php
/** Retention safety on owned tables only; includes interrupted and concurrent writes. */
global $wpdb;
if (!defined('WP_CLI') || !WP_CLI || !preg_match('/^(tests?[-_]|wordpress$)/', DB_NAME)) {
    throw new RuntimeException('A disposable WordPress test database is required.');
}
$prefix = 'slimstat_purge_' . bin2hex(random_bytes(6)) . '_';
$originalPrefix = $wpdb->prefix;
$originalDb = wp_slimstat::$wpdb;
$originalSettings = wp_slimstat::$settings;
$originalLimit = ini_get('max_execution_time');
$savedOptions = [];
foreach (['cron', wp_slimstat::LAST_PURGE_OK_OPTION, 'slimstat_data_ver', 'slimstat_goals_cache_ver', 'slimstat_degradations'] as $key) {
    $savedOptions[$key] = get_option($key, null);
}
$tables = array_map(static function ($suffix) use ($prefix) { return $prefix . $suffix; }, ['slim_events_archive', 'slim_events', 'slim_stats_archive', 'slim_stats']);
$now = wp_slimstat::now();
$old = $now - 40 * DAY_IN_SECONDS;
$check = static function ($ok, $message) { if (!$ok) { throw new RuntimeException($message); } };
$run = static function ($sql) use ($wpdb) { if (false === $wpdb->query($sql)) { throw new RuntimeException($wpdb->last_error); } };
$read = static function ($suffix, $key) use ($wpdb, $prefix) {
    $rows = $wpdb->get_results("SELECT * FROM {$prefix}{$suffix} ORDER BY {$key}", ARRAY_A);
    if ($wpdb->last_error) { throw new RuntimeException($wpdb->last_error); }
    return $rows;
};
$drop = static function () use ($run, $tables) { foreach ($tables as $table) { $run("DROP TABLE IF EXISTS {$table}"); } };
$setup = static function ($engine = 'InnoDB') use ($run, $prefix, $drop, $old, $now) {
    $drop();
    $run("CREATE TABLE {$prefix}slim_stats (id BIGINT UNSIGNED PRIMARY KEY, resource VARCHAR(200), notes VARCHAR(200), dt BIGINT, KEY dt (dt)) ENGINE={$engine} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $foreign = $engine === 'InnoDB' ? ", CONSTRAINT {$prefix}fk FOREIGN KEY (id) REFERENCES {$prefix}slim_stats(id) ON DELETE CASCADE" : '';
    $run("CREATE TABLE {$prefix}slim_events (event_id BIGINT UNSIGNED PRIMARY KEY, id BIGINT UNSIGNED, notes VARCHAR(200), dt BIGINT, KEY id (id), KEY dt (dt) {$foreign}) ENGINE={$engine} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $run("CREATE TABLE {$prefix}slim_stats_archive LIKE {$prefix}slim_stats");
    $run("CREATE TABLE {$prefix}slim_events_archive LIKE {$prefix}slim_events");
    $run("INSERT INTO {$prefix}slim_stats VALUES (1, '/Alpha', NULL, {$old}), (2, '/retained', 'canary', {$now})");
    $run("INSERT INTO {$prefix}slim_events VALUES (1, 1, 'old event', {$old}), (2, 1, 'recent child of old parent', {$now}), (3, 2, 'retained event', {$now})");
    wp_slimstat::$settings['auto_purge_delete'] = 'on';
    update_option(wp_slimstat::LAST_PURGE_OK_OPTION, 1, false);
};
$ledger = null;
$failures = [];
try {
    $wpdb->prefix = $prefix;
    wp_slimstat::$wpdb = $wpdb;
    wp_slimstat::$settings['auto_purge'] = '30';
    wp_slimstat::$settings['auto_purge_optimize'] = 'no';
    // Isolate live-data retention from the independently tested archive expiration job.
    wp_slimstat::$settings['archive_retention_days'] = 0;
    foreach (['InnoDB', 'MyISAM'] as $engine) {
        foreach (['complete', 'delete-only', 'partial-archive', 'missing-copy', 'truncated-copy', 'missing-column', 'custom-column', 'case-collision', 'many-events', 'late-event', 'late-edit', 'budget-resume'] as $case) {
            try {
                $setup($engine);
                $beforeStats = $read('slim_stats', 'id'); $beforeEvents = $read('slim_events', 'event_id');
                if ($case === 'delete-only') { wp_slimstat::$settings['auto_purge_delete'] = 'no'; }
                if ($case === 'partial-archive') {
                    $run("INSERT INTO {$prefix}slim_stats_archive SELECT * FROM {$prefix}slim_stats WHERE id = 1");
                    $run("INSERT INTO {$prefix}slim_events_archive SELECT * FROM {$prefix}slim_events WHERE event_id = 1");
                }
                if ($case === 'case-collision') {
                    $run("INSERT INTO {$prefix}slim_stats_archive SELECT * FROM {$prefix}slim_stats WHERE id = 1");
                    $run("UPDATE {$prefix}slim_stats_archive SET resource = '/alpha' WHERE id = 1");
                }
                if ($case === 'missing-column') { $run("ALTER TABLE {$prefix}slim_stats_archive DROP COLUMN resource"); }
                if ($case === 'custom-column') {
                    foreach (['slim_stats', 'slim_stats_archive'] as $suffix) {
                        $run("ALTER TABLE {$prefix}{$suffix} ADD custom_payload VARCHAR(100)");
                    }
                    $run("UPDATE {$prefix}slim_stats SET custom_payload = 'must survive retention'");
                    $beforeStats = $read('slim_stats', 'id');
                }
                if ($case === 'many-events') {
                    for ($i = 4; $i <= 1004; ++$i) { $run("INSERT INTO {$prefix}slim_events VALUES ({$i}, 1, 'event-{$i}', {$old})"); }
                    $beforeEvents = $read('slim_events', 'event_id');
                }
                if ($case === 'truncated-copy') { $run("ALTER TABLE {$prefix}slim_stats_archive MODIFY resource VARCHAR(3)"); }
                if ($case === 'budget-resume') {
                    for ($day = 3; $day <= 60; ++$day) {
                        $dt = $now - $day * DAY_IN_SECONDS + HOUR_IN_SECONDS;
                        $run("INSERT INTO {$prefix}slim_stats VALUES ({$day}, '/day-{$day}', NULL, {$dt})");
                        $eventId = 100 + $day;
                        $run("INSERT INTO {$prefix}slim_events VALUES ({$eventId}, {$day}, 'event-{$day}', {$dt})");
                    }
                    $beforeStats = $read('slim_stats', 'id'); $beforeEvents = $read('slim_events', 'event_id');
                    ini_set('max_execution_time', '1');
                }
                $triggered = false; $statements = [];
                $ledger = static function ($sql) use ($case, $prefix, $wpdb, $now, &$triggered, &$statements) {
                    $statements[] = $sql;
                    if ($case === 'missing-copy' && strpos($sql, "INSERT IGNORE INTO {$prefix}slim_events_archive") !== false) {
                        $triggered = true; return 'SELECT 0'; // An ignored/no-op archive write is not coverage.
                    }
                    $target = $case === 'late-edit' ? 'slim_stats' : 'slim_events';
                    if (!$triggered && preg_match('/^DELETE(?:\s+\w+)?\s+FROM\s+`?' . $prefix . $target . '\b/i', $sql)) {
                        $triggered = true;
                        if ($case === 'late-event') { $wpdb->query("INSERT INTO {$prefix}slim_events VALUES (99, 1, 'arrived during purge', {$now})"); }
                        if ($case === 'late-edit') { $wpdb->query("UPDATE {$prefix}slim_stats SET resource = '/changed-after-copy' WHERE id = 1"); }
                        if ($case === 'budget-resume') { usleep(600000); }
                    }
                    return $sql;
                };
                add_filter('query', $ledger);
                wp_slimstat::wp_slimstat_purge();
                remove_filter('query', $ledger); $ledger = null;
                ini_set('max_execution_time', $originalLimit);
                $stats = $read('slim_stats', 'id'); $events = $read('slim_events', 'event_id');
                $archiveStats = $read('slim_stats_archive', 'id'); $archiveEvents = $read('slim_events_archive', 'event_id');
                $find = static function ($rows, $key, $id) { foreach ($rows as $row) { if ((int) $row[$key] === $id) { return $row; } } return null; };
                $check($find($stats, 'id', 2) === $beforeStats[1] && $find($events, 'event_id', 3) === $beforeEvents[2], 'retained canaries changed');
                if (in_array($case, ['missing-copy', 'truncated-copy', 'missing-column', 'custom-column', 'case-collision'], true)) {
                    $check($find($stats, 'id', 1) === $beforeStats[0], 'unverified original pageview was deleted');
                    if ($case === 'missing-copy') { $check($triggered && $find($events, 'event_id', 1) === $beforeEvents[0], 'unarchived event was deleted'); }
                    $check((int) get_option(wp_slimstat::LAST_PURGE_OK_OPTION) === 1, 'failed verification stamped success');
                } elseif ($case === 'late-event') {
                    $late = $find($events, 'event_id', 99) ?? $find($archiveEvents, 'event_id', 99);
                    $check($triggered && ($late['notes'] ?? '') === 'arrived during purge', 'concurrent event disappeared without an archive');
                } elseif ($case === 'late-edit') {
                    $changed = $find($stats, 'id', 1) ?? $find($archiveStats, 'id', 1);
                    $check($triggered && ($changed['resource'] ?? '') === '/changed-after-copy', 'concurrent edit disappeared without an archive');
                } elseif ($case === 'budget-resume') {
                    $check(count($stats) > 29 && count($stats) < 60, 'budget must stop after bounded progress');
                    $check((int) get_option(wp_slimstat::LAST_PURGE_OK_OPTION) === 1, 'partial pass stamped success');
                    $next = wp_next_scheduled('wp_slimstat_purge');
                    $check($next && $next <= time() + 120, 'partial pass must schedule a near-term continuation');
                    wp_slimstat::wp_slimstat_purge();
                    $check(count($read('slim_stats', 'id')) === 29 && count($read('slim_events', 'event_id')) === 29, 'continuation did not finish');
                    $check(count($read('slim_stats_archive', 'id')) === 31 && count($read('slim_events_archive', 'event_id')) === 32, 'continuation archive coverage differs');
                    $expectedStats = array_values(array_filter($beforeStats, static function ($row) use ($now) { return (int) $row['dt'] < $now - 30 * DAY_IN_SECONDS; }));
                    $oldIds = array_column($expectedStats, 'id');
                    $expectedEvents = array_values(array_filter($beforeEvents, static function ($row) use ($oldIds) { return in_array($row['id'], $oldIds, true); }));
                    $check($read('slim_stats_archive', 'id') === $expectedStats && $read('slim_events_archive', 'event_id') === $expectedEvents, 'resumed archive row values changed');
                } else {
                    $check(count($stats) === 1 && count($events) === 1, 'retention left eligible rows');
                    if ($case !== 'delete-only') {
                        $check($archiveStats === [$beforeStats[0]] && $archiveEvents === array_values(array_filter($beforeEvents, static function ($row) { return (int) $row['id'] === 1; })), 'archive values differ or duplicate');
                    } else {
                        $check($archiveStats === [] && $archiveEvents === [], 'delete-only wrote an archive');
                        $check(!preg_grep('/(?:INSERT|SHOW|JOIN).*' . $prefix . 'slim_.*archive/i', $statements), 'delete-only read archive schema or contents');
                    }
                    $check((int) get_option(wp_slimstat::LAST_PURGE_OK_OPTION) > 1, 'complete pass must stamp success');
                }
                if ($case === 'complete') {
                    $parentDeletes[$engine] = array_values(preg_grep('/^DELETE s FROM ' . $prefix . 'slim_stats/', $statements))[0];
                }
                echo "PASS {$engine} {$case}\n";
            } catch (Throwable $error) {
                $failures[] = "{$engine} {$case}: " . $error->getMessage();
                echo 'FAIL ' . end($failures) . "\n";
            } finally {
                if ($ledger) { remove_filter('query', $ledger); $ledger = null; }
                ini_set('max_execution_time', $originalLimit);
            }
        }
        // Execute the actual parent DELETE captured above while another connection owns
        // the parent and commits a new child. The guard must re-evaluate after the wait.
        foreach (['REPEATABLE READ', 'READ COMMITTED'] as $isolation) {
            $locker = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
            $worker = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
            try {
                $setup($engine);
                $run("INSERT INTO {$prefix}slim_stats_archive SELECT * FROM {$prefix}slim_stats WHERE id = 1");
                $run("DELETE FROM {$prefix}slim_events WHERE id = 1");
                $worker->query('SET SESSION innodb_lock_wait_timeout = 5, lock_wait_timeout = 5');
                $worker->query('SET SESSION TRANSACTION ISOLATION LEVEL ' . $isolation);
                if ($engine === 'InnoDB') {
                    $locker->query('START TRANSACTION');
                    $check('1' === $locker->get_var("SELECT id FROM {$prefix}slim_stats WHERE id = 1 FOR UPDATE"), 'race holder must lock the parent');
                } else {
                    $check(false !== $locker->query("LOCK TABLES {$prefix}slim_stats WRITE, {$prefix}slim_events WRITE"), 'race holder must lock legacy tables');
                }
                $handle = $worker->__get('dbh');
                $check(mysqli_query($handle, $parentDeletes[$engine], MYSQLI_ASYNC), 'async parent deletion must start');
                usleep(100000);
                $ready = [$handle]; $errors = []; $reject = [];
                $check(mysqli_poll($ready, $errors, $reject, 0) === 0, 'parent DELETE must be waiting on the owned lock');
                $check(false !== $locker->query("INSERT INTO {$prefix}slim_events VALUES (99, 1, 'concurrent commit', {$now})"), 'concurrent event insert must succeed');
                $locker->query($engine === 'InnoDB' ? 'COMMIT' : 'UNLOCK TABLES');
                $ready = [$handle]; $errors = []; $reject = [];
                $check(mysqli_poll($ready, $errors, $reject, 8) === 1, 'waiting parent DELETE must finish');
                $check(mysqli_reap_async_query($handle) !== false && mysqli_affected_rows($handle) === 0, 'waiting DELETE must retain the parent of a newly committed child');
                $check('1' === $wpdb->get_var("SELECT id FROM {$prefix}slim_stats WHERE id = 1")
                    && 'concurrent commit' === $wpdb->get_var("SELECT notes FROM {$prefix}slim_events WHERE event_id = 99"), 'concurrent child or parent disappeared');
                echo "PASS {$engine} concurrent lock release {$isolation}\n";
            } catch (Throwable $error) {
                $failures[] = "{$engine} concurrent lock release {$isolation}: " . $error->getMessage();
                echo 'FAIL ' . end($failures) . "\n";
            } finally {
                $locker->query('ROLLBACK'); $locker->query('UNLOCK TABLES');
                $locker->close(); $worker->close();
            }
        }
    }
} finally {
    $drop();
    $wpdb->prefix = $originalPrefix;
    wp_slimstat::$wpdb = $originalDb;
    wp_slimstat::$settings = $originalSettings;
    ini_set('max_execution_time', $originalLimit);
    foreach ($savedOptions as $key => $value) {
        if ($value === null) { delete_option($key); } else { update_option($key, $value); }
    }
}
if ($failures) { throw new RuntimeException(implode('; ', $failures)); }
echo "PASS: retention archive/delete/replay/failure/concurrent-write/budget matrix on InnoDB and MyISAM\n";
