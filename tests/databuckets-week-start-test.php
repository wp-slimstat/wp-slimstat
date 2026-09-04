<?php
/**
 * Weekly chart buckets follow WordPress's `start_of_week`, not ISO weeks — and a row past the
 * range never lands in a phantom bucket.
 *
 * ── WHERE THIS CAME FROM ────────────────────────────────────────────────────────────────────
 *
 * `tests/e2e/chart-negative-regression.spec.ts` carried two live assertions about the current
 * code and two about the OLD code: it `git worktree add`-ed `master` inside the E2E container
 * and grepped the old `DataBuckets.php`. The container is not a git repository, so the setup
 * could never succeed, and master is 5.5.x now — a spec that cannot pass, sitting in the census
 * denominator. The two live assertions moved here, where they run on all six Tier 1 PHP lanes
 * instead of one soft E2E lane; "master had the bug" became mutations D1 and D2.
 *
 * Vendor-free: `DataBuckets` needs `get_option`, `wp_date`, `wp_timezone`, `ABSPATH`, and a
 * `$wpdb` for the timezone probe. UTC throughout so the arithmetic is the calendar's.
 *
 * Run: php tests/databuckets-week-start-test.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit(1);
}

error_reporting(E_ALL);
date_default_timezone_set('UTC');

define('ABSPATH', dirname(__DIR__) . '/');

$GLOBALS['__slimstat_test_start_of_week'] = 1;

function get_option($key, $default = false)
{
    return 'start_of_week' === $key ? $GLOBALS['__slimstat_test_start_of_week'] : $default;
}

function wp_date($format, $timestamp = null, $timezone = null)
{
    return date($format, (int) $timestamp);
}

function wp_timezone()
{
    return new DateTimeZone('UTC');
}

// serverTimezoneOffset() reads \wp_slimstat::$wpdb ?? $GLOBALS['wpdb']; a missing CLASS is a
// fatal that `??` does not catch, so the class must exist with a null property.
class wp_slimstat
{
    public static $wpdb = null;
}

$GLOBALS['wpdb'] = new class {
    public function get_var($sql)
    {
        return 0;
    }
};

require_once dirname(__DIR__) . '/src/Helpers/DataBuckets.php';

$failures    = [];
$range_start = strtotime('2026-02-18');
$range_end   = strtotime('2026-03-17') + 86399;

// ── 1. start_of_week = 6 (Saturday): Fri 13 and Sat 14 March are different site-weeks ───
//
// Labels over 18 Feb–17 Mar with Saturday weeks: Feb 18 (partial), Feb 21, Feb 28, Mar 7, Mar 14.
// initSeqWeek() zero-fills every bucket, so a strict compare of the whole dataset also proves
// nothing leaked into a neighbouring bucket.
$GLOBALS['__slimstat_test_start_of_week'] = 6;
$buckets = new \SlimStat\Helpers\DataBuckets('M j', 'WEEK', $range_start, $range_end, 0, 0); // previous period unused
$buckets->addRow(strtotime('2026-03-13'), 100, 0, 'current');
$buckets->addRow(strtotime('2026-03-14'), 200, 0, 'current');
$out    = $buckets->toArray();
$labels = array_map(static fn($l) => trim((string) $l, "'"), $out['labels']);

if (['Feb 18', 'Feb 21', 'Feb 28', 'Mar 7', 'Mar 14'] !== $labels) {
    $failures[] = 'sow=6: weekly labels over 18 Feb–17 Mar 2026 should be [Feb 18, Feb 21, Feb 28, '
        . 'Mar 7, Mar 14]; got [' . implode(', ', $labels) . ']';
}
if ([0, 0, 0, 100, 200] !== $out['datasets']['v1']) {
    $failures[] = 'sow=6: Friday 13 March (100) belongs in the Mar 7 bucket and Saturday 14 March '
        . '(200) in the Mar 14 bucket; got [' . implode(', ', $out['datasets']['v1']) . ']. Both in '
        . 'one bucket means weeks are cut on ISO Mondays (date(\'W\')) instead of on start_of_week '
        . '— Saturday landed in the Mar 7 bucket';
}

// ── 2. start_of_week = 0 (Sunday): boundary rows kept, a row one week past the range dropped ──
//
// Labels: Feb 18 (partial), Feb 22, Mar 1, Mar 8, Mar 15. A row at the exact start, one a
// minute before the end, and one on Sunday 22 March — a week past the range, which the old
// `<=` bounds check parked at index == points, a phantom bucket with no label.
$GLOBALS['__slimstat_test_start_of_week'] = 0;
$buckets = new \SlimStat\Helpers\DataBuckets('M j', 'WEEK', $range_start, $range_end, 0, 0);
$buckets->addRow($range_start, 10, 0, 'current');
$buckets->addRow($range_end - 60, 5, 0, 'current');
$buckets->addRow(strtotime('2026-03-22'), 7, 0, 'current');
$out = $buckets->toArray();

if ([10, 0, 0, 0, 5] !== $out['datasets']['v1']) {
    $failures[] = 'sow=0: expected [10, 0, 0, 0, 5]; got [' . implode(', ', $out['datasets']['v1'])
        . ']. A sixth entry, or a total of 22, means the row one week PAST the range was accepted '
        . '— the bounds check admits offset == points, a row one week past the range landed in a '
        . 'phantom bucket with no label';
}

if ($failures) {
    fwrite(STDERR, 'FAIL: DataBuckets week start (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: weekly buckets cut on start_of_week (sow=6 separates Fri 13 / Sat 14 March), and a row "
    . "one week past the range is dropped rather than parked in a phantom bucket\n";
