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

$result = [
    'filter_applied'      => $applied,
    'network_view_total'  => $total,
    'golden_expected'     => $expected,
    'raw_sum_of_counted'  => $truth,
    'agrees'              => ($total === $expected),
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
