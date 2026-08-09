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
exit(2);
