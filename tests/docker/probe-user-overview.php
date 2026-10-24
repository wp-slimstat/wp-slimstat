<?php
// Probe UserOverviewAddon::get_raw_results — dump the report's FULL answer, keyed by
// username, plus every SQL statement it issued, so the F6 join split can be compared
// before/after as data rather than as prose.
//
// NO `declare(strict_types=1)`: WP-CLI's eval-file evaluates this file, so a declare()
// would not be the first statement and PHP fatals.
//
// It REPORTS rather than asserts, like probe-network-view.php: on the pre-split arm
// under a genuinely separate analytics server, the report is EXPECTED to fail — that
// failure is the RED baseline the split has to move, and a probe that exited non-zero
// on it would be failing the environment for a defect in the product.
//
// Determinism notes, so the null control (same arm twice → byte-identical JSON) holds:
//   - the report window is PINNED below, because wp_slimstat_db::init() derives its
//     default window from "now" and a moving window makes the two arms answer
//     different questions;
//   - the login-aggregate and get_results() transients are flushed FIRST, because they
//     are keyed on md5(sql) — the two arms issue different SQL, so a warm cache would
//     time-shift one arm's answer but not the other's;
//   - each invocation is a fresh PHP process, so the addon's static caches start empty.

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
$analytics = \wp_slimstat::$wpdb;
if (!($analytics instanceof \wpdb)) {
    // init() runs at plugins_loaded:20 with the plugin active; a null here means the
    // environment is broken, and every number below would be about the breakage.
    fwrite(STDERR, "wp_slimstat::\$wpdb is not a wpdb — environment broken, refusing to measure\n");
    exit(1);
}

// ── normalise: flush every cache either arm could hide behind ───────────────────────
$flushed = (int) $wpdb->query(
    "DELETE FROM {$wpdb->options}
      WHERE option_name LIKE '\\_transient\\_slimstat\\_query\\_%'
         OR option_name LIKE '\\_transient\\_timeout\\_slimstat\\_query\\_%'
         OR option_name LIKE '\\_transient\\_slimstat\\_pro\\_last\\_logins%'
         OR option_name LIKE '\\_transient\\_timeout\\_slimstat\\_pro\\_last\\_logins%'
         OR option_name LIKE '\\_transient\\_slimstat\\_pro\\_login\\_counts%'
         OR option_name LIKE '\\_transient\\_timeout\\_slimstat\\_pro\\_login\\_counts%'"
);
wp_cache_flush();

\wp_slimstat_db::init();

// Pin the window over everything: both arms answer "all time", not "the 30 days that
// happened to contain the moment each arm ran".
\wp_slimstat_db::$filters_normalized['utime']['start'] = 1;
\wp_slimstat_db::$filters_normalized['utime']['end']   = 2000000000;

// ── CONTROLS: prove the two arms saw the same world before either answers ───────────
$corpus = $analytics->get_row(
    "SELECT COUNT(*) AS total, COUNT(username) AS with_username,
            COUNT(DISTINCT username) AS distinct_usernames
       FROM {$wpdb->prefix}slim_stats", ARRAY_A
);
$users_total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");

echo "CONTROLS:\n";
echo '  handles: ' . (($analytics === $wpdb) ? 'SAME object (no external DB)' : 'DISTINCT (external DB addon live)') . "\n";
printf("  analytics dbname=%s prefix=%s · core dbname=%s prefix=%s\n",
    isset($analytics->dbname) ? $analytics->dbname : '?', $analytics->prefix,
    isset($wpdb->dbname) ? $wpdb->dbname : '?', $wpdb->prefix);
printf("  corpus: slim_stats total=%s with_username=%s distinct_usernames=%s · wp_users=%d\n",
    $corpus ? $corpus['total'] : 'ERR', $corpus ? $corpus['with_username'] : 'ERR',
    $corpus ? $corpus['distinct_usernames'] : 'ERR', $users_total);
printf("  window pinned: 1..2000000000 · caches flushed: %d option rows + object cache\n", $flushed);

// ── the call, with every statement it issues captured from BOTH handles ─────────────
$captured  = array();
$capturing = true;
add_filter('query', function ($q) use (&$captured, &$capturing) {
    if ($capturing) {
        $q = trim(preg_replace('/\s+/', ' ', $q));
        // Transient writes carry their expiry as a quoted epoch — wall-clock, so two
        // otherwise-identical runs differ by that one value and the null control
        // (rightly) fails. Normalise quoted 10-digit epochs; the pinned window's own
        // bounds are unquoted integers and survive untouched.
        $captured[] = preg_replace("/'\d{10}'/", "'<TS>'", $q);
    }
    return $q;
});

$nq_core_before      = $wpdb->num_queries;
$nq_analytics_before = $analytics->num_queries;
$error = null;
$rows  = array();
try {
    $rows = \WpSlimstatPro\Addon\Addons\UserOverviewAddon::get_raw_results();
} catch (\Throwable $t) {
    $error = get_class($t) . ': ' . $t->getMessage();
}
$capturing = false;

$nq_core      = $wpdb->num_queries - $nq_core_before;
$nq_analytics = $analytics->num_queries - $nq_analytics_before;

// ── the durations statement's own cost: rows hydrated and rows read ─────────────────
// slim_p8_01's per-user time_on_site used to hydrate one row per VISIT and sum in PHP;
// the fold sums in SQL and hydrates one row per USER. Identified by its unique column
// name (first SELECT naming visit_duration — its transient write repeats the name but
// comes later and is not a SELECT), re-executed on the analytics handle so the counts
// are attributable to THIS statement alone. Deterministic on a fixed corpus: hydration
// is a row count, Handler_read_rnd_next counts logical row reads, not I/O.
// Counter read matches probe-chart-totals.php: a point SELECT on performance_schema,
// not SHOW SESSION STATUS, whose own ~500-row materialisation would drown the number.
$rnd_next = static function () use ($analytics) {
    return (int) $analytics->get_var("SELECT VARIABLE_VALUE FROM performance_schema.session_status WHERE VARIABLE_NAME = 'Handler_read_rnd_next'");
};

$durations_cost = null;
foreach ($captured as $stmt) {
    if (0 === stripos($stmt, 'select') && false !== stripos($stmt, 'visit_duration')) {
        $rnd_before     = $rnd_next();
        $hydrated       = $analytics->get_results($stmt, ARRAY_A);
        $durations_cost = array(
            'sql'           => $stmt,
            'rows_hydrated' => count((array) $hydrated),
            'rnd_next'      => $rnd_next() - $rnd_before,
        );
        break;
    }
}

// ── the answer, keyed by username so comparison is keyed, never positional ──────────
$keyed = array();
$order = array();
foreach ((array) $rows as $i => $row) {
    $order[] = isset($row['username']) ? $row['username'] : "?$i";
    if (!isset($row['username'])) {
        continue;
    }
    $keyed[$row['username']] = array(
        'ID'              => isset($row['ID']) ? (string) $row['ID'] : null,
        'user_email'      => isset($row['user_email']) ? $row['user_email'] : null,
        'user_registered' => isset($row['user_registered']) ? $row['user_registered'] : null,
        'pageviews'       => isset($row['pageviews']) ? (string) $row['pageviews'] : null,
        'login_count'     => isset($row['login_count']) ? (int) $row['login_count'] : null,
        'last_login_ts'   => isset($row['last_login_ts']) ? (int) $row['last_login_ts'] : null,
        'time_on_site'    => isset($row['time_on_site']) ? (string) $row['time_on_site'] : null,
    );
}
ksort($keyed);

$report = array(
    'row_count'          => count($rows),
    'error'              => $error,
    'analytics_last_err' => (string) $analytics->last_error,
    'queries'            => array('core+analytics total' => count($captured),
                                  'core_handle' => $nq_core, 'analytics_handle' => $nq_analytics,
                                  'handles_same_object' => ($analytics === $wpdb)),
    'sort_order'         => $order,
    'rows_by_username'   => $keyed,
    'durations_stmt'     => $durations_cost,
    'sql'                => $captured,
);

echo "UO-JSON-BEGIN\n";
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
echo "UO-JSON-END\n";
