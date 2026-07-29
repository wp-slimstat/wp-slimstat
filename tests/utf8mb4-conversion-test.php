<?php
/**
 * The utf8mb4 conversion must target the collation WordPress uses for users — not the
 * database default — and must not pretend it can run online.
 *
 * ── The trap this pins ─────────────────────────────────────────────────────────────
 *
 * The obvious implementation converts to utf8mb4 and lets MySQL pick the collation, which
 * means the database default. Measured on the reference install, that turns a slow join
 * into a fatal one, because the charsets then match while the COLLATIONS do not:
 *
 *     Illegal mix of collations (utf8mb4_unicode_ci,IMPLICIT)
 *                           and (utf8mb4_unicode_520_ci,IMPLICIT) for operation '='
 *
 * Measured on identical data, the Pro user join across `username` / `user_login`:
 *
 *     utf8mb3_general_ci      120.9 ms   drives from the 39,680-row side
 *     utf8mb4_unicode_ci       11.1 ms   drives from wp_users, then ref
 *     utf8mb4_unicode_520_ci   ERROR     illegal mix of collations
 *
 * ── And why it is a migration rather than an upgrade step ──────────────────────────
 *
 * `ALGORITHM=INPLACE` is refused for a charset change — MySQL answers "Cannot change column
 * type INPLACE. Try ALGORITHM=COPY." So it is a full rebuild that blocks writes, and on a
 * tracking table blocked writes are dropped pageviews. Measured on the real 408 MB /
 * 443,535-row table: 12.4 s at ~36k rows/s, extrapolating to ~42 s at the 1.5M tier.
 * Storage grew +0% (408 -> 409 MB) because the stored data is ASCII.
 *
 * That cost belongs behind an explicit click, which is what the migration UI is.
 *
 * Defect ids live in the workspace performance notes, outside this repository —
 * deliberately not linked, since this file ships to wp.org.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

$file   = $plugin_root . '/src/Migration/Migrations/ConvertTablesToUtf8mb4.php';
$source = (string) @file_get_contents($file);
if ('' === $source) {
    fwrite(STDERR, "FAIL: cannot read src/Migration/Migrations/ConvertTablesToUtf8mb4.php\n");
    exit(1);
}

// ── 1. The target collation comes from wp_users, not the database default ───
$target = slimstat_function_body($source, 'targetCollation');
if ('' === $target) {
    $failures[] = 'targetCollation() is gone — re-anchor this assertion rather than deleting it';
} else {
    if (!preg_match('/user_login/', $target) || !preg_match('/wpdb->users/', $target)) {
        $failures[] = 'the target collation is not resolved from wp_users.user_login. Using the '
            . 'database default instead leaves the charsets matching and the collations '
            . 'different, which makes the Pro user join throw ER_CANT_AGGREGATE_2COLLATIONS — '
            . 'measured, it turns a slow join into a fatal one';
    }
    if (!preg_match('/utf8mb4_/', $target)) {
        $failures[] = 'targetCollation() has no utf8mb4 fallback for the case where wp_users '
            . 'cannot be inspected';
    }
}

// The ALTER must name that collation explicitly.
$run = slimstat_function_body($source, 'run');
if ('' === $run) {
    $failures[] = 'run() not found';
} else {
    if (!preg_match('/CONVERT TO CHARACTER SET utf8mb4 COLLATE/i', $run)) {
        $failures[] = 'the ALTER does not pin an explicit COLLATE. Without it MySQL applies '
            . 'the database default, which is the failure mode this migration exists to avoid';
    }

    // ── 2. It must not claim to be an online operation ──────────────────────
    if (preg_match('/ALGORITHM\s*=\s*INPLACE/i', $run)) {
        $failures[] = 'the ALTER requests ALGORITHM=INPLACE, which MySQL refuses for a charset '
            . 'change ("Cannot change column type INPLACE"). The statement would fail outright '
            . 'rather than falling back';
    }

    // ── 3. A rebuild must not wait forever behind an open report query ──────
    if (!preg_match('/lock_wait_timeout/', $run)) {
        $failures[] = 'run() does not bound lock_wait_timeout. A full rebuild can queue behind '
            . 'an open report query and inherit whatever the host default is';
    }

    // ── 4. A failure has to be visible ──────────────────────────────────────
    if (!preg_match('/record_degradation/', $run)) {
        $failures[] = 'a failed conversion is not recorded, so a site left half-converted has '
            . 'no indication of it';
    }
}

// ── 5. shouldRun() must be driven by the schema, not a stored flag ──────────
// A flag can say "done" while the tables say otherwise — after a restore, say.
$should = slimstat_function_body($source, 'shouldRun');
$pending = slimstat_function_body($source, 'pendingTables');
if ('' === $pending || !preg_match('/information_schema|CHARACTER_SET_NAME/i', $pending)) {
    $failures[] = 'the migration does not decide from the live schema whether it is needed. A '
        . 'stored flag would report success on a database restored from a utf8mb3 dump';
}
if ('' !== $should && !preg_match('/pendingTables|shouldRunCache/', $should)) {
    $failures[] = 'shouldRun() no longer consults the pending-table probe';
}

// ── 6. All four tables, archives included ───────────────────────────────────
foreach (['slim_stats', 'slim_events', 'slim_stats_archive', 'slim_events_archive'] as $table) {
    if (!preg_match('/' . preg_quote($table, '/') . "'/", $source)) {
        $failures[] = "{$table} is not converted. Leaving one table behind reintroduces the "
            . 'mismatch the moment anything joins across them';
    }
}

// ── 7. It is registered, or it is dead code ─────────────────────────────────
$service = (string) @file_get_contents($plugin_root . '/src/Migration/MigrationService.php');
if (!preg_match('/new ConvertTablesToUtf8mb4\(/', $service)) {
    $failures[] = 'ConvertTablesToUtf8mb4 is not registered in MigrationService, so it never '
        . 'appears in the Migration page and cannot be run';
}

// ── Report ─────────────────────────────────────────────────────────────────
if ($failures !== []) {
    fwrite(STDERR, 'FAIL: utf8mb4 conversion (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: utf8mb4 conversion (collation resolved from wp_users, no false INPLACE claim, "
    . "schema-driven, all four tables, registered)\n";
exit(0);
