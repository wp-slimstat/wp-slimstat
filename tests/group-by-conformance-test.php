<?php
/**
 * Source-level: the aggregate queries do not name columns they neither group nor aggregate.
 *
 * F7 / Phase Q. `count_bouncing_pages()` read
 *
 *     SELECT resource, visit_id … GROUP BY resource HAVING COUNT(visit_id) = 1
 *
 * `visit_id` is neither in the GROUP BY nor aggregated, which MySQL rejects under
 * `ONLY_FULL_GROUP_BY` — a mode that is ON by default since 5.7 and named in the server's own
 * `sql_mode`. The outer `COUNT(*)` never looked at the value.
 *
 * WHAT WAS RECORDED, AND WHAT WAS MEASURED. EXPECTED-DIFFS said "it errors under
 * ONLY_FULL_GROUP_BY and Bounce Pages silently reads 0". Measured on the bench corpus it returns
 * **56, matching an independently computed 56** — because `wpdb::set_sql_mode()` strips that mode
 * from every WordPress connection, and WordPress documents doing so. Forcing the mode back on in
 * the same session DOES reject the statement, so the SQL is genuinely non-conforming while the
 * consequence recorded for it never occurred.
 *
 * So this gate exists for the LATENT case, stated honestly: any connection that keeps the mode —
 * a `slimstat_custom_wpdb` filter returning something that is not `wpdb`, an external database
 * accessed by other means, a future core change — turns a working report into an empty one.
 *
 * DELIBERATELY NOT A GENERAL SQL PARSER. It checks the two statements this seam touched, by name.
 * A parser that tried to answer the question for every query in the tree would be a second
 * parser disagreeing with MySQL's, which is the failure mode this programme records most often.
 * Its scope is stated in its own failure messages so nobody reads it as broader than it is.
 *
 * 7.4-safe: pure source analysis through the tokeniser. No WordPress, no database.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

$db_rel  = 'admin/view/wp-slimstat-db.php';
$db_path = $plugin_root . '/' . $db_rel;
$db_src  = (string) file_get_contents($db_path);

// ── 1. count_exit_pages() stays deleted ─────────────────────────────────────
//
// It carried the same shape (`SELECT resource, dt … GROUP BY resource HAVING dt = MAX(dt)`) and
// had ZERO callers — measured, by walking every own-code PHP file, before it was removed. Deleting
// it removed a non-conforming query rather than fixing one. Asserting its ABSENCE because a
// reintroduction would arrive with the defect attached.
$own_files = slimstat_own_php_files(
    [$plugin_root . '/admin', $plugin_root . '/src'],
    $plugin_root . '/src/Dependencies'
);
$own_files[] = $plugin_root . '/wp-slimstat.php';

foreach ($own_files as $file) {
    $rel = slimstat_rel_path($plugin_root, $file);
    $src = slimstat_strip_comments_and_strings((string) file_get_contents($file), false);

    if (preg_match('/function\s+count_exit_pages\s*\(/', $src)) {
        $failures[] = "{$rel} re-declares count_exit_pages(). It was deleted in F7 as dead code "
            . 'with a non-conforming GROUP BY; it had zero callers, measured rather than assumed';
    }
}

// ── 2. count_bouncing_pages() selects only what it groups by ────────────────
//
// Read from the ORIGINAL source, comments blanked and string CONTENTS KEPT: the SQL under test
// IS a string literal, and stripping strings would blank exactly what this looks for. That
// mistake has shipped twice in this tree — once in migration-ui-honesty-test.php, once in the
// first draft of network-scope-symmetry-test.php.
$body = slimstat_blank_comments(slimstat_function_body($db_src, 'count_bouncing_pages'), false);

// THE INNER QUERY IS EXTRACTED AS ONE BLOCK, not assembled from two independent regexes.
//
// The first version of this file took the SELECT list from the LAST `SELECT … FROM` in the body
// and the GROUP BY from the FIRST one. Nothing required those to belong to the same query, so a
// third SELECT appearing after the inner one — a subquery in a WHERE or HAVING, or a rewrite
// where the outer SELECT trails — would compare one query's columns against another's grouping
// and PASS. A false pass is the direction that matters: it certifies conformance the code does
// not have, and the vacuity floor cannot catch it because the wrong list is non-empty.
//
// Anchored on the derived-table parentheses instead — the one structural feature this query is
// guaranteed to have, since the outer COUNT(*) needs something to count.
if (!preg_match('/FROM\s*\((.+?)\)\s*as\s+ts1/si', $body, $derived)) {
    $failures[] = 'count_bouncing_pages() no longer wraps its inner query in `FROM ( … ) as ts1`, '
        . 'so this check cannot locate the statement it inspects. Failing rather than passing: a '
        . 'check that cannot find its subject has not found it conformant';
} else {
    $inner_sql  = $derived[1];
    $has_select = (bool) preg_match('/SELECT\s+(.+?)\s+FROM/si', $inner_sql, $select);
    $has_group  = (bool) preg_match('/GROUP\s+BY\s+(.+?)(?:\bHAVING\b|\bORDER\b|\bLIMIT\b|$)/si', $inner_sql, $group);

    if (!$has_select || !$has_group) {
        $failures[] = sprintf(
            'count_bouncing_pages()\'s inner query no longer parses (SELECT found: %s, GROUP BY '
                . 'found: %s) — an unparseable subject is not a conformant one',
            $has_select ? 'yes' : 'no',
            $has_group ? 'yes' : 'no'
        );
    } else {
        $grouped  = array_values(array_filter(array_map('trim', explode(',', $group[1]))));
        $selected = array_values(array_filter(array_map('trim', explode(',', $select[1]))));

        // ONLY REAL AGGREGATES ARE EXEMPT. The first version skipped any term containing `(`,
        // which exempts `LOWER(browser)` — a non-aggregate function over a non-grouped column,
        // which MySQL rejects exactly as it rejects the bare column. Second false pass, same
        // direction. The fix is to NAME the functions that carry their own justification rather
        // than to treat a parenthesis as one.
        $aggregates = '/^(?:COUNT|SUM|AVG|MIN|MAX|GROUP_CONCAT|STD|STDDEV|VARIANCE|BIT_OR|BIT_AND|BIT_XOR)\s*\(/i';

        foreach ($selected as $column) {
            if (preg_match($aggregates, $column)) {
                continue;
            }

            // Compared without a trailing alias, so `resource AS r` does not read as a different
            // column from the `resource` in the GROUP BY.
            $bare = trim((string) preg_replace('/\s+(?:as\s+)?\w+$/i', '', $column)) ?: $column;

            if (!in_array($column, $grouped, true) && !in_array($bare, $grouped, true)) {
                $failures[] = sprintf(
                    'count_bouncing_pages() selects "%s", which is neither an aggregate nor in its '
                        . 'GROUP BY (%s). MySQL rejects that under ONLY_FULL_GROUP_BY — on by '
                        . 'default since 5.7 — and the only reason the report works today is that '
                        . 'wpdb::set_sql_mode() strips the mode. Any connection that keeps it '
                        . 'turns this report into an empty one',
                    $column,
                    implode(', ', $grouped)
                );
            }
        }

        // Vacuity floor. The loop passes trivially on lists that parsed to nothing, and a regex
        // over SQL is exactly the parse that goes quietly stale.
        if ([] === $selected || [] === $grouped) {
            $failures[] = sprintf(
                'count_bouncing_pages() parsed to %d selected column(s) and %d grouped — the check '
                    . 'above ran on nothing, which is how an assertion certifies a property it has '
                    . 'stopped testing',
                count($selected),
                count($grouped)
            );
        }
    }
}

// ── 3. "pages per visit" counts PAGES, not pages-that-have-an-ip ───────────
//
// `get_max_and_average_pages_per_visit()`'s inner query counted `count(ip)`. `ip` is
// `VARCHAR(39) DEFAULT NULL` and `count(<column>)` skips NULLs, so a pageview recorded without an
// ip was not counted as a page — and this function answers "pages per visit", where a row with no
// ip is still a page. On any install with a NULL ip the old form understated both the average and
// the maximum, silently.
//
// It is also 47% cheaper, measured: `count(ip)` reads the ip column of every row to decide
// whether it is NULL. That is a consequence, not the reason.
// ANCHORED ON THE SUBQUERY, for the reason section 2 above records at length: its first two
// drafts scanned the whole body and could be satisfied by a different statement than the one
// under test. Section 3's own first draft repeated that mistake exactly, and a review
// demonstrated three false passes against real mutants:
//
//   count(ss.ip)        table-aliased      — `[a-z_]+` does not match a dot
//   count(`ip`)         backtick-quoted    — nor a backtick
//   count(DISTINCT ip)                     — nor a space
//
// Each of those slipped through as soon as ANY `count(*)` existed elsewhere in the body, such as
// an outer `COUNT(*) AS visits`. The regression this section exists to stop was re-certified as
// conformant. A fourth mutant showed the mirror problem: a legitimate second `count(ip)`
// elsewhere in the body produced a FALSE FAIL.
//
// The anchor is the string literal that carries the per-visit grouping. Whatever COUNT lives in
// THAT literal is the one that decides what "a page" means.
$mav = slimstat_blank_comments(slimstat_function_body($db_src, 'get_max_and_average_pages_per_visit'), false);

if (!preg_match('/\'([^\']*GROUP BY visit_id[^\']*)\'/i', $mav, $sub)) {
    $failures[] = 'get_max_and_average_pages_per_visit() no longer builds a subquery literal '
        . 'grouping by visit_id, so this check cannot locate the per-visit aggregate it '
        . 'inspects. Failing rather than passing: an unlocatable subject has not been found '
        . 'conformant';
} else {
    $subquery = $sub[1];

    // Anything that is not literally `*` — an alias, a backtick, a DISTINCT, a plain column.
    if (preg_match('/count\s*\(\s*([^)*][^)]*)\)/i', $subquery, $counted)) {
        $failures[] = sprintf(
            'get_max_and_average_pages_per_visit() counts count(%s) rather than count(*). '
                . 'count(<column>) skips NULLs, and "pages per visit" must count PAGES — a '
                . 'pageview whose column is NULL is still a page. It also reads that column on '
                . 'every row: measured at 302,855 Handler_read_rnd_next against 152,854 for '
                . 'count(*) on a 152,014-row table — a delta of 150,001, one full pass',
            trim($counted[1])
        );
    } elseif (!preg_match('/count\s*\(\s*\*\s*\)/i', $subquery)) {
        $failures[] = 'the per-visit subquery contains no COUNT at all — the check above would '
            . 'pass on a query that had stopped aggregating';
    }
}

// ── get_top_aggr's ORDER BY carries a tie-break ─────────────────────────────────────────
//
// ORDER BY an aggregate alone leaves equal-count rows in PLAN order, and the derived-table
// + join shape's plan order varies BETWEEN EXECUTIONS on MySQL 5.7 — measured as a failed
// same-corpus null control in the Run 42 floor cells (two identical runs, tied rows 19/20
// swapped), and as tied rows flapping between page refreshes for a human. The grouped
// column as a second key makes the order a property of the data. Comments blanked: the
// bare `counthits DESC` this forbids is quoted in prose right here.
$aggr_body = slimstat_function_body($db_src, 'get_top_aggr');
if ('' === $aggr_body) {
    $failures[] = 'get_top_aggr() not found — re-anchor this section rather than deleting it';
} else {
    // The literal lives in a string that full blanking would remove, so comments only are
    // blanked and the assertion is PRESENCE of the tie-broken spelling — sufficient because
    // the single call site makes the two spellings mutually exclusive, and the registered
    // C49 mutation proves the match fails when the bare form returns.
    $aggr_no_comments = slimstat_blank_comments($aggr_body, false);
    if (!preg_match('/orderBy\s*\(\s*sprintf\s*\(\s*\'counthits DESC, %s ASC\'/', $aggr_no_comments)) {
        $failures[] = 'get_top_aggr() no longer orders by counthits DESC with the grouped '
            . 'column as tie-break — equal-count rows return in plan order, which varies '
            . 'between executions on 5.7 (measured: a same-corpus null control failed) and '
            . 'between refreshes for a user';
    }
}

// ── get_top's tie-break orders by the ALIAS, never the aliased expression ───────────────
//
// By the tie-break block, $_column has been rewritten to "<expr> AS <alias>" for every
// as_column report, and appending THAT produced `ORDER BY counthits DESC, REPLACE(...)
// AS referer ASC` — a syntax error MySQL rejects at "AS referer ASC". Measured live:
// Top Referring Domains, platform and trailing-slash resource all rendered empty while
// debug.log filled with the rejection. $_as_column is the bare alias (and equals the
// plain column when no alias is declared), so it is BOTH the only valid spelling inside
// ORDER BY and the spelling a caller's own order_by uses — which is what lets the
// containment check match at all. The behavioural pair lives in
// tests/Unit/QueryBuilderTest.php; this scan is the vendor-independent gate the
// mutation registry can fire on every CI lane.
$top_body = slimstat_function_body($db_src, 'get_top');
if ('' === $top_body) {
    $failures[] = 'get_top() not found — re-anchor this section rather than deleting it';
} else {
    $top_no_comments = slimstat_blank_comments($top_body, false);
    if (false === strpos($top_no_comments, "\$tiebreak_parts[] = \$_as_column . ' ASC';")) {
        $failures[] = "get_top() no longer appends the tie-break as \$_as_column — the alias "
            . 'is the only spelling of the sort column that is valid inside ORDER BY once '
            . 'the select column carries AS';
    }
    if (false !== strpos($top_no_comments, "\$tiebreak_parts[] = \$_column . ' ASC';")) {
        $failures[] = 'get_top() appends $_column to the tie-break — that string is '
            . '"<expr> AS <alias>" for every as_column report, and an aliased expression '
            . 'inside ORDER BY is the exact syntax error measured live (near "AS referer ASC")';
    }
}

// ── the sibling sites hold the same contract at the CALLER ──────────────────
//
// get_top_aggr() and get_group_by() interpolate their column argument into GROUP BY and
// ORDER BY verbatim, so an expression — or "<expr> AS <alias>" via get_top_aggr's fully
// plumbed as_column path — is D72 pre-built, dead only because every current caller
// passes a bare column. This holds the callers to that contract: the moment a report
// registry entry hands either function an expression, the fix is to extend the function
// the way get_top() was extended, not to weaken this scan.
$reports_src = (string) file_get_contents($plugin_root . '/admin/view/wp-slimstat-reports.php');
if ('' === $reports_src) {
    $failures[] = 'wp-slimstat-reports.php unreadable — the caller-contract scan has no input';
} else {
    $reports_no_comments = slimstat_blank_comments($reports_src, false);
    foreach (['outer_select_column', 'group_by'] as $arg_key) {
        preg_match_all("/'" . $arg_key . "'\s*=>\s*'([^']*)'/", $reports_no_comments, $m);
        foreach ($m[1] as $value) {
            if ('' !== $value && !preg_match('/^[A-Za-z0-9_]+$/', $value)) {
                $failures[] = sprintf(
                    "a report passes %s => '%s' — get_top_aggr()/get_group_by() interpolate "
                        . 'this into GROUP BY and ORDER BY verbatim, where an expression or an '
                        . 'AS alias is invalid SQL (the D72 class); extend the function first',
                    $arg_key,
                    $value
                );
            }
        }
    }
}

// ── get_recent's '*' expansion names only columns the table HAS ─────────────
//
// D74, the D73 window's remaining reader. The upgrade contract (S7/P1) defers DDL to the
// migration screen, so v6 legitimately serves the Access Log from a table older than v5's
// own 4.8.4.1 updater — the one that added `fingerprint` and `tz_offset`. Naming either is
// an Unknown-column rejection get_results() reports identically to "no visits": the report
// renders empty with no error on screen. Same fix as the identity ladder, through the same
// per-request per-prefix memo — so 29 names cost ONE probe, not 29. Measured rather than
// assumed, and the honest figure is not zero: goals and funnels do not run on the Access
// Log's screen, so this call site is the memo's first toucher there and pays that one
// SHOW COLUMNS (~1 ms warm) itself.
//
// TWO LAYERS, each covering the other's blind side, exactly as the funnel ladder's §6 pin
// does — the string-literal bypass there was DEMONSTRATED by a blind reviewer, not imagined.
// (1) STRINGS STRIPPED: the guard must exist as CODE, so a body that keeps the spelling in a
// comment or a literal while dropping the call cannot stay green. (2) STRUCTURAL: the result
// must be ACCUMULATED INSIDE a block the guard governs.
//
// Layer 2 was a `return $manifest;` regex until a blind reviewer demonstrated it pins a
// LOCAL VARIABLE'S SPELLING and nothing else: `$out = $manifest; … return $out;` keeps a
// fact_column_present() call in the body, never returns `$manifest`, satisfies both layers,
// and ships D74 verbatim. Registered as D74-03 rather than merely fixed. The replacement
// asks the question the defect actually answers — is the append governed by the guard —
// through slimstat_guarded_block_ranges(), the helper PITFALLS 45 extracted for exactly
// this (containment is by TOKEN INDEX; its two hand-written copies had already drifted into
// comparing byte offsets against token ranges, which can never fire).
// No absence branch: slimstat_function_body() THROWS when the definition is gone (its own
// docblock: "not found is fatal"), which is the verdict a renamed subject should get. An
// empty body reaches layer 1 and fails there, loudly.
$recent_body = slimstat_function_body($db_src, 'recent_columns');

if (false === strpos(
    slimstat_strip_comments_and_strings($recent_body, false),
    'self::fact_column_present('
)) {
    $failures[] = "recent_columns() holds no fact_column_present() call in CODE — get_recent('*') "
        . 'then names every late-era column on a table that may predate them, and the Access '
        . 'Log becomes an Unknown-column rejection reported identically to "no visits"';
}

$recent_tokens = slimstat_tokenize($recent_body, false);
$guarded       = slimstat_guarded_block_ranges($recent_tokens, 'fact_column_present');
$accumulates   = false;

// An append is `$var [ ] =`. Whitespace and comments are skipped by the same walk the
// library uses everywhere, so formatting cannot decide the verdict.
foreach ($recent_tokens as $i => $token) {
    if (!is_array($token) || T_VARIABLE !== $token[0]) {
        continue;
    }

    $open = slimstat_next_significant($recent_tokens, $i);
    if (!isset($recent_tokens[$open]) || '[' !== $recent_tokens[$open]) {
        continue;
    }

    $close = slimstat_next_significant($recent_tokens, $open);
    if (!isset($recent_tokens[$close]) || ']' !== $recent_tokens[$close]) {
        continue;
    }

    $assign = slimstat_next_significant($recent_tokens, $close);
    if (!isset($recent_tokens[$assign]) || '=' !== $recent_tokens[$assign]) {
        continue;
    }

    foreach ($guarded as [$block_open, $block_close]) {
        if ($i > $block_open && $i < $block_close) {
            $accumulates = true;
            break 2;
        }
    }
}

// Empty $guarded lands here too, and must: with no guarded block there is nothing for the
// append to be inside, and a containment assertion over zero ranges passes vacuously.
if (!$accumulates) {
    $failures[] = 'recent_columns() does not build its result inside a block guarded by '
        . 'fact_column_present() — the intersection is what makes the list safe, and a list '
        . 'assembled outside the guard IS D74: it names fingerprint and tz_offset, which v5 '
        . 'itself only added at 4.8.4.1, so the deferred-DDL window makes an older table a '
        . 'legitimate serving state whose Access Log then reads as "no visits"';
}

if ($failures) {
    fwrite(STDERR, 'FAIL: GROUP BY conformance (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: the aggregate queries this seam touched name only what they group or aggregate, "
    . "get_top_aggr's ORDER BY is deterministic on ties, and get_recent's '*' names only "
    . "columns the table in front of us has\n";
