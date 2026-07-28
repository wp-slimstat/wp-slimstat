<?php
/**
 * Regression test: no path may compute an unbounded number of goal/funnel aggregates
 * (D14, D40).
 *
 * Four entry points read these options and compute an aggregate per entry:
 *
 *   show_goals_compact()   admin dashboard widget + [slimstat f=widget w=slim_p9_01],
 *   show_funnels_compact() the same for funnels — both reachable on an **anonymous
 *                          frontend pageview**
 *   get_goals_raw()        the email-report cron, via the report's `raw` callback
 *   get_funnels_raw()      the same, and it had no tier gate at all
 *
 * Measured on the reference install, cold cache, 443k rows, with 12 active goals and 6
 * funnels stored against tier maxima of 5 and 3 — the shape a Pro-to-free downgrade or
 * an imported option produces:
 *
 *     Goals    65 queries  3,695 ms   ->  30 queries  1,734 ms
 *     Funnels  51 queries  1,181 ms   ->  34 queries    694 ms
 *
 * What this pins is the BOUND, not the absolute cost. Inside the tier maximum a public
 * shortcode render still computes up to that many aggregates on a cache miss; bounding
 * that means deciding a public request may not compute analytics at all, which changes
 * what the shortcode shows on a cold cache.
 *
 * The funnels renderer's behaviour is covered by execution in
 * tests/funnels-widget-compact-test.php (chains built, deferred panels, ARIA). This
 * file covers the three paths that have no such harness.
 *
 * @see admin/view/wp-slimstat-reports.php
 * @see admin/view/wp-slimstat-db.php
 */

declare(strict_types=1);

namespace {

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

require_once __DIR__ . '/lib/source-scan.php';

$failures = [];
$passes   = 0;

function gfw_assert(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $passes;
    if ($ok) {
        $passes++;
        return;
    }
    $failures[] = $name . ($detail !== '' ? " — {$detail}" : '');
}

$reports = (string) file_get_contents(__DIR__ . '/../admin/view/wp-slimstat-reports.php');
$db      = (string) file_get_contents(__DIR__ . '/../admin/view/wp-slimstat-db.php');
if ($reports === '' || $db === '') {
    fwrite(STDERR, "FAIL: cannot read the report/db sources\n");
    exit(1);
}

// ── 1. The goals widget bounds what it computes ────────────────────────────
//
// Asserted on the tier filter rather than on a private helper name: the filter is the
// contract, the helper is an implementation detail.
//
// Deliberately NOT routed through pause_excess_free_goals(), the admin card's
// normalizer — that marks excess goals inactive, and the compact renderer skips
// inactive goals entirely, so reusing it would make them vanish rather than appear
// without numbers. It also persists, on a path that is often an anonymous frontend
// request.
$goals_widget = slimstat_function_body($reports, 'show_goals_compact');
gfw_assert(
    'show_goals_compact() exists',
    $goals_widget !== '',
    'method not found — this test can no longer see the widget path'
);
gfw_assert(
    'the goals widget consults the tier limit',
    strpos($goals_widget, 'slimstat_max_goals') !== false,
    'it computes an aggregate for every active goal in the option, unbounded'
);

// It must not persist anything. The admin card owns that write; a public shortcode
// doing it would be a wp_options write on an anonymous pageview — the exact class of
// defect D29/D30 removed from the tracking path.
gfw_assert(
    'the goals widget does not write the option',
    !preg_match('/update_option\s*\(/', $goals_widget),
    'a frontend shortcode render would write wp_options'
);

// ── 2. The cron exporters are bounded too ──────────────────────────────────
//
// These run from the email-report cron via each report's `raw` callback. They are one
// seam away from the widget and were missed by the first pass at this fix.
$goals_raw = slimstat_function_body($db, 'get_goals_raw');
gfw_assert(
    'get_goals_raw() is bounded',
    $goals_raw !== '' && strpos($goals_raw, 'slimstat_max_goals') !== false,
    'the email-report cron computes an aggregate for every stored goal'
);

$funnels_raw = slimstat_function_body($db, 'get_funnels_raw');
gfw_assert(
    'get_funnels_raw() is bounded',
    $funnels_raw !== '' && strpos($funnels_raw, 'slimstat_max_funnels') !== false,
    'the email-report cron builds a temp-table chain for every stored funnel'
);
gfw_assert(
    'get_funnels_raw() is gated on the tier at all',
    $funnels_raw !== '' && preg_match('/max_funnels\s*<=\s*0|max_funnels\s*<\s*1/', $funnels_raw) === 1,
    'unlike show_funnels(), it built funnels the tier says do not exist — so a '
        . 'Pro-to-free downgrade kept running them on cron'
);

// ── 3. The bound is filterable, per entry type ─────────────────────────────
//
// Goals and funnels have different tier defaults and very different per-entry cost, so
// one number governing both would be unusable: the filter carries the type.
gfw_assert(
    'the bound is filterable',
    preg_match("/apply_filters\(\s*'slimstat_widget_max_entries'/", $reports) === 1,
    'no slimstat_widget_max_entries filter — the bound cannot be tuned per site'
);
gfw_assert(
    'the filter tells a callback which entry type it is bounding',
    preg_match("/apply_filters\(\s*'slimstat_widget_max_entries'\s*,[^)]*,\s*\\\$type/", $reports) === 1,
    'one return value governs both goals and funnels, so a site cannot raise one and '
        . 'lower the other'
);

// ── Report ──────────────────────────────────────────────────────────────────
if ($failures !== []) {
    fwrite(STDERR, 'FAIL: goals/funnels compute bound (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

printf("PASS: goals/funnels compute bound (%d assertions)\n", $passes);
exit(0);

}
