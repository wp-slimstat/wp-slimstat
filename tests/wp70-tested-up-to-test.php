<?php
/**
 * Source-level: the readme.txt "Tested up to:" header matches the expected
 * WordPress version, and is a two-segment major.minor (the wp.org parser
 * strips any third segment).
 *
 * PINS the WP "Tested up to" readiness (Phase 6 flips $expected to '7.0' in the
 * same commit that bumps the header — RED before / green after).
 */

declare(strict_types=1);

$plugin_root = dirname(__DIR__);
$expected    = '7.0'; // Two-segment per the wp.org parser; bumped with readme.txt in Phase 6.

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
// Once on '7.0', enforce the wp.org two-segment form so a future 7.0.x regresses.
if ('7.0' === $expected && !preg_match('/^\d+\.\d+$/', $actual)) {
    fwrite(STDERR, "FAIL: Tested up to must be two-segment major.minor (got '{$actual}').\n");
    exit(1);
}

echo "OK: readme.txt Tested up to = {$actual}\n";
