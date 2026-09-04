<?php
/**
 * Source-level: a query never runs against the wrong database connection — F6 (C44).
 *
 * The analytics connection is the DEFAULT for a Query (the constructor binds
 * `\wp_slimstat::$wpdb`), and under the custom-DB add-on that is a different database,
 * possibly on a different server. Two symmetric mistakes follow, and this gate names both:
 *
 *   1. A CORE table (`wp_posts`, `wp_comments`, `wp_users`, …) queried on the ANALYTICS
 *      handle — the database that has no such table. `get_your_blog()` did this for six of
 *      its seven metrics; every one read zero or errored silently on an external-DB
 *      install. A core-table query must carry `->local()` (or `->on($GLOBALS['wpdb'])`).
 *
 *   2. A single SQL statement naming BOTH a core table and a `slim_` table. That can only
 *      resolve when both databases live on one MySQL instance — the moment they are
 *      separated (the entire point of the add-on) it is a hard error. Split it into two
 *      queries and join in PHP.
 *
 * Tokens, not text: the from-table argument is read from the token stream, and comments
 * are irrelevant because a Query builder call is executable code.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

// Core-table accessors on wpdb. A `->from($GLOBALS['wpdb']->posts)` or a name built from
// one of these is a core table; anything ending `slim_...` is analytics.
//
// HAND-MAINTAINED, and it must stay a SUPERSET of wpdb's own table lists ($tables /
// $global_tables / $ms_global_tables) — this gate is a 7.4-safe scan that does not boot
// WordPress, so it cannot ask wpdb at scan time. A core table missing from this list fails
// OPEN: a Query on it without ->local() would ship unflagged, which is the exact C44 bug.
// If WordPress adds a core table (or this plugin starts querying commentmeta/links/etc.),
// add it here.
$core_props  = ['posts', 'postmeta', 'comments', 'commentmeta', 'users', 'usermeta',
    'terms', 'termmeta', 'term_taxonomy', 'term_relationships', 'options', 'links',
    'blogs', 'blogmeta', 'site', 'sitemeta', 'signups', 'registration_log'];
$core_lookup = array_flip($core_props);

$plugin_root = dirname(__DIR__);
$failures    = [];
$checked     = 0;

// Every own PHP file under admin/ and src/ (not tests, not deps). The exclusion is the
// ABSOLUTE dependency path — the helper matches it at offset 0 against the absolute file
// path, so a relative `/Dependencies/` literal silently excludes nothing (the shape every
// other caller passes as `$plugin_root . '/src/Dependencies'`).
// wp-slimstat.php is scanned too. It was outside this list, and it holds a live builder
// chain — `\SlimStat\Utils\Query::delete($table_stats)->where('dt', '<', $days_ago)` at the
// purge path — that no cross-database check had ever examined. The routing rule this gate
// enforces applies to the root file exactly as it applies to admin/ and src/.
$files = slimstat_own_php_files(
    [$plugin_root . '/admin', $plugin_root . '/src', $plugin_root . '/wp-slimstat.php'],
    $plugin_root . '/src/Dependencies'
);

foreach ($files as $file) {
    $source = (string) @file_get_contents($file);
    if ('' === $source) {
        continue;
    }

    $rel = slimstat_rel_path($plugin_root, $file);
    // Tokenise the COMMENT-BLANKED source, so a chain's reconstructed text cannot contain a
    // comment that says "->posts" or "->local()" — the raw-text hazard the source-scan
    // strength gate exists to remove. Blanking preserves byte offsets, so token positions
    // and line numbers are unmoved.
    // $is_file = true: these are whole files read from disk, so skip slimstat_tokenize's
    // fragment-sniff pass (a third full token_get_all just to recompute a fact we know).
    $tokens = token_get_all(slimstat_blank_comments($source, true));
    $count  = count($tokens);

    $query_entry_types = slimstat_name_token_types();

    for ($i = 0; $i < $count; $i++) {
        // Find `Query::select(` / `Query::update(` / etc. — the start of a builder chain.
        // This is the chain-ENTRY detector, i.e. the denominator: a spelling it cannot see
        // drops the whole chain before it is ever examined, and the printed "N chains checked"
        // shrinks silently rather than failing. T_STRING alone misses the qualified spelling
        // on PHP 8, where `\SlimStat\Utils\Query::delete(...)` is a single
        // T_NAME_FULLY_QUALIFIED token.
        //
        // Both halves were needed to reach the one such chain in this tree, and the count is
        // the evidence: adding wp-slimstat.php to the file list above put it in scope, and
        // this type widening made it visible — 67 chains before, 68 now. It routes to a slim_
        // table, so nothing was hiding behind it; being uncounted is the finding.
        if (!is_array($tokens[$i])
            || !isset($query_entry_types[$tokens[$i][0]])
            || 'Query' !== slimstat_last_name_segment($tokens[$i][1])) {
            continue;
        }
        $next = slimstat_next_significant($tokens, $i);
        if ($next >= $count || !is_array($tokens[$next]) || T_DOUBLE_COLON !== $tokens[$next][0]) {
            continue;
        }

        // The chain runs to the statement terminator ';'.
        $end = $i;
        $depth = 0;
        for ($k = $i; $k < $count; $k++) {
            $t = $tokens[$k];
            if ('(' === $t) { $depth++; }
            elseif (')' === $t) { $depth--; }
            elseif (';' === $t && 0 === $depth) { $end = $k; break; }
        }

        $checked++;

        // Walk the chain's TOKENS, not reconstructed text. Every `->name` inside the chain
        // is a T_OBJECT_OPERATOR followed by a T_STRING; a `->posts` sitting inside a string
        // literal is a T_CONSTANT_ENCAPSED_STRING and cannot match here, which a text scan
        // would false-trip on. `$core_lookup` names the core-table accessors; `->local`/
        // `->on` is the routing that excuses one.
        $names_core = false;
        $routed     = false;
        for ($k = $i; $k <= $end; $k++) {
            if (!is_array($tokens[$k]) || T_OBJECT_OPERATOR !== $tokens[$k][0]) {
                continue;
            }
            $m    = slimstat_next_significant($tokens, $k);
            $name = ($m <= $end && is_array($tokens[$m]) && T_STRING === $tokens[$m][0]) ? $tokens[$m][1] : '';
            if (isset($core_lookup[$name])) {
                $names_core = true;
            }
            if ('local' === $name || 'on' === $name) {
                $routed = true;
            }
        }

        if (!$names_core || $routed) {
            continue;
        }

        // A core-table chain that does NOT route to the local handle.
        {
            $failures[] = sprintf(
                '%s: a Query on a CORE table (%s) does not call ->local()/->on() — it runs on the '
                    . 'analytics connection, which under the custom-DB add-on has no such table',
                $rel,
                trim(preg_replace('/\s+/', ' ', substr(slimstat_token_text_range($tokens, $i, $end), 0, 90)))
            );
        }
    }

    // The un-splittable-join case — a SINGLE statement naming both a core table and a
    // slim_ table — is asserted where it actually lives: wp-slimstat-pro's
    // tests/cross-database-handle-test.php, on UserOverviewAddon's `{dbname}.wp_users
    // INNER JOIN slim_stats`. Free has none (a whole-file check here false-positived on
    // get_your_blog's separate ->local() calls and on tooltip prose containing
    // "wp_posts"), so the free gate owns only the per-chain handle-routing assertion
    // above, which is the one with a real subject here.
}

// Vacuity floor: this tree has many Query chains; zero checked means the walk broke.
if ($checked < 20) {
    $failures[] = sprintf('only %d Query chain(s) walked — the scan is broken and asserted '
        . 'almost nothing', $checked);
}

echo "\n";
if ([] !== $failures) {
    fwrite(STDERR, 'FAIL: cross-database handle (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

printf("PASS: %d Query chain(s) checked; every core-table query routes to the local connection, "
    . "and no cross-database statement mixes core and slim_ tables\n", $checked);
exit(0);
