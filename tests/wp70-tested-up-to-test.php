<?php
/**
 * Source-level: the readme.txt "Tested up to:" header matches the expected
 * WordPress version, and is a major.minor as required by WordPress.org.
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
$expected    = '7.1'; // WordPress.org requires major.minor; CI pins the tested patch release.

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
// The public header uses major.minor; the CI lane may pin a patch.
if (!preg_match('/^\d+\.\d+$/', $actual)) {
    fwrite(STDERR, "FAIL: Tested up to must be a WordPress release version (got '{$actual}').\n");
    exit(1);
}

// THE LANE. A version tested by nothing is not tested.
$ci = slimstat_yaml_strip_comments((string) file_get_contents($plugin_root . '/.github/workflows/ci.yml'));
if ('' === $ci) {
    fwrite(STDERR, "FAIL: cannot read .github/workflows/ci.yml\n");
    exit(1);
}
$ci_jobs = slimstat_ci_job_blocks($ci);
if (!isset($ci_jobs['standard'])) {
	fwrite(STDERR, "FAIL: cannot find the Tier 2 standard job in .github/workflows/ci.yml\n");
	exit(1);
}
$tier_two      = $ci_jobs['standard'];
$tier_two_code = slimstat_yaml_strip_comments($tier_two);
$wp_lanes      = slimstat_ci_wp_lanes($tier_two);
$tested_lane   = false;
foreach ($wp_lanes as $wp_version => $php_version) {
	if (($actual === $wp_version || 0 === strpos($wp_version, $actual . '.')) && '8.3' === $php_version) {
		$tested_lane = true;
		break;
	}
}
if (!$tested_lane || false === strpos($tier_two_code, 'npm run test:e2e')) {
	fwrite(STDERR, "FAIL: readme.txt says Tested up to {$actual}, and no focused Tier 2 runtime "
		. "lane runs that WordPress release on PHP 8.3.\n");
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
