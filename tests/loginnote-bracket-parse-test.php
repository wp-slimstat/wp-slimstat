<?php

/**
 * Regression test for the Real-Time login/logout note parser (C1).
 *
 * Source: admin/view/right-now.php (the $login_logout block).
 * Issue: notes are stored bracket-delimited ([loggedin:user][...]) but the
 * parser used explode(';') + str_replace('loggedin:',''), so a username
 * rendered with stray brackets ([john]) and multi-segment rows leaked the
 * other segments.
 *
 * right-now.php is a procedural view (no callable function), so this test:
 *   1. exercises a mirror of the exact parse algorithm against the inputs
 *      that regressed, and
 *   2. source-scans right-now.php to pin that it uses the bracket+legacy
 *      tolerant split and no longer uses the old explode(';') form.
 *
 * Run: php tests/loginnote-bracket-parse-test.php
 */

declare(strict_types=1);

$failures = 0;
function assert_same($expected, $actual, string $msg): void
{
    global $failures;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$msg}\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n");
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

/**
 * Mirror of the right-now.php $login_logout parser. Returns [type, username]
 * pairs where type is 'in' or 'out'. Kept in lock-step with the view via the
 * source-scan below.
 */
function parse_login_notes(string $notes): array
{
    $out = [];
    if ($notes && (false !== strpos($notes, 'loggedin:') || false !== strpos($notes, 'loggedout:'))) {
        $segments = preg_split('/\]\[|;/', trim($notes, '[]'));
        foreach ($segments as $a_note) {
            if (0 === strpos($a_note, 'loggedin:')) {
                $out[] = ['in', substr($a_note, strlen('loggedin:'))];
            } elseif (0 === strpos($a_note, 'loggedout:')) {
                $out[] = ['out', substr($a_note, strlen('loggedout:'))];
            }
        }
    }
    return $out;
}

// --- bracket format (current storage) ---------------------------------------
assert_same([['in', 'john']], parse_login_notes('[loggedin:john]'), 'single bracketed login → clean username, no [ ]');
assert_same([['out', 'jane']], parse_login_notes('[loggedout:jane]'), 'single bracketed logout');
assert_same([['in', 'john']], parse_login_notes('[results:5][loggedin:john]'), 'multi-segment: only the login segment, no leak of results:');
assert_same([['in', 'john'], ['out', 'john']], parse_login_notes('[loggedin:john][loggedout:john]'), 'login + logout in one row');

// --- colon usernames survive (the reason B4 was dropped) --------------------
assert_same([['in', 'a:b']], parse_login_notes('[loggedin:a:b]'), 'username containing a colon survives intact');

// --- legacy semicolon format (backward compatibility) -----------------------
assert_same([['in', 'john']], parse_login_notes('loggedin:john'), 'legacy unbracketed login');
assert_same([['in', 'john'], ['out', 'jane']], parse_login_notes('loggedin:john;loggedout:jane'), 'legacy semicolon-separated');

// --- non-login notes ignored ------------------------------------------------
assert_same([], parse_login_notes('[browser:chrome][country:us]'), 'unrelated notes produce nothing');
assert_same([], parse_login_notes(''), 'empty notes produce nothing');

// --- source-scan: the real view uses the new parser, not the old one --------
$view = file_get_contents(__DIR__ . '/../admin/view/right-now.php');
assert_true(false !== $view, 'right-now.php is readable');
assert_true(
    false !== strpos($view, "preg_split('/\\]\\[|;/', trim(\$results[\$i]['notes'], '[]'))"),
    'right-now.php uses the bracket+legacy tolerant preg_split'
);
assert_true(
    false === strpos($view, "explode(';', \$results[\$i]['notes'])"),
    'right-now.php no longer uses the old explode(\';\') login parser'
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed in loginnote-bracket-parse-test.php\n");
    exit(1);
}
echo "OK: login/logout note parser renders bracket + legacy formats cleanly\n";
