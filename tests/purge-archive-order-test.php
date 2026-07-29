<?php
/**
 * The purge must archive what it deletes, and must not rebuild the tables for nothing.
 *
 * `wp_slim_events.id` is a FOREIGN KEY into `wp_slim_stats(id)` ON DELETE CASCADE, created
 * by the plugin itself. The purge ran, in this order:
 *
 *     1. INSERT INTO slim_stats_archive  ... FROM slim_stats  WHERE dt < cutoff
 *     2. DELETE FROM slim_stats WHERE dt < cutoff          <-- cascades, killing the events
 *     3. INSERT INTO slim_events_archive ... FROM slim_events WHERE dt < cutoff
 *     4. DELETE FROM slim_events WHERE dt < cutoff
 *
 * Step 2 destroys exactly the rows step 3 exists to save. Reproduced on scratch tables
 * carrying the real foreign key: 5 events in, 0 archived; with the order corrected, 5 of 5.
 *
 * It is not a partial loss. An event is stamped with its parent pageview's id at the
 * moment the pageview is recorded, so an event is never older than its parent — measured
 * across all 131,147 rows of the reference dataset: `MIN(e.dt - s.dt) = 0`, zero rows with
 * `e.dt < s.dt`, zero orphans. `e.dt < cutoff` therefore implies `s.dt < cutoff`, so every
 * archive-eligible event is cascaded away first. 102,573 of 102,573 at a 30-day cutoff.
 *
 * Two further properties are pinned here because each of them, on its own, silently
 * disables retention or destroys data:
 *
 *  - The archive INSERTs must be idempotent, and must carry `event_id`. A run interrupted
 *    between archive and delete leaves rows in the archive; the next run then fails on a
 *    duplicate primary key, the `false !== $is_copy_done` guard skips the DELETE, and
 *    retention is wedged forever — silently, every 12 hours. Omitting `event_id` has the
 *    inverse failure: silent duplication.
 *
 *  - OPTIMIZE TABLE must not run on a schedule. On InnoDB it is a full table rebuild
 *    ("Table does not support optimize, doing recreate + analyze instead"), and its cost
 *    tracks table SIZE, not rows removed — measured at 5.5-31.9 s on a 443,535-row /
 *    408 MB replica, and back-to-back runs on a zero-fragmentation table cost the same as
 *    the first. It ran unconditionally, twice a day, even when nothing qualified.
 *
 * Defect id (D1) lives in the workspace performance notes, outside this repository —
 * deliberately not linked, since this file ships to wp.org.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

$source = file_get_contents($plugin_root . '/wp-slimstat.php');
if ($source === false) {
    fwrite(STDERR, "FAIL: cannot read wp-slimstat.php\n");
    exit(1);
}

$purge = slimstat_function_body($source, 'wp_slimstat_purge');
if ($purge === '') {
    fwrite(STDERR, "FAIL: wp_slimstat_purge() not found in wp-slimstat.php\n");
    exit(1);
}

/** Offset of the first match, or null. */
$at = static function (string $pattern) use ($purge): ?int {
    return preg_match($pattern, $purge, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : null;
};

// ── 1. Everything touching events happens before the parent rows are deleted ─
$statsDelete   = $at('/delete\s*\(\s*\$table_stats\s*\)/');
$eventsArchive = $at('/INSERT[^;]*\$table_events_archive/s');
// Both interpolation spellings: "DELETE e FROM {$table_events}" and the builder form.
$eventsDelete  = $at('/DELETE\s+\w+\s+FROM\s+\{?\$table_events\}?|delete\s*\(\s*\$table_events\s*\)/s');

if ($statsDelete === null) {
    $failures[] = 'no DELETE against $table_stats found — if the purge was restructured, '
        . 're-anchor these assertions rather than deleting them';
} else {
    if ($eventsArchive === null) {
        $failures[] = 'the events archive INSERT was not found';
    } elseif ($eventsArchive > $statsDelete) {
        $failures[] = 'the events are archived AFTER the stats DELETE. wp_slim_events.id is '
            . 'a FOREIGN KEY ... ON DELETE CASCADE, so the parent DELETE destroys the event '
            . 'rows first and the archive receives nothing';
    }
    if ($eventsDelete === null) {
        $failures[] = 'no explicit DELETE against $table_events found — relying on the FK '
            . 'cascade makes the deleted set differ from the archived set, and does nothing '
            . 'at all on an install whose tables are MyISAM';
    } elseif ($eventsDelete > $statsDelete) {
        $failures[] = 'the events are deleted AFTER the stats DELETE, so the cascade gets '
            . 'there first';
    }
}

// ── 2. Archiving is idempotent, so an interrupted run cannot wedge retention ─
if (!preg_match('/INSERT\s+IGNORE\s+INTO/i', $purge)) {
    $failures[] = 'the archive INSERTs are not INSERT IGNORE — a run interrupted between '
        . 'archiving and deleting leaves rows in the archive, the next run dies on a '
        . 'duplicate primary key, the guard skips the DELETE, and retention stops working '
        . 'permanently and silently';
}
// That the list carries event_id, and that the INSERT is generated from it, are pinned by
// §2c below and by tests/Unit/PurgeArchiveCollisionGuardTest.php against the real constant.

// ── 2b. A key collision must stop the purge, not delete through it ──────────
//
// INSERT IGNORE cannot tell "already archived" — the replay case it exists for — from
// "a DIFFERENT row already owns this primary key", and reports the second as a silent
// no-op. Deleting afterwards destroys live rows that were never archived. Reachable:
// DELETE does not reset AUTO_INCREMENT, but MariaDB and MySQL 5.7 re-derive it as
// MAX(id)+1 on restart, so clearing records and later rebooting hands out ids from 1
// again. Proven on scratch tables: without this guard, rows the admin asked to ARCHIVE
// were deleted and never copied — data loss where the old code merely stopped working.
if (!preg_match('/PurgeArchive::sameRow\(/', $purge)) {
    $failures[] = 'nothing compares the rows about to be archived against what the archive '
        . 'already holds under those keys — INSERT IGNORE will silently skip a colliding row '
        . 'and the DELETE will then destroy the live copy';
}
if (!preg_match('/record_degradation/', $purge)) {
    $failures[] = 'the purge can abandon a run without recording a degradation — retention '
        . 'would stop silently and the tables would simply keep growing';
}

// ── 2c. The guard is wired to the same column lists the INSERT copies ───────
//
// A guard narrower than the INSERT it protects has false negatives, and every one of them
// is a live row that INSERT IGNORE silently declined to copy and the DELETE then destroyed.
// Measured: an archive row agreeing with the live row on only (id, dt, notes) passed a
// three-column discriminator, live events went 1 -> 0, the archive kept the stale payload,
// and nothing was recorded in slimstat_degradations.
//
// The guard's own behaviour — every copied column compared, only the join key skipped,
// NULL-safe — is pinned directly in tests/Unit/PurgeArchiveCollisionGuardTest.php against
// the real builder. What can only be checked here is the WIRING: that both statements are
// generated from one shared column list, so they cannot drift apart again.
$guardTargets = [
    ['$table_events_archive', 'EVENT_COLUMNS', 'event_id', 'events'],
    ['$table_stats_archive', 'STATS_COLUMNS', 'id', 'pageviews'],
];

foreach ($guardTargets as [$archiveVar, $constant, $joinKey, $label]) {
    $quoted = preg_quote($archiveVar, '/');

    if (!preg_match(
        '/INSERT\s+IGNORE\s+INTO\s+\{?' . $quoted . '\}?\s*\(\s*"\s*\.\s*implode\(/s',
        $purge,
        $ins,
        PREG_OFFSET_CAPTURE
    )) {
        $failures[] = "the {$label} archive INSERT does not build its column list by imploding "
            . 'a shared list — a hand-written list is free to drift from the guard, and the '
            . 'guard is what stops the DELETE removing rows IGNORE never copied';
        continue;
    }

    $before = substr($purge, 0, $ins[0][1]);

    if (!preg_match('/PurgeArchive::' . $constant . '/', $before)) {
        $failures[] = "the {$label} columns do not come from PurgeArchive::{$constant}";
    }
    if (!preg_match('/PurgeArchive::sameRow\([^,]+,\s*\'' . $joinKey . '\'/', $before)) {
        $failures[] = "the {$label} archive INSERT is not preceded by a PurgeArchive::sameRow() "
            . "collision guard joined on '{$joinKey}'";
    }
}

// Nothing may hand-roll a comparison chain beside the shared builder: that is exactly how
// the three-column discriminator got here, and in review it looks like a working guard.
if (preg_match('/<=>/', $purge)) {
    $failures[] = 'the purge contains a literal <=> comparison — collision guards must come '
        . 'from PurgeArchive::sameRow(), which compares every column the INSERT copies';
}

// ── 2d. The archived column lists still match the tables the installer creates ──
//
// The unit test proves every listed column is compared, but it takes the list as given —
// so a column dropped from the list is invisible to it, and a column ADDED to the schema
// is invisible to both. Either way the archive quietly stops carrying that column while
// the purge goes on deleting the live rows. Compare against the CREATE TABLE the installer
// actually issues, which is the only in-repo statement of the real shape.
// Read as source, not via the autoloader: these scans run on PHP-only CI lanes with no
// WordPress and no vendor tree, and PurgeArchive.php exits unless ABSPATH is defined.
$installer   = file_get_contents($plugin_root . '/admin/index.php');
$archiveSrc  = file_get_contents($plugin_root . '/src/Utils/PurgeArchive.php');

$constColumns = static function (string $name) use ($archiveSrc): array {
    if ($archiveSrc === false || !preg_match('/const\s+' . $name . '\s*=\s*\[(.*?)\];/s', $archiveSrc, $m)) {
        return [];
    }
    preg_match_all("/'([a-z_]+)'/", $m[1], $cols);
    return $cols[1];
};

$schemaColumns = static function (string $sql): array {
    // Column definitions only: "name TYPE ...", stopping at the keys and constraints.
    preg_match_all('/^\s*([a-z_]+)\s+(?:INT|BIGINT|SMALLINT|TINYINT|VARCHAR|CHAR|TEXT|DATETIME)\b/im', $sql, $m);
    return $m[1];
};

$schemaTargets = [
    ['/\$stats_table_sql\s*=\s*"(.*?)";/s', 'STATS_COLUMNS', 'slim_stats'],
    ['/\$events_table_sql\s*=\s*"(.*?)";/s', 'EVENT_COLUMNS', 'slim_events'],
];

foreach ($schemaTargets as [$pattern, $constant, $table]) {
    if ($installer === false || !preg_match($pattern, $installer, $sql)) {
        $failures[] = "could not find the {$table} CREATE TABLE in admin/index.php — "
            . 're-anchor this assertion rather than deleting it';
        continue;
    }

    $inSchema = $schemaColumns($sql[1]);
    $declared = $constColumns($constant);

    if ($declared === []) {
        $failures[] = "PurgeArchive::{$constant} not found in src/Utils/PurgeArchive.php";
        continue;
    }

    $unarchived = array_values(array_diff($inSchema, $declared));
    $phantom    = array_values(array_diff($declared, $inSchema));

    if ($unarchived !== []) {
        $failures[] = "{$table} has column(s) " . implode(', ', $unarchived) . ' that '
            . "PurgeArchive::{$constant} does not archive — the purge would delete those rows "
            . 'while the archive silently drops that column';
    }
    if ($phantom !== []) {
        $failures[] = "PurgeArchive::{$constant} names " . implode(', ', $phantom) . ' which '
            . "{$table} does not have — the archive INSERT would fail outright and, because "
            . 'the purge fails closed, retention would stop';
    }
}

// ── 3. The parent DELETE only runs if the events were safely dealt with ─────
// Fail closed. Deleting pageviews whose events could not be archived is the data loss
// this whole file exists to prevent, and it must not be reachable through an error path.
if ($statsDelete !== null) {
    $before = substr($purge, 0, $statsDelete);
    if (!preg_match('/(false\s*===|!==\s*false|if\s*\(\s*false)/', $before)) {
        $failures[] = 'nothing checks whether the events step succeeded before the stats '
            . 'DELETE runs — on any archive failure the cascade destroys the events anyway';
    }
}

// ── 4. Events are selected by their parent too, not only their own dt ───────
// The cascade keys on the PARENT id while the archive filter keys on the event's own dt.
// An event still inside the retention window whose parent has aged out is cascaded away
// without ever being eligible for archiving: 900 such rows at a 60-day cutoff on the
// reference dataset, where the observed event-to-parent lag reaches 43 days.
if (!preg_match('/\bs\.dt\s*<|stats.*\bdt\s*<[^;]*\bOR\b|\bOR\b[^;]*\bs\.dt/is', $purge)) {
    $failures[] = 'the events are selected only by their own dt — an event whose parent '
        . 'pageview has aged out is cascade-deleted without being archived, because the '
        . 'cascade keys on the parent while the filter keys on the child';
}

// ── 5. OPTIMIZE TABLE is not on a schedule ──────────────────────────────────
$optimizes = preg_match_all('/OPTIMIZE\s+TABLE/i', $purge);
if ($optimizes > 0) {
    // The archive tables are append-only. They do not fragment, so rebuilding them is
    // pure cost with no benefit, forever.
    if (preg_match('/OPTIMIZE\s+TABLE[^;]*_archive/i', $purge)) {
        $failures[] = 'the purge rebuilds an archive table. Archive tables are append-only '
            . 'and never fragment — this is a full InnoDB rebuild bought for nothing';
    }
    // Must be reached only after real work, not on every tick.
    if (!preg_match('/\$rows_removed/', $purge)) {
        $failures[] = 'OPTIMIZE TABLE is not gated on rows actually removed — it is a full '
            . 'InnoDB rebuild whose cost tracks table size rather than rows removed, and it '
            . 'ran twice a day even when zero rows qualified';
    }
    // A transient is evictable, and it would fail in the wrong direction: past its
    // retention horizon a site removes rows on every tick, so an evicted flag restores
    // exactly the twice-daily rebuild this gate exists to stop — on the largest sites.
    if (preg_match('/(get|set)_transient\s*\(\s*[\'"]slimstat_purge/', $purge)) {
        $failures[] = 'the OPTIMIZE throttle is stored in a transient — an object cache can '
            . 'evict it, and eviction re-enables the twice-daily table rebuild. Use a '
            . 'non-autoloaded option';
    }
}

// ── 6. A tick with nothing to do costs nothing ──────────────────────────────
// The common case by far: the default retention is 420 days, so on almost every install
// almost every tick has no qualifying rows.
if (!preg_match('/LIMIT\s+1|get_var/i', $purge)) {
    $failures[] = 'the purge does not probe cheaply for work before doing any — with the '
        . 'shipped 420-day retention, nearly every one of the twice-daily ticks has nothing '
        . 'to purge and should cost two indexed lookups';
}

// ── Report ─────────────────────────────────────────────────────────────────
if ($failures !== []) {
    fwrite(STDERR, 'FAIL: purge archive order (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: purge archive order (events archived and deleted before their parents; archiving "
    . "idempotent; no scheduled table rebuilds)\n";
exit(0);
