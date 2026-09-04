<?php
/**
 * Source-level: the readme.txt "Tested up to:" header matches the expected
 * WordPress version, and is a two-segment major.minor (the wp.org parser
 * strips any third segment).
 *
 * PINS the WP "Tested up to" readiness: $expected and the header move in the same commit,
 * RED before and green after.
 *
 * AND THE LANE MOVES WITH THEM. A "Tested up to" that names a WordPress nothing in CI boots
 * is a claim about testing that no test performed — which is what this header meant while it
 * said 7.0 and the matrix stopped at 7.0 without ever running 7.1. The declared version must
 * appear in .github/workflows/ci.yml's Tier 2 matrix, so bumping one without the other is
 * a failure here rather than a discovery by a user on the newest WordPress.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$expected    = '7.1'; // Two-segment per the wp.org parser; bumped with readme.txt and the CI lane.

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
// The wp.org parser strips a third segment, so a 7.1.2 here would display as something the
// header does not say. Unconditional now: it was gated on $expected === '7.0', which made the
// check disappear the moment the value it was written for was bumped.
if (!preg_match('/^\d+\.\d+$/', $actual)) {
    fwrite(STDERR, "FAIL: Tested up to must be two-segment major.minor (got '{$actual}').\n");
    exit(1);
}

// THE LANE. A version tested by nothing is not tested.
$ci = (string) file_get_contents($plugin_root . '/.github/workflows/ci.yml');
if ('' === $ci) {
    fwrite(STDERR, "FAIL: cannot read .github/workflows/ci.yml\n");
    exit(1);
}
if (!preg_match('/^  standard:\s*$.*?(?=^  [a-zA-Z0-9_-]+:\s*$|\z)/ms', $ci, $matches)) {
    fwrite(STDERR, "FAIL: cannot find the Tier 2 standard job in .github/workflows/ci.yml\n");
    exit(1);
}
$tier_two      = $matches[0];
$tier_two_code = slimstat_yaml_strip_comments($tier_two);
$wp_lanes      = slimstat_ci_wp_lanes($tier_two);
if ('8.3' !== ($wp_lanes[$actual] ?? null) || false === strpos($tier_two_code, 'npm run test:e2e')) {
    fwrite(STDERR, "FAIL: readme.txt says Tested up to {$actual}, and no Tier 2 runtime lane "
        . "runs that WordPress on PHP 8.3.\n");
    exit(1);
}

// Pull requests should exercise the focused compatibility flow, while pushes may retain the
// full committed-baseline run. The job must also run for this repository's real default branch.
$required_ci_fragments = [
    "github.ref == 'refs/heads/master'",
    "github.base_ref == 'master'",
    '"${{ github.event_name }}" != "pull_request"',
    'npm run test:e2e -- --grep "@compat"',
];
foreach ($required_ci_fragments as $fragment) {
    if (false === strpos($tier_two_code, $fragment)) {
        fwrite(STDERR, "FAIL: Tier 2 compatibility job is missing required behavior: {$fragment}\n");
        exit(1);
    }
}

// VACUITY FLOOR: the matrix must have been found at all, or the check above passes on a job
// whose shape changed rather than on a compatibility matrix.
if (count($wp_lanes) < 5) {
    fwrite(STDERR, "FAIL: fewer than five `{ wp: \"x.y\" }` Tier 2 matrix entries found — the "
        . "scan has stopped reading the matrix, so the lane check above proves nothing.\n");
    exit(1);
}

echo "OK: readme.txt Tested up to = {$actual}; matching focused Tier 2 runtime lane verified\n";
