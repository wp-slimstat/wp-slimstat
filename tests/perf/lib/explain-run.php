<?php
/**
 * Seeds, renders every registered SlimStat report with query capture on, then
 * EXPLAINs everything captured and reports a verdict.
 *
 * Run via tests/perf/explain-gate.sh, or directly against a disposable WP install:
 *
 *     wp eval-file tests/perf/lib/explain-run.php 100000
 *
 * Prints a human-readable summary, a JSON payload, and a final
 * `VERDICT: PASS|FAIL|ERROR` line. The shell wrapper keys off the verdict line
 * because `wp eval-file` exits 0 regardless of what the payload found.
 *
 * @package wp-slimstat-tests
 */

// No declare(strict_types=1) here on purpose: `wp eval-file` eval()s this file,
// and a declare() is only legal as the very first statement of a script — it
// fatals under eval(). explain-capture.php, which is require_once'd rather than
// eval'd, does declare strict types.

if (!defined('ABSPATH')) {
    fwrite(STDERR, "must run inside WordPress (wp eval-file)\n");
    exit(2);
}

if (!defined('WP_CLI') || !WP_CLI || !preg_match('/^(tests?[-_]|wordpress$)/', DB_NAME)) {
    echo "ERROR: a disposable database is required for synthetic seeding\nVERDICT: ERROR\n";
    return;
}

$threshold = isset($args[0]) ? (int) $args[0] : 100000;
$seed_rows = (int) ($threshold + intdiv($threshold, 5));

require_once __DIR__ . '/explain-capture.php';
require_once __DIR__ . '/scan-budget.php';
$budgets = json_decode((string) file_get_contents(__DIR__ . '/aggregate-budgets.json'), true);
if (!is_array($budgets) || $budgets === []) {
    echo "ERROR: aggregate budgets missing or invalid\nVERDICT: ERROR\n";
    return;
}

if (!class_exists('wp_slimstat')) {
    echo "ERROR: wp_slimstat is not loaded — is the plugin active?\n";
    echo "VERDICT: ERROR\n";
    return;
}

// Install the capture wrapper. wp_slimstat::$wpdb is a public static assigned
// once during init() (wp-slimstat.php:363), and Query reads it at construction
// (src/Utils/Query.php:60) — so wrapping it here catches every query built from
// now on. This deliberately replaces the earlier mu-plugin approach: no file
// has to be shuttled into the container, and the harness runs anywhere.
$raw_wpdb = wp_slimstat::$wpdb ?? $GLOBALS['wpdb'];
if (!$raw_wpdb instanceof wpdb) {
    echo "ERROR: wp_slimstat::\$wpdb is not a wpdb instance\n";
    echo "VERDICT: ERROR\n";
    return;
}
wp_slimstat::$wpdb = new SlimStat_Explain_Capture_WPDB($raw_wpdb);

// ── Seed ───────────────────────────────────────────────────────────────────
// A plan is only meaningful on a table big enough that the optimiser stops
// preferring a scan for trivial cardinality.
// Uses the shared seeder so the gate measures the same distributions the
// benchmark matrix does — a plan is only as honest as the data under it.
require_once dirname(__DIR__, 2) . '/bench/lib/seeder.php';

try {
    (new SlimStat_Bench_Seeder($raw_wpdb))->seedTo(
        $seed_rows,
        365,
        static function (string $line): void {
            echo $line . "\n";
        }
    );
} catch (\Throwable $e) {
    echo 'ERROR: seeding failed — ' . $e->getMessage() . "\n";
    echo "VERDICT: ERROR\n";
    return;
}

$row_counts = [];
$tables = $raw_wpdb->get_col($raw_wpdb->prepare('SHOW TABLES LIKE %s', $raw_wpdb->esc_like($raw_wpdb->prefix . 'slim_') . '%'));
foreach ($tables as $table) {
    $row_counts[$table] = (int) $raw_wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
    printf("table %-28s %10s rows\n", $table, number_format($row_counts[$table]));
}
echo "\n";

// ── Render every registered report ─────────────────────────────────────────
// Capture cold SQL on reruns too; a cached report otherwise disappears from the gate.
WP_CLI::runcommand('transient delete --all');
wp_cache_flush();
require_once dirname(__DIR__, 2) . '/bench/lib/reports-bootstrap.php';

$reports = slimstat_bench_bootstrap_reports();
printf("rendering %d registered reports\n", count($reports));

// A spread of ranges: a plan that is fine over 1 day can still scan over 90.
$ranges = ['interval equals -1', 'interval equals -30', 'interval equals -90'];

$rendered      = 0;
$render_errors = [];

// Range is the outer loop: wp_slimstat_db::init() rebuilds ~70 translated
// column labels and runs a COUNT(id) over the whole range on every call, so
// calling it once per report/range pair would add ~200 needless COUNTs over a
// seeded table. Filter state is static and no report callback mutates it.
foreach ($ranges as $range) {
    wp_slimstat_db::init($range);

    foreach ($reports as $report_id => $report) {
        if (empty($report['callback'])) {
            continue;
        }
        $GLOBALS['slimstat_explain_context'] = $report_id . ' | ' . $range;
        try {
            ob_start();
            // Go through callback_wrapper(), not the raw callback: it applies
            // _check_args()'s defaults (use_date_filters, results_per_page,
            // filter_op, …) which decide the WHERE and LIMIT clauses. Calling
            // the callback directly EXPLAINs a query shape production never
            // issues — a green gate proving nothing.
            wp_slimstat_reports::callback_wrapper(['id' => $report_id]);
            ob_end_clean();
            $rendered++;
        } catch (\Throwable $e) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            // Keep going: one broken report must not hide every other plan.
            $render_errors[] = $report_id . ' (' . $range . '): ' . $e->getMessage();
        }
    }
}

printf("rendered %d report/range combinations", $rendered);
if ($render_errors !== []) {
    printf(" — %d raised an exception", count($render_errors));
}
echo "\n\n";

// ── EXPLAIN every captured query ───────────────────────────────────────────
$captured = SlimStat_Explain_Capture_WPDB::captured();
if ($captured === []) {
    echo "ERROR: no queries were captured — the harness is not measuring anything\n";
    echo "VERDICT: ERROR\n";
    return;
}

$offenders = [];
$checked   = 0;
$plan_errors = [];
$budgeted_scans = [];
$report_costs = [];

foreach ($captured as $entry) {
    $sql = $entry['sql'];

    // EXPLAIN through the raw handle so the plan queries aren't self-captured.
    $plan = $raw_wpdb->get_results('EXPLAIN ' . $sql, ARRAY_A);
    if (!$plan) {
        $plan_errors[] = $entry['context'] . ': ' . $raw_wpdb->last_error;
        continue;
    }
    $checked++;
    $samples = null;

    foreach ($plan as $row) {
        if (!slimstat_plan_row_is_full_scan($row)) {
            continue;
        }
        $table = (string) ($row['table'] ?? '');
        // <derivedN> rows have no table of their own; the underlying scan
        // appears as its own plan row and is caught there.
        if ($table === '' || strpos($table, '<') === 0) {
            continue;
        }

        $physical_table = slimstat_plan_table($sql, $table, array_keys($row_counts));
        if ($physical_table === null) {
            $plan_errors[] = $entry['context'] . ': cannot resolve scan alias ' . $table;
            continue;
        }
        if ($row_counts[$physical_table] <= $threshold) {
            continue;
        }

        $scan = [
            'table'    => $physical_table,
            'context'  => $entry['context'],
            'alias'    => $table,
            'type'     => (string) ($row['type'] ?? ''),
            'rows'     => $row_counts[$physical_table],
            'examined' => (int) ($row['rows'] ?? 0),
            'key'      => $row['key'] ?? null,
            'key_len'  => $row['key_len'] ?? null,
            'extra'    => $row['Extra'] ?? '',
            'sql'      => $sql,
        ];
        $shape = slimstat_scan_shape($raw_wpdb->remove_placeholder_escape($sql), $raw_wpdb->prefix, (string) wp_parse_url(home_url(), PHP_URL_HOST));
        $budget = slimstat_scan_budget($budgets, $shape, $entry['context']);
        if ($budget !== null) {
            try {
                if ($samples === null) {
                    $samples = slimstat_measure_scan($raw_wpdb, $sql);
                    $report_costs[$entry['context']] = ($report_costs[$entry['context']] ?? 0) + max(array_column($samples, 'ms'));
                }
                $scan['samples'] = $samples;
                $scan['budget'] = $budget;
                if (slimstat_scan_within_budget($samples, $row_counts[$physical_table], $budget)) {
                    $budgeted_scans[] = $scan;
                    continue;
                }
            } catch (\Throwable $e) {
                $plan_errors[] = $entry['context'] . ': ' . $e->getMessage();
            }
        }
        $offenders[] = $scan;
    }
}

printf("EXPLAINed %d distinct query shapes\n\n", $checked);

if ($checked === 0) {
    echo "ERROR: nothing could be EXPLAINed — refusing to report success\n";
    echo "VERDICT: ERROR\n";
    return;
}

foreach ($offenders as $o) {
    printf("FULL %s SCAN  %s\n", 'index' === $o['type'] ? 'INDEX' : 'TABLE', $o['context']);
    printf("           alias=%s over %s rows, examined≈%s, key=%s%s%s\n",
        $o['alias'],
        number_format($o['rows']),
        number_format($o['examined']),
        $o['key'] ?? 'NONE',
        null !== $o['key_len'] ? ', key_len=' . $o['key_len'] : '',
        $o['extra'] !== '' ? ', extra=' . $o['extra'] : ''
    );
    printf("           %s\n\n", substr((string) preg_replace('/\s+/', ' ', $o['sql']), 0, 300));
}

echo wp_json_encode([
    'threshold'     => $threshold,
    'row_counts'    => $row_counts,
    'shapes'        => $checked,
    'offenders'     => $offenders,
    'render_errors' => $render_errors,
    'plan_errors'   => $plan_errors,
    'budgeted_scans' => $budgeted_scans,
    'report_scan_ms' => $report_costs,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

if ($render_errors !== [] || $plan_errors !== []) {
    echo "VERDICT: ERROR — report rendering or plan inspection failed\n";
    return;
}

if ($offenders !== [] || ($report_costs !== [] && max($report_costs) > 2000)) {
    printf("VERDICT: FAIL — %d unbudgeted/over-budget full scan(s) over %s rows; maximum report scan cost %.2fms (limit 2000ms)\n",
        count($offenders), number_format($threshold), $report_costs === [] ? 0 : max($report_costs));
    return;
}

printf("Measured %d reviewed aggregate scans; each query <=500ms and <=2 table-equivalents of reads, each report/range <=2000ms\n", count($budgeted_scans));
echo "VERDICT: PASS\n";
