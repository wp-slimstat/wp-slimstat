<?php
// Probe D50: the email cron's per-author pageview counts. BEFORE, the authors loop
// asked count_records('id', author = %s) once per registered user; AFTER, one grouped
// statement (authorPageviewMaps) answers every author and the loop looks the counts up.
//
// The probe runs BOTH forms against the same corpus in the same process: the per-author
// count_records calls ARE the before-arm's behaviour, executed here as the ORACLE, so
// the equivalence table below is the before-vs-after answer comparison whichever arm
// carries the probe. On an arm without authorPageviewMaps() the maps side reports
// absent — that absence is the before-arm's cost record: one full scan per user.
//
// NO `declare(strict_types=1)`: WP-CLI's eval-file evaluates this file.

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "runs under WP-CLI\n");
    exit(1);
}

if (!class_exists('\WpSlimstatPro\Addon\Addons\EmailReportsAddon')) {
    echo "SKIP: wp-slimstat-pro is not active — nothing to probe\n";
    exit(0);
}

include_once WP_PLUGIN_DIR . '/wp-slimstat/admin/view/wp-slimstat-db.php';

global $wpdb;
$analytics = \wp_slimstat::$wpdb;

// ── normalise: flush every cache either arm could hide behind ───────────────────────
$flushed = (int) $wpdb->query(
    "DELETE FROM {$wpdb->options}
      WHERE option_name LIKE '\\_transient\\_slimstat\\_%'
         OR option_name LIKE '\\_transient\\_timeout\\_slimstat\\_%'"
);
wp_cache_flush();

\wp_slimstat_db::init();
\wp_slimstat_db::$filters_normalized['utime']['start'] = 1;
\wp_slimstat_db::$filters_normalized['utime']['end']   = 2000000000;

// ── CONTROLS ────────────────────────────────────────────────────────────────────────
$corpus = $wpdb->get_results(
    "SELECT COALESCE(author, '(null)') AS author, COUNT(*) AS rows_n FROM {$wpdb->prefix}slim_stats GROUP BY author ORDER BY author",
    ARRAY_A
);
echo "CONTROLS:\n";
foreach ((array) $corpus as $row) {
    printf("  corpus: author=%-8s rows=%s\n", $row['author'], $row['rows_n']);
}
printf("  window pinned: 1..2000000000 · caches flushed: %d option rows + object cache\n", $flushed);

// Handler_read_next, not rnd_next: COUNT along the clustered index registers on
// read_next, so it is the counter that registers BOTH forms here — the oracle's
// users × index-scan rows and the maps side's one scan. rnd_next would invert the
// story in this JSON (the full-scan-per-user side reads 0, the one-statement side
// reads its temp table) — measured before this comment was written.
$read_next = static function () use ($analytics) {
    return (int) $analytics->get_var("SELECT VARIABLE_VALUE FROM performance_schema.session_status WHERE VARIABLE_NAME = 'Handler_read_next'");
};

$addon = new ReflectionClass('\WpSlimstatPro\Addon\Addons\EmailReportsAddon');

$author_where = $addon->getMethod('authorWhere');
$author_where->setAccessible(true);

$logins = [];
foreach (get_users(['fields' => ['user_login']]) as $u) {
    $logins[] = $u->user_login;
}
sort($logins);

// ── the ORACLE (= the before-arm's loop, verbatim): count_records per login ─────────
$oracle       = [];
$nq_before    = $analytics->num_queries;
$rn_before    = $read_next();
foreach ($logins as $login) {
    $oracle[$login] = (int) \wp_slimstat_db::count_records('id', $author_where->invoke(null, $login));
}
$oracle_cost = ['statements' => $analytics->num_queries - $nq_before, 'read_next' => $read_next() - $rn_before];

// ── the AFTER form, where this arm has it: one grouped statement + lookups ──────────
$maps_side = ['present' => false];
if ($addon->hasMethod('authorPageviewMaps')) {
    $maps_method = $addon->getMethod('authorPageviewMaps');
    $maps_method->setAccessible(true);

    $nq_before  = $analytics->num_queries;
    $rn_before  = $read_next();
    $maps       = $maps_method->invoke(null);
    $maps_cost  = ['statements' => $analytics->num_queries - $nq_before, 'read_next' => $read_next() - $rn_before];

    $lookups = [];
    foreach ($logins as $login) {
        $lookups[$login] = \WpSlimstatPro\Support\UsernameIndex::lookup($maps, $login);
    }

    $agree = true;
    foreach ($logins as $login) {
        if ($lookups[$login] !== $oracle[$login]) {
            $agree = false;
        }
    }

    $maps_side = ['present' => true, 'cost' => $maps_cost, 'lookups' => $lookups, 'agrees_with_oracle' => $agree];
}

echo "EA-JSON-BEGIN\n";
echo json_encode([
    'logins'      => $logins,
    'oracle'      => $oracle,
    'oracle_cost' => $oracle_cost,
    'maps'        => $maps_side,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
echo "EA-JSON-END\n";
