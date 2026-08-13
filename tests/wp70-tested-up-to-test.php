<?php
/**
 * Source-level: the readme.txt "Tested up to:" header matches the expected
 * WordPress version, and is a two-segment major.minor (the wp.org parser
 * strips any third segment).
 *
 * PINS the WordPress "Tested up to" readiness. Bump $expected in the same
 * commit as the readme header — RED before / green after.
 */

declare(strict_types=1);

$plugin_root = dirname(__DIR__);
$expected    = '7.1'; // Two-segment per the wp.org parser; bumped with readme.txt.

$readme = file_get_contents($plugin_root . '/readme.txt');
if (false === $readme) { fwrite(STDERR, "FAIL: cannot read readme.txt\n"); exit(1); }

if (!preg_match('/^\s*Tested up to:\s*(\S+)\s*$/m', $readme, $m)) {
    fwrite(STDERR, "FAIL: no `Tested up to:` header in readme.txt\n");
    exit(1);
}
$actual = $m[1];

if ($actual !== $expected) {
    fwrite(STDERR, "FAIL: readme.txt Tested up to is '{$actual}', expected '{$expected}'.\n");
    exit(1);
}
// Enforce the wp.org two-segment form so a future 7.1.x regresses.
if (!preg_match('/^\d+\.\d+$/', $actual)) {
    fwrite(STDERR, "FAIL: Tested up to must be two-segment major.minor (got '{$actual}').\n");
    exit(1);
}

$ci = file_get_contents($plugin_root . '/.github/workflows/ci.yml');
if (false === $ci) { fwrite(STDERR, "FAIL: cannot read .github/workflows/ci.yml\n"); exit(1); }

$lane = sprintf('- { wp: "%s", php: "8.3" }', $expected);
if (false === strpos($ci, $lane)) {
    fwrite(STDERR, "FAIL: CI has no WordPress {$expected} / PHP 8.3 compatibility lane.\n");
    exit(1);
}
if (false === strpos($ci, 'WP_REF="${WP_VERSION}-branch"')) {
    fwrite(STDERR, "FAIL: CI does not resolve unreleased WordPress versions to their release branch.\n");
    exit(1);
}
if (false === strpos($ci, "github.base_ref == 'master'")) {
    fwrite(STDERR, "FAIL: the compatibility job does not run for pull requests targeting master.\n");
    exit(1);
}

echo "OK: readme.txt Tested up to = {$actual}; matching CI lane is configured\n";
