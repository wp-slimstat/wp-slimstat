<?php
// Probe get_goals_raw()/get_funnels_raw() with and without a caller-supplied WHERE —
// the D58 surface. The per-author email loop hands every report's raw callback an
// `author = %s` clause; these two callbacks declared $_args and read none of it, so
// every author was mailed the site-wide numbers under their own name.
//
// The probe deliberately runs its calls in the CRON'S OWN ORDER — site-wide, then
// author after author, then one author AGAIN — inside one PHP process, because the
// defect's nastiest form is not the ignored WHERE but the caches: a scope-blind memo
// or transient key serves the first answer to every later caller. The repeat read
// exists so a key that is merely "first caller wins" cannot masquerade as correct.
//
// NO `declare(strict_types=1)`: WP-CLI's eval-file evaluates this file, so a declare()
// would not be the first statement and PHP fatals.
//
// It REPORTS rather than asserts (probe-user-overview.php's stance): on the before
// arm the scoped answers are EXPECTED to equal the site-wide ones — that equality is
// the RED this run exists to record, and a probe that exited non-zero on it would be
// failing the environment for a defect in the product.

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "runs under WP-CLI\n");
    exit(1);
}

include_once WP_PLUGIN_DIR . '/wp-slimstat/admin/view/wp-slimstat-db.php';

global $wpdb;

// ── fixture options: one goal, one two-step funnel — deterministic, idempotent ──────
update_option('slimstat_goals', [
    ['id' => 'g1', 'active' => 1, 'name' => 'Buy page', 'dimension' => 'resource', 'operator' => 'contains', 'value' => '/buy'],
], false);
update_option('slimstat_funnels', [
    ['id' => 'f1', 'name' => 'Landing to signup', 'steps' => [
        ['name' => 'Landing', 'dimension' => 'resource', 'operator' => 'equals', 'value' => '/f1'],
        ['name' => 'Signup', 'dimension' => 'resource', 'operator' => 'equals', 'value' => '/f2'],
    ]],
], false);

// Free's default funnel cap is 0 — the funnel half of D58 only runs where Pro raises
// it, which is exactly the email cron's world. Emulate that tier cap, and state it.
add_filter('slimstat_max_funnels', function () { return 10; });

// ── normalise: every cache either arm could hide behind ─────────────────────────────
$flushed = (int) $wpdb->query(
    "DELETE FROM {$wpdb->options}
      WHERE option_name LIKE '\\_transient\\_slimstat\\_%'
         OR option_name LIKE '\\_transient\\_timeout\\_slimstat\\_%'"
);
wp_cache_flush();

\wp_slimstat_db::init();

// Pin the window: both arms answer "all time", not a moving 30 days.
\wp_slimstat_db::$filters_normalized['utime']['start'] = 1;
\wp_slimstat_db::$filters_normalized['utime']['end']   = 2000000000;

// ── CONTROLS ────────────────────────────────────────────────────────────────────────
$corpus = $wpdb->get_results(
    "SELECT COALESCE(author, '(null)') AS author, COUNT(*) AS rows_n,
            COUNT(DISTINCT fingerprint) AS visitors
       FROM {$wpdb->prefix}slim_stats GROUP BY author ORDER BY author",
    ARRAY_A
);
echo "CONTROLS:\n";
foreach ((array) $corpus as $row) {
    printf("  corpus: author=%-8s rows=%s visitors=%s\n", $row['author'], $row['rows_n'], $row['visitors']);
}
printf("  goals option: %d · funnels option: %d · caches flushed: %d option rows + object cache\n",
    count(get_option('slimstat_goals', [])), count(get_option('slimstat_funnels', [])), $flushed);
echo "  window pinned: 1..2000000000\n";

// ── the calls, in the cron's order, scoped answers bracketed by a repeat read ──────
$author_where = static function ($login) use ($wpdb) {
    return $wpdb->prepare('author = %s', $login);
};

$report = [
    'goals' => [
        'site'         => \wp_slimstat_db::get_goals_raw([]),
        'alice'        => \wp_slimstat_db::get_goals_raw(['where' => $author_where('alice')]),
        'bob'          => \wp_slimstat_db::get_goals_raw(['where' => $author_where('bob')]),
        'alice_repeat' => \wp_slimstat_db::get_goals_raw(['where' => $author_where('alice')]),
    ],
    'funnels' => [
        'site'         => \wp_slimstat_db::get_funnels_raw([]),
        'alice'        => \wp_slimstat_db::get_funnels_raw(['where' => $author_where('alice')]),
        'bob'          => \wp_slimstat_db::get_funnels_raw(['where' => $author_where('bob')]),
        'alice_repeat' => \wp_slimstat_db::get_funnels_raw(['where' => $author_where('alice')]),
    ],
];

echo "GA-JSON-BEGIN\n";
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
echo "GA-JSON-END\n";
