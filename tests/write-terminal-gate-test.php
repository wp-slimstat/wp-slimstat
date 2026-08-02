<?php
/**
 * Source-level: the tracker's write terminals stay tri-state.
 *
 * The behaviour is pinned by tests/Unit/WriteTerminalTest.php. This is the CONSTRUCT half,
 * and it exists for two reasons the unit test cannot cover:
 *
 *   1. It runs on the 7.4 lane and in the mutation registry, where PHPUnit cannot — the
 *      committed autoloader is classmap-authoritative, so a PHPUnit gate would be INVALID
 *      on a clean checkout. That is the ADR-E8 trap, and picking a gate that cannot run is
 *      how a mutation run reports KILLED for a reason that has nothing to do with the code.
 *   2. It forbids the SHAPE that produced C30 anywhere on the write path, not just the
 *      shape at the two call sites the unit test drives.
 *
 * Scoped to constructs, and comments/strings are blanked first — this file names every
 * pattern it forbids, and a raw-text scan would match itself.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

$storage   = slimstat_strip_comments_and_strings((string) file_get_contents($plugin_root . '/src/Tracker/Storage.php'));
$processor = slimstat_strip_comments_and_strings((string) file_get_contents($plugin_root . '/src/Tracker/Processor.php'));

// ── C30: no terminal may test only for `false` ──────────────────────────────
$process = slimstat_function_body($processor, 'process');

if (preg_match('/false\s*===\s*\$stat\[[\'"]id[\'"]\]/', $process)) {
    $failures[] = 'process() still tests `false === $stat[\'id\']`. Query::execute() returns '
        . '`insert_id ?: $result`, so a 0 — an INSERT IGNORE swallowing a duplicate, or a row '
        . 'an FK refused — passes that check and propagates as $stat[\'id\'] = 0, whose event '
        . 'insert then violates the FK and is silently dropped';
}

if (!preg_match('/->isStored\s*\(/', $process)) {
    $failures[] = 'process() no longer asks whether a row was actually INSERTED. "Not failed" '
        . 'is not "stored" — that conflation is C30';
}

// ── S6: the missing-column window is handled, and reactively ────────────────
$insert = slimstat_function_body($storage, 'insertRow');

if (!preg_match('/isUnknownColumnError\s*\(/', $insert)) {
    $failures[] = 'insertRow() no longer detects an unknown-column failure. init_environment() '
        . 'uses CREATE TABLE IF NOT EXISTS, which can create a missing table and can NEVER add '
        . 'a missing column — so without this the v6-code-on-v5-schema window drops every hit, '
        . 'unbounded, because auto-updates and WP-CLI produce no admin request';
}

if (!preg_match('/array_intersect_key\s*\(/', $insert)) {
    $failures[] = 'insertRow() no longer writes the INTERSECTION of wanted and present columns '
        . '(decision P1). Losing a field is better than losing the pageview';
}

$probe = slimstat_function_body($storage, 'presentColumns');
if ('' === trim($probe)) {
    $failures[] = 'the column probe is gone';
}

// The probe must not be reachable before an insert has already failed: the tracker budget
// is denominated in queries and wp_options writes, and neither may move on the path that
// always runs.
if (preg_match('/presentColumns\s*\(/', $insert)) {
    $before = substr($insert, 0, (int) strpos($insert, 'presentColumns('));
    if (false === strpos($before, 'isFailed')) {
        $failures[] = 'the column probe runs before the insert has failed. It must be reactive: '
            . 'a preemptive probe adds a query to every tracked hit, which moves a budget that '
            . 'test:visit-id-query-budget and test:tracker-option-writes both pin';
    }
}

// ── The stale-insert_id trap ────────────────────────────────────────────────
$write = slimstat_function_body($storage, 'write');

if (false === strpos($write, 'last_error')) {
    $failures[] = 'the insert terminal no longer reads $wpdb->last_error. It cannot infer '
        . 'failure from the return value alone: $wpdb->insert_id KEEPS the previous successful '
        . 'insert\'s id when a later statement fails, so `insert_id ?: $result` hands back the '
        . 'PAGEVIEW\'s id for a failed EVENT insert on the same connection';
}

// ── C31: the second write path reports what it did ──────────────────────────
$update = slimstat_function_body($storage, 'updateRow');

if (!preg_match('/(if\s*\(\s*false\s*===\s*\$query->execute\s*\(\s*\)|\$\w+\s*=\s*\$query->execute\s*\()/', $update)) {
    $failures[] = 'updateRow() discards $query->execute() again. It is the second write path — '
        . 'every dt_out heartbeat, every `;;;` append, every `[k:v]` note — and dt_out is the '
        . 'one column an insert-time dual write can never carry, so a divergence there must at '
        . 'least be representable';
}

foreach (['insertRow' => $insert, 'updateRow' => $update] as $name => $body) {
    if (!preg_match('/WriteResult::/', $body)) {
        $failures[] = "{$name}() no longer returns a WriteResult, so its outcome is an integer "
            . 'again — and an integer cannot distinguish "stored 0 rows" from "row id 0"';
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: write terminals (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: write terminals are tri-state; the column probe is reactive and intersects\n";
