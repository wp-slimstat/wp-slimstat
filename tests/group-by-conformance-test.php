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
$aggr_body = slimstat_function_body(
    (string) file_get_contents($db_path),
    'get_top_aggr'
);
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

if ($failures) {
    fwrite(STDERR, 'FAIL: GROUP BY conformance (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: the aggregate queries this seam touched name only what they group or aggregate, "
    . "and get_top_aggr's ORDER BY is deterministic on ties\n";
