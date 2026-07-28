<?php
/**
 * Query budget for the admin-bar menu.
 *
 * `admin_bar_menu` fires on the FRONT END too, for every logged-in user, on every
 * themed pageview. Whatever add_menu_to_adminbar() does is therefore paid by the
 * whole site, not just by SlimStat's own screens.
 *
 * It was issuing seven analytics queries inline — including two unindexable
 * `referer NOT LIKE '%host%'` scans and a derived-table GROUP BY over visit_id —
 * while its own AJAX twin, get_adminbar_stats(), computed the same figures in two
 * conditional-aggregate queries behind a 60-second transient 1,200 lines away.
 * Measured on 443k rows: 886,726 rows read per frontend request.
 *
 * The two paths also disagreed about when "today" starts: the render path used
 * mktime(0,0,0) (server timezone) and the AJAX path strtotime('today',
 * current_time('timestamp')) (site timezone). On any site whose WordPress
 * timezone differs from the server's, the adminbar and its own refresh showed
 * different numbers for the same day.
 *
 * This pins both properties: one shared cached source of truth, and no raw
 * analytics queries on the render path.
 *
 * Baseline and defect id (D31) live in the workspace performance notes, outside
 * this repository — deliberately not linked, since this file ships to wp.org.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

$source = file_get_contents($plugin_root . '/admin/index.php');
if ($source === false) {
    fwrite(STDERR, "FAIL: cannot read admin/index.php\n");
    exit(1);
}

$render = slimstat_function_body($source, 'add_menu_to_adminbar');
if ($render === '') {
    fwrite(STDERR, "FAIL: add_menu_to_adminbar() not found in admin/index.php\n");
    exit(1);
}

// ── 1. The render path must not issue analytics queries of its own ──────────
// This is the budget. It runs on every logged-in frontend pageview.
foreach (['get_var', 'get_row', 'get_results', 'get_col', 'query'] as $method) {
    if (preg_match('/\$wpdb\s*->\s*' . $method . '\s*\(/', $render)) {
        $failures[] = sprintf(
            'add_menu_to_adminbar() calls $wpdb->%s() directly — that is an analytics query on '
                . 'every logged-in FRONTEND pageview. Use the shared cached helper instead.',
            $method
        );
    }
}

// Anchored to SQL shape rather than the bare keyword: this body is mostly HTML,
// where `<select>` and CSS classes such as `user-select` are legitimate.
if (preg_match('/\bSELECT\s[\s\S]{0,400}?\bFROM\b/i', $render)) {
    $failures[] = 'add_menu_to_adminbar() contains inline SQL — the render path must read from cache';
}

// ── 2. Both paths must share one source of truth ────────────────────────────
$ajax = slimstat_function_body($source, 'get_adminbar_stats');
if ($ajax === '') {
    $failures[] = 'get_adminbar_stats() not found — the AJAX twin is the cached path';
}

$shared = slimstat_function_body($source, 'adminbar_today_stats');
if ($shared === '') {
    $failures[] = 'adminbar_today_stats() not found — the cached today/yesterday figures must live '
        . 'in one helper that both the render path and the AJAX path call';
} else {
    // Any cross-request backend is acceptable — the invariant is that the
    // figures are cached, not which API stores them.
    if (!preg_match('/(get_transient|wp_cache_get)\s*\(/', $shared)) {
        $failures[] = 'adminbar_today_stats() never reads a cache — caching is the whole point';
    }
    if (!preg_match('/(set_transient|wp_cache_set)\s*\(/', $shared)) {
        $failures[] = 'adminbar_today_stats() never writes a cache';
    }
    foreach (['add_menu_to_adminbar' => $render, 'get_adminbar_stats' => $ajax] as $name => $body) {
        if ($body !== '' && !preg_match('/adminbar_today_stats\s*\(/', $body)) {
            $failures[] = "{$name}() does not call adminbar_today_stats() — the two paths have "
                . 'drifted apart again';
        }
    }
}

// ── 2b. The online count is cached too ──────────────────────────────────────
//
// It was left uncached on purpose when the rest of the adminbar was fixed, because it
// is the one figure labelled "right now". Measured since: with 30 minutes of live
// traffic (3,000 pageviews over 600 visits) it reads 8,685 rows and takes 4.1 ms — and
// it ran on every admin render for every logged-in user, plus a per-minute AJAX poll.
//
// The figure already counts activity over a 30-minute window, so a value up to a minute
// old is a small change to how true it is. Signed off 2026-07-28.
$online_raw    = slimstat_function_body($source, 'query_online_count');
$online_cached = slimstat_function_body($source, 'online_count');

if ($online_cached === '') {
    $failures[] = 'online_count() not found — the cached wrapper the render path and the AJAX '
        . 'path should both read from';
} else {
    if (!preg_match('/(get_transient|wp_cache_get)\s*\(/', $online_cached)) {
        $failures[] = 'online_count() never reads a cache — caching is the whole point';
    }
    if (!preg_match('/(set_transient|wp_cache_set)\s*\(/', $online_cached)) {
        $failures[] = 'online_count() never writes a cache';
    }
    // A count of 0 is a legitimate answer, and the commonest one on a quiet site. If the
    // hit check treats it as a miss, the cache never serves anything on exactly the
    // installs where it would otherwise be free — the same shape as the goal transients
    // that could never be read back (D33).
    if (!preg_match('/false\s*!==\s*\$\w+|\$\w+\s*!==\s*false/', $online_cached)) {
        $failures[] = 'online_count() does not test its cache hit strictly against false — a '
            . 'legitimate count of 0 would read as a miss and recompute on every render';
    }
    // The raw query must stay behind the wrapper: a caller reaching past it puts the
    // 8,685-row scan back on whichever path it is called from.
    foreach (['add_menu_to_adminbar' => $render, 'get_adminbar_stats' => $ajax] as $name => $body) {
        if ($body !== '' && preg_match('/query_online_count\s*\(/', $body)) {
            $failures[] = "{$name}() calls query_online_count() directly, bypassing the cache";
        }
    }
}

if ($online_raw === '') {
    $failures[] = 'query_online_count() not found — the raw query the wrapper should guard';
} elseif (preg_match('/(get_transient|set_transient)\s*\(/', $online_raw)) {
    $failures[] = 'query_online_count() caches internally — the raw query and its cache belong '
        . 'in separate functions, or the AJAX path cannot ask for a fresh value if it ever needs one';
}

// ── 3. One definition of "today" ────────────────────────────────────────────
// mktime(0,0,0) is the server's midnight; current_time() is the site's. Using
// both produced two different day boundaries for the same numbers.
// Asserted against the helper, not the render path. Sections 1-2 already forbid
// date math in $render, so checking it there was a test that could never fail
// while the helper itself silently regressed to server-timezone midnight.
if ($shared !== '') {
    if (preg_match('/\bmktime\s*\(\s*0\s*,\s*0\s*,\s*0\s*\)/', $shared)) {
        $failures[] = 'adminbar_today_stats() derives midnight with mktime(0,0,0) — the SERVER '
            . 'timezone. "Today" must start at the site timezone midnight, or the adminbar and '
            . 'its own AJAX refresh disagree about which day it is';
    }
    if (!preg_match('/current_time\s*\(/', $shared)) {
        $failures[] = 'adminbar_today_stats() does not use current_time() — the day boundary must '
            . 'follow the site timezone';
    }
}

// ── Report ─────────────────────────────────────────────────────────────────
if ($failures !== []) {
    fwrite(STDERR, 'FAIL: adminbar query budget (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

// "no inline analytics queries" is the accurate claim: for Pro the render path
// still calls LiveAnalyticsReport::get_users_chart_data(), which is cached but
// not free.
echo "PASS: adminbar query budget (no inline analytics queries on the render path; "
    . "both paths share one cached, site-timezone helper)\n";
exit(0);
