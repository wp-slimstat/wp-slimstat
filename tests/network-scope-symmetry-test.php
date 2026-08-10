<?php
/**
 * Source-level: the two sides of a report's ratio are scoped by the SAME condition.
 *
 * D22 / PITFALLS 23. `reports.php` renders `100 * counthits / wp_slimstat_db::$pageviews`. The
 * numerator comes from `get_top()`, the denominator from `count_records()`. Scope one and not
 * the other and every row is wrong by the ratio of the two scopes — silently, because the only
 * clamp in that code guards `> 99`.
 *
 * Measured twice on the golden fixture, and the second time is why this file exists rather than
 * a comment:
 *
 *   1. Scoping count_records() alone: denominator 15 → 40, numerator 15. Rows understated ~2.7x;
 *      a report summing to 100% summed to ~37%. Reverted.
 *
 *   2. THE SAME STATE FROM A DIRECTION NO REVIEWER CONTROLS. Pro's `slimstat_get_var_sql`
 *      rewriter is years old; `slimstat_network_merge_active` is new in v6. So v6 free against
 *      an OLDER Pro had the old rewriter scoping the denominator while get_top() stayed
 *      main-site. Measured against a Pro zip built before the filter existed:
 *      `report_denominator: 40, report_numerator: 15, report_scope: MIXED`.
 *
 * The fix is that BOTH sides are gated on one flag — `NetworkMerge::isMerging()` — so an
 * install whose Pro cannot answer falls back to consistent-main-site, which is merely the old
 * behaviour, rather than to a ratio that renders wrong.
 *
 * WHAT THIS FILE CHECKS, and what it deliberately does not: it checks that the gate is present
 * on both sides and comes from one source. Whether the resulting NUMBERS agree is not a source
 * question and is not answered here — `tests/docker/probe-network-view.php` answers it against a
 * real network, reporting consistent-main-site | MIXED | consistent-network. This gate exists so
 * that a change which breaks the symmetry fails in CI, seconds after it is written, instead of
 * on the next topology run.
 *
 * 7.4-safe: pure source analysis through the tokeniser. No WordPress, no database.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

$db_rel  = 'admin/view/wp-slimstat-db.php';
$db_path = $plugin_root . '/' . $db_rel;

if (!is_file($db_path)) {
    fwrite(STDERR, "FAIL: {$db_rel} is missing\n");
    exit(1);
}

// ── 1. Both sides consult the same gate ─────────────────────────────────────
//
// Read as CODE, not as text: `isMerging` appears in the prose above this assertion's own subject
// and in several explanatory comments in the file under test. A raw scan would find those and
// report symmetry that the code does not have — instance eight of name-not-construct, in the
// gate written to prevent an asymmetry.
$numerator_body   = slimstat_function_body((string) file_get_contents($db_path), 'get_top');
$denominator_body = slimstat_function_body((string) file_get_contents($db_path), 'count_records');

$gate = 'NetworkMerge::isMerging()';

// The denominator names the gate directly. The numerator reaches it through Query::getAll(),
// which is where the SQL is finished — so what it must name is the INTENT it passes.
$denominator_code = slimstat_strip_comments_and_strings($denominator_body, false);

if (false === strpos($denominator_code, 'NetworkMerge::isMerging')) {
    $failures[] = "count_records() does not consult {$gate}. It is the DENOMINATOR of every "
        . '"top" report; scoping it while the numerator stays main-site understates every row '
        . '~2.7x on the golden fixture, and nothing in reports.php catches that direction';
}

// PRESENCE IS NOT ENOUGH, and the first version of this assertion stopped there. `$merging` is
// also used to choose the inner SELECT, so deleting the gate from the RETURN — restoring the
// exact 40/15 MIXED state measured against an older Pro — left `NetworkMerge::isMerging` in the
// body and the assertion green. A registered mutation applied cleanly and SURVIVED.
//
// So what is pinned is the RELATIONSHIP: no call may pass an outer aggregate to getVar() unless
// `$merging` governs it. Matched over a preceding window rather than as one literal syntax,
// because an if/else refactor of the same logic is correct and a gate that forbids its own valid
// fix is worse than no gate.
if (preg_match_all('/getVar\(\s*NetworkMerge::/', $denominator_code, $calls, PREG_OFFSET_CAPTURE)) {
    foreach ($calls[0] as [, $offset]) {
        $window = substr($denominator_code, max(0, $offset - 120), min(120, $offset));

        if (false === strpos($window, '$merging')) {
            $failures[] = 'count_records() passes an outer aggregate to getVar() without '
                . '$merging governing the call. An older Pro answers the years-old '
                . 'slimstat_get_var_sql rewriter but not the new merge gate, so the denominator '
                . 'moves to 40 while get_top() stays at 15 — MIXED, on a version combination '
                . 'nobody controls, measured exactly that way on a topology run';
        }
    }
} else {
    $failures[] = 'count_records() never passes an outer aggregate to getVar(), so the '
        . 'denominator can no longer be network-scoped at all — the check above is then '
        . 'satisfied by a function that has stopped doing the thing it guards';
}

if (false === strpos(slimstat_strip_comments_and_strings($numerator_body, false), 'NetworkMerge::SUM')) {
    $failures[] = 'get_top() does not declare a NetworkMerge intent, so its SQL is never offered '
        . 'to the Network View. That is the numerator standing still while the denominator moves';
}

// EVERY NUMERATOR OF count_records(), NOT JUST get_top(). The first version of this file pinned
// one pair and passed while count_records_having() — the numerator for the bounce rate, the
// new-visitor rate and the seven duration buckets, all divided by count_records() — sat
// unscoped beside a freshly scoped denominator. PITFALLS 23, on a second pair, inside the change
// that fixed the first, with the comment it deleted having named it: "Scoping either one alone
// breaks the pair."
//
// Written as a LIST so adding a numerator means adding a row here, rather than remembering that
// this file exists.
//
// AND MATCHED ON THE CALL, NOT ON THE MENTION. Two earlier versions of this checked
// `strpos($body, 'NetworkMerge::')`, which every one of these functions satisfies from the
// `$merging = NetworkMerge::isMerging()` line alone — so deleting the intent from the RETURN,
// which is the whole defect, left the assertion green. Both mutations applied cleanly and
// SURVIVED. What must be pinned is that the intent reaches the EXECUTOR.
$scoped = [
    'count_records'        => 'the denominator of every "top" report',
    'count_records_having' => 'the bounce rate, the new-visitor rate and the seven duration buckets',
    'get_top'              => 'the rows of every "top" report',
];

foreach ($scoped as $fn => $what) {
    $body = slimstat_strip_comments_and_strings(
        slimstat_function_body((string) file_get_contents($db_path), $fn),
        false
    );

    if (!preg_match_all('/get(?:All|Var)\(\s*NetworkMerge::/', $body, $calls, PREG_OFFSET_CAPTURE)) {
        $failures[] = "{$fn}() never passes a NetworkMerge intent to getAll()/getVar(), so its "
            . "SQL is never offered to the Network View. It is the numerator or denominator for "
            . "{$what}, and one side of a ratio moving alone renders silently wrong";
        continue;
    }

    // …and that the call is GOVERNED by the shared gate — but only for getVar(), because the two
    // executors gate in different places and demanding one shape for both is a gate that fails
    // on correct code. Query::getVar() keys purely on being handed a non-empty aggregate, so its
    // CALLER must decide; Query::getAll() consults NetworkMerge::isMerging() itself, and that
    // branch is asserted separately below. The first version applied the caller rule to both and
    // went red against a working tree.
    foreach ($calls[0] as [$call, $offset]) {
        if (false === strpos($call, 'getVar(')) {
            continue;
        }

        $window = substr($body, max(0, $offset - 140), min(140, $offset));

        if (false === strpos($window, '$merging')) {
            $failures[] = "{$fn}() hands getVar() an outer aggregate without \$merging governing "
                . "the call. Pro's slimstat_get_var_sql rewriter predates the merge gate by "
                . 'years, so an install on an older Pro scopes whichever side does not need the '
                . 'new filter — measured on a topology run as denominator 40, numerator 15, MIXED';
        }
    }
}

// ── 2. The gate has exactly one source ──────────────────────────────────────
//
// Two predicates that could disagree is the whole failure mode: free would build a
// union-friendly inner query that nothing unions, or hand a count to an outer COUNT(DISTINCT).
$merge_rel = 'src/Utils/NetworkMerge.php';
$merge_src = (string) @file_get_contents($plugin_root . '/' . $merge_rel);

if ('' === $merge_src) {
    $failures[] = "{$merge_rel} is missing — there is no single source for the merge gate";
} elseif (!preg_match("/apply_filters\(\s*'slimstat_network_merge_active'/", $merge_src)) {
    $failures[] = "{$merge_rel} no longer asks 'slimstat_network_merge_active'. Free must ASK "
        . 'whether a merge is happening; inferring it would put the capability gate in the '
        . 'plugin that does not own it';
}

// Nothing else may answer that question for itself.
$own_files = slimstat_own_php_files(
    [$plugin_root . '/admin', $plugin_root . '/src'],
    $plugin_root . '/src/Dependencies'
);

$askers = [];
foreach ($own_files as $file) {
    $rel = ltrim(str_replace($plugin_root, '', $file), '/');
    if ($rel === $merge_rel) {
        continue;
    }
    if (false !== strpos(slimstat_strip_comments_and_strings((string) file_get_contents($file), false), "'slimstat_network_merge_active'")) {
        $askers[] = $rel;
    }
}

if ($askers !== []) {
    $failures[] = 'the merge gate is asked outside ' . $merge_rel . ' by ' . implode(', ', $askers)
        . '. Two predicates for one question can disagree, and the disagreement is a union that '
        . 'never happens or a count handed to COUNT(DISTINCT)';
}

// ── 3. Query only unions when the caller declared how to merge ──────────────
$query_rel = 'src/Utils/Query.php';

// COMMENTS BLANKED, STRING CONTENTS KEPT — because a filter NAME is a string literal, and
// stripping strings blanks exactly what this looks for. The first version of this assertion used
// the stripping helper and failed against a file that applies both filters on adjacent lines.
// schema-single-source-test.php carries a paragraph about this precise trap; writing it a second
// time, in the gate written to prevent a different silent asymmetry, is the whole reason the
// paragraph is there.
$query_literal = slimstat_blank_comments((string) file_get_contents($plugin_root . '/' . $query_rel), false);
$query_src     = slimstat_strip_comments_and_strings((string) file_get_contents($plugin_root . '/' . $query_rel), false);

foreach (['slimstat_get_var_sql', 'slimstat_get_results_sql'] as $filter) {
    if (false === strpos($query_literal, $filter)) {
        $failures[] = "{$query_rel} does not apply {$filter}. Every report migrated to this "
            . 'builder — get_top, get_recent, get_group_by, the charts, goals and funnels — is '
            . 'then invisible to the Network View, which is D22 exactly';
    }
}

// The guard that makes an undeclared aggregate stay on one blog. Without it, a query nobody has
// declared a merge for is silently summed — and a SUM over COUNT(DISTINCT ip) is the 7-where-
// the-answer-is-6 defect M1 was ratified to prevent.
$getvar_body = slimstat_function_body((string) file_get_contents($plugin_root . '/' . $query_rel), 'getVar');

// The other half of the gate, and the counterpart to the caller rule above: getAll() consults
// the merge flag ITSELF, so get_top() does not have to. If this branch stops asking, every
// grouped report unions unconditionally — including on a single site, where there is nothing to
// union and the filter is a no-op that has quietly disabled the live/historical partitioning.
$getall_body = slimstat_strip_comments_and_strings(
    slimstat_function_body((string) file_get_contents($plugin_root . '/' . $query_rel), 'getAll'),
    false
);

if (false === strpos($getall_body, 'NetworkMerge::isMerging')) {
    $failures[] = "Query::getAll() does not consult the merge gate, so a declared intent unions "
        . 'unconditionally — and on a single site that silently replaces the live/historical '
        . 'partitioning with a filter that does nothing';
}

if (!preg_match("/''\s*!==\s*\\\$networkAggregate/", slimstat_strip_comments_and_strings($getvar_body, false))) {
    $failures[] = "Query::getVar() applies the union filter without checking that the caller "
        . 'declared an outer aggregate. An undeclared query would be merged by whatever the '
        . 'rewriter assumes, which for a distinct count is a wrong number that looks right';
}

if ($failures) {
    fwrite(STDERR, 'FAIL: network scope symmetry (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: numerator and denominator share one merge gate, and nothing merges undeclared\n";
