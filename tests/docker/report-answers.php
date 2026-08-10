<?php
// Dump the ANSWERS a set of reports gives, as stable JSON, for before/after comparison.
//
// The opening PHP tag IS required here and `declare(strict_types=1)` is NOT. WP-CLI's eval-file
// evaluates a close-tag followed by the file's contents, so a file lacking an opening tag is
// echoed as text — which is how this first ran, with the comparison reading this file's own
// source as its data — and a declare() inside that wrapper is no longer the script's first
// statement and fatals.
//
// This comment does not spell that close-tag literally, because doing so ends the PHP block
// even inside a line comment. That was the second failure of this file, and it produced the
// identical symptom as the first: source text where JSON was expected.
//
// WHY. Counting statements tells you what a change COST. It says nothing about whether the
// numbers moved. A refactor that halves the query count and quietly changes a total is worse
// than the code it replaced, and no gate in this programme could have seen it: the unit tests
// exercise the new path only, and the topology probe checks a single aggregate.
//
// So this asks the real reports, against the real seeded corpus, and prints the answers. Two
// arms, same database, diffed. Any difference is either a defect or an Expected-Diff entry —
// never a shrug.
//
// DETERMINISM is the whole contract here. Every report below is ordered, and the values are
// emitted in a fixed key order, so a byte diff between arms means the ANSWER changed and not
// that a hash map iterated differently. Reports that depend on the current clock are pinned to
// an explicit range for the same reason.

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "runs under WP-CLI\n");
    exit(1);
}

$db_file = WP_PLUGIN_DIR . '/wp-slimstat/admin/view/wp-slimstat-db.php';
if (!class_exists('wp_slimstat_db') && is_readable($db_file)) {
    require_once $db_file;
}

if (!class_exists('wp_slimstat_db')) {
    WP_CLI::error('wp_slimstat_db is not loadable');
}

if (method_exists('wp_slimstat_db', 'init')) {
    wp_slimstat_db::init();
}

/** Normalise a report row set into a plain, ordered array of arrays. */
$rows = static function ($result) {
    $out = [];
    foreach ((array) $result as $row) {
        $row = (array) $row;
        ksort($row);
        $out[] = $row;
    }

    return $out;
};

$answers = [];

// ── provenance, so the artifact can prove two revisions actually ran ────────
// A blind auditor pointed out that nothing IN the files recorded which code produced them: a
// harness that silently failed to switch arms, or ran one revision twice, would emit two
// identical files indistinguishable from a genuine equivalence result. The strongest possible
// "identical" is then also the most likely false positive. This makes that detectable.
$answers['_arm_version'] = defined('SLIMSTAT_ANALYTICS_VERSION') ? SLIMSTAT_ANALYTICS_VERSION : 'unknown';

// Hashed over the SHIPPED PHP surface — src/, admin/ and the main file — not one file.
//
// A single-file hash said "these arms are the same code" for two genuinely different commits
// whose difference lay elsewhere, and aborted a valid run. It would also have missed the
// opposite: a change confined to src/ leaving the fingerprinted file untouched, so two different
// arms would have looked identical and the abort would never have fired at all.
//
// Vendor is excluded deliberately: it is normalised between arms on purpose (the autoloader is
// rebuilt per arm), so including it would report a difference the harness itself created.
$slimstat_root = WP_PLUGIN_DIR . '/wp-slimstat';
$slimstat_hash = [];

foreach (['src', 'admin'] as $slimstat_dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $slimstat_root . '/' . $slimstat_dir,
        RecursiveDirectoryIterator::SKIP_DOTS
    ));
    foreach ($it as $slimstat_file) {
        $path = $slimstat_file->getPathname();
        if (substr($path, -4) === '.php' && false === strpos($path, '/Dependencies/')) {
            $slimstat_hash[] = substr($path, strlen($slimstat_root)) . ':' . md5_file($path);
        }
    }
}

$slimstat_hash[] = 'wp-slimstat.php:' . md5_file($slimstat_root . '/wp-slimstat.php');
sort($slimstat_hash);

$answers['_arm_fingerprint'] = md5(implode('|', $slimstat_hash));
$answers['_arm_files']       = count($slimstat_hash);

// ── scalar counts ───────────────────────────────────────────────────────────
// Date filters off throughout: the corpus is seeded relative to "now", and a report whose
// window moves between two arms run minutes apart would diff for a reason that is not the code.
$answers['count_records_id']       = (int) wp_slimstat_db::count_records('id', '', false);
$answers['count_records_ip']       = (int) wp_slimstat_db::count_records('ip', '', false);
$answers['count_records_visit_id'] = (int) wp_slimstat_db::count_records('visit_id', '', false);
$answers['count_records_resource'] = (int) wp_slimstat_db::count_records('resource', '', false);
$answers['count_human_hits']       = (int) wp_slimstat_db::count_records('id', 'browser_type <> 1', false);

// ── top reports — the GROUP BY paths, which is where cardinality bites ──────
foreach (['resource', 'browser', 'country', 'platform', 'referer'] as $column) {
    $answers['top_' . $column] = $rows(wp_slimstat_db::get_top([
        'columns'          => $column,
        'use_date_filters' => false,
    ]));
}

// ── unique visitors per dimension — a metric that is NOT COUNT(*) ──────────
// Added because a blind audit found every measure in the set was `counthits`, so the
// pageviews-versus-uniques distinction — the classic confusion in this plugin, and the one F9's
// golden fixture is shaped around — could have been rewritten entirely without a single answer
// moving. get_top_aggr walks a different code path from get_top.
// THESE TWO RETURNED `[]` FROM THE DAY THEY WERE ADDED, in every arm of every run, and a blind
// adjudicator found it by asking why two reports were empty while their siblings had 59 and 105
// rows. The call was wrong, not the function: `$_outer_select_column` is a column to GROUP BY,
// and it was handed `COUNT(DISTINCT visit_id) AS counthits`. That renders
//
//     SELECT COUNT(DISTINCT visit_id) AS counthits, ts1.aggrid AS browser, COUNT(*) AS counthits
//       … GROUP BY COUNT(DISTINCT visit_id) AS counthits
//
// — a duplicate alias AND a GROUP BY over an aggregate. Invalid, and `get_results()` answers
// `[]` for a failed query exactly as it does for one that found nothing, with `show_errors` off
// in this container. So the reports added specifically to stop `get_top_aggr()` being rewritten
// without a single answer moving were themselves incapable of moving. The comment above them
// described a coverage the code did not have.
//
// The real shape, from `slim_p4_24` "Top Exit Pages": collapse to ONE ROW PER VISIT with
// MAX(id) — the visit's last pageview — then group and count. `COUNT(*)` over that is visits,
// not pageviews, which is the distinction this block exists for.
foreach (['browser', 'country'] as $column) {
    // ARRAY FORM, so `use_date_filters` can be turned off — every other answer in this file is
    // clock-independent by construction and these two must be too. The scalar form cannot
    // express it, and until this run get_top_aggr() PARSED the flag and never consulted it, so
    // an unfiltered aggregate was silently a 28-day one. Left as it was, a run crossing local
    // midnight would report DIFFERENCES on these two with no code difference at all — in a
    // harness that escalates any difference to "a defect or an EXPECTED-DIFFS entry".
    $answers['uniques_' . $column] = $rows(wp_slimstat_db::get_top_aggr([
        'columns'             => 'visit_id',
        'where'               => 'visit_id > 0',
        'outer_select_column' => $column,
        'aggr_function'       => 'MAX',
        'use_date_filters'    => false,
    ]));
}

// ── a FILTERED query — the WHERE-builder, otherwise untouched ──────────────
// Also from that audit: every report above is an unfiltered GROUP BY, so the filter/segment
// path — the largest and riskiest surface in a reports layer — was entirely unmeasured.
$answers['top_resource_human'] = $rows(wp_slimstat_db::get_top([
    'columns'          => 'resource',
    'where'            => 'browser_type <> 1 AND resource IS NOT NULL',
    'use_date_filters' => false,
]));

// ── a HAVING path — the bounce numerator ────────────────────────────────────
$answers['bouncing_visits'] = (int) wp_slimstat_db::count_records_having(
    'visit_id',
    'visit_id > 0 AND browser_type <> 1',
    'COUNT(id) = 1'
);

// ── an explicit date window, so range filtering is compared too ─────────────
// Pinned to absolute bounds rather than "last 30 days": two arms run minutes apart would
// otherwise select different rows and diff for a reason that is not the change.
$end   = (int) getenv('SLIMSTAT_ANSWERS_END');
$start = (int) getenv('SLIMSTAT_ANSWERS_START');
if ($end > 0 && $start > 0) {
    $answers['window_start'] = $start;
    $answers['window_end']   = $end;
    $answers['rows_in_window'] = (int) wp_slimstat_db::count_records(
        'id',
        'dt BETWEEN ' . $start . ' AND ' . $end,
        false
    );
    $answers['top_resource_in_window'] = $rows(wp_slimstat_db::get_top([
        'columns'          => 'resource',
        'where'            => 'dt BETWEEN ' . $start . ' AND ' . $end,
        'use_date_filters' => false,
    ]));
}

ksort($answers);

// ── every report must have said SOMETHING ──────────────────────────────────
//
// "18 reports, every answer byte-for-byte equal" is a strong claim and an empty report satisfies
// it perfectly. Two of these returned `[]` from the day they were added — a malformed call to
// get_top_aggr() that `get_results()` reports identically to "found nothing", with show_errors
// off in this container — so for every run since, two of the eighteen were identical because
// neither of them ran.
//
// Found by a blind adjudicator asking why two reports were empty while their siblings had 59 and
// 105 rows on the same 150,000 records. Not by this harness, which had no opinion about it.
//
// Scalars are exempt from the emptiness rule but not from the check: a count of 0 is a real
// answer on an empty corpus, and the row-count controls in seed-bench.sh already refuse one.
$hollow = [];
foreach ($answers as $key => $value) {
    if ('_' === $key[0] || !is_array($value)) {
        continue;
    }
    if ([] === $value) {
        $hollow[] = $key;
    }
}

if ($hollow !== []) {
    // Marked distinctly, because the caller captures this arm's whole output into a .raw file and
    // surfaces the failure as "the arm produced no answers" — the same text an activation or
    // autoloader failure produces. Without the marker the operator has to open the raw file to
    // learn whether the arm failed to BOOT or merely failed to MEASURE, which are different
    // problems with different fixes.
    fwrite(STDERR, sprintf(
        "SLIMSTAT-HOLLOW-REPORT FAIL: %d report(s) returned no rows — %s.\nAn empty report compares equal to an empty "
            . "report, so it cannot detect a change in the code that produces it. Either the "
            . "corpus cannot exercise them or the call is malformed; both make this arm's "
            . "\"identical\" weaker than it reads.\n",
        count($hollow),
        implode(', ', $hollow)
    ));
    exit(1);
}

echo "SLIMSTAT-ANSWERS " . json_encode($answers) . "\n";

// ── timing, emitted separately so the answers stay byte-comparable ──────────
//
// Only meaningful because I8 landed: on the old corpus a 30-day report and an all-time report
// scanned the same rows, so any latency difference between them measured nothing.
//
// REPEATED, and reported as a distribution. A blind adjudicator refused a previous single-shot
// ms figure on three grounds, all correct: n=1 has no spread, the wall clock was dominated by
// WordPress booting, and the direction happening to agree with the statement count is exactly
// what makes such a number tempting. So the clock starts AFTER boot, wraps only the report call,
// and each report runs REPS times with the minimum reported alongside the median.
//
// The minimum is the honest headline for a warm comparison: it is the run least perturbed by
// whatever else the machine was doing. The median is printed beside it so a wide spread is
// visible rather than hidden.
$reps = max(1, (int) (getenv('SLIMSTAT_TIMING_REPS') ?: 5));

$timed = [
    'count_records_id'   => static function () { return wp_slimstat_db::count_records('id', '', false); },
    'top_resource'       => static function () { return wp_slimstat_db::get_top(['columns' => 'resource', 'use_date_filters' => false]); },
    'top_referer'        => static function () { return wp_slimstat_db::get_top(['columns' => 'referer', 'use_date_filters' => false]); },
    'top_country'        => static function () { return wp_slimstat_db::get_top(['columns' => 'country', 'use_date_filters' => false]); },
    'bouncing_visits'    => static function () {
        return wp_slimstat_db::count_records_having('visit_id', 'visit_id > 0 AND browser_type <> 1', 'COUNT(id) = 1');
    },
];

if ($end > 0 && $start > 0) {
    $timed['top_resource_in_window'] = static function () use ($start, $end) {
        return wp_slimstat_db::get_top([
            'columns'          => 'resource',
            'where'            => 'dt BETWEEN ' . $start . ' AND ' . $end,
            'use_date_filters' => false,
        ]);
    };
}

$timings = ['_reps' => $reps];

/**
 * Session status counters, which are DETERMINISTIC where milliseconds are not.
 *
 * A null control — the same ref as both arms — showed top_resource moving +12.69 ms (+11.3%)
 * with no code difference at all, so the ms figures on this harness cannot carry a claim. These
 * can: Handler_read_rnd_next is rows read from a full scan, Created_tmp_disk_tables is the
 * MEMORY-to-disk spill A4 is about, Sort_rows is what a filesort moved. They do not vary with
 * how busy the machine is.
 *
 * If these are identical across two arms, any latency difference between those arms is
 * environmental BY DEFINITION — which is the check that makes a timing number interpretable
 * rather than merely printed.
 */
$counters = static function () {
    $wanted = [
        'Handler_read_rnd_next', 'Handler_read_next', 'Handler_read_key', 'Handler_read_first',
        'Created_tmp_tables', 'Created_tmp_disk_tables', 'Sort_rows', 'Sort_scan', 'Select_scan',
    ];

    $out = [];
    foreach ($GLOBALS['wpdb']->get_results("SHOW SESSION STATUS WHERE Variable_name IN ('" . implode("','", $wanted) . "')") as $row) {
        $out[$row->Variable_name] = (int) $row->Value;
    }

    return $out;
};

foreach ($timed as $name => $fn) {
    $samples = [];

    // Counters are read once, around a SINGLE clean execution, before the timing loop. Summing
    // them over repetitions would just multiply by $reps and hide the per-execution figure that
    // is the actual comparable.
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
    $GLOBALS['wpdb']->query(
        "DELETE FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE '\\_transient\\_%slimstat%'"
            . " OR option_name LIKE '\\_transient\\_timeout\\_%slimstat%'"
    );

    $before_counters = $counters();
    $fn();
    $after_counters = $counters();

    $delta = [];
    foreach ($after_counters as $k => $v) {
        $delta[$k] = $v - ($before_counters[$k] ?? 0);
    }

    for ($i = 0; $i < $reps; $i++) {
        // The query cache must go between samples or every repetition after the first measures
        // the cache instead of the query.
        //
        // wp_cache_flush() ALONE IS NOT ENOUGH, and the first version of this used only that.
        // Query caches through get_transient(), and with no external object cache a transient
        // lives in wp_options — so the flush cleared the in-memory copy and the next read came
        // straight back from the database. The result was every report timing at 0.22–0.38 ms
        // over 150,000 rows, which is a single-row option read wearing a GROUP BY's name. The
        // arm-to-arm delta looked consistent and meant nothing.
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        $GLOBALS['wpdb']->query(
            "DELETE FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE '\\_transient\\_%slimstat%'"
                . " OR option_name LIKE '\\_transient\\_timeout\\_%slimstat%'"
        );

        $t0 = microtime(true);
        $fn();
        $samples[] = (microtime(true) - $t0) * 1000;
    }

    // RAW samples kept, not just min/median/max. A blind adjudicator noted that discarding
    // 4 of 7 leaves no variance to reason about, so a delta can be neither supported nor
    // refuted from the artifact — only eyeballed against a range.
    sort($samples);
    $timings[$name] = [
        'samples'  => array_map(static function ($s) { return round($s, 2); }, $samples),
        'min'      => round($samples[0], 2),
        'median'   => round($samples[intdiv(count($samples), 2)], 2),
        'max'      => round($samples[count($samples) - 1], 2),
        'counters' => $delta,
    ];
}

echo "SLIMSTAT-TIMING " . json_encode($timings) . "\n";
