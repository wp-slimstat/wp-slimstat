<?php
// How many reports does the parity oracle actually compare? (I3)
//
// Opening tag required, declare(strict_types=1) not — WP-CLI's eval-file wraps this.
//
// THE SUSPICION, and it came from a probe that failed rather than from reading. A probe written to
// re-examine slim_p1_03's time-dependence exemption rendered the report and got **0 bytes**. No
// exception. The cause is two lines in the render path:
//
//   callback_wrapper()      returns early unless current_user_can($minimum_capability)
//   raw_results_to_html()   returns '' when async_load is 'on' and this is not an AJAX request
//
// `async_load` DEFAULTS TO 'on' for new installs (EXPECTED-DIFFS R7), the bench container is a new
// install, and **52 report definitions route through raw_results_to_html()**. Nothing in
// tests/bench/lib/ sets that option — `grep -rn async_load tests/bench/lib/` returns nothing.
//
// If that holds, the parity oracle has been comparing EMPTY STRINGS for most of its corpus: two
// empty renders hash identically, so every one of them reports parity, every run, whatever the
// code does. The forward plan lists "force async_load off" among I3's snapshot repairs — this
// probe measures the size of what that repair recovers, before it is made.
//
// WHAT IS MEASURED: every registered report rendered twice — once with the option as the container
// actually has it, once with async_load forced off — counting how many produce nothing. The
// difference is the oracle's blind spot, in reports.

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "runs under WP-CLI\n");
    exit(1);
}

require_once WP_PLUGIN_DIR . '/wp-slimstat/tests/bench/lib/reports-bootstrap.php';

global $wpdb;
$fail = 0;

echo "CONTROLS\n";

$rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}slim_stats");
printf("  corpus: %d rows\n", $rows);
if ($rows < 1000) {
    echo "  [FAIL] corpus too small — an empty report would be the correct answer\n";
    echo "VERDICT: ABORTED\n";
    exit(1);
}
echo "  [PASS] corpus is non-trivial, so a report producing nothing is a defect and not a fact\n";

$reports = slimstat_bench_bootstrap_reports();
printf("  registered reports: %d\n", count($reports));
if (count($reports) < 20) {
    echo "  [FAIL] fewer than 20 reports registered — the bootstrap did not run\n";
    echo "VERDICT: ABORTED\n";
    exit(1);
}
echo "  [PASS] the registry is populated\n";

// The capability gate is the OTHER way a report renders nothing. Proven satisfied here so the
// counts below can be attributed to async_load rather than left ambiguous between the two.
$user = wp_get_current_user();
printf("  current user: #%d (%s)\n", (int) $user->ID, $user->user_login ?: '(none)');
$cap = wp_slimstat::$settings['capability_can_view'] ?: 'read';
printf("  capability_can_view: %s — current_user_can: %s\n", $cap, current_user_can($cap) ? 'YES' : 'NO');

if (!current_user_can($cap)) {
    echo "  [FAIL] the capability gate is closed, so EVERY report would render nothing and the\n";
    echo "         async_load measurement below could not be attributed\n";
    echo "VERDICT: ABORTED\n";
    exit(1);
}
echo "  [PASS] the capability gate is open — an empty render below is async_load, not permissions\n";

// THE STORED VALUE, NOT THE ONE THE BOOTSTRAP JUST SET.
//
// slimstat_bench_bootstrap_reports() now forces async_load off — which is the fix this probe
// exists to verify. Reading wp_slimstat::$settings here would therefore have both arms running
// with 'no', $recovered always empty, and "VERDICT: MEASURED — no async_load blind spot" printed
// on every future run INCLUDING one where the fix had been reverted. A control that can no longer
// fail, committed as evidence that it passed.
//
// The option is what a real install has. Arm 1 restores it explicitly so the two arms differ.
$stored_options = get_option('slimstat_options', []);
$stored_async   = is_array($stored_options) ? ($stored_options['async_load'] ?? 'on') : 'on';

printf("\n  async_load, as STORED in the database: %s\n", var_export($stored_async, true));
printf("  async_load, as the bench bootstrap sets it: %s\n",
    var_export(wp_slimstat::$settings['async_load'] ?? '(unset)', true));

if ($stored_async === (wp_slimstat::$settings['async_load'] ?? null)) {
    echo "  NOTE: the two agree, so arm 1 below is not exercising the suppressed shape. That is\n";
    echo "        only meaningful if the STORED value is genuinely 'no'.\n";
}

// ── render every report, both ways ─────────────────────────────────────────

$render_all = static function () use ($reports) {
    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
          WHERE option_name LIKE '\\_transient\\_slimstat%'
             OR option_name LIKE '\\_transient\\_timeout\\_slimstat%'"
    );
    wp_cache_flush();
    wp_slimstat_db::init();

    $empty = [];
    $bytes = 0;

    foreach ($reports as $id => $report) {
        if (empty($report['callback'])) {
            continue;
        }

        try {
            ob_start();
            wp_slimstat_reports::callback_wrapper(['id' => $id]);
            $html = (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $html = '';
        }

        $bytes += strlen($html);
        if ('' === trim($html)) {
            $empty[] = $id;
        }
    }

    return ['empty' => $empty, 'bytes' => $bytes];
};

$bootstrapped                        = wp_slimstat::$settings['async_load'] ?? 'on';
wp_slimstat::$settings['async_load'] = $stored_async;

$as_is = $render_all();
printf("\n1. AS A REAL INSTALL HAS IT (async_load = %s, from the stored option)\n", var_export($stored_async, true));
printf("   %d reports rendered nothing · %d bytes total\n", count($as_is['empty']), $as_is['bytes']);

wp_slimstat::$settings['async_load'] = 'no';

$forced = $render_all();
printf("\n2. WITH async_load FORCED OFF\n");
printf("   %d reports rendered nothing · %d bytes total\n", count($forced['empty']), $forced['bytes']);

wp_slimstat::$settings['async_load'] = $bootstrapped;

// ── the blind spot ─────────────────────────────────────────────────────────
echo "\n3. THE DIFFERENCE — reports the oracle compares as empty-vs-empty today\n";

$recovered = array_values(array_diff($as_is['empty'], $forced['empty']));

if ([] === $recovered) {
    echo "   none. async_load is not suppressing any report in this container, so the oracle's\n";
    echo "   coverage is whatever the other controls say it is.\n";
} else {
    printf("   %d report(s) render ONLY with async_load off:\n", count($recovered));
    foreach (array_slice($recovered, 0, 30) as $id) {
        printf("     %s\n", $id);
    }
    if (count($recovered) > 30) {
        printf("     … and %d more\n", count($recovered) - 30);
    }
    printf("\n   Two empty renders hash identically, so each would report PARITY on every run\n");
    printf("   regardless of what the code did. Bytes recovered: %d -> %d.\n",
        $as_is['bytes'], $forced['bytes']);
}

// -- THE ASSERTION, as opposed to the measurement --------------------------
//
// async_load is a PRODUCT FEATURE: a real install having it 'on' is correct, and arm 1 above will
// therefore keep showing ~50 suppressed reports forever. That is context, not a defect, and
// failing on it would give this probe a verdict that can never be green.
//
// What must hold is that the HARNESS overrides it. If slimstat_bench_bootstrap_reports() ever
// stops forcing async_load off, the oracle silently returns to comparing empty against empty
// across most of its corpus - and nothing else in the tree would notice, because identical is
// exactly what it would see.
echo "\n5. THE ASSERTION - does the bench bootstrap override the product default?\n";
printf("   bootstrap set async_load to %s\n", var_export($bootstrapped, true));

if ('no' !== $bootstrapped) {
    printf("   [FAIL] the bench bootstrap left async_load as %s, so the parity oracle renders\n"
        . "          %d of %d reports empty and compares them against each other. Restore the\n"
        . "          override in tests/bench/lib/reports-bootstrap.php\n",
        var_export($bootstrapped, true), count($as_is['empty']), count($reports));
    $fail = 1;
} else {
    printf("   [PASS] the harness renders %d of %d reports, not %d\n",
        count($reports) - count($forced['empty']), count($reports),
        count($reports) - count($as_is['empty']));
}

// Whatever async_load does, a report that is empty BOTH ways is empty for another reason and is
// equally uncomparable. Named separately so the two causes are not conflated.
if ([] !== $forced['empty']) {
    printf("\n4. STILL EMPTY with async_load off — %d report(s), a different cause:\n", count($forced['empty']));
    foreach (array_slice($forced['empty'], 0, 20) as $id) {
        printf("     %s\n", $id);
    }
}

echo "\nVERDICT: " . (0 === $fail ? 'MEASURED - the harness overrides the product default' : 'FAILED') . "\n";
exit($fail);
