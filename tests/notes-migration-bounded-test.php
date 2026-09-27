<?php
/**
 * Source-level: the 4.8.8 notes conversion is bounded, resumable, and does not
 * rewrite rows it should leave alone.
 *
 * PINS FIX (S4/S1a). The original was one unbatched statement:
 *
 *   UPDATE {prefix}slim_stats SET notes = CONCAT('[', REPLACE(notes,';',']['), ']')
 *    WHERE notes NOT LIKE '[%'
 *
 * Three problems, in descending order of consequence:
 *
 * 1. UNBOUNDED. One statement, one transaction, no time budget. On a large table it
 *    cannot finish inside max_execution_time; and because the schema version is
 *    stamped only at the END of update_tables_and_options(), each attempt rolled back
 *    its undo and the whole upgrade restarted on the next admin request. That is an
 *    unbounded retry loop, not a failed upgrade.
 *
 * 2. It rewrote the empty string. `'' NOT LIKE '[%'` is TRUE, so a row with empty
 *    notes became the literal '[]'. Measured 0 such rows on the 443,535-row reference
 *    table — the blast radius was overstated in review — but it is wrong wherever
 *    they exist, and the reference table is post-4.8.8 so it cannot exercise this
 *    migration at all.
 *
 * 3. It relied on NULL semantics implicitly. `NULL NOT LIKE '[%'` is NULL, so NULL
 *    rows were excluded — correctly, and by accident. On the reference table that is
 *    329,029 of 443,535 rows (74%). An edit that switched to COALESCE or IFNULL would
 *    silently turn all of them into '[]'.
 *
 * WHY THIS MATTERS FOR S1. The `$wp_slimstat::` fatal (S1) sits UPSTREAM of this
 * statement, so on a pre-4.8.4 site this UPDATE has never executed. Fixing the typo
 * without fixing this converts a white screen into a hung upgrade loop, on the
 * population least able to diagnose it. The two must ship together.
 *
 * This is a construct scan, not a vocabulary scan: it isolates the converter's body
 * by brace matching and asserts on the statements inside it. A behavioural test needs
 * the converter extracted somewhere that can be driven without WordPress — tracked as
 * Phase B work, alongside the replica tests that cannot fail.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$source      = (string) @file_get_contents($plugin_root . '/admin/index.php');

if ('' === $source) {
    fwrite(STDERR, "FAIL: cannot read admin/index.php\n");
    exit(1);
}

$failures = [];

$body = slimstat_function_body($source, 'convert_notes_to_brackets');
if ('' === $body) {
    fwrite(STDERR, "FAIL: convert_notes_to_brackets() not found — the notes conversion must live in one\n"
        . "  isolatable place so it can be bounded and resumed. Inlining it back into\n"
        . "  run_schema_upgrade() reintroduces the unbounded single statement.\n");
    exit(1);
}

// ── 1. The UPDATE is bounded by a primary-key range ─────────────────────────
// Note: not `[^;]*` — the statement contains a `';'` string literal in the REPLACE(),
// so a semicolon-terminated class stops short of the WHERE clause.
if (!preg_match('/UPDATE\s.*?WHERE\s+id\s*>\s*%d\s+AND\s+id\s*<=\s*%d/s', $body)) {
    $failures[] = 'the UPDATE is not bounded by an `id > %d AND id <= %d` range. Without a '
        . 'primary-key bound each statement scans the whole table and cannot finish inside '
        . 'max_execution_time on a large one';
}

// ── 2. It excludes empty and NULL notes ─────────────────────────────────────
if (false === strpos($body, "notes <> ''")) {
    $failures[] = "the UPDATE does not exclude empty notes (`notes <> ''`). The empty string "
        . "satisfies NOT LIKE '[%' and would be rewritten to the literal '[]'";
}
if (false === strpos($body, 'notes IS NOT NULL')) {
    $failures[] = 'the UPDATE does not state `notes IS NOT NULL`. NULL rows are excluded only '
        . 'by NULL comparison semantics; on the reference table that is 74% of rows relying on '
        . 'an implicit rule';
}

// ── 3. Progress survives a timeout ──────────────────────────────────────────
if (!preg_match('/update_option\(\s*self::NOTES_CURSOR_OPTION/', $body)) {
    $failures[] = 'progress is not persisted. Without a stored cursor a timeout restarts the '
        . 'conversion from the beginning every request — the retry loop this fix exists to end';
}
if (!preg_match('/update_option\(\s*self::NOTES_CURSOR_OPTION\s*,.*?,\s*false\s*\)/s', $body)) {
    $failures[] = 'the cursor option is autoloaded. It is written once per batch and read once '
        . 'per upgrade, so it must pass false and stay out of alloptions';
}

// ── 3b. The ceiling is pinned, not re-read ──────────────────────────────────
// Without a pinned ceiling the walk chases rows inserted while it runs: the tracker
// keeps writing, ids keep climbing, and the work set never closes. Those rows are
// already bracketed so the UPDATE skips them — but the WALK does not.
if (!preg_match('/[\'"]ceiling[\'"]\s*=>/', $body)) {
    $failures[] = 'the work set has no pinned ceiling carried across resumes. Re-deriving '
        . 'MAX(id) each pass lets a busy site extend the migration for as long as it runs';
}
if (!preg_match('/id\s*>\s*%d\s+AND\s+id\s*<=\s*%d\s+ORDER BY id/', $body)) {
    $failures[] = 'the batch probe is not bounded by the pinned ceiling, so it can walk past '
        . 'the work set into rows inserted after the migration began';
}

// ── 4. There is a wall-clock budget, and exhausting it yields ───────────────
if (!preg_match('/SCHEMA_UPGRADE_TIME_BUDGET/', $body)) {
    $failures[] = 'no wall-clock budget. A batch loop with no time bound is still one request '
        . 'that can exceed max_execution_time, just with more statements';
}

// ── 4b. The budget is shared across the whole upgrade, not per loop ─────────
// Two independently-budgeted loops in one request is not a budget: the notes
// conversion could spend its 10s and the transient sweep could then spend another.
// Not a `[^)]*` class: the condition contains `(time() - $upgrade_began)`, so a
// paren-excluding class stops inside it. (Second time in this file — the first was
// `[^;]*` stopping at the `';'` literal in the REPLACE(). Character-class exclusions
// in these assertions keep breaking on nested delimiters; use a lazy `.*?`.)
$sweep_bounded = preg_match(
    '/for\s*\(\s*\$sweep\s*=.*?SCHEMA_UPGRADE_TIME_BUDGET.*?\)\s*\{/s',
    $source
);
if (!$sweep_bounded) {
    $failures[] = 'the transient sweep in run_schema_upgrade() does not share the upgrade time '
        . 'budget. It issues up to 50,000 row deletes, so an unclocked sweep can blow the '
        . 'request budget that the notes loop just respected';
}

// ── 5. An incomplete conversion must NOT stamp the schema version ───────────
// This is the property that makes the whole thing safe: returning early from
// run_schema_upgrade() leaves the version untouched, so the next request resumes.
$upgrade = slimstat_function_body($source, 'run_schema_upgrade');
if ('' === $upgrade) {
    $failures[] = 'run_schema_upgrade() not found';
} elseif (!preg_match('/if\s*\(\s*!\s*self::convert_notes_to_brackets\([^)]*\)\s*\)\s*\{[^}]*return\s+false\s*;/s', $upgrade)) {
    $failures[] = 'an incomplete notes conversion does not return early from run_schema_upgrade(). '
        . 'Falling through stamps the schema version at the end of the function and abandons '
        . 'every remaining row in a half-converted column';
}

// ── 6. The cursor is removed on uninstall ───────────────────────────────────
$uninstall = (string) @file_get_contents($plugin_root . '/uninstall.php');
if (false === strpos($uninstall, 'slimstat_notes_migration_cursor')) {
    $failures[] = 'uninstall.php does not delete slimstat_notes_migration_cursor. Every option '
        . 'this plugin creates has to be removable';
}

// No counter here. The eight checks above are straight-line statements over a fixed
// list, so a count of them is always eight and guards nothing — unlike
// consent-migration-gate-test.php, where the equivalent counter walks a set the scan
// DISCOVERS and a zero there genuinely means the scan matched nothing. The vacuity
// risk in this file is the converter going missing, which the early exit above covers.
if ($failures !== []) {
    fwrite(STDERR, 'FAIL: notes migration is not bounded (' . count($failures) . " problem(s))\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

echo "PASS: notes migration bounded, resumable and correctly scoped (11 properties)\n";
exit(0);
