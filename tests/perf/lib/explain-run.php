<?php
/**
 * Seeds, renders every registered SlimStat report with query capture on, then
 * EXPLAINs everything captured and reports a verdict.
 *
 * Run via tests/perf/explain-gate.sh, or directly against any WP install:
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

$threshold = isset($args[0]) ? (int) $args[0] : 100000;
$seed_rows = (int) ($threshold + intdiv($threshold, 5));

require_once __DIR__ . '/explain-capture.php';

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

$stats_table  = $raw_wpdb->prefix . 'slim_stats';
$events_table = $raw_wpdb->prefix . 'slim_events';

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
foreach ([$stats_table, $events_table] as $table) {
    $row_counts[$table] = (int) $raw_wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
    printf("table %-28s %10s rows\n", $table, number_format($row_counts[$table]));
}
echo "\n";

// ── Render every registered report ─────────────────────────────────────────
if (!class_exists('wp_slimstat_reports')) {
    require_once SLIMSTAT_ANALYTICS_DIR . 'admin/view/wp-slimstat-reports.php';
}

// Reports are capability-gated inside callback_wrapper(); without a user the
// gate would render nothing and pass vacuously.
if (!is_user_logged_in()) {
    $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
    if ($admins) {
        wp_set_current_user((int) $admins[0]);
    }
}

wp_slimstat_reports::init();
$reports = wp_slimstat_reports::$reports;
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

foreach ($captured as $entry) {
    $sql = $entry['sql'];

    // EXPLAIN through the raw handle so the plan queries aren't self-captured.
    $plan = $raw_wpdb->get_results('EXPLAIN ' . $sql, ARRAY_A);
    if (!$plan) {
        continue; // statement shape MySQL won't plan; nothing to assert
    }
    $checked++;

    // EXPLAIN reports the *alias* ("t1", "te"), not the table, so per-row name
    // lookup silently treats every joined scan as unknown-and-therefore-fine.
    // Size the statement by the largest table it actually names instead.
    $max_rows = 0;
    foreach ($row_counts as $table => $count) {
        if (strpos($sql, $table) !== false) {
            $max_rows = max($max_rows, $count);
        }
    }
    if ($max_rows <= $threshold) {
        continue;
    }

    foreach ($plan as $row) {
        if (($row['type'] ?? '') !== 'ALL') {
            continue;
        }
        $table = (string) ($row['table'] ?? '');
        // <derivedN> rows have no table of their own; the underlying scan
        // appears as its own plan row and is caught there.
        if ($table === '' || strpos($table, '<') === 0) {
            continue;
        }

        $offenders[] = [
            'context'  => $entry['context'],
            'alias'    => $table,
            'rows'     => $max_rows,
            'examined' => (int) ($row['rows'] ?? 0),
            'key'      => $row['key'] ?? null,
            'extra'    => $row['Extra'] ?? '',
            'sql'      => $sql,
        ];
    }
}

printf("EXPLAINed %d distinct query shapes\n\n", $checked);

if ($checked === 0) {
    echo "ERROR: nothing could be EXPLAINed — refusing to report success\n";
    echo "VERDICT: ERROR\n";
    return;
}

foreach ($offenders as $o) {
    printf("FULL SCAN  %s\n", $o['context']);
    printf("           alias=%s over %s rows, examined≈%s, key=%s%s\n",
        $o['alias'],
        number_format($o['rows']),
        number_format($o['examined']),
        $o['key'] ?? 'NONE',
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
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

if ($offenders !== []) {
    printf("VERDICT: FAIL — %d full table scan(s) over %s rows\n",
        count($offenders), number_format($threshold));
    return;
}

echo "VERDICT: PASS\n";
