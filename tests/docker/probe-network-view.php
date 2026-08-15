<?php
// Probe what the Network View actually reports for total pageviews, and compare it to the
// golden fixture's hand-computed answer.
//
// NO `declare(strict_types=1)`: WP-CLI's own eval-file command loads a file by evaluating it, so
// a declare() is no longer the script's first statement and PHP fatals.
//
// WHY THIS EXISTS. The topology harness previously "verified" 40 counted rows by applying the
// archived-blog filter itself and summing the per-blog tables in raw SQL. That is 15+14+11 by
// construction — it can only fail if the loader failed, and it says nothing whatever about the
// plugin. Worse, its failure message named an outcome ("46 means the archived blog leaked in")
// that its own query was incapable of producing. A guard that looked like it worked.
//
// The real membership test lives in Pro, NetworkViewAddon::getVarSQL():
//
//     $blog_details->public == 1 && $blog_details->blog_id != $blog_details->site_id
//
// Archiving a blog sets `archived = 1` and leaves `public = 1`, so an archived subsite passes
// it. And `blog_id != site_id` is a NETWORK id comparison, so every blog of every OTHER network
// passes it too. This probe asks the filter itself rather than re-deriving the rule — a second
// implementation of the membership test would agree with whichever version I wrote, which is
// the whole failure mode being tested for.
//
// It REPORTS rather than asserts. Fixing the Network View is seam F9; this establishes the RED
// baseline F9 has to move, and a harness that failed on it would be failing the environment for
// a defect in the product.

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "runs under WP-CLI\n");
    exit(1);
}

$spec     = require __DIR__ . '/../fixtures/golden/spec.php';
$expected = $spec['expected']['pageviews'];

global $wpdb;

// NetworkScope::isRequested() needs a user with manage_network and a network-admin context.
// Without them the filter declines and the probe measures the single-site path instead —
// silently, and with a plausible-looking number.
$super = get_users(['role' => 'administrator', 'number' => 1]);
if ($super) {
    wp_set_current_user($super[0]->ID);
    grant_super_admin($super[0]->ID);
}
if (!defined('WP_NETWORK_ADMIN')) {
    define('WP_NETWORK_ADMIN', true);
}

$base = "SELECT COUNT(*) AS counthits FROM {$wpdb->prefix}slim_stats WHERE 1=1";
$sql  = apply_filters('slimstat_get_var_sql', $base, 'SUM(counthits) AS counthits');

$applied = ($sql !== $base);
$total   = $applied ? (int) $wpdb->get_var($sql) : null;

// Raw ground truth, for contrast only: the tables that SHOULD be counted, summed directly.
//
// Scoped to THIS network. get_sites() defaults network_id to 0, which means "every network" —
// so on a multi-network install the unscoped version summed the other network's blogs too and
// reported 47 against a golden expectation of 40, i.e. the ground truth carried the very leak it
// was there to contrast against.
$truth = 0;
foreach (get_sites([
    'number'     => 0,
    'network_id' => get_current_network_id(),
    'archived'   => 0,
    'deleted'    => 0,
    'spam'       => 0,
]) as $site) {
    switch_to_blog($site->blog_id);
    $truth += (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}slim_stats");
    restore_current_blog();
}

// D22: which report paths reach the addon, and which do not.
//
// reports.php computes a "top" row's share as `100 * counthits / wp_slimstat_db::$pageviews`.
// BOTH sides currently bypass the Network View: `count_records()` (the denominator) and
// `get_top()` (the numerator) each build a Query, and src/Utils/Query.php applies no filters at
// all. So the ratio is main-site-over-main-site — wrong as a *network* figure, but internally
// consistent, which is why no percentage exceeds 100% today.
//
// Reported as two separate numbers, never as a single "safe" boolean. The first version of this
// asserted `denominator >= total`, which is satisfied by the mixed-scope state AND by the
// correct one — it could not have told them apart, which is PITFALLS 21/22 inside the seam that
// wrote them. What matters is whether the two sides AGREE on scope, so that is what it prints.
$denominator = null;

// wp_slimstat_db lives in admin/view/ and is loaded only on admin screens, so it is required
// explicitly here — a `class_exists` that quietly answers false would report `null` and read as
// "not measured" rather than as the defect.
$db_file = WP_PLUGIN_DIR . '/wp-slimstat/admin/view/wp-slimstat-db.php';
if (!class_exists('wp_slimstat_db') && is_readable($db_file)) {
    require_once $db_file;
}

$numerator  = null;
$about_rows = null;
$about_max  = null;
$top_max    = null;
$ppv_avg    = null;
$ppv_max    = null;

if (class_exists('wp_slimstat_db')) {
    if (method_exists('wp_slimstat_db', 'init')) {
        wp_slimstat_db::init();
    }

    // Date filters OFF. The fixture is dated January 2026 and the container's clock is not, so
    // the default range excludes every row and count_records() answers 0 — which would read as
    // "the denominator is main-site-only" for an entirely unrelated reason. What is being
    // measured here is TABLE SCOPE, so the time axis has to be taken out of it.
    $denominator = (int) wp_slimstat_db::count_records('id', '', false);

    // The NUMERATOR, from the same path reports.php uses for a "top" report. Summed over its
    // rows so it is directly comparable to the denominator: on a correctly-scoped install the
    // rows of a top-resources report account for every pageview, so the two must be equal.
    //
    // This is the assertion PITFALLS 23 says was missing. Measuring only the denominator could
    // not tell "both main-site" (consistent, wrong scope) from "denominator alone moved"
    // (inconsistent, silently wrong) — the two states differ only in the numerator.
    // One pass computes BOTH claims: the SUM (the D22 numerator) and ROW IDENTITY (the
    // P3 assertion nothing was making). The fixture puts /about/ on two blogs (6 + 5);
    // ratified P3 says the network report lists it TWICE, per blog. A GROUP BY that
    // omits blog_id merges them into one row of 11 — and the sum is 11 either way, so
    // the numerator alone cannot see it (that is exactly how it went unnoticed). Row
    // count and max figure discriminate.
    $rows = wp_slimstat_db::get_top(['columns' => 'resource', 'use_date_filters' => false]);
    if (is_array($rows)) {
        $numerator  = 0;
        $about_rows = 0;
        $about_max  = 0;
        $top_max    = 0;
        foreach ($rows as $row) {
            $resource   = is_array($row) ? ($row['resource'] ?? '') : ($row->resource ?? '');
            $hits       = (int) (is_array($row) ? ($row['counthits'] ?? 0) : ($row->counthits ?? 0));
            $numerator += $hits;
            $top_max    = max($top_max, $hits);
            if ('/about/' === $resource) {
                $about_rows++;
                $about_max = max($about_max, $hits);
            }
        }
    }

    // M4 — pages per visit, the report's own function. Three distinguishable answers on
    // this fixture: 40/7 = 5.7143 (network, correct), 5.8333 (mean of per-blog averages —
    // what an outer AVG over unioned per-blog AVGs computes), 5.0 with max 6 (main-site
    // only — the unrouted answer). Max must read 10 (visit 301) when network-scoped.
    //
    // The WINDOW IS PINNED first, because this function exposes no date toggle at all —
    // get_combined_where() applies the default living window, the fixture is dated, and
    // the first run of this probe measured 0/0: the clock, not the scope. Same rationale
    // as the denominator above — the subject is BLOG scope, so the time axis is removed.
    wp_slimstat_db::$filters_normalized['utime']['start'] = 1;
    wp_slimstat_db::$filters_normalized['utime']['end']   = 2000000000;
    $ppv = wp_slimstat_db::get_max_and_average_pages_per_visit();
    if (is_array($ppv) && isset($ppv[0])) {
        $row     = $ppv[0];
        $ppv_avg = round((float) (is_array($row) ? ($row['avghits'] ?? 0) : ($row->avghits ?? 0)), 4);
        $ppv_max = (int) (is_array($row) ? ($row['maxhits'] ?? 0) : ($row->maxhits ?? 0));
    }
}

$result = [
    'filter_applied'      => $applied,
    'network_view_total'  => $total,
    'golden_expected'     => $expected,
    'raw_sum_of_counted'  => $truth,
    'agrees'              => ($total === $expected),
    // D22 probe — BOTH sides of reports.php's ratio, and the state they are in.
    //
    // `report_denominator` is count_records(); `report_numerator` is the summed counthits of a
    // top-resources report. Three distinguishable states, which is the whole point:
    //
    //   consistent-main-site  num == den == main-site total   today: wrong scope, but every
    //                                                         percentage is internally right
    //   MIXED                 num != den                      silently wrong: rows understate or
    //                                                         overstate, and reports.php clamps
    //                                                         only the >99 direction
    //   consistent-network    num == den == network total     the target
    //
    // The previous version reported only the denominator and a `denominator >= total` flag,
    // which the mixed state and the correct state both satisfy. It could not have told them
    // apart — PITFALLS 21/22/23.
    'report_denominator'  => $denominator,
    'report_numerator'    => $numerator,
    // P3 row identity: /about/ lives on two blogs (6 + 5). Ratified P3 lists it twice;
    // a blog_id-less GROUP BY merges it into one row of 11 that the SUM cannot see.
    'about_rows'          => $about_rows,
    'about_rows_expected' => $spec['expected']['about_rows_in_network_report'] ?? null,
    'about_max'           => $about_max,
    'about_max_expected'  => $spec['expected']['about_largest_single_figure'] ?? null,
    'top_resource_max'    => $top_max,
    'top_max_expected'    => $spec['expected']['top_resource_per_blog_max'] ?? null,
    // M4: pages/visit — network 5.7143, mean-of-blogs trap 5.8333, main-site 5.0/max 6.
    'ppv_avg'             => $ppv_avg,
    'ppv_avg_expected'    => round((float) ($spec['expected']['pages_per_visit_network'] ?? 0), 4),
    'ppv_avg_trap'        => round((float) ($spec['expected']['pages_per_visit_mean_of_blogs'] ?? 0), 4),
    'ppv_max'             => $ppv_max,
    'ppv_max_expected'    => $spec['expected']['max_pages_single_visit'] ?? null,
    'report_scope'        => (null === $numerator || null === $denominator)
        ? 'unmeasured'
        : ($numerator !== $denominator
            ? 'MIXED'
            : ($denominator === $total ? 'consistent-network' : 'consistent-main-site')),
];

WP_CLI::log('NETWORK-VIEW-PROBE ' . json_encode($result));

if (!$applied) {
    WP_CLI::warning(
        'the slimstat_get_var_sql filter did not modify the query, so the Network View path was '
            . 'NOT exercised. Either Pro is not active or NetworkScope::isRequested() declined — '
            . 'treat this run as measuring nothing.'
    );
    exit(0);
}

if ($total === $expected) {
    WP_CLI::success(sprintf('Network View total = %d, matching the golden fixture', $total));
    exit(0);
}

WP_CLI::warning(sprintf(
    'Network View reports %d where the golden fixture says %d. This is the F9 baseline, not a '
        . 'harness failure: NetworkViewAddon tests `public == 1`, and archiving a subsite leaves '
        . '`public` set — so blog 4 is counted. Raw sum of the blogs that SHOULD count = %d.',
    $total,
    $expected,
    $truth
));
exit(0);
