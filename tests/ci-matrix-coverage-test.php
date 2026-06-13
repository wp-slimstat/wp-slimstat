<?php
/**
 * Source-level enforcement of the CI matrix contract.
 *
 * The plugin declares "Requires PHP: N.N" in its header. Every supported PHP
 * version in [floor, current-stable] must appear in at least one CI tier
 * matrix whose job runs PHPUnit or a composer test:* script — not lint-only.
 * The PR #307 audit surfaced that 7.4 was previously lint-only, masking a
 * fatal in admin/index.php; this test prevents that gap from re-opening.
 *
 * @see https://github.com/wp-slimstat/wp-slimstat/issues/303 (audit follow-up)
 */

declare(strict_types=1);

$plugin_root = dirname(__DIR__);

$header = file_get_contents($plugin_root . '/wp-slimstat.php');
if ($header === false) {
    fwrite(STDERR, "FAIL: cannot read wp-slimstat.php (Requires PHP source of truth)\n");
    exit(1);
}
if (!preg_match('/^\s*\*\s*Requires PHP:\s*([0-9]+\.[0-9]+)/m', $header, $hm)) {
    fwrite(STDERR, "FAIL: no `Requires PHP: N.N` in wp-slimstat.php header\n");
    exit(1);
}
$floor = $hm[1];

// Ceiling — current PHP stable. Bumped manually when a new major.minor ships;
// not derivable from plugin metadata.
$ceiling = '8.5';

$required_versions = [];
[$fmaj, $fmin] = array_map('intval', explode('.', $floor));
[$cmaj, $cmin] = array_map('intval', explode('.', $ceiling));
for ($maj = $fmaj; $maj <= $cmaj; $maj++) {
    $minStart = ($maj === $fmaj) ? $fmin : 0;
    $minEnd   = ($maj === $cmaj) ? $cmin : 99;
    for ($min = $minStart; $min <= $minEnd; $min++) {
        if ($maj === 7 && $min > 4) break; // 7.4 → 8.0 jump
        $required_versions[] = "{$maj}.{$min}";
    }
}

$ci_yaml = file_get_contents($plugin_root . '/.github/workflows/ci.yml');
if ($ci_yaml === false) {
    fwrite(STDERR, "FAIL: cannot read .github/workflows/ci.yml\n");
    exit(1);
}

// Split into job blocks (each starts at `  name: "Tier`). Per-job: extract its
// matrix.php list AND check the job body invokes PHPUnit or composer test:*.
// This guards the actual risk (lint-only coverage masks runtime fatals).
$job_blocks = preg_split('/(?=^\s{2}\w+:\s*\n\s+name:\s*"Tier)/m', $ci_yaml);

$covered_with_runtime = [];
foreach ($job_blocks as $block) {
    if (!preg_match('/matrix:\s*(?:#[^\n]*\n\s*)*php:\s*\[([^\]]+)\]/', $block, $mm)) continue;
    $runs_real_tests = (bool) preg_match('/(composer\s+test:|vendor\/bin\/phpunit|\bphpunit\b)/', $block);
    if (!$runs_real_tests) continue;
    if (preg_match_all('/"([0-9.]+)"/', $mm[1], $vm)) {
        foreach ($vm[1] as $v) $covered_with_runtime[$v] = true;
    }
}

$missing = array_diff($required_versions, array_keys($covered_with_runtime));
if ($missing) {
    fwrite(STDERR, "FAIL: PHP versions claimed-supported but absent from a CI matrix that runs real tests:\n");
    foreach ($missing as $v) fwrite(STDERR, "  - PHP {$v}\n");
    fwrite(STDERR, "\nAdd to .github/workflows/ci.yml `matrix.php` in a tier whose job runs `composer test:*` or `phpunit`.\n");
    exit(1);
}

ksort($covered_with_runtime);
echo "OK: CI matrix covers PHP {$floor}–{$ceiling} [" . implode(', ', array_keys($covered_with_runtime)) . "] with runtime tests\n";
