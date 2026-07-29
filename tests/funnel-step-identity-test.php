<?php
/**
 * A funnel step must be satisfied by a DIFFERENT physical row than the step before it,
 * and the temp table that carries the visitor identity must not re-declare its type.
 *
 * ── D54: one pageview, two steps ───────────────────────────────────────────────────
 *
 * The chain stores (vid, MIN(dt)) per step and requires the next step's row at
 * `dt >= r.t`. `>=` is deliberate — dt has one-second granularity, and two genuinely
 * ordered steps can land in the same second. But `>=` alone lets ONE physical pageview
 * satisfy TWO steps whenever the rules overlap ("contains shop" then "contains
 * shop/cart" against a single visit to /shop/cart), reporting a conversion that never
 * happened. Nothing validates steps as distinct: ajax_save_funnel() checks step count
 * and shape only.
 *
 * Measured through the real get_funnel_results() on scratch tables, with four visitors —
 * A (one pageview matching both rules), B (a genuine journey 50 s apart), C (never
 * reached step 2), D (two SEPARATE pageviews in the same second):
 *
 *     shipped   step 1: 4 visitors   step 2: 3   <- A fabricated a conversion
 *     fixed     step 1: 4 visitors   step 2: 2   <- B and D only
 *
 * Tightening `>=` to `>` was measured too, and is NOT the fix: it drops visitor D, a real
 * conversion. The question is not "later", it is "a different row" — so the row identity
 * travels with the timestamp.
 *
 * ── D53: the temp table's collation ────────────────────────────────────────────────
 *
 * The temp table declared `vid VARCHAR(64)`, which takes the DATABASE's default collation,
 * and step 2 joins it against the visitor-identity expression, which carries the SOURCE
 * column's collation. When they differ, MySQL refuses:
 *
 *     Illegal mix of collations (utf8mb4_unicode_520_ci,IMPLICIT)
 *                           and (utf8mb4_general_ci,IMPLICIT) for operation '='
 *
 * and every step from the second on reports 0. Note the trigger is same-charset,
 * DIFFERENT-collation — a utf8mb4 database over utf8mb3 columns is a coercible superset
 * and joins fine, which is why this looked unreproducible at first.
 *
 * Deriving the column instead of declaring it also removes the VARCHAR(64) ceiling (D16):
 * identities reach 73 characters on the reference dataset (740 rows over 64), and
 * WordPress clears STRICT_TRANS_TABLES, so the overflow truncated silently.
 *
 * Defect ids live in the workspace performance notes, outside this repository —
 * deliberately not linked, since this file ships to wp.org.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

$source = file_get_contents($plugin_root . '/admin/view/wp-slimstat-db.php');
if ($source === false) {
    fwrite(STDERR, "FAIL: cannot read admin/view/wp-slimstat-db.php\n");
    exit(1);
}

$chain = slimstat_function_body($source, 'get_funnel_results');
if ($chain === '') {
    fwrite(STDERR, "FAIL: get_funnel_results() not found\n");
    exit(1);
}

// ── 1. The temp table derives its columns rather than declaring them ────────
if (preg_match('/CREATE\s+TEMPORARY\s+TABLE\s+\$temp_write\s*\(\s*vid\s+VARCHAR/i', $chain)) {
    $failures[] = 'the funnel temp table declares `vid VARCHAR(...)` again. That column then '
        . "carries the DATABASE's default collation while the join compares it against the "
        . "visitor-identity expression, which carries the SOURCE column's — and when those "
        . 'differ MySQL refuses the comparison and every step after the first reports 0 '
        . 'visitors, with the error hidden. Derive the column from the SELECT instead';
}
if (!preg_match('/CREATE\s+TEMPORARY\s+TABLE\s+\$temp_write\s*\(\s*KEY\s*\(\s*vid\s*\)\s*\)\s*AS/i', $chain)) {
    $failures[] = 'the funnel temp table is no longer created as `(KEY(vid)) AS <select>` — '
        . 're-anchor this assertion rather than deleting it, and keep the column derived';
}

// ── 2. The row that satisfied a step is carried forward ─────────────────────
if (!preg_match('/AS\s+rid\b/', $chain)) {
    $failures[] = 'the funnel temp table no longer carries `rid` — without the id of the row '
        . 'that satisfied a step, the next step cannot tell whether the row it matched is the '
        . 'same physical row, and one pageview satisfies two overlapping steps';
}

// ── 2b. `rid` must be an ARGMIN, not a second aggregate beside MIN(dt) ──────
//
// `MIN(dt) AS t, MIN(id) AS rid` looks right and is not: the two are evaluated
// independently over the group, so for a visitor with rows (id 5, dt 100) and (id 8, dt 90)
// it stores t=90 with rid=5 — an id belonging to a row that did NOT achieve t. Measured
// through get_funnel_results(): a visitor who genuinely converted was reported as 0,
// because the row that should have satisfied step 2 was the one wrongly excluded.
//
// Reachable whenever id order and dt order disagree, which concurrent writers make
// ordinary: dt is stamped by PHP before the INSERT, so a request that starts later can
// commit first and take a lower id.
if (preg_match('/MIN\(\s*%s\s*\)\s+AS\s+rid/i', $chain)) {
    $failures[] = '`rid` is a plain MIN() beside MIN(dt). Those are independent aggregates '
        . 'over the same group, so `rid` need not name the row that achieved `t` — and when '
        . 'it does not, step N+1 excludes a row that never satisfied step N while the row '
        . 'that did stays eligible. Use an argmin ordered by (dt, id)';
}
if (!preg_match('/SUBSTRING_INDEX\s*\(\s*GROUP_CONCAT\s*\(.*ORDER\s+BY/is', $chain)) {
    $failures[] = 'the row id is no longer selected as an argmin over (dt, id) — re-anchor '
        . 'this assertion rather than deleting it, and keep `rid` tied to the row that '
        . 'achieved `t`';
}

// ── 3. …and the next step excludes exactly that row ─────────────────────────
if (!preg_match('/%s\s*<>\s*r\.rid/i', $chain)) {
    $failures[] = 'the step N>1 query does not exclude the row that satisfied step N. With '
        . 'only `dt >= r.t`, a single pageview matching two overlapping step rules (e.g. '
        . '"contains shop" then "contains shop/cart") is counted as a conversion — measured '
        . 'at 3 converted visitors where 2 converted';
}
// The exclusion is only meaningful between steps reading the same table: pageview ids and
// event ids are independent counters, so across a kind change equality is coincidence.
if (!preg_match('/\$prev_is_event\s*===?\s*\$is_event|\$is_event\s*===?\s*\$prev_is_event/', $chain)) {
    $failures[] = 'the row exclusion is applied without checking that the previous step read '
        . 'the same table. Pageview 5 and event 5 are different rows, so comparing their ids '
        . 'across a kind change drops legitimate conversions';
}

// The ordering comparison must stay inclusive: `>` drops a real conversion where two
// separate pageviews land in the same second. Measured, not assumed.
if (preg_match('/%s\s*>\s*r\.t\b/', $chain)) {
    $failures[] = 'the step ordering test was tightened from `>= r.t` to `> r.t`. That does '
        . 'stop one row satisfying two steps, but it also drops genuine conversions whose '
        . 'two pageviews share a second — measured. Exclude the ROW, not the timestamp';
}

// ── 4. Each step identifies its row in the table it actually read ───────────
if (!preg_match('/\$row_id_expr\s*=\s*\$is_event\s*\?\s*[\'"]te\.event_id[\'"]\s*:\s*[\'"]t1\.id[\'"]/', $chain)) {
    $failures[] = 'the per-step row identifier is not chosen by step kind — an event step '
        . 'must identify its row by te.event_id and a pageview step by t1.id';
}

// ── Report ─────────────────────────────────────────────────────────────────
if ($failures !== []) {
    fwrite(STDERR, 'FAIL: funnel step identity (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: funnel step identity (steps consume distinct rows; temp table inherits the "
    . "visitor identity's own collation and width)\n";
exit(0);
