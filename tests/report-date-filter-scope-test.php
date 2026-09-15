<?php
/**
 * A report's date filter must be applied where the report asked for it, or not at all.
 *
 * Two independent defects made that untrue, and they compounded.
 *
 * 1. `raw_results_to_html()` passed `true` for `$_use_date_filters` as a literal, so a
 *    report declaring `'use_date_filters' => false` got the filter anyway. Both
 *    "Currently Online" widgets declare it, precisely because "who is here right now"
 *    is not a question about the report's date range. `get_top()` honours the flag
 *    correctly — but by then the range had already been glued into the WHERE string.
 *
 * 2. `get_combined_where()` appends the range with `sprintf('%s AND %s', $_where, $range)`
 *    and does not parenthesise the caller's clause. SQL binds AND tighter than OR, so a
 *    caller whose WHERE contains a top-level OR gets
 *
 *        A OR B  +  AND C   ->   A OR (B AND C)
 *
 *    — a filter that silently applies to half the condition.
 *
 * Measured on the 443k-row reference dataset, viewing a historical range while one
 * visitor is active:
 *
 *   slim_p1_04 (Currently Online)        1 IP     — accidentally right: its unwrapped
 *                                                   OR let `dt_out` escape the filter
 *   slim_p1_18 (Users Currently Online)  0 users  — WRONG, 1 was online: its OR is
 *                                                   self-wrapped, so the filter applied
 *
 * Two sibling widgets on the same screen, disagreeing, because one of them happened to
 * write an extra pair of parentheses.
 *
 * Defect id (D62) lives in the workspace performance notes, outside this repository —
 * deliberately not linked, since this file ships to wp.org.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

$reports = file_get_contents($plugin_root . '/admin/view/wp-slimstat-reports.php');
$db      = file_get_contents($plugin_root . '/admin/view/wp-slimstat-db.php');

if ($reports === false || $db === false) {
    fwrite(STDERR, "FAIL: cannot read the report/db view sources\n");
    exit(1);
}

// ── 1. The declared flag reaches get_combined_where() ───────────────────────
// Two checks, deliberately independent of each other. An earlier draft nested the
// robust one inside a regex that counted positional arguments by splitting on commas —
// so a future `absint($x)` argument would have silently skipped the check entirely
// rather than failing. Neither is redundant: the first catches "reads the flag but
// passes a literal anyway", the second catches "never reads the flag at all".
$raw = slimstat_function_body($reports, 'raw_results_to_html');
if ($raw === '') {
    $failures[] = 'raw_results_to_html() not found in wp-slimstat-reports.php';
} else {
    if (strpos($raw, 'use_date_filters') === false) {
        $failures[] = 'raw_results_to_html() never reads $_args[\'use_date_filters\'] — the '
            . 'reports that declare it are ignored';
    }
    if (preg_match('/get_combined_where[^;]*?,\s*(?:true|false|1|0)\s*,/s', $raw)) {
        $failures[] = 'raw_results_to_html() passes a boolean literal into '
            . 'get_combined_where() — a report declaring \'use_date_filters\' => false still '
            . 'gets a date range welded into its WHERE before get_top(), which does honour '
            . 'the flag, ever sees it. Read the report\'s own value.';
    }
}

// ── 2. The caller's clause is parenthesised before the range is appended ────
//
// This is the general guard. Fixing only the flag above leaves the trap armed for the
// next report — or Pro addon — that writes an unwrapped OR.
$combined = slimstat_function_body($db, 'get_combined_where');
if ($combined === '') {
    $failures[] = 'get_combined_where() not found in wp-slimstat-db.php';
} else {
    // Every place the function ANDs something onto the caller's clause, not just the
    // time range: the first draft of this test checked only one of the two and passed
    // while the other was still bare. With the caller's clause on the RIGHT of the AND
    // the consequence is worse — the whole second branch of an OR escapes the filter.
    // Checks the PROPERTY, not a list of bad spellings.
    //
    // Two earlier drafts were fooled in turn: the first matched only
    // `sprintf('%s AND %s', …)` and missed `$_where .= ' AND ' . $clause`; the second
    // added `.=` and was still fooled by `$_where = $_where . ' AND ' . $clause`. There
    // are always more ways to concatenate. So instead: every statement in this function
    // that assigns to $_where and mentions AND must show $_where adjacent to a
    // parenthesis — `(%s) AND`, `AND (%s)`, or a literal `($_where`.
    //
    // This matters because the missed site was live on the path this fix opens: with a
    // column filter active, "Currently Online" scoped to Chrome still listed non-Chrome
    // visitors whose dt_out fell in the window. A guard covering two of three sites is
    // worse than none, because it reads as coverage.
    // Scanned on the RIGHT-HAND SIDE of each assignment only, with comments stripped.
    // Both matter, and both were learned the hard way: a whole-statement scan accepted
    // `!empty($_where)` from the enclosing `if` as proof of parenthesisation, and the
    // explanatory comments above each line contain parenthesised SQL of their own.
    $body = preg_replace(['{/\*.*?\*/}s', '{//[^\n]*}'], '', $combined);

    $bare = [];
    if (preg_match_all('/\$_where\s*\.?=\s*([^;]+);/', (string) $body, $m)) {
        foreach ($m[1] as $rhs) {
            // Only assignments that splice an AND into the clause are in scope.
            if (!preg_match('/[\'"][^\'"]*\bAND\b/i', $rhs)) {
                continue;
            }
            // The caller's clause must appear wrapped: '(%s) AND …' or '… AND (%s)'.
            if (strpos($rhs, '(%s)') !== false) {
                continue;
            }
            $bare[] = trim(preg_replace('/\s+/', ' ', $rhs));
        }
    }
    if ($bare !== []) {
        $failures[] = count($bare) . ' place(s) in get_combined_where() AND a condition onto '
            . "the caller's WHERE without parenthesising it. AND binds tighter than OR, so a "
            . 'caller clause containing a top-level OR rebinds and the condition silently '
            . 'applies to only part of it:' . "\n      " . implode("\n      ", array_map(
                static fn($s) => substr($s, 0, 100),
                $bare
            ));
    }
}

// ── Report ─────────────────────────────────────────────────────────────────
if ($failures !== []) {
    fwrite(STDERR, 'FAIL: report date-filter scope (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: report date-filter scope (declared use_date_filters is honoured; the time range "
    . "cannot rebind across a caller's OR)\n";
exit(0);
