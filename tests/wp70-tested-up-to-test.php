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

$lane         = sprintf('- { wp: "%s", php: "8.3" }', $expected);
$job_blocks   = preg_split('/(?=^\s{2}\w+:\s*\n\s+name:\s*"Tier)/m', $ci);
$runtime_lane = false;
foreach ($job_blocks as $block) {
	if (false === strpos($block, $lane)) continue;
	$runtime_lane = (bool) preg_match('/(?:npm\s+run\s+test:e2e|playwright\s+test)/', $block);
	break;
}
if (!$runtime_lane) {
	fwrite(STDERR, "FAIL: CI has no WordPress {$expected} / PHP 8.3 lane in a runtime E2E job.\n");
	exit(1);
}
if (false === strpos($ci, 'resolve-wordpress-ref.sh')) {
	fwrite(STDERR, "FAIL: CI does not use the tested WordPress reference resolver.\n");
	exit(1);
}
if (false === strpos($ci, "github.base_ref == 'master'")) {
	fwrite(STDERR, "FAIL: the compatibility job does not run for pull requests targeting master.\n");
	exit(1);
}
if (false === strpos($ci, '"${{ github.event_name }}" != "pull_request"')) {
	fwrite(STDERR, "FAIL: pull requests are not restricted to the focused compatibility smoke.\n");
	exit(1);
}

$resolver = $plugin_root . '/.github/scripts/resolve-wordpress-ref.sh';
$fixtures = [
	'tag-present' => [
		'tag'        => 'refs/tags/7.1',
		'branch'     => '',
		'ref'        => '7.1',
		'git_status' => 0,
		'status'     => 0,
	],
	'branch-only' => [
		'tag'        => '',
		'branch'     => 'refs/heads/7.1-branch',
		'ref'        => '7.1-branch',
		'git_status' => 0,
		'status'     => 0,
	],
	'no-ref' => [
		'tag'        => '',
		'branch'     => '',
		'ref'        => 'trunk',
		'git_status' => 0,
		'status'     => 0,
	],
	'lookup-error' => [
		'tag'        => '',
		'branch'     => '',
		'ref'        => '',
		'git_status' => 28,
		'status'     => 1,
	],
];

foreach ($fixtures as $name => $fixture) {
	$tmp = $plugin_root . '/tests/.wp-ref-' . bin2hex(random_bytes(8));
	if (!mkdir($tmp, 0700)) {
		fwrite(STDERR, "FAIL: cannot create resolver fixture directory.\n");
		exit(1);
	}
	$git = "#!/usr/bin/env bash\nset -eu\nif [ " . (int) $fixture['git_status'] . " -ne 0 ]; then exit " . (int) $fixture['git_status'] . "; fi\nif [ \"\$1\" != \"ls-remote\" ]; then exit 2; fi\ncase \"\$2\" in\n  --tags) printf '%s\\n' " . escapeshellarg($fixture['tag']) . " ;;\n  --heads) printf '%s\\n' " . escapeshellarg($fixture['branch']) . " ;;\n  *) exit 2 ;;\nesac\n";
	file_put_contents($tmp . '/git', $git);
	chmod($tmp . '/git', 0700);
	$command = 'GIT_COMMAND=' . escapeshellarg($tmp . '/git') . ' bash ' . escapeshellarg($resolver) . ' 7.1 2>/dev/null';
	$output  = [];
	$status  = 0;
	exec($command, $output, $status);
	unlink($tmp . '/git');
	rmdir($tmp);
	$ref = implode("\n", $output);
	if ($fixture['status'] !== $status || $fixture['ref'] !== $ref) {
		fwrite(STDERR, "FAIL: {$name} resolver fixture returned '{$ref}' (status {$status}), expected '{$fixture['ref']}' (status {$fixture['status']}).\n");
		exit(1);
	}
}

echo "OK: readme.txt Tested up to = {$actual}; matching runtime CI lane and resolver behavior verified\n";
