<?php
/**
 * Query budget for the comparison chart.
 *
 * Two independent costs, measured on the 443k-row reference dataset:
 *
 * 1. A timezone probe — `SELECT UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(UTC_TIMESTAMP())`
 *    — issued once per sqlFor() call, so 2 to 4 times per report screen. At 0.039 ms a
 *    call this is NOT a latency problem and this file does not pretend otherwise. It is
 *    a round trip for a value that changes twice a year, on a path that runs on every
 *    admin render, and the fix is a static memo. Cheap to do, cheap to keep right.
 *
 * 2. The result cache, which is the one that matters. The chart aggregates with
 *    GROUP BY over four nested date functions, which forces a temporary table and a
 *    filesort: 577 ms for a 30-day window on this dataset, and the window covers 43.5%
 *    of the table so a full scan is genuinely the right plan — there is no index to add.
 *    The only lever is not running it again.
 *
 *    Caching was switched off outright whenever the range included today, so EVERY
 *    default view — "last 30 days" always ends today — paid the full 577 ms on every
 *    render, re-aggregating 29 days of immutable history to pick up one live day.
 *    Measured: three consecutive renders, three executions.
 *
 * This pins both: the probe is memoised, and the cache can actually be hit for a range
 * that includes today.
 *
 * Defect ids (D60, D42) live in the workspace performance notes, outside this
 * repository — deliberately not linked, since this file ships to wp.org.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

$source = file_get_contents($plugin_root . '/src/Modules/Chart.php');
if ($source === false) {
    fwrite(STDERR, "FAIL: cannot read src/Modules/Chart.php\n");
    exit(1);
}

// ── 1. The timezone probe is issued once per request, not once per chart ────
$sqlFor = slimstat_function_body($source, 'sqlFor');
if ($sqlFor === '') {
    $failures[] = 'sqlFor() not found in Chart.php';
} elseif (preg_match('/\$wpdb\s*->\s*get_var\s*\(/', $sqlFor)) {
    $failures[] = 'sqlFor() issues a query directly — it runs once per chart, and the only '
        . 'thing it was asking for is the server timezone offset. Read it through the memoised '
        . 'helper instead';
}

// DataBuckets is the single source. It asked the same question of the same connection
// as Chart did, once per constructor, and one is constructed per chart — so a two-chart
// screen paid four round trips for one number. The two probes were spelled differently
// (`TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())` vs `UNIX_TIMESTAMP(NOW()) -
// UNIX_TIMESTAMP(UTC_TIMESTAMP())`) but return the identical value, verified against the
// server; only the sign convention each CALLER applies afterwards differs, and sharing
// the probe leaves that untouched.
$buckets = file_get_contents($plugin_root . '/src/Helpers/DataBuckets.php');
if ($buckets === false) {
    $failures[] = 'cannot read src/Helpers/DataBuckets.php';
} else {
    // Optional lookup on purpose: "DataBuckets has no constructor at all" is a legal way
    // to satisfy "its constructor must not query", so absence must not be fatal here.
    $ctor = slimstat_find_function_body($buckets, '__construct');
    if (null !== $ctor && preg_match('/\$wpdb\s*->\s*get_var\s*\(/', $ctor)) {
        $failures[] = 'DataBuckets::__construct() queries the server timezone on every '
            . 'instantiation, and one is constructed per chart — read it through the memo';
    }

    $offset = slimstat_function_body($buckets, 'serverTimezoneOffset');
    if ($offset === '') {
        $failures[] = 'DataBuckets::serverTimezoneOffset() not found — the timezone probe must '
            . 'live in one memoised place';
    } else {
        if (!preg_match('/\bstatic\b/', $offset)) {
            $failures[] = 'DataBuckets::serverTimezoneOffset() has no static memo — the probe '
                . 'would still run once per chart';
        }
        if (!preg_match('/UTC_TIMESTAMP/i', $offset)) {
            $failures[] = 'DataBuckets::serverTimezoneOffset() does not issue the offset probe '
                . '— it is supposed to be the one place that does';
        }
    }
}

// And nowhere else may ask. Counted across both files, because the whole point is that
// there is ONE probe per request, not one per class.
$copies = preg_match_all('/UTC_TIMESTAMP/i', $source) + preg_match_all('/UTC_TIMESTAMP/i', (string) $buckets);
if ($copies > 1) {
    $failures[] = $copies . ' timezone probes across Chart.php and DataBuckets.php — there '
        . 'must be exactly one, behind the memo';
}

// ── 2. A range that includes today must still be cacheable ─────────────────
//
// This is the D33 defect in a different file: a cache whose key or gate makes it
// unreachable on exactly the queries people actually run. There the goal transients
// were keyed on a range end clamped to the current second; here caching was refused
// outright for any range touching today.
$fetch = slimstat_function_body($source, 'fetchChartData');
if ($fetch === '') {
    $failures[] = 'fetchChartData() not found in Chart.php';
} else {
    if (preg_match('/allowCaching\s*\(\s*(false|0)\s*[,)]/', $fetch)) {
        $failures[] = 'fetchChartData() disables caching unconditionally somewhere — the '
            . 'historical part of a today-inclusive range is immutable and must still be cached';
    }
    // Anchored on the constant, inside fetchChartData() alone. An earlier draft matched
    // /bucket/i anywhere in the file — which the `use SlimStat\Helpers\DataBuckets`
    // import satisfies, making the assertion incapable of failing. Same lesson the
    // adminbar test records about anchoring on shape rather than a bare keyword.
    // Anchored on the ASSIGNMENT, not on the constant appearing somewhere in the body.
    // Merely mentioning CACHE_LIVE_BUCKET_SECONDS is satisfied by using it as the TTL,
    // and a mutation that deleted the quantisation while keeping the TTL went
    // undetected until this was tightened. The TTL bounds staleness; the quantisation
    // is what makes the key reachable at all, and only one of those can be dropped
    // silently.
    if (!preg_match('/\$args\[[\'"]end[\'"]\]\s*=[^;]*CACHE_LIVE_BUCKET_SECONDS/', $fetch)) {
        $failures[] = 'fetchChartData() does not quantise the window end by '
            . 'CACHE_LIVE_BUCKET_SECONDS — a cache key derived from a range end that moves '
            . 'every second can never be hit, which is the same defect as the goal '
            . 'transients (D33)';
    }
}

// The value belongs in a named constant rather than a literal buried in a call: that is
// the property a refactor can silently undo. The specific number is argued once, in the
// constant's own docblock. Re-asserting a range here would put two rationales for one
// number in two files — the drift the single constant exists to prevent.
if (!preg_match('/const\s+CACHE_LIVE_BUCKET_SECONDS\s*=\s*\d+/', $source)) {
    $failures[] = 'CACHE_LIVE_BUCKET_SECONDS not declared — the live-range bucket and TTL must '
        . 'be a named constant, not a literal buried in a call';
}

// ── Report ─────────────────────────────────────────────────────────────────
if ($failures !== []) {
    fwrite(STDERR, 'FAIL: chart query budget (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: chart query budget (timezone probe memoised per request; today-inclusive ranges "
    . "remain cacheable)\n";
exit(0);
