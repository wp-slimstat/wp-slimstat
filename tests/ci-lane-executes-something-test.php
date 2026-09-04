<?php
/**
 * No CI lane reports success having executed nothing.
 *
 * Tier 3's PHP 7.4 and 8.0 lanes existed for months and ran two steps: checkout and setup-php.
 * Every test step carried `if: matrix.php != '7.4' && matrix.php != '8.0'`, so both reached
 * nothing, printed 170 log lines, and reported success. The lanes were deleted; this is the gate
 * that stops the next one: for every job × matrix cell ci.yml declares, at least one step that
 * RUNS for that cell (slimstat_ci_step_runs_for — `==` allow-list, `!=` deny-list) invokes a
 * test command. Lint is not a test command: a lane whose only step is lint is the lane this
 * exists to catch.
 *
 * Run: php tests/ci-lane-executes-something-test.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit(1);
}

error_reporting(E_ALL);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

$ci_code = slimstat_yaml_strip_comments((string) file_get_contents($plugin_root . '/.github/workflows/ci.yml'));

// A denylist of shapes, and it says so: composer/npm test scripts, PHPUnit, k6, the EXPLAIN
// gate, and any invocation of a `tests/…-test.php` script by any path (a container path such
// as `wp-content/plugins/wp-slimstat/tests/x-test.php` counts).
$test_command = '/(composer\s+test|vendor\/bin\/phpunit|\bphpunit\b|npm\s+run\s+test|k6\s+run|explain-gate\.sh|(^|[\s\/])tests\/[a-z0-9-]+-test\.php)/';

$jobs_seen = 0;
$cells     = 0;

foreach (slimstat_ci_job_blocks($ci_code) as $job_id => $block) {
    $job_cells = slimstat_ci_matrix_cells($block);
    if ([] === $job_cells) {
        continue;
    }
    $jobs_seen++;

    $job_name   = preg_match('/^\s+name:\s*"([^"]+)"/m', $block, $nm) ? $nm[1] : $job_id;
    $test_steps = preg_grep($test_command, slimstat_ci_steps($block)) ?: [];

    foreach ($job_cells as [$key, $value]) {
        $cells++;
        foreach ($test_steps as $step) {
            if (slimstat_ci_step_runs_for($step, $key, $value)) {
                continue 2;
            }
        }
        $failures[] = sprintf('lane "%s · %s %s" reaches no step that runs a test command — every test '
            . 'step is conditioned away for it. It checks out, sets up PHP, and reports success '
            . 'having executed nothing', $job_name, $key, $value);
    }
}

// VACUITY FLOOR, derived rather than remembered: count matrix declarations and cells with an
// independent regex over the same text, and require the walk above to have seen every one.
$declared_matrices = preg_match_all('/^\s+strategy:\s*$/m', $ci_code);
$declared_cells    = 0;
if (preg_match_all('/matrix:\s*php:\s*\[([^\]]+)\]/', $ci_code, $lists)) {
    foreach ($lists[1] as $list) {
        $declared_cells += preg_match_all('/"[0-9.]+"/', $list);
    }
}
$declared_cells += preg_match_all('/\{\s*wp:\s*"[0-9.]+",\s*php:\s*"[0-9.]+"\s*\}/', $ci_code);

if ($jobs_seen !== $declared_matrices || $cells !== $declared_cells || $cells < 12) {
    $failures[] = sprintf('the walk saw %d matrix job(s) / %d cell(s); an independent count of ci.yml '
        . 'finds %d / %d. The two must agree, or a job the split dropped is a lane this gate never '
        . 'looked at', $jobs_seen, $cells, $declared_matrices, $declared_cells);
}

if ($failures) {
    fwrite(STDERR, 'FAIL: CI lane executes something (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo sprintf("PASS: every one of %d matrix cells across %d jobs reaches at least one step that runs a test command\n", $cells, $jobs_seen);
