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

// ── 5. An errored chain is never cached ─────────────────────────────────────
//
// When CREATE TEMPORARY TABLE … AS SELECT fails — privilege revoked, malformed step
// rule, STRICT truncation, deadlock — the chain bails with $had_error set. That partial
// result must not reach set_transient: a cached zero would present a corrupt funnel as
// real data for five minutes per miss and mask a revoked CREATE TEMPORARY privilege
// indefinitely — the silent zero this seam removed, resurrected at the cache layer.
// Token walk, token indices as the one unit throughout (the byte-vs-token drift is the
// exact copied-guard hazard the shared helpers exist to prevent).
$chain_tokens = slimstat_tokenize($chain, false);
$chain_count  = count($chain_tokens);

$transient_at = [];
foreach ($chain_tokens as $i => $t) {
    if (is_array($t) && T_STRING === $t[0] && 'set_transient' === $t[1]) {
        $transient_at[] = $i;
    }
}

if (1 !== count($transient_at)) {
    $failures[] = 'get_funnel_results() holds ' . count($transient_at) . ' set_transient '
        . 'call(s) where exactly 1 was expected — a second cache write is a second chance to '
        . 'cache an errored chain; re-anchor this section rather than deleting it';
} else {
    $guarded = false;
    for ($i = 0; $i < $chain_count; $i++) {
        if (!is_array($chain_tokens[$i]) || T_IF !== $chain_tokens[$i][0]) {
            continue;
        }
        $cond_end = slimstat_token_paren_end($chain_tokens, $i, $chain_count);
        if (null === $cond_end) {
            continue;
        }
        $negates_flag = false;
        for ($k = $i; $k <= $cond_end; $k++) {
            if (is_array($chain_tokens[$k]) && T_VARIABLE === $chain_tokens[$k][0]
                && '$had_error' === $chain_tokens[$k][1]) {
                // Walk back over whitespace: `! $had_error` is the WPCS spelling of the
                // same guard, and a reformat must not read as a removed guard.
                $p = $k - 1;
                while ($p >= 0 && is_array($chain_tokens[$p]) && T_WHITESPACE === $chain_tokens[$p][0]) {
                    $p--;
                }
                if ($p >= 0 && '!' === $chain_tokens[$p]) {
                    $negates_flag = true;
                    break;
                }
            }
        }
        if (!$negates_flag) {
            continue;
        }
        $block = slimstat_token_block_range($chain_tokens, $cond_end, $chain_count);
        if (null !== $block && $transient_at[0] > $block[0] && $transient_at[0] < $block[1]) {
            $guarded = true;
            break;
        }
    }
    if (!$guarded) {
        $failures[] = 'get_funnel_results() caches without the !$had_error guard — an errored '
            . 'chain (revoked CREATE TEMPORARY privilege, malformed rule, deadlock) would be '
            . 'served from cache as a plausible zero, self-healing never, instead of '
            . 'recomputing on the next request';
    }
}

// The guard is only as real as the flag: an error path that never SETS $had_error makes
// the branch above unreachable-in-practice while the scan stays green. Strings and
// comments blanked, so the three required mentions are code: init, set-on-failure, guard.
$chain_flag_mentions = substr_count(slimstat_strip_comments_and_strings($chain, false), '$had_error');
if ($chain_flag_mentions < 3) {
    $failures[] = 'get_funnel_results() mentions $had_error ' . $chain_flag_mentions . ' time(s) '
        . 'where at least 3 are expected (initialised, set on CREATE failure, cache guard) — '
        . 'the bail flag has been hollowed';
}

// ── 6. The identity ladder names only tiers the table in front of us HAS ────
//
// The upgrade contract defers DDL to the migration screen, so an upgraded site
// legitimately serves v6 funnels from a table without vid_hash (and, pre-5.x,
// without fingerprint). An unconditional ladder is then an Unknown-column
// rejection that get_results() reports identically to "no visitors" — measured
// live on this workspace's own upgraded database: every goal and funnel read 0.
// The behavioural pins live in tests/Unit/QueryBuilderTest.php (ladder shape
// per schema state, byte-identity on complete schemas); this scan is the
// vendor-independent gate the mutation registry fires on every lane.
$ladder = slimstat_function_body($source, 'visitor_id_expr');
if ('' === $ladder) {
    $failures[] = 'visitor_id_expr() not found — re-anchor this section rather than deleting it';
} else {
    // Two layers, each covering the other's blind side. (1) STRINGS STRIPPED: the
    // guard must exist as CODE, twice — a guard removed from code while its spelling
    // survives inside a string literal must not keep this green (the registered
    // name-only mutation fires exactly that bypass). (2) COMMENTS-ONLY BLANKED: each
    // tier's own spelling must be present, so the two guards cannot collapse into
    // one or swap to a column nothing declares.
    $guard_calls = substr_count(
        slimstat_strip_comments_and_strings($ladder, false),
        'self::fact_column_present('
    );
    if ($guard_calls < 2) {
        $failures[] = sprintf(
            'visitor_id_expr() holds %d fact_column_present() guard(s) in CODE where 2 are '
                . 'expected (fingerprint, vid_hash) — an unguarded tier on an upgraded table '
                . 'without the column turns every goal, funnel and unique-visitor count into '
                . 'an Unknown-column rejection reported identically to "no visitors"',
            $guard_calls
        );
    }
    $ladder_no_comments = slimstat_blank_comments($ladder, false);
    foreach (['fingerprint', 'vid_hash'] as $optional_tier) {
        if (false === strpos($ladder_no_comments, "self::fact_column_present('{$optional_tier}')")) {
            $failures[] = "visitor_id_expr() no longer guards the {$optional_tier} tier behind "
                . 'fact_column_present() — on an upgraded table without the column, every '
                . 'goal, funnel and unique-visitor count becomes an Unknown-column rejection '
                . 'reported identically to "no visitors"';
        }
    }
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
    . "visitor identity's own collation and width; an errored chain is never cached)\n";
exit(0);
