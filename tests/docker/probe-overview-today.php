<?php
// Do the Overview's "Today" and "Yesterday" report what they claim? (F8 / slim_p1_03)
//
// Opening tag required, declare(strict_types=1) not — WP-CLI's eval-file wraps this.
//
// THE CLAIM UNDER TEST, from EXPECTED-DIFFS:
//
//   "slim_p1_03's exemption rests on a MISDIAGNOSIS: wp_slimstat::date_i18n() takes one parameter
//    and silently discards the second, so 'Today' computes `dt > now`. That is a defect
//    masquerading as a live window — fix it and the report becomes deterministic and
//    value-comparable."
//
// `wp_slimstat::date_i18n($_format)` accepts ONE argument and forwards it to WordPress's
// date_i18n() with no timestamp. Two call sites in get_overview_summary() pass a second:
//
//   'Today'      dt >       date_i18n('U', mktime(0,0,0, m, d,   Y))
//   'Yesterday'  dt BETWEEN date_i18n('U', mktime(0,0,0, m, d-1, Y))
//                       AND date_i18n('U', mktime(23,59,59, m, d-1, Y))
//
// If the second argument is discarded, every one of those collapses to "now" — so Today asks for
// pageviews in the FUTURE and Yesterday asks for a window of zero width. Both would read 0 on
// every install, forever, while looking like a live-window quirk.
//
// So this probe compares the RENDERED metric against an independently computed count, and prints
// the SQL each one implies. A probe that only printed the metric would show a plausible 0.

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "runs under WP-CLI\n");
    exit(1);
}

global $wpdb;

if (!class_exists('wp_slimstat_db')) {
    require_once WP_PLUGIN_DIR . '/wp-slimstat/admin/view/wp-slimstat-db.php';
}
if (method_exists('wp_slimstat_db', 'init')) {
    wp_slimstat_db::init();
}

$fail  = 0;
$table = $wpdb->prefix . 'slim_stats';

echo "CONTROLS\n";

// `dt` is stored as current_time('timestamp') — UTC plus the site's GMT offset — so every
// comparison below has to be in that same scheme. Taken from the plugin's own helper rather than
// from time(), because a probe that invents its own clock is measuring its own clock.
$now = (int) wp_slimstat::now();
printf("  wp_slimstat::now() = %d (%s)\n", $now, gmdate('Y-m-d H:i:s', $now));

$span = $wpdb->get_row("SELECT MIN(dt) lo, MAX(dt) hi, COUNT(*) n FROM {$table}", ARRAY_A);
printf("  corpus: %d rows, dt %s .. %s\n", (int) $span['n'],
    gmdate('Y-m-d H:i:s', (int) $span['lo']), gmdate('Y-m-d H:i:s', (int) $span['hi']));

// The whole test is vacuous on a corpus with nothing in the last two days: 0 would be the RIGHT
// answer and the defect would be invisible.
$midnight_today     = mktime(0, 0, 0, (int) wp_slimstat::date_i18n('m'), (int) wp_slimstat::date_i18n('d'), (int) wp_slimstat::date_i18n('Y'));
$midnight_yesterday = mktime(0, 0, 0, (int) wp_slimstat::date_i18n('m'), (int) wp_slimstat::date_i18n('d') - 1, (int) wp_slimstat::date_i18n('Y'));
$end_yesterday      = mktime(23, 59, 59, (int) wp_slimstat::date_i18n('m'), (int) wp_slimstat::date_i18n('d') - 1, (int) wp_slimstat::date_i18n('Y'));

$truth_today = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(id) FROM {$table} WHERE dt > %d",
    $midnight_today
));
$truth_yesterday = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(id) FROM {$table} WHERE dt BETWEEN %d AND %d",
    $midnight_yesterday,
    $end_yesterday
));

printf("  independently: today=%d  yesterday=%d\n", $truth_today, $truth_yesterday);

if (0 === $truth_today && 0 === $truth_yesterday) {
    echo "  [FAIL] the corpus has no rows in the last two days, so 0 is the CORRECT answer and\n";
    echo "         this probe cannot tell a working metric from a broken one\n";
    echo "VERDICT: ABORTED\n";
    exit(1);
}
echo "  [PASS] the corpus has rows in the window, so a 0 below would be a defect and not a fact\n";

// ── 1. What does the wrapper do with a second argument? ────────────────────
echo "\n1. wp_slimstat::date_i18n('U', <timestamp>) — is the timestamp honoured?\n";

$asked     = $midnight_today;
$answered  = (int) wp_slimstat::date_i18n('U', $asked);

printf("  asked for  %d (%s)\n", $asked, gmdate('Y-m-d H:i:s', $asked));
printf("  answered   %d (%s)\n", $answered, gmdate('Y-m-d H:i:s', $answered));

if ($answered === $asked) {
    echo "  [PASS] the timestamp is honoured\n";
} else {
    printf("  [CONFIRMED] the second argument is DISCARDED — off by %d seconds, which is\n"
        . "              'now' rather than the midnight that was requested\n", abs($answered - $asked));
    $fail = 1;
}

// ── 2. What do the two metrics actually render? ────────────────────────────
echo "\n2. get_overview_summary() — Today and Yesterday as rendered\n";

$summary = wp_slimstat_db::get_overview_summary();

$seen = [];
foreach ((array) $summary as $row) {
    $metric = (string) ($row['metric'] ?? '');
    if ('Today' === $metric || 'Yesterday' === $metric) {
        // number_format_i18n() output, so the separators come back out before comparing.
        $seen[$metric] = (int) preg_replace('/[^0-9]/', '', (string) ($row['value'] ?? '0'));
    }
}

foreach (['Today' => $truth_today, 'Yesterday' => $truth_yesterday] as $metric => $truth) {
    if (!array_key_exists($metric, $seen)) {
        printf("  [FAIL] the summary has no \"%s\" row — this probe is not reading the report\n", $metric);
        $fail = 1;
        continue;
    }

    printf("  %-10s rendered=%-8d independent=%-8d", $metric, $seen[$metric], $truth);

    if ($seen[$metric] === $truth) {
        echo "  [PASS]\n";
    } else {
        echo "  [CONFIRMED WRONG]\n";
        $fail = 1;
    }
}

// ── 3. And the reason, spelled out in SQL ──────────────────────────────────
echo "\n3. The comparison each metric actually issues\n";

printf("  Today     asks  dt > %d        (%s)\n",
    (int) wp_slimstat::date_i18n('U', $midnight_today),
    gmdate('Y-m-d H:i:s', (int) wp_slimstat::date_i18n('U', $midnight_today)));
printf("  should be dt > %d        (%s)\n", $midnight_today, gmdate('Y-m-d H:i:s', $midnight_today));

$y_lo = (int) wp_slimstat::date_i18n('U', $midnight_yesterday);
$y_hi = (int) wp_slimstat::date_i18n('U', $end_yesterday);
printf("  Yesterday asks  dt BETWEEN %d AND %d   (width %d s)\n", $y_lo, $y_hi, $y_hi - $y_lo);
printf("  should be dt BETWEEN %d AND %d   (width %d s)\n",
    $midnight_yesterday, $end_yesterday, $end_yesterday - $midnight_yesterday);

echo "\nVERDICT: " . (0 === $fail ? 'MEASURED — both metrics agree with an independent count' : 'DEFECT CONFIRMED') . "\n";
exit(0);
