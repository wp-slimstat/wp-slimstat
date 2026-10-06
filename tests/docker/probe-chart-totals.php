<?php
// Is the chart's second (totals) query redundant?
//
// Opening tag required, declare(strict_types=1) not — WP-CLI's eval-file wraps this.
//
// A5 in the forward plan proposes dropping it: "drop the additive totals query first (2.0x ->
// 1.0x, ~10 lines, no schema)". That is worth ~one query per chart render, and there are several
// charts per dashboard, so the saving is real IF the premise holds.
//
// The premise is that the totals are the SUM of the per-bucket rows. That is true for an
// additive aggregate like COUNT(x). It is false for COUNT(DISTINCT x) — a visitor who appears on
// Monday and Tuesday is counted once in the range total and twice in the summed buckets.
//
// admin/view/wp-slimstat-reports.php declares data2 as COUNT( DISTINCT ip ) on most charts, and
// data1 as COUNT( DISTINCT visit_id ) on the visits chart. So this measures the gap rather than
// asserting it, on the same corpus everything else here uses.

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "runs under WP-CLI\n");
    exit(1);
}

global $wpdb;
$t = $wpdb->prefix . 'slim_stats';

$now   = time();
$start = $now - 90 * 86400;

$bucket = "FROM_UNIXTIME(dt, '%Y-%m-%d')";

$report = static function (string $label, string $expr) use ($wpdb, $t, $bucket, $start, $now) {
    // What the totals query returns: one aggregate over the whole range.
    $range = (int) $wpdb->get_var(
        "SELECT {$expr} FROM `{$t}` WHERE dt BETWEEN {$start} AND {$now}"
    );

    // What dropping it and summing the chart's own rows would give.
    $summed = (int) $wpdb->get_var(
        "SELECT COALESCE(SUM(v), 0) FROM (
            SELECT {$expr} AS v FROM `{$t}`
             WHERE dt BETWEEN {$start} AND {$now}
             GROUP BY {$bucket}
         ) b"
    );

    $delta = $summed - $range;
    $pct   = $range > 0 ? ($delta / $range) * 100 : 0;

    printf(
        "  %-28s range %8d   summed buckets %8d   %+8d  (%+.1f%%)  %s\n",
        $label,
        $range,
        $summed,
        $delta,
        $pct,
        0 === $delta ? 'ADDITIVE' : 'NOT ADDITIVE'
    );

    return 0 === $delta;
};

echo "\n  Does summing the chart's per-bucket rows reproduce its totals row?\n";
echo "  (90-day range, daily buckets, the shape every chart in reports.php uses)\n\n";

$additive = [];
$additive['COUNT(ip)']                 = $report('COUNT( ip )', 'COUNT(ip)');
$additive['COUNT(DISTINCT ip)']        = $report('COUNT( DISTINCT ip )', 'COUNT(DISTINCT ip)');
$additive['COUNT(DISTINCT visit_id)']  = $report('COUNT( DISTINCT visit_id )', 'COUNT(DISTINCT visit_id)');
$additive['COUNT(searchterms)']        = $report('COUNT( searchterms )', 'COUNT(searchterms)');
$additive['COUNT(DISTINCT searchterms)'] = $report('COUNT( DISTINCT searchterms )', 'COUNT(DISTINCT searchterms)');

echo "\n";

$non_additive = array_keys(array_filter($additive, static function ($v) {
    return !$v;
}));

if ([] === $non_additive) {
    echo "VERDICT: every aggregate is additive — the totals query IS redundant and A5 holds.\n";
    exit(0);
}

printf(
    "VERDICT: %d of %d aggregates are NOT additive: %s\n",
    count($non_additive),
    count($additive),
    implode(', ', $non_additive)
);
echo "\n";
echo "A5's premise does not hold for these. Dropping the totals query and summing the chart's\n";
echo "own rows would overstate them by the amounts above — silently, because the chart would\n";
echo "still render and the number would still look plausible.\n";

// ── The ROLLUP premise — the route Run 8 left open ──────────────────────────────────
//
// A5's drop-the-totals route died above: DISTINCT is not additive over buckets. WITH
// ROLLUP is the other route — its super-aggregate row is computed over the GROUPS'
// UNDERLYING ROWS, not by summing the group results, so COUNT(DISTINCT ip)'s rollup row
// should equal the separate range total exactly. "Should" is a premise, so it is
// measured here first, on every aggregate the charts declare, including the two-period
// CASE shape Chart.php actually renders — and priced with the same deterministic
// counter Run 12 used, A-B-B-A, so "one pass instead of two" is a number, not a hope.

$counter = static function () use ($wpdb): int {
    return (int) $wpdb->get_var("SELECT VARIABLE_VALUE FROM performance_schema.session_status WHERE VARIABLE_NAME = 'Handler_read_rnd_next'");
};

echo "\n  Does the WITH ROLLUP super-row equal the separate range total?\n\n";

// ONE template per query shape, every later use derived from it — the A-B-B-A price
// comparison below is only meaningful while arm A's SQL is byte-equivalent to arm B's
// minus ` WITH ROLLUP`, and independently typed literals can drift apart silently.
$range_tpl  = "SELECT __EXPR__ FROM `{$t}` WHERE dt BETWEEN {$start} AND {$now}";
$bucket_tpl = "SELECT {$bucket} AS b, __EXPR__ AS v FROM `{$t}` WHERE dt BETWEEN {$start} AND {$now} GROUP BY {$bucket}";
// __EXPR__ substitution, not sprintf: $bucket carries FROM_UNIXTIME format specifiers
// (%Y-%m-%d), and sprintf fataled on them the first time this ran.

$rollup_ok = true;
$dip_range = null;
foreach ([
    'COUNT(ip)'                => 'COUNT( ip )',
    'COUNT(DISTINCT ip)'       => 'COUNT( DISTINCT ip )',
    'COUNT(DISTINCT visit_id)' => 'COUNT( DISTINCT visit_id )',
] as $label => $expr) {
    $range = (int) $wpdb->get_var(str_replace("__EXPR__", $expr, $range_tpl));
    if ('COUNT(DISTINCT ip)' === $label) {
        $dip_range = $range;
    }

    $rows = $wpdb->get_results(str_replace("__EXPR__", $expr, $bucket_tpl) . ' WITH ROLLUP', ARRAY_A);
    $super = null;
    foreach ($rows as $r) {
        if (null === $r['b']) {
            $super = (int) $r['v'];
        }
    }

    $ok = ($super === $range);
    $rollup_ok = $rollup_ok && $ok;
    printf("  %-28s range %8d   rollup super-row %8s   %s\n", $label, $range, var_export($super, true), $ok ? 'EQUAL' : 'DIFFERS');
}

// The production shape: two periods via a CASE label, per-period totals from the
// (period, NULL) super-rows. The grand-total (NULL, NULL) row is discarded.
$prev_start = $start - 90 * 86400;
$case  = "CASE WHEN dt BETWEEN {$start} AND {$now} THEN 'current' ELSE 'previous' END";
$both  = "dt BETWEEN {$prev_start} AND {$now}";
$expr  = 'COUNT( DISTINCT ip )';

// `current` is the range total the loop above already computed for DISTINCT ip.
$sep = ['current' => (int) $dip_range];
$sep['previous'] = (int) $wpdb->get_var("SELECT {$expr} FROM `{$t}` WHERE dt BETWEEN {$prev_start} AND " . ($start - 1));
$rows = $wpdb->get_results(
    "SELECT {$case} AS period, {$bucket} AS b, {$expr} AS v FROM `{$t}`
      WHERE {$both} GROUP BY period, {$bucket} WITH ROLLUP",
    ARRAY_A
);
$per_period = [];
foreach ($rows as $r) {
    if (null === $r['b'] && null !== $r['period']) {
        $per_period[$r['period']] = (int) $r['v'];
    }
}
$two_ok = ($per_period['current'] ?? -1) === $sep['current'] && ($per_period['previous'] ?? -1) === $sep['previous'];
$rollup_ok = $rollup_ok && $two_ok;
printf(
    "  %-28s current %d/%d  previous %d/%d   %s\n",
    'two-period CASE + ROLLUP',
    $per_period['current'] ?? -1, $sep['current'],
    $per_period['previous'] ?? -1, $sep['previous'],
    $two_ok ? 'EQUAL' : 'DIFFERS'
);

// ── The price: two plain passes vs one ROLLUP pass, A-B-B-A ─────────────────────────
echo "\n  Handler_read_rnd_next per form (A = buckets + separate total, B = one ROLLUP):\n\n";
// Both arms derived from the SAME templates the correctness section used — arm B is
// arm A's bucket query plus exactly ' WITH ROLLUP', by construction.
$dip_bucket_sql = str_replace("__EXPR__", "COUNT( DISTINCT ip )", $bucket_tpl);
$dip_range_sql  = str_replace("__EXPR__", "COUNT( DISTINCT ip )", $range_tpl);
$run_a = static function () use ($wpdb, $dip_bucket_sql, $dip_range_sql) {
    $wpdb->get_results($dip_bucket_sql, ARRAY_A);
    $wpdb->get_var($dip_range_sql);
};
$run_b = static function () use ($wpdb, $dip_bucket_sql) {
    $wpdb->get_results($dip_bucket_sql . ' WITH ROLLUP', ARRAY_A);
};
foreach (['A' => $run_a, 'B' => $run_b, 'B2' => $run_b, 'A2' => $run_a] as $arm => $fn) {
    $before = $counter();
    $fn();
    printf("  %-3s rnd_next %d\n", $arm, $counter() - $before);
}

echo "\n";
if ($rollup_ok) {
    echo "ROLLUP VERDICT: every super-row equals its separate total, two-period shape included —\n";
    echo "the WITH ROLLUP rewrite is LICENSED: one pass replaces two with no answer change.\n";
    exit(2); // still exit 2: the A5 half above remains refuted
}
echo "ROLLUP VERDICT: a super-row DIFFERS from its separate total — the rewrite is REFUTED\n";
echo "on this server; the chart keeps its two queries.\n";
exit(2);
