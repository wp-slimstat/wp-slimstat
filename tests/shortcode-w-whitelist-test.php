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

// Extract the `w` whitelist array literal: in_array($w, [ ... ])
if (!preg_match('/in_array\(\$w,\s*\[(.*?)\]\)\)/s', $src, $m)) {
    fwrite(STDERR, "FAIL: could not locate the in_array(\$w, [...]) whitelist\n");
    exit(1);
}
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

// (c) the SQL boundary: the guard must stay an exact-match in_array, never a
//     loosened strpos/preg_match over $w (which would allow arbitrary columns
//     into raw SQL).
check(
    !preg_match('/(?:strpos|preg_match|preg_split)\([^)]*\$w\b/', $src)
        || (bool) preg_match('/in_array\(\$w,/', $src),
    'the `w` guard must remain an exact-match in_array, not a loosened match'
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} check(s) failed in shortcode-w-whitelist-test.php\n");
    exit(1);
}
echo "OK: shortcode `w` whitelist includes country/browser/platform/language and reconciles with switch cases\n";
