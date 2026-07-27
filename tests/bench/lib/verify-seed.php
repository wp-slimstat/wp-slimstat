<?php
// Verifies that seeded data actually reproduces the measured profile.
//
//   wp eval-file tests/bench/lib/verify-seed.php [tolerance]
//
// A seeder that silently drifts is worse than no seeder: every benchmark and
// EXPLAIN plan built on it would be measuring traffic that does not exist.
// This caught two real defects on first run — referer and fingerprint NULL
// rates seeded at 0.0000 against measured 0.7755 and 0.1431, because
// prepare('%s', null) emits an empty string rather than NULL.
//
// Cardinality is checked as a floor, not an equality: sampling 50k rows cannot
// reach every value in a 1326-value tail, and cardinality converges upward with
// row count. Rates are checked against a tolerance.
//
// No declare(strict_types=1): `wp eval-file` eval()s this file.

if (!defined('ABSPATH')) {
    fwrite(STDERR, "must run inside WordPress (wp eval-file)\n");
    exit(2);
}

$tolerance = isset($args[0]) ? (float) $args[0] : 0.02;

$db      = (class_exists('wp_slimstat') && wp_slimstat::$wpdb instanceof wpdb) ? wp_slimstat::$wpdb : $GLOBALS['wpdb'];
$table   = $db->prefix . 'slim_stats';
$marker  = "ip LIKE '203.0.113.%'";
$profile = json_decode((string) file_get_contents(dirname(__DIR__) . '/seed-profile.json'), true);

if (!is_array($profile)) {
    echo "ERROR: seed-profile.json unreadable\n";
    echo "VERDICT: ERROR\n";
    return;
}

// One pass instead of eleven. There is no index on `ip`, so every one of these
// was a full scan — 11 x 443k today, 11 x 10M at the stress tier.
$agg = $db->get_row(
    "SELECT COUNT(*) rows_, COUNT(DISTINCT visit_id) visits,
            SUM(browser_type = 1) bt1, SUM(browser_type = 2) bt2,
            SUM(referer IS NULL) referer_null, SUM(fingerprint IS NULL) fingerprint_null,
            SUM(content_type IS NULL) content_type_null, SUM(category IS NULL) category_null,
            SUM(author IS NULL) author_null,
            COUNT(DISTINCT resource) d_resource, COUNT(DISTINCT user_agent) d_user_agent,
            COUNT(DISTINCT country) d_country, COUNT(DISTINCT browser) d_browser,
            COUNT(DISTINCT platform) d_platform
     FROM `{$table}` WHERE {$marker}",
    ARRAY_A
);

$rows = (int) ($agg['rows_'] ?? 0);
if ($rows === 0) {
    echo "ERROR: no seeded rows found — run tests/bench/lib/seed.php first\n";
    echo "VERDICT: ERROR\n";
    return;
}

$failures = [];
printf("%-30s %10s\n\n", 'seeded rows', number_format($rows));
printf("%-30s %10s %10s %8s\n", 'metric', 'seeded', 'profile', 'delta');
echo str_repeat('-', 62) . "\n";

$check_rate = static function (string $label, float $got, float $want, ?float $tol = null) use (&$failures, $tolerance) {
    $tol   = $tol ?? $tolerance;
    $delta = abs($got - $want);
    printf("%-30s %10.4f %10.4f %8.4f%s\n", $label, $got, $want, $delta, $delta > $tol ? '  FAIL' : '');
    if ($delta > $tol) {
        $failures[] = sprintf('%s: seeded %.4f, profile %.4f (tolerance %.4f)', $label, $got, $want, $tol);
    }
};

$visits = (int) $agg['visits'];
$pv     = $visits > 0 ? $rows / $visits : 0.0;
$want_pv = (float) ($profile['mean_pageviews_per_visit'] ?? 1.0);
// Visits are generated whole but truncated at the row target, and the tail is
// extremely heavy — one real visit carried 3,338 pageviews, i.e. 1 visit in
// 11,481. Below a few hundred thousand rows, whether that bucket gets sampled
// at all swings the mean by more than any seeder defect would, so the tolerance
// tracks how much data there is rather than pretending small runs are precise.
$pv_slack = $rows >= 500000 ? 0.15 : 0.35;
$pv_bad   = abs($pv - $want_pv) > $want_pv * $pv_slack;
printf("%-30s %10.3f %10.3f %8.3f%s\n", 'pageviews per visit', $pv, $want_pv, abs($pv - $want_pv),
    $pv_bad ? '  FAIL' : '');
if ($pv_bad) {
    $failures[] = sprintf('pageviews per visit: seeded %.3f, profile %.3f (>%d%% off at %s rows)',
        $pv, $want_pv, (int) ($pv_slack * 100), number_format($rows));
}

foreach (['1' => 'bot share (browser_type=1)', '2' => 'preview share (browser_type=2)'] as $type => $label) {
    if (!isset($profile['browser_type_mix'][$type])) {
        continue;
    }
    $got = (int) $agg['bt' . $type] / $rows;
    // browser_type is assigned per VISIT — a session is a bot or it isn't —
    // while the profile measures it per ROW. With a heavy-tailed visit-length
    // distribution, whether a few long sessions land in a rare bucket moves the
    // row share more than any seeder defect would. Widened deliberately rather
    // than making sessions incoherent by re-rolling the type per pageview.
    $check_rate($label, $got, (float) $profile['browser_type_mix'][$type], 0.03);
}

// Every column the seeder writes is checked, and a column the profile does not
// describe is a failure rather than a silent skip: an `isset() continue` here is
// exactly what let content_type seed at 67.8% NULL against a measured 0%.
foreach (['referer', 'fingerprint', 'content_type', 'category', 'author'] as $column) {
    $key = $column . '_null';
    if (!array_key_exists($key, $agg)) {
        $failures[] = "{$column}: seeded but not measured by this check";
        continue;
    }
    $want = isset($profile['null_rates'][$column]) ? (float) $profile['null_rates'][$column] : 0.0;
    $check_rate("{$column} NULL rate", (int) $agg[$key] / $rows, $want);
}

echo str_repeat('-', 62) . "\n";
printf("%-30s %10s %10s\n", 'cardinality (floor check)', 'seeded', 'profile');
foreach (['resource', 'user_agent', 'country', 'browser', 'platform'] as $column) {
    if (!isset($profile['distinct'][$column])) {
        continue;
    }
    $got  = (int) ($agg['d_' . $column] ?? 0);
    $want = (int) $profile['distinct'][$column];
    // Below 200k rows the tail simply has not been sampled; only flag a
    // shortfall large enough to distort selectivity.
    $floor = $rows >= 200000 ? (int) ($want * 0.8) : (int) ($want * 0.4);
    printf("%-30s %10d %10d%s\n", "  {$column}", $got, $want, $got < $floor ? '  FAIL' : '');
    if ($got < $floor) {
        $failures[] = sprintf('%s cardinality %d is below the %d floor for %s rows', $column, $got, $floor, number_format($rows));
    }
}

echo "\n";
if ($failures !== []) {
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    printf("VERDICT: FAIL — %d metric(s) do not match the measured profile\n", count($failures));
    return;
}

echo "VERDICT: PASS — seeded data matches the measured profile\n";
