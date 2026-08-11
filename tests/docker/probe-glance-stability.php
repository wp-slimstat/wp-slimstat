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

    $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, 'UTF-8');
    preg_match_all('/(?<![\w.])(\d{1,3}(?:,\d{3})+|\d+(?:\.\d+)?)\s*%?/', $text, $m);

    // The labels too, so a moving number can be NAMED rather than reported as "position 6".
    $labels = [];
    foreach (preg_split('/\n+/', $text) as $line) {
        $line = trim(preg_replace('/\s+/', ' ', $line));
        if ('' !== $line) {
            $labels[] = $line;
        }
    }

    return ['numbers' => $m[1], 'bytes' => strlen($html), 'lines' => $labels, 'error' => $error];
};

$first = $render();
printf("\nfirst render:  %d numbers, %d bytes%s\n", count($first['numbers']), $first['bytes'],
    null === $first['error'] ? '' : '   ERROR: ' . $first['error']);

// AN EMPTY RENDER IS NOT A STABLE RENDER, and the first version of this probe did not say so. It
// printed "NOTHING MOVED across 0 numbers -> the exemption is no longer earned" about a report
// that had produced 0 bytes. That is PITFALLS 38 — an empty result is not an answer — inside a
// probe written to re-examine an exemption, which is the population where a confident vacuous
// pass does the most damage.
if (0 === $first['bytes'] || [] === $first['numbers']) {
    printf("  [FAIL] the report rendered %d bytes and %d numbers. Nothing below would be a\n"
        . "         statement about time-dependence: two empty renders are equal for a reason\n"
        . "         that has nothing to do with the clock.%s\n",
        $first['bytes'],
        count($first['numbers']),
        null === $first['error'] ? '' : "\n         The render threw: " . $first['error']);
    echo "VERDICT: ABORTED\n";
    exit(1);
}
printf("  [PASS] the report renders %d numbers — there is something for the clock to move\n",
    count($first['numbers']));

sleep($gap);

$second = $render();
printf("second render: %d numbers, %d bytes%s\n", count($second['numbers']), $second['bytes'],
    null === $second['error'] ? '' : '   ERROR: ' . $second['error']);

$rows_after = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}slim_stats");
if ($rows_after !== $rows_before) {
    printf("  [FAIL] the corpus changed under the probe (%d -> %d) — any movement below could be\n"
        . "         a write rather than the clock\n", $rows_before, $rows_after);
    echo "VERDICT: ABORTED\n";
    exit(1);
}
printf("  [PASS] the corpus did not change under the probe (%d rows both times)\n", $rows_after);

// ── which values moved ─────────────────────────────────────────────────────
echo "\nWHICH VALUES MOVED\n";

$moved = [];
$len   = max(count($first['numbers']), count($second['numbers']));

for ($i = 0; $i < $len; $i++) {
    $a = $first['numbers'][$i] ?? '—';
    $b = $second['numbers'][$i] ?? '—';

    if ($a !== $b) {
        // Name it from the rendered text: the label sits beside its value on the same line.
        // WHOLE-TOKEN match, not strpos(). An unanchored substring makes "40" match "1,405",
        // "402" and any year, and a vanished value arrives here as the em dash from the ?? above,
        // which matches the first line containing any em dash. This probe's whole deliverable is
        // naming WHICH value is time-dependent so the exemption can be narrowed from the report to
        // the value — and a wrong label narrows it around the wrong number, leaving the moving one
        // asserted and a stable one exempt.
        $label  = '';
        $needle = preg_quote((string) $b, '/');
        foreach ($second['lines'] as $line) {
            if ('—' !== $b && preg_match('/(?<![\d,.])' . $needle . '(?![\d,.])/', $line)) {
                $label = $line;
                break;
            }
        }
        if ('' === $label) {
            $label = '(value not locatable in the rendered text — position only)';
        }
        $moved[] = ['pos' => $i, 'from' => $a, 'to' => $b, 'label' => $label];
    }
}

if ([] === $moved) {
    printf("  NOTHING MOVED across %d numbers.\n", $len);
    echo "  -> the report is stable on a static corpus, and the exemption is no longer earned.\n";
    echo "     slim_p1_03 can be value-compared like any other report.\n";
} else {
    printf("  %d of %d numbers moved:\n", count($moved), $len);
    foreach ($moved as $m) {
        printf("    #%-3d %-8s -> %-8s  %s\n", $m['pos'], $m['from'], $m['to'], substr($m['label'], 0, 60));
    }
    echo "  -> the exemption is still earned, but only for these. Every OTHER number in this\n";
    echo "     report is stable and is currently unasserted for no reason.\n";
}

printf("\n  bytes: %d -> %d (%s)\n", $first['bytes'], $second['bytes'],
    $first['bytes'] === $second['bytes'] ? 'identical' : 'CHANGED');

echo "\nVERDICT: MEASURED\n";
exit($fail);
