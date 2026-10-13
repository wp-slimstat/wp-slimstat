<?php
// Probe slim_p8_01 (User Overview) under NETWORK scope — the merged path end-to-end.
//
// Run 27 rebuilt the durations merge vocabulary and verified its union composition
// STATICALLY against the rewriter's template; Runs 27/28 both recorded the missing
// end-to-end exercise as harness debt. This probe pays it: a real multisite, Pro's
// rewriter live, get_raw_results() answering across blogs through the plugin's own
// filters. On a pre-Run-27 Pro arm the durations union selects a `user` column no arm
// produces — the expected reading there is time_on_site lost (0s) while pageviews
// still merge, which is the RED this probe exists to record.
//
// NO `declare(strict_types=1)`: WP-CLI's eval-file evaluates this file.

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "runs under WP-CLI\n");
    exit(1);
}

if (!class_exists('\WpSlimstatPro\Addon\Addons\UserOverviewAddon')) {
    echo "SKIP: wp-slimstat-pro is not active — nothing to probe\n";
    exit(0);
}

include_once WP_PLUGIN_DIR . '/wp-slimstat/admin/view/wp-slimstat-db.php';

global $wpdb;
$analytics = apply_filters('slimstat_custom_wpdb', $wpdb);

// NetworkScope::isRequested() needs manage_network and a network-admin context —
// probe-network-view.php's recipe. Without them the probe measures the single-site
// path, silently, with a plausible-looking number.
$super = get_users(['role' => 'administrator', 'number' => 1]);
if ($super) {
    wp_set_current_user($super[0]->ID);
    grant_super_admin($super[0]->ID);
}
if (!defined('WP_NETWORK_ADMIN')) {
    define('WP_NETWORK_ADMIN', true);
}

// ── normalise: flush every cache either arm could hide behind ───────────────────────
// The slimstat_ prefix already covers every slimstat_pro_ key — one pattern pair, so a
// future probe copying this shape does not infer the pro family needs its own clauses.
$flushed = (int) $wpdb->query(
    "DELETE FROM {$wpdb->options}
      WHERE option_name LIKE '\\_transient\\_slimstat\\_%'
         OR option_name LIKE '\\_transient\\_timeout\\_slimstat\\_%'"
);
wp_cache_flush();

\wp_slimstat_db::init();
\wp_slimstat_db::$filters_normalized['utime']['start'] = 1;
\wp_slimstat_db::$filters_normalized['utime']['end']   = 2000000000;

// ── CONTROLS: the scope must actually be network, or nothing below means anything ───
$scope_on = class_exists('\WpSlimstatPro\Support\NetworkScope')
    && \WpSlimstatPro\Support\NetworkScope::isRequested();

echo "CONTROLS:\n";
printf("  network scope engaged: %s\n", $scope_on ? 'YES' : 'NO — measuring the single-site path');
printf("  window pinned: 1..2000000000 · caches flushed: %d option rows + object cache\n", $flushed);

// ── the call, every statement captured — the union SQL is the diagnosis artifact ────
$captured  = array();
$capturing = true;
add_filter('query', function ($q) use (&$captured, &$capturing) {
    if ($capturing) {
        $q = trim((string) preg_replace('/\s+/', ' ', $q));
        $captured[] = preg_replace("/'\d{10}'/", "'<TS>'", $q);
    }
    return $q;
});

$error = null;
$rows  = array();
try {
    $rows = \WpSlimstatPro\Addon\Addons\UserOverviewAddon::get_raw_results();
} catch (\Throwable $t) {
    $error = get_class($t) . ': ' . $t->getMessage();
}
$capturing = false;

$keyed = array();
foreach ((array) $rows as $i => $row) {
    if (!isset($row['username'])) {
        continue;
    }
    $keyed[$row['username']] = array(
        'pageviews'    => isset($row['pageviews']) ? (string) $row['pageviews'] : null,
        'time_on_site' => isset($row['time_on_site']) ? (string) $row['time_on_site'] : null,
    );
}
ksort($keyed);

// Only union-bearing statements in the artifact: full capture is large on a network,
// and the union shape is what the adjudicator needs. EXCEPT in the defect world where
// scope engaged but NO union was built — there the substitute statements ARE the
// diagnosis (what answered instead?), so a bounded slim_-statement fallback replaces
// an empty list that could only say "not union" and force a re-run to learn more.
$union_sql = array_values(array_filter($captured, static function ($q) {
    return false !== stripos($q, 't_union_all');
}));
$fallback_sql = [];
if ($scope_on && [] === $union_sql) {
    $fallback_sql = array_slice(array_values(array_filter($captured, static function ($q) {
        return false !== stripos($q, 'slim_');
    })), 0, 20);
}

echo "UO-NET-JSON-BEGIN\n";
echo json_encode([
    'scope_engaged'      => $scope_on,
    'error'              => $error,
    'analytics_last_err' => (string) $analytics->last_error,
    'row_count'          => count($rows),
    'rows_by_username'   => $keyed,
    'union_statements'   => count($union_sql),
    'union_sql'          => $union_sql,
    'fallback_sql'       => $fallback_sql,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
echo "UO-NET-JSON-END\n";
