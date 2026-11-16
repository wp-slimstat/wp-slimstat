<?php
/** wp eval-file on a disposable WordPress DB; all fixture tables have an owned random prefix. */
global $wpdb;
if (!defined('WP_CLI') || !WP_CLI || !preg_match('/^(tests?[-_]|wordpress$)/', DB_NAME)) {
    throw new RuntimeException('A disposable WordPress test database is required.');
}
$eraser = \SlimStat\Services\Privacy\DataEraser::class;
if (getenv('SLIMSTAT_ERASER_BASELINE')) {
    require getenv('SLIMSTAT_ERASER_BASELINE');
    $eraser = \SlimStat\Tests\BaselinePrivacy\DataEraser::class;
}
$originalPrefix = $wpdb->prefix;
$prefix = 'slimstat_cdb_' . bin2hex(random_bytes(6)) . '_';
$tables = ['slim_stats', 'slim_stats_archive', 'slim_events', 'slim_events_archive'];
$failures = [];
$run = static function ($sql) use ($wpdb) {
    if (false === $wpdb->query($sql)) { throw new RuntimeException($wpdb->last_error); }
};
try {
    foreach ($tables as $table) {
        $source = false !== strpos($table, 'events') ? 'slim_events' : 'slim_stats';
        $run("CREATE TABLE {$prefix}{$table} LIKE {$originalPrefix}{$source}");
    }
    $wpdb->prefix = $prefix;
    wp_slimstat::$wpdb = $wpdb;
    foreach (['live', 'archive', 'both'] as $location) {
        foreach ([0, 1, 500, 501, 1000, 1001] as $n) {
            foreach ($tables as $table) { $run("DELETE FROM {$prefix}{$table}"); }
            foreach (('both' === $location ? ['', '_archive'] : [('live' === $location ? '' : '_archive')]) as $suffix) {
                for ($i = 1; $i <= $n; ++$i) {
                    $run("INSERT INTO {$prefix}slim_stats{$suffix} (id, dt, email) VALUES ({$i}, 1000000, 'erase@example.test')");
                    $run("INSERT INTO {$prefix}slim_events{$suffix} (event_id, id, dt) VALUES ({$i}, {$i}, 1000001)");
                }
            }
            foreach (['', '_archive'] as $suffix) {
                $run("INSERT INTO {$prefix}slim_stats{$suffix} (id, dt, email) VALUES (9999, 1000000, 'keep@example.test')");
                $run("INSERT INTO {$prefix}slim_events{$suffix} (event_id, id, dt) VALUES (9999, 9999, 1000001)");
            }
            foreach (['eraseEvents', 'erasePageviews'] as $method) {
                for ($page = 1; $page <= 50; ++$page) {
                    $result = $eraser::$method('erase@example.test', $page);
                    if ($result['done']) { break; }
                }
                $suffix = 'eraseEvents' === $method ? 'slim_events' : 'slim_stats';
                $remaining = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}{$suffix} WHERE id != 9999")
                    + (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}{$suffix}_archive WHERE id != 9999");
                if ($remaining || $page > 4) {
                    $failures[] = "{$location} {$n} {$method}: remaining={$remaining}, calls={$page}";
                }
            }
            foreach ($tables as $table) {
                if ('1' !== $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}{$table} WHERE id = 9999")) {
                    $failures[] = "{$location} {$n}: unrelated visitor lost from {$table}";
                }
            }
        }
    }
    foreach ($tables as $table) { $run("DELETE FROM {$prefix}{$table}"); }
    $run("INSERT INTO {$prefix}slim_stats (id, dt, email) VALUES (1, 1000000, 'erase@example.test')");
    $run("INSERT INTO {$prefix}slim_events (event_id, id, dt) VALUES (1, 1, 1000001)");
    $run("INSERT INTO {$prefix}slim_stats_archive (id, dt, email) VALUES (1, 1000000, 'keep@example.test')");
    $run("INSERT INTO {$prefix}slim_events_archive (event_id, id, dt) VALUES (1, 1, 1000001)");
    $eraser::eraseEvents('erase@example.test', 1);
    if ('1' !== $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}slim_events_archive")) {
        $failures[] = 'Reused live/archive IDs must not erase another visitor';
    }
    if (!getenv('SLIMSTAT_ERASER_BASELINE')) {
        $wpdb->suppress_errors(true);
        $run("DROP TABLE {$prefix}slim_events");
        if (!is_wp_error($eraser::eraseEvents('erase@example.test', 1))) {
            $failures[] = 'Event query failure must stop the WordPress erasure pipeline';
        }
        $run("DROP TABLE {$prefix}slim_stats");
        if (!is_wp_error($eraser::erasePageviews('erase@example.test', 1))) {
            $failures[] = 'Pageview query failure must stop the WordPress erasure pipeline';
        }
    }
} finally {
    $wpdb->prefix = $originalPrefix;
    foreach (array_reverse($tables) as $table) { $run("DROP TABLE IF EXISTS {$prefix}{$table}"); }
}
if ($failures) { throw new RuntimeException(implode("\n", $failures)); }
echo "PASS: live and archived erasure at 0, 1, 500, 501, 1000 and 1001 rows completes without leftovers\n";
