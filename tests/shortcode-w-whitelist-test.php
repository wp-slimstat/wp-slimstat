<?php

/**
 * Regression test for the [slimstat] shortcode `w` parameter whitelist (C2).
 *
 * Source: wp-slimstat.php — slimstat_shortcode().
 * Issue #277: columns country / browser / platform / language have render
 * handlers (or are valid DB columns) but were missing from the `w` whitelist,
 * so shortcodes using them returned the "invalid parameter for w" comment.
 *
 * The whitelist `in_array($w, [...])` is ALSO the only column allow-list before
 * get_recent()/get_top() interpolate the column into raw SQL, so this test pins
 * both (a) the newly-allowed columns and (b) that every switch-case column is
 * whitelisted and the guard stays an exact-match in_array (not a loosened
 * substring/regex that would open SQL injection).
 *
 * Run: php tests/shortcode-w-whitelist-test.php
 */

declare(strict_types=1);

$failures = 0;
function check(bool $ok, string $msg): void
{
    global $failures;
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        $failures++;
    }
}

$src = file_get_contents(__DIR__ . '/../wp-slimstat.php');
if (false === $src) {
    fwrite(STDERR, "FAIL: cannot read wp-slimstat.php\n");
    exit(1);
}

// Locate the exact in_array() call that guards $w and capture its literal array
// argument (the columns are string literals, so the first ']' closes the array).
if (!preg_match('/in_array\(\$w,\s*\[(.*?)\]\s*,\s*true\s*\)/s', $src, $m)) {
    fwrite(STDERR, "FAIL: could not locate a strict in_array(\$w, [...], true) guard\n");
    exit(1);
}
$guard = $m[0];
preg_match_all("/'([^']+)'/", $m[1], $tokens);
$whitelist = $tokens[1];

// (a) the columns issue #277 was about must now be allowed.
foreach (['country', 'browser', 'platform', 'language'] as $col) {
    check(in_array($col, $whitelist, true), "whitelist must include '{$col}' (#277)");
}

// (b) every column with a dedicated switch-case handler must be whitelisted,
//     so a valid `w` can never be rejected by the guard.
preg_match_all("/case\s+'([a-z_]+)':/", $src, $cases);
$switchColumns = array_unique($cases[1]);
// 'count' is handled by the f=count branch, not a w-column case; the rest are real columns.
$ignore = ['count', 'count-all', 'recent', 'recent-all', 'top', 'top-all', 'widget'];
foreach ($switchColumns as $col) {
    if (in_array($col, $ignore, true)) {
        continue;
    }
    check(in_array($col, $whitelist, true), "switch-case column '{$col}' must be whitelisted");
}

// (c) the SQL boundary: get_recent()/get_top() interpolate the column into raw
//     SQL, so the guard captured above MUST be a strict, exact-match in_array
//     over a literal array (type-juggling-proof) — not a loosened strpos/regex.
//     We assert against the captured $guard scope, not "in_array anywhere".
check(
    (bool) preg_match('/^in_array\(\$w,\s*\[/', $guard) && false !== strpos($guard, '], true)'),
    'the `w` guard must be a strict in_array($w, [...], true) over a literal array'
);
check(
    !preg_match('/(?:strpos|preg_match|preg_split)\s*\([^)]*\$w\b/', $src),
    'the `w` value must not be fed to a loosened strpos/preg_* before the in_array guard'
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} check(s) failed in shortcode-w-whitelist-test.php\n");
    exit(1);
}
echo "OK: shortcode `w` whitelist includes country/browser/platform/language and reconciles with switch cases\n";
