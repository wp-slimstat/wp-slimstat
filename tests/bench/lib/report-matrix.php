<?php
// Report latency matrix — the instrument behind Scorecard M3/M4/M5.
//
//   wp eval-file tests/bench/lib/report-matrix.php [ranges] [runs] [out.json]
//
//   ranges  comma-separated day counts, default "7,30,90"  (0 = today)
//   runs    warm iterations per cell; the LAST is recorded, default 3
//   out     write JSON here as well as printing the table
//
// Per cell it records wall time, query count, and the two server counters that
// explain a slow report better than wall time alone: Created_tmp_disk_tables
// (a GROUP BY that spilled out of tmp_table_size) and Innodb_rows_read (how
// much of the table the plan actually touched).
//
// Results carry the environment fingerprint. Two runs are only comparable when
// their fingerprints match — otherwise a busier laptop reads as a regression.
//
// No declare(strict_types=1): `wp eval-file` (WP-CLI 2.12) eval()s this file.

if (!defined('ABSPATH')) {
    fwrite(STDERR, "must run inside WordPress (wp eval-file)\n");
    exit(2);
}

define('SLIMSTAT_BENCH_FINGERPRINT_LIB', true);
require_once __DIR__ . '/fingerprint.php';

if (!class_exists('wp_slimstat')) {
    echo "ERROR: wp_slimstat is not loaded — is the plugin active?\n";
    echo "VERDICT: ERROR\n";
    return;
}

$range_days = array_map('intval', explode(',', (string) ($args[0] ?? '7,30,90')));
$runs       = max(1, (int) ($args[1] ?? 3));
$out_path   = $args[2] ?? '';

$db = wp_slimstat::$wpdb instanceof wpdb ? wp_slimstat::$wpdb : $GLOBALS['wpdb'];

$fingerprint = slimstat_bench_fingerprint($db);
$fp_hash     = slimstat_bench_fingerprint_hash($fingerprint);

printf("fingerprint %s · %s rows · buffer pool %s MB\n",
    $fp_hash,
    number_format($fingerprint['data']['stats_rows']),
    number_format($fingerprint['server']['buffer_pool_bytes'] / 1048576, 1));

if ($fingerprint['server']['buffer_pool_bytes'] < $fingerprint['data']['bytes_total']) {
    printf("WARNING: buffer pool < table size — these are cold-disk numbers.\n");
}
echo "\n";

if (!class_exists('wp_slimstat_reports')) {
    require_once SLIMSTAT_ANALYTICS_DIR . 'admin/view/wp-slimstat-reports.php';
}

// Reports are capability-gated inside callback_wrapper(); without a user every
// cell would render nothing and report a flattering zero.
if (!is_user_logged_in()) {
    $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
    if ($admins) {
        wp_set_current_user((int) $admins[0]);
    }
}

wp_slimstat_reports::init();
$reports = wp_slimstat_reports::$reports;

/** One global-status counter read. */
$status = static function (wpdb $db): array {
    $out  = ['tmp_disk' => 0, 'rows_read' => 0];
    $rows = $db->get_results(
        "SHOW GLOBAL STATUS WHERE Variable_name IN ('Created_tmp_disk_tables','Innodb_rows_read')",
        ARRAY_A
    ) ?: [];
    foreach ($rows as $row) {
        if ($row['Variable_name'] === 'Created_tmp_disk_tables') {
            $out['tmp_disk'] = (int) $row['Value'];
        } elseif ($row['Variable_name'] === 'Innodb_rows_read') {
            $out['rows_read'] = (int) $row['Value'];
        }
    }
    return $out;
};

$cells   = [];
$total   = count($reports) * count($range_days);
$done    = 0;

foreach ($range_days as $days) {
    $filter = $days === 0 ? 'interval equals -1' : 'interval equals -' . $days;
    $label  = $days === 0 ? 'today' : $days . 'd';

    foreach ($reports as $report_id => $report) {
        if (empty($report['callback'])) {
            continue;
        }
        $done++;

        $ms = null;
        $queries = 0;
        $delta   = ['tmp_disk' => 0, 'rows_read' => 0];
        $error   = null;

        for ($run = 1; $run <= $runs; $run++) {
            // init() per run: it resets filter state and its own $pageviews
            // cache, so a warm run must start from the same place as run 1.
            wp_slimstat_db::init($filter);

            $before_q = (int) $db->num_queries;
            $before_s = $status($db);
            $start    = microtime(true);

            try {
                ob_start();
                wp_slimstat_reports::callback_wrapper(['id' => $report_id]);
                ob_end_clean();
            } catch (\Throwable $e) {
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                $error = $e->getMessage();
                break;
            }

            $elapsed = (microtime(true) - $start) * 1000;
            $after_s = $status($db);

            // Only the last run is recorded — earlier ones prime the buffer
            // pool and any transient the report writes.
            $ms      = $elapsed;
            $queries = (int) $db->num_queries - $before_q;
            $delta   = [
                'tmp_disk'  => $after_s['tmp_disk'] - $before_s['tmp_disk'],
                'rows_read' => $after_s['rows_read'] - $before_s['rows_read'],
            ];

            // Query Monitor's dropin defines SAVEQUERIES unconditionally, so
            // wpdb retains every statement; drop them between runs.
            $db->queries = [];
        }

        $cells[] = [
            'report'    => $report_id,
            'title'     => wp_strip_all_tags((string) ($report['title'] ?? $report_id)),
            'range'     => $label,
            'ms'        => $ms === null ? null : round($ms, 1),
            'queries'   => $queries,
            'tmp_disk'  => $delta['tmp_disk'],
            'rows_read' => $delta['rows_read'],
            'error'     => $error,
        ];

        if ($done % 25 === 0) {
            printf("  … %d/%d cells\n", $done, $total);
        }
    }
}

// ── Summary ────────────────────────────────────────────────────────────────
$ok = array_values(array_filter($cells, static fn(array $c): bool => $c['ms'] !== null));
usort($ok, static fn(array $a, array $b): int => $b['ms'] <=> $a['ms']);

$percentile = static function (array $sorted_desc, float $p): float {
    if ($sorted_desc === []) {
        return 0.0;
    }
    $asc = array_reverse(array_column($sorted_desc, 'ms'));
    $idx = (int) ceil(($p / 100) * count($asc)) - 1;
    return (float) $asc[max(0, min($idx, count($asc) - 1))];
};

printf("\n%-18s %-7s %9s %8s %9s %12s\n", 'report', 'range', 'ms', 'queries', 'tmp_disk', 'rows_read');
echo str_repeat('-', 70) . "\n";
foreach (array_slice($ok, 0, 25) as $c) {
    printf("%-18s %-7s %9.1f %8d %9d %12s\n",
        $c['report'], $c['range'], $c['ms'], $c['queries'], $c['tmp_disk'], number_format($c['rows_read']));
}

echo "\n";
foreach ($range_days as $days) {
    $label = $days === 0 ? 'today' : $days . 'd';
    $sub   = array_values(array_filter($ok, static fn(array $c): bool => $c['range'] === $label));
    if ($sub === []) {
        continue;
    }
    printf("%-7s  p50 %8.1f ms   p95 %9.1f ms   max %9.1f ms   total %9.1f ms   (%d reports)\n",
        $label,
        $percentile($sub, 50),
        $percentile($sub, 95),
        $sub[0]['ms'],
        array_sum(array_column($sub, 'ms')),
        count($sub));
}

$errors = array_filter($cells, static fn(array $c): bool => $c['error'] !== null);
if ($errors !== []) {
    printf("\n%d cell(s) raised an exception:\n", count($errors));
    foreach (array_slice($errors, 0, 8) as $c) {
        printf("  %s (%s): %s\n", $c['report'], $c['range'], substr((string) $c['error'], 0, 110));
    }
}

$payload = [
    'fingerprint_hash' => $fp_hash,
    'fingerprint'      => $fingerprint,
    'runs_per_cell'    => $runs,
    'cells'            => $cells,
];

if ($out_path !== '') {
    file_put_contents($out_path, wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    printf("\nwrote %s\n", $out_path);
}

echo "\nVERDICT: OK\n";
