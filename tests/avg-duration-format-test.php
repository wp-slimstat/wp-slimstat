<?php

/**
 * Regression test for the Average Visit Duration formatting (C4 / issue #78).
 *
 * Source: admin/view/wp-slimstat-db.php (get_visits_duration averaging block).
 * Bug: date('m:s', $seconds) used the MONTH token 'm' (always 01 near the
 * epoch) instead of minutes, and applied the site timezone offset to an
 * elapsed-seconds value — so e.g. 600s (10 min) rendered as "01:00".
 * Fix: gmdate('i:s' | 'H:i:s', $seconds).
 *
 * Run: php tests/avg-duration-format-test.php
 */

declare(strict_types=1);

$failures = 0;
function assert_same($expected, $actual, string $msg): void
{
    global $failures;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$msg} — expected '{$expected}', got '{$actual}'\n");
        $failures++;
    }
}
function assert_true(bool $cond, string $msg): void
{
    global $failures;
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        $failures++;
    }
}

/** Mirror of the wp-slimstat-db.php averaging/format block. */
function format_avg_duration($total_seconds, int $visits): string
{
    if ($visits > 0) {
        $t = intval($total_seconds / $visits);
        return gmdate($t >= 3600 ? 'H:i:s' : 'i:s', $t);
    }
    return '0:00';
}

assert_same('00:45', format_avg_duration(45, 1), '45s → 00:45');
assert_same('10:00', format_avg_duration(600, 1), '600s → 10:00 (was "01:00" under the month-token bug)');
assert_same('01:02:05', format_avg_duration(3725, 1), '3725s → 01:02:05 (rolls into hours)');
assert_same('00:30', format_avg_duration(120, 4), 'averaging: 120s/4 visits → 30s → 00:30');
assert_same('0:00', format_avg_duration(0, 0), 'no human visits → 0:00');

// Document the original bug so the fix intent is locked: the old code emitted
// the month token, not minutes, for anything over 59 seconds.
assert_true(date('m:s', 600) !== '10:00', 'sanity: the old date(\'m:s\') form did NOT produce 10:00');

// Source-scan: the view uses gmdate, not the buggy date('m:s', ...).
$src = file_get_contents(__DIR__ . '/../admin/view/wp-slimstat-db.php');
assert_true(false !== $src, 'wp-slimstat-db.php readable');
assert_true(false === strpos($src, "date('m:s'"), "wp-slimstat-db.php no longer uses date('m:s', ...)");
assert_true(false !== strpos($src, "gmdate(\$average_time >= 3600 ? 'H:i:s' : 'i:s'"), 'wp-slimstat-db.php uses gmdate i:s / H:i:s');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed in avg-duration-format-test.php\n");
    exit(1);
}
echo "OK: average visit duration formats as mm:ss / h:mm:ss\n";
