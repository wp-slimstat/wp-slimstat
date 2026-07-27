<?php
// Report-parity oracle — captures what every report actually shows a user.
//
//   wp eval-file tests/bench/lib/parity-snapshot.php <out.json> [cells]
//   wp eval-file tests/bench/lib/parity-compare.php  <before.json> <after.json>
//
// The rule this exists to enforce: a faster wrong report is a worse product.
// Nothing in the v6 programme may change a number a user sees unless that
// change is deliberate, documented and signed off.
//
// It snapshots the RENDERED OUTPUT, not raw query results, because formatting
// is where rounding and locale differences surface — number_format_i18n() is
// what the user reads, not the float behind it.
//
// Cells (the four combinations that hide bugs):
//   historical-unfiltered  a fixed absolute window, no filters
//   historical-filtered    same window plus a column filter
//   straddling-unfiltered  a window whose end is "now" — the ONLY way to
//                          exercise Query::getAll()'s split-merge path
//   straddling-filtered    same, plus a filter
//
// Absolute windows are used wherever possible so a snapshot does not depend on
// when it was taken. The straddling cells cannot be made time-independent by
// definition; the capture clock is recorded and parity-compare refuses to
// compare snapshots taken on different local days.
//
// No declare(strict_types=1): `wp eval-file` (WP-CLI 2.12) eval()s this file.

if (!defined('ABSPATH')) {
    fwrite(STDERR, "must run inside WordPress (wp eval-file)\n");
    exit(2);
}

$out_path = (string) ($args[0] ?? '');
if ($out_path === '') {
    echo "usage: wp eval-file parity-snapshot.php <out.json> [cell,cell,...]\n";
    echo "VERDICT: ERROR\n";
    return;
}

define('SLIMSTAT_BENCH_FINGERPRINT_LIB', true);
require_once __DIR__ . '/fingerprint.php';

if (!class_exists('wp_slimstat')) {
    echo "ERROR: wp_slimstat is not loaded — is the plugin active?\n";
    echo "VERDICT: ERROR\n";
    return;
}

$db = wp_slimstat::$wpdb instanceof wpdb ? wp_slimstat::$wpdb : $GLOBALS['wpdb'];

// A fixed window inside the dataset, so historical cells are reproducible
// regardless of when the snapshot runs.
//
// Anchored two days BEFORE now, not at MAX(dt), and this is load-bearing:
// init_filters() clamps every window's end to now(), and real datasets contain
// future-dated rows (this one runs a day ahead of the wall clock). Anchoring on
// MAX(dt) therefore produced a window whose end crept forward in real time,
// pulling those future rows in a few at a time — 158 reports "changed" between
// two runs of identical code, purely from the clock advancing.
//
// Ending the window two days back puts it entirely in the past, where nothing
// can drift into it.
$max_dt = (int) $db->get_var("SELECT MAX(dt) FROM `{$db->prefix}slim_stats`");
if ($max_dt <= 0) {
    echo "ERROR: no rows in slim_stats — nothing to snapshot\n";
    echo "VERDICT: ERROR\n";
    return;
}
// Quantised to the day it lands in: only the day/month/year are used to build
// the filter, so carrying a to-the-second value would make two snapshots taken
// a minute apart look like different windows.
$anchor      = min($max_dt, time()) - (2 * DAY_IN_SECONDS);
$anchor_day  = gmdate('j', $anchor);
$anchor_mon  = gmdate('n', $anchor);
$anchor_yr   = gmdate('Y', $anchor);
$anchor_date = gmdate('Y-m-d', $anchor);
$fixed      = sprintf('day equals %d&&&month equals %d&&&year equals %d&&&interval equals -30',
    $anchor_day, $anchor_mon, $anchor_yr);

// A filter that exercises the WHERE-building path without depending on any
// particular row surviving: browser_type is always present and low-cardinality.
$filter = 'browser_type equals 0';

$all_cells = [
    'historical-unfiltered' => $fixed,
    'historical-filtered'   => $fixed . '&&&' . $filter,
    'straddling-unfiltered' => 'interval equals -30',
    'straddling-filtered'   => 'interval equals -30&&&' . $filter,
];

$wanted = isset($args[1]) && $args[1] !== ''
    ? array_intersect_key($all_cells, array_flip(array_map('trim', explode(',', (string) $args[1]))))
    : $all_cells;

if (!class_exists('wp_slimstat_reports')) {
    require_once SLIMSTAT_ANALYTICS_DIR . 'admin/view/wp-slimstat-reports.php';
}

if (!is_user_logged_in()) {
    $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
    if ($admins) {
        wp_set_current_user((int) $admins[0]);
    }
}

/**
 * Strip everything that legitimately differs between two runs of identical code.
 *
 * Anything removed here is invisible to the oracle, so the list is deliberately
 * short and each entry is justified — over-normalising is how a parity check
 * quietly stops checking.
 */
$normalise = static function (string $html): string {
    $patterns = [
        // Nonces and per-request tokens.
        '/(_wpnonce|nonce|security)=[a-f0-9]{6,}/i'          => '$1=NONCE',
        '/name="[^"]*nonce[^"]*"\s+value="[^"]*"/i'          => 'name="NONCE" value="NONCE"',
        // Cache-busting and asset version query strings.
        '/\?ver=[\w.\-]+/'                                    => '?ver=VER',
        // Relative times ("3 mins ago") move with the wall clock.
        '/\b\d+\s+(second|minute|min|hour|day|week|month|year)s?\s+ago\b/i' => 'RELTIME ago',
        // Absolute timestamps rendered from now().
        '/\b\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}(:\d{2})?\b/'     => 'TIMESTAMP',
        // Raw UNIX epochs (2023-2033) embedded in markup — charts ship the
        // window in data-args as {"start":…,"end":…}, and init_filters() clamps
        // that end to the CURRENT SECOND, so two renders a second apart differ.
        // Users never see an epoch, so normalising it hides nothing from them.
        //
        // Worth noting rather than only working around: this is the same
        // second-precision clamp that gives goal transients a cache key which
        // can never be hit twice (defect D33). The oracle rediscovered it
        // independently.
        '/\b1[7-9]\d{8}\b/'                                   => 'EPOCH',
        // DOM ids that embed a counter or random suffix.
        '/id="[\w\-]*?(chart|canvas)[\w\-]*?\d+"/i'          => 'id="DYNAMIC"',
        // Whitespace noise.
        '/\s+/'                                               => ' ',
    ];
    return trim((string) preg_replace(array_keys($patterns), array_values($patterns), $html));
};

/** Pull the numbers a user actually reads out of the rendered HTML. */
$extract_numbers = static function (string $html): array {
    $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, 'UTF-8');
    preg_match_all('/(?<![\w.])(\d{1,3}(?:,\d{3})+|\d+(?:\.\d+)?)\s*%?/', $text, $m);
    return array_slice($m[1], 0, 200);
};

wp_slimstat_reports::init();
$reports = wp_slimstat_reports::$reports;

$snapshot = [
    'captured_at'      => time(),
    'captured_day'     => wp_date('Y-m-d'),
    'timezone'         => wp_timezone_string(),
    'fingerprint_hash' => slimstat_bench_fingerprint_hash(slimstat_bench_fingerprint($db)),
    'anchor_date'      => $anchor_date,
    'stats_rows'       => (int) $db->get_var("SELECT COUNT(*) FROM `{$db->prefix}slim_stats`"),
    'cells'            => [],
];

foreach ($wanted as $cell => $filters) {
    printf("cell %s\n", $cell);
    foreach ($reports as $report_id => $report) {
        if (empty($report['callback'])) {
            continue;
        }
        wp_slimstat_db::init($filters);

        $html  = '';
        $error = null;
        try {
            ob_start();
            wp_slimstat_reports::callback_wrapper(['id' => $report_id]);
            $html = (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $error = $e->getMessage();
        }

        $clean = $normalise($html);
        $snapshot['cells'][$cell][$report_id] = [
            'hash'    => $error === null ? md5($clean) : null,
            'bytes'   => strlen($clean),
            'numbers' => $error === null ? $extract_numbers($clean) : [],
            'error'   => $error,
        ];
        $db->queries = [];
    }
}

file_put_contents($out_path, wp_json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$total  = array_sum(array_map('count', $snapshot['cells']));
$errors = 0;
foreach ($snapshot['cells'] as $cell_reports) {
    foreach ($cell_reports as $r) {
        if ($r['error'] !== null) {
            $errors++;
        }
    }
}

printf("\ncaptured %d report/cell snapshots (%d could not render)\n", $total, $errors);
printf("wrote %s\n", $out_path);
echo "VERDICT: OK\n";
