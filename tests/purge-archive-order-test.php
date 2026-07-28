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
if (!preg_match('/\(\s*event_id\s*,/', $purge)) {
    $failures[] = 'the events archive INSERT does not carry event_id — without the primary '
        . 'key there is nothing for INSERT IGNORE to dedupe on, so a repeated run silently '
        . 'duplicates every archived event';
}

// ── 2b. A key collision must stop the purge, not delete through it ──────────
//
// INSERT IGNORE cannot tell "already archived" — the replay case it exists for — from
// "a DIFFERENT row already owns this primary key", and reports the second as a silent
// no-op. Deleting afterwards destroys live rows that were never archived. Reachable:
// DELETE does not reset AUTO_INCREMENT, but MariaDB and MySQL 5.7 re-derive it as
// MAX(id)+1 on restart, so clearing records and later rebooting hands out ids from 1
// again. Proven on scratch tables: without this guard, rows the admin asked to ARCHIVE
// were deleted and never copied — data loss where the old code merely stopped working.
if (!preg_match('/<=>/', $purge)) {
    $failures[] = 'nothing compares the rows about to be archived against what the archive '
        . 'already holds under those keys — INSERT IGNORE will silently skip a colliding row '
        . 'and the DELETE will then destroy the live copy';
}
if (!preg_match('/record_degradation/', $purge)) {
    $failures[] = 'the purge can abandon a run without recording a degradation — retention '
        . 'would stop silently and the tables would simply keep growing';
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
