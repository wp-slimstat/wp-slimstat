<?php
/**
 * The EXPLAIN gate's two blind spots, driven shut — I4's carry-over from the Lane I audit.
 *
 * ── What the audit found (Run 36), live in the instrument since it landed ───────────
 *
 *   1. explain-capture recorded only statements naming slim_stats or slim_events.
 *      Every other table — slim_meta, slim_user_agents, both archives, every future
 *      Phase G registration — was invisible to the gate: their queries were never
 *      EXPLAINed at all, and "no full scans found" silently meant "on two tables".
 *
 *   2. explain-run's plan check was `type !== 'ALL' → continue`: a FULL INDEX SCAN
 *      (type=index) — the same row count through a different tree — passed the gate
 *      as if it were indexed access. The I4 prescription said so from the start.
 *
 * This test drives the REAL mechanisms, not descriptions of them: the capture class
 * records through its actual query() path against a stub inner wpdb, and the scan
 * predicate (extracted to explain-capture.php precisely so it can be required here —
 * explain-run exits by design outside its eval context) answers hand-built plan rows.
 */

declare(strict_types=1);

error_reporting(E_ALL);

// The capture class type-hints wpdb; the real one is not loadable here and does not
// need to be — record() decides before delegation, and delegation hits this stub.
class wpdb
{
    public function query($q)
    {
        return 0;
    }

    public function __call($name, $args)
    {
        return null;
    }
}

if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

require_once __DIR__ . '/perf/lib/explain-capture.php';

$failures = [];

// ── 1. the capture filter: any slim_ table, only slim_ tables ───────────────────────
$inner   = new wpdb();
$capture = new SlimStat_Explain_Capture_WPDB($inner);
SlimStat_Explain_Capture_WPDB::reset();

$cases = [
    // [sql, must_be_captured, why]
    ['SELECT COUNT(*) FROM wp_slim_stats WHERE 1=1', true, 'the table the old filter allowed'],
    ['SELECT ua FROM wp_slim_user_agents WHERE id = 5', true, 'invisible to the old two-name filter'],
    ['SELECT * FROM wp_slim_stats_archive WHERE dt < 100', true, 'the archive — captured even under the old filter, but only by SUBSTRING ACCIDENT (slim_stats matches inside slim_stats_archive); now captured by contract'],
    ['SELECT meta_value FROM wp_slim_meta WHERE meta_key = "install_uuid"', true, 'F6\'s own table, never EXPLAINed before'],
    ['SELECT option_value FROM wp_options WHERE option_name = "_transient_slimstat_query_abc"', false, 'core reads are not ours to gate, and slimstat option keys carry no slim_ underscore'],
    ['SELECT post_title FROM wp_posts WHERE ID = 1', false, 'core'],
    ['UPDATE wp_slim_stats SET dt = 1', false, 'only SELECTs have a plan worth checking'],
];

foreach ($cases as [$sql, $expected, $why]) {
    SlimStat_Explain_Capture_WPDB::reset();
    $capture->query($sql);
    $got = [] !== SlimStat_Explain_Capture_WPDB::captured();
    if ($got !== $expected) {
        $failures[] = sprintf(
            'capture filter: %s was %s — %s',
            substr($sql, 0, 60),
            $got ? 'CAPTURED (expected dropped)' : 'DROPPED (expected captured)',
            $why
        );
    }
}

// ── 2. the scan predicate: ALL and index are both full scans, keyed access is not ───
if (!function_exists('slimstat_plan_row_is_full_scan')) {
    $failures[] = 'slimstat_plan_row_is_full_scan() does not exist — the plan check is still '
        . 'the inline `!== ALL` early-continue that passed full index scans';
} else {
    $plan_rows = [
        [['type' => 'ALL'], true, 'the classic full table scan'],
        [['type' => 'index'], true, 'a FULL INDEX SCAN reads every row through the index — '
            . 'the blind spot the audit named'],
        [['type' => 'range'], false, 'bounded read'],
        [['type' => 'ref'], false, 'keyed access'],
        [['type' => 'const'], false, 'single row'],
        [[], false, 'no type at all must not read as a scan'],
    ];
    foreach ($plan_rows as [$row, $expected, $why]) {
        if (slimstat_plan_row_is_full_scan($row) !== $expected) {
            $failures[] = sprintf(
                'scan predicate: type=%s judged %s — %s',
                $row['type'] ?? '(none)',
                $expected ? 'NOT a scan (it is)' : 'a scan (it is not)',
                $why
            );
        }
    }
}

if ([] !== $failures) {
    fwrite(STDERR, 'FAIL: explain gate strength (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "OK: capture sees every slim_ table and only them; ALL and index both read as full scans\n";
