<?php
// Is "At a Glance" still time-dependent, and if so WHICH value? (I3 / slim_p1_03)
//
// Opening tag required, declare(strict_types=1) not — WP-CLI's eval-file wraps this.
//
// THE EXEMPTION UNDER TEST. `parity-compare.php` excuses `slim_p1_03` from value comparison with:
//
//   "At a Glance carries a rolling live window; a value was measured decrementing 40 -> 39 over
//    66 seconds with byte-identical markup, which is rows leaving the trailing edge"
//
// That is the WEAKEST check in the oracle — the report renders, and not one of its numbers is
// asserted. EXPECTED-DIFFS separately recorded the exemption as resting on a misdiagnosis:
// `date_i18n()` discarding its second argument, so "Today" computed `dt > now`. That defect is
// fixed (Run 14). The question this probe answers is whether the exemption is still earned, and
// at what GRAIN.
//
// It matters because the two answers lead somewhere different:
//
//   nothing moves          -> lift the exemption; the whole report becomes value-compared
//   one line moves         -> narrow it to that VALUE; the other eight become value-compared
//   several move           -> the exemption stands, now with a measured reason instead of an
//                            inherited one
//
// A report-level exemption is a blind spot the size of the report. A value-level one is the size
// of the value.
//
// METHOD: render the report twice on a corpus nothing is writing to, separated by more than the
// 66 seconds the original observation used, and diff the extracted numbers position by position.

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "runs under WP-CLI\n");
    exit(1);
}

$gap = (int) (getenv('GLANCE_GAP') ?: 70);

require_once WP_PLUGIN_DIR . '/wp-slimstat/tests/bench/lib/reports-bootstrap.php';

global $wpdb;
$fail = 0;

echo "CONTROLS\n";

$rows_before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}slim_stats");
printf("  corpus: %d rows\n", $rows_before);

if ($rows_before < 1000) {
    echo "  [FAIL] corpus too small — a rolling window over almost no rows moves for reasons\n";
    echo "         that have nothing to do with the report\n";
    echo "VERDICT: ABORTED\n";
    exit(1);
}
echo "  [PASS] corpus is non-trivial\n";
printf("  gap between renders: %d s (the original observation used 66)\n", $gap);

$reports = slimstat_bench_bootstrap_reports();
if (!isset($reports['slim_p1_03'])) {
    echo "  [FAIL] slim_p1_03 is not in the report registry — nothing below measures it\n";
    echo "VERDICT: ABORTED\n";
    exit(1);
}
echo "  [PASS] slim_p1_03 is registered and renderable\n";

$render = static function () {
    // Transients cleared between renders: a cached result would make the two snapshots identical
    // for a reason that has nothing to do with time-dependence, and this probe would report
    // "stable" about the cache rather than about the report.
    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
          WHERE option_name LIKE '\\_transient\\_slimstat%'
             OR option_name LIKE '\\_transient\\_timeout\\_slimstat%'"
    );
    wp_cache_flush();

    if (method_exists('wp_slimstat_db', 'init')) {
        wp_slimstat_db::init();
    }

    $error = null;
    try {
        ob_start();
        wp_slimstat_reports::callback_wrapper(['id' => 'slim_p1_03']);
        $html = (string) ob_get_clean();
    } catch (\Throwable $e) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $html  = '';
        $error = $e->getMessage();
    }

    // STRUCTURAL, not positional. The report renders `<p>Label <span>value</span></p>` with no
    // newline between rows, so the flattened text is ONE line and a positional guess attributed
    // the move to "Pageviews 22,258Days in Range 28Average Daily Pageviews 795F". Worse, the flat
    // number regex also captures the literal 30 inside the LABEL "Last 30 minutes", so the array
    // has nine numbers for eight metrics and every index past that point is off by one.
    //
    // Naming the wrong value would narrow the exemption around a stable number and leave the
    // moving one unasserted — strictly worse than the report-level exemption it replaces.
    preg_match_all('#<p>(.*?)<span>(.*?)</span></p>#s', $html, $rows, PREG_SET_ORDER);

    $pairs = [];
    foreach ($rows as $row) {
        $label = trim(html_entity_decode(wp_strip_all_tags($row[1]), ENT_QUOTES, 'UTF-8'));
        $value = trim(html_entity_decode(wp_strip_all_tags($row[2]), ENT_QUOTES, 'UTF-8'));
        if ('' !== $label) {
            $pairs[$label] = $value;
        }
    }

    return ['pairs' => $pairs, 'bytes' => strlen($html), 'error' => $error, 'html' => $html];
};

$trials = max(2, (int) (getenv('GLANCE_TRIALS') ?: 3));

// N SAMPLES, NOT ONE PAIR — and this is the correction the probe's own first result forced.
//
// Run A showed "Last 30 minutes" moving 19 -> 18 across 70 seconds. Run B, same probe, same
// corpus, showed NOTHING moving, and on that evidence alone the probe recommended lifting the
// exemption entirely. Both are true: a 30-minute rolling window only moves when a row actually
// falls out of the trailing edge during the sample, and whether one does in any given 70 seconds
// is chance.
//
// So absence of movement in one trial is not stability. The asymmetry is the whole point:
//
//   moved at least once   -> PROVES time-dependence. One observation is enough.
//   never moved in N      -> evidence of stability, and only as strong as N.
//
// Accumulated across trials, and the verdict says which kind of evidence it has.
$samples = [];
$first    = null;

for ($t = 0; $t < $trials; $t++) {
    if ($t > 0) {
        sleep($gap);
    }

    $r = $render();

    if (0 === $t) {
        $first = $r;

        echo "\n--- rendered markup, verbatim ---\n";
        echo $r['html'] . "\n";
        echo "--- end ---\n";

        printf("\nsample 1: %d label/value pairs, %d bytes%s\n", count($r['pairs']), $r['bytes'],
            null === $r['error'] ? '' : '   ERROR: ' . $r['error']);

        if (0 === $r['bytes'] || [] === $r['pairs']) {
            printf("  [FAIL] the report rendered %d bytes and %d pairs. Nothing below would be a\n"
                . "         statement about time-dependence: two empty renders are equal for a\n"
                . "         reason that has nothing to do with the clock.%s\n",
                $r['bytes'],
                count($r['pairs']),
                null === $r['error'] ? '' : "\n         The render threw: " . $r['error']);
            echo "VERDICT: ABORTED\n";
            exit(1);
        }
        printf("  [PASS] the report renders %d labelled values — there is something to move\n",
            count($r['pairs']));
    } else {
        printf("sample %d: %d pairs, %d bytes\n", $t + 1, count($r['pairs']), $r['bytes']);
    }

    $samples[] = $r;
}

$rows_after = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}slim_stats");
if ($rows_after !== $rows_before) {
    printf("  [FAIL] the corpus changed under the probe (%d -> %d) — any movement below could be\n"
        . "         a write rather than the clock\n", $rows_before, $rows_after);
    echo "VERDICT: ABORTED\n";
    exit(1);
}
printf("  [PASS] the corpus did not change under the probe (%d rows throughout)\n", $rows_after);

// -- which values moved, over every sample --------------------------------
echo "\nWHICH VALUES MOVED\n";
printf("  %d samples, %d s apart, over %d s total\n", $trials, $gap, $gap * ($trials - 1));

$seen = [];
foreach ($samples as $r) {
    foreach ($r['pairs'] as $label => $value) {
        $seen[$label][] = $value;
    }
}

$moved  = [];
$stable = [];
foreach ($seen as $label => $values) {
    if (count(array_unique($values)) > 1) {
        $moved[$label] = $values;
    } else {
        $stable[$label] = $values[0];
    }
}

if ([] === $moved) {
    printf("  nothing moved across %d values in %d samples.\n", count($stable), $trials);
    echo "  -> EVIDENCE OF STABILITY, AND ONLY AS STRONG AS THE SAMPLE. A rolling window moves\n";
    echo "     only when a row leaves its trailing edge, which may not happen in any given\n";
    echo "     window — this same probe saw 'Last 30 minutes' move 19 -> 18 in one run and\n";
    echo "     nothing move in the next. Do not lift an exemption on this alone; raise\n";
    echo "     GLANCE_TRIALS or GLANCE_GAP until either something moves or the sample is\n";
    echo "     long enough to mean something.\n";
} else {
    printf("  %d of %d values MOVED — proven time-dependent:\n", count($moved), count($seen));
    foreach ($moved as $label => $values) {
        printf("    %-28s %s\n", $label, implode(' -> ', $values));
    }
    printf("\n  %d stable across the same window:\n", count($stable));
    foreach ($stable as $label => $value) {
        printf("    %-28s %s\n", $label, $value);
    }
    echo "\n  -> the exemption is earned by the moved value(s) ONLY. Exempting the whole report\n";
    echo "     leaves the stable ones unasserted for no reason — a blind spot the size of the\n";
    echo "     report where one the size of a value would do.\n";
}

$second = end($samples);

printf("\n  bytes: %d -> %d (%s)\n", $first['bytes'], $second['bytes'],
    $first['bytes'] === $second['bytes'] ? 'identical' : 'CHANGED');

echo "\nVERDICT: MEASURED\n";
exit($fail);
