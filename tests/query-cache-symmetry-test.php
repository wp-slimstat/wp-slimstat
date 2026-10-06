<?php
/**
 * A cache that is read must be written, and a sweep must name the prefix that is written.
 *
 * ── D72: read on every call, written only sometimes ────────────────────────────────
 *
 * wp_slimstat_db::get_results() reads a transient for EVERY select that reaches it, then
 * used to write it back only when a conversion regex could parse the SQL into the query
 * builder. Anything that regex could not parse paid a get_transient() on every render and
 * never once benefited.
 *
 * That is every Pro report. Measured on slimview3: 3 get_results() calls, **0 converted**,
 * all three read, none wrote, and `_transient_slimstat_query_*` held 0 rows. The same query
 * in two formattings, measured through the real method:
 *
 *     SELECT … FROM tbl WHERE …          (single spaces)  -> regex matched, cache written
 *     "\n\t\t\tSELECT …\n\t\t\tFROM …"   (tab indented)   -> read every time, never written
 *
 * Widening the regex is not the fix: it demands `[^ ]+` for the table, and Pro's queries
 * read `FROM \`local\`.wp_users tu INNER JOIN …`. The asymmetry is the defect, not the
 * parsing. The win is small and should be stated as such — 600 -> 598 queries per render on
 * slimview3 with a historical range — but a cache nobody can hit is worse than no cache.
 *
 * ── D7: the sweep named a prefix nothing writes ────────────────────────────────────
 *
 * The upgrade routine cleared `_transient_wp_slimstat_cache_%`. Keys are written by
 * Query::getCacheKeyForQuery() as `wp_slimstat_query_%`. Measured on the reference install:
 * the old LIKE matched **0** rows against **2,146** that existed. The stale-cache
 * inconsistency it was written to prevent was never prevented, and nothing else purges them.
 *
 * The `wp_slimstat_cache_` prefix came from a second, entirely unused caching mechanism
 * (getCacheKey / getCachedResult / setCachedResult) that has been deleted, so the surviving
 * prefix is unambiguous.
 *
 * Defect ids live in the workspace performance notes, outside this repository —
 * deliberately not linked, since this file ships to wp.org.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

$db    = (string) @file_get_contents($plugin_root . '/admin/view/wp-slimstat-db.php');
$admin = (string) @file_get_contents($plugin_root . '/admin/index.php');
$query = (string) @file_get_contents($plugin_root . '/src/Utils/Query.php');

if ('' === $db || '' === $admin || '' === $query) {
    fwrite(STDERR, "FAIL: cannot read one of the source files\n");
    exit(1);
}

// ── 1. get_results() writes back on every path it reads on ──────────────────
$results = slimstat_function_body($db, 'get_results');
if ($results === '') {
    $failures[] = 'wp_slimstat_db::get_results() not found — re-anchor rather than deleting';
} else {
    // Strip comments before counting, or the D72 defect-story comment in the function —
    // which names get_transient() in prose — counts as a read path. The old fixed floor
    // of 2 writes silently absorbed exactly that miscount.
    $code   = slimstat_strip_comments_and_strings($results, false);
    $reads  = preg_match_all('/get_transient\s*\(/', $code);
    $writes = preg_match_all('/set_transient\s*\(/', $code);

    // Structural, not a hand-kept constant: every read path must carry a write. The
    // Query-converter exit path (which carried its own write) is deleted — its parse
    // captured one character of the WHERE clause, see get-results-convert-contract-test.php,
    // which also asserts this symmetry BEHAVIOURALLY. This comparison is the cheap tripwire
    // for the D72 shape returning: a read path added without a write passes a fixed floor
    // but cannot pass writes < reads.
    if ($writes < $reads) {
        $failures[] = 'get_results() reads a transient but writes one on fewer paths than it '
            . 'reads on — a cache paid for on every render that can never once be hit';
    }

    // The fallback must be gated, or a live window whose SQL moves every second writes a
    // row per render that nothing can read.
    if (!preg_match('/window_is_cacheable\s*\(/', $results)) {
        $failures[] = 'the fallback cache write is not gated on the window being historical. '
            . 'A live window carries a timestamp that moves every second, so each write '
            . 'would be unreadable by the next request — the accumulation that left '
            . 'thousands of orphaned rows in wp_options';
    }
}

$gate = slimstat_function_body($db, 'window_is_cacheable');
if ($gate === '') {
    $failures[] = 'window_is_cacheable() not found';
} elseif (!preg_match('/utime.*end/s', $gate) || !preg_match('/</', $gate)) {
    $failures[] = 'window_is_cacheable() no longer compares the window end against today';
}

// ── 2. The upgrade sweep names the prefix that is actually written ──────────
if (!preg_match('/wp_slimstat_query_/', $query)) {
    $failures[] = 'Query no longer builds keys prefixed wp_slimstat_query_ — re-anchor the '
        . 'sweep assertion below to whatever prefix replaced it';
}
if (preg_match("/_transient_wp_slimstat_cache_/", $admin)) {
    $failures[] = 'the upgrade routine still sweeps `_transient_wp_slimstat_cache_`, a prefix '
        . 'nothing writes. Measured: 0 rows matched against 2,146 present. Sweep the prefix '
        . 'Query::getCacheKeyForQuery() actually emits';
}
// The prefix is written with SQL-escaped underscores (`\_transient\_wp\_slimstat\_query\_`),
// so strip backslashes before looking for it rather than trying to match both spellings.
if (!preg_match('/DELETE FROM[^;]*option_name LIKE[^;]*transient[^;]*wp_slimstat_query/s', str_replace('\\', '', $admin))) {
    $failures[] = 'nothing sweeps the wp_slimstat_query_ cache on upgrade. Stale cached '
        . 'values are what produced report percentages above 100%';
}

// ── 3. The dead second cache mechanism stays dead ───────────────────────────
foreach (['getCachedResult', 'setCachedResult'] as $method) {
    if (preg_match('/function\s+' . $method . '\s*\(/', $query)) {
        $failures[] = "Query::{$method}() is back. It keyed on a prefix nothing sweeps and "
            . 'nothing else reads — two caching mechanisms is how the sweep came to name the '
            . 'wrong one';
    }
}

// ── Report ─────────────────────────────────────────────────────────────────
if ($failures !== []) {
    fwrite(STDERR, 'FAIL: query cache symmetry (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: query cache symmetry (reads are written back, gated on a stable window; the "
    . "upgrade sweep names the live prefix)\n";
exit(0);
