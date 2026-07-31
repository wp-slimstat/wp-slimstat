<?php
/**
 * Every window that reaches the present is clamped in ONE place, and that clamp can be
 * bucketed so the window stops moving every second.
 *
 * ── Why this exists ────────────────────────────────────────────────────────────────
 *
 * `utime.end` is clamped to the current SECOND. That timestamp travels into the SQL, the
 * query cache keys on a hash of the SQL, and so an identical report rendered twice a second
 * apart produces two different keys — neither of which is ever read again.
 *
 * Measured over real logged-in admin renders of slimview2, steady state:
 *
 *                                queries/render   dead wp_options rows/render
 *     clamp unbucketed (default)       25                    11
 *     clamp bucketed to 1 hour         20                     6
 *
 * 724 such `_transient_wp_slimstat_query_*` rows had accumulated on the reference install.
 *
 * The same instability is why the parity oracle declares its midnight-straddling cells
 * "render-only" and never compares their values — the window end cannot be pinned. Those
 * are exactly the cells that exercise Query::getAll()'s split-merge path, so the oracle
 * exercises that path and then declines to look at the result. Bucketing makes the window
 * pinnable and the cells comparable.
 *
 * ── Why it defaults to off ─────────────────────────────────────────────────────────
 *
 * Turning it on changes a number a user sees: a report ending "now" ends at the bucket
 * boundary instead, excluding up to one bucket of the most recent traffic. That is a
 * product decision, so it ships inert and the caller opts in.
 *
 * The residual 6 rows/render with bucketing on are NOT from this clamp — they are Live
 * Analytics statements carrying their own `time()`, which no change here can reach.
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

// ── 1. The clamp happens in one named place ─────────────────────────────────
$clamp = slimstat_function_body($source, 'live_window_end');
if ('' === trim($clamp)) {
    $failures[] = 'live_window_end() is empty. Every window that reaches the present must be '
        . 'clamped through one place, or the bucket cannot be applied consistently and the '
        . 'parity oracle cannot pin a straddling window';
}

// ── 2. No clamp site bypasses it ────────────────────────────────────────────
// Both sites previously assigned intval(date_i18n('U')) directly.
//
// Scoped to init_filters(), the method that builds the window, rather than to the whole
// file: a file-wide count would be satisfied by a clamp added to some unrelated method,
// and the count below is the only thing pinning the hour-granularity branch. Absence of
// init_filters() throws rather than silently counting zero.
$builder = slimstat_function_body($source, 'init_filters');

if (preg_match_all("/\\\$fn\['utime'\]\['end'\]\s*=\s*intval\(\s*date_i18n\(\s*'U'\s*\)\s*\)/", $builder, $m)) {
    $failures[] = 'a utime.end clamp assigns intval(date_i18n(\'U\')) directly instead of '
        . 'going through live_window_end(), so that window keeps moving every second — its '
        . 'cache entries are written and never read, and the oracle cannot pin it';
}

$clampSites = preg_match_all("/\\\$fn\['utime'\]\['end'\]\s*=\s*self::live_window_end\(\)/", $builder);
if ($clampSites < 2) {
    $failures[] = 'expected both utime.end clamp sites to call live_window_end(); found '
        . $clampSites . '. The second site is the hour-granularity branch, and missing it '
        . 'leaves hourly reports uncacheable';
}

if ($clamp !== '') {
    // ── 3. It ships inert ───────────────────────────────────────────────────
    if (!preg_match("/apply_filters\(\s*'slimstat_live_window_bucket_seconds'\s*,\s*0\s*\)/", $clamp)) {
        $failures[] = 'the bucket filter does not default to 0. Bucketing excludes up to one '
            . 'bucket of the most recent traffic from every live report, which is a product '
            . 'decision — it must ship as a no-op and be opted into';
    }

    // ── 4. A bucket of 0 or 1 is off, not a division ────────────────────────
    if (!preg_match('/\$bucket\s*<\s*2/', $clamp)) {
        $failures[] = 'live_window_end() does not treat a bucket below 2 as disabled. A bucket '
            . 'of 0 would divide by zero and a bucket of 1 is a no-op computed the long way';
    }

    // ── 5. It rounds DOWN ───────────────────────────────────────────────────
    // Rounding up would push the window end into the future and admit rows that do not
    // exist yet, which is worse than the instability being fixed.
    if (!preg_match('/intdiv\s*\(|floor\s*\(/', $clamp)) {
        $failures[] = 'the bucket does not round down. Rounding up moves the window end into '
            . 'the future, so a report would claim a window it cannot have data for';
    }
    if (preg_match('/ceil\s*\(|round\s*\(/', $clamp)) {
        $failures[] = 'the bucket rounds with ceil()/round(), which can move the window end '
            . 'past "now"';
    }
}

// ── Report ─────────────────────────────────────────────────────────────────
if ($failures !== []) {
    fwrite(STDERR, 'FAIL: live window clamp (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: live window clamp (one clamp site, bucket filter ships inert, rounds down)\n";
exit(0);
