<?php
/**
 * Source-level enforcement of the CI matrix contract.
 *
 * The plugin declares "Requires PHP: N.N" in its header. Every supported PHP version in
 * [floor, current-stable] must be covered by CI — and the PR #307 audit says what "covered"
 * has to mean: 7.4 was lint-only and that masked a FATAL in admin/index.php. A version that
 * is never executed cannot surface a fatal.
 *
 * So coverage is counted in two kinds, per version and PER STEP:
 *
 *   EXECUTION — PHPUnit, or a Tier 2 E2E include pair. The plugin is loaded and run.
 *   STATIC    — source-level scans and the mutation registry. Real tests, on every lane,
 *               but they read source; they never execute the plugin.
 *
 * Per-step, because the earlier form of this scan asked whether the job block ANYWHERE
 * mentioned `composer test:` and credited every version in the matrix with it. Every PHPUnit
 * step in the fast job carries `if: matrix.php != '7.4' && matrix.php != '8.0'`, so the gate
 * written because "7.4 was lint-only" reported 7.4 as running runtime tests while PHPUnit
 * executed on neither version.
 *
 * A version with neither kind fails. A version with STATIC only fails unless it is named in
 * $execution_exempt with a reason — and an exemption that is no longer needed, or no longer
 * applies to a supported version, fails too, so the list cannot become a permission slip.
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

// Split into job blocks (each starts at `  name: "Tier`). Per-job: extract the PHP
// versions it runs, then credit each version only with the steps that ACTUALLY RUN for it.
//
// WHY PER-STEP, AND NOT PER-BLOCK. This scan used to ask whether the job block anywhere
// contained `composer test:` or `phpunit`, and credit every version in the matrix with it.
// Every test step in the `fast` job carries `if: matrix.php != '7.4' && matrix.php != '8.0'`,
// so 7.4 and 8.0 were credited with PHPUnit runs that are skipped for them — the gate written
// against "7.4 was lint-only, masking a fatal" could not see 7.4 being lint-only. It reported
// "covers PHP 7.4-8.5 with runtime tests" while PHPUnit executed on neither.
//
// Two kinds of coverage, kept apart, because conflating them is how the above happened:
//   EXECUTION — PHPUnit, or a Tier 2 E2E lane. The plugin is loaded and run. This is what
//               catches a runtime fatal, which is the defect this gate exists for.
//   STATIC    — source-level scans and the mutation registry. Real tests, and they run on
//               every lane, but they read source; they never execute the plugin.
$job_blocks = preg_split('/(?=^\s{2}\w+:\s*\n\s+name:\s*"Tier)/m', $ci_yaml);

// Does this step run for $version? BOTH conditional forms, and both quote styles.
//
// The first draft only understood `matrix.php != 'X'`. GitHub Actions also takes the
// inclusion form, `matrix.php == '8.2'`, which this file's own nightly wp-env / k6 / EXPLAIN
// steps all use — and an inclusion-gated step was credited to EVERY version in the matrix,
// so a PHPUnit step that runs on 8.2 alone would have satisfied 7.4 and 8.0. That is the
// same over-crediting the rewrite exists to end, reintroduced through the other operator.
// `==` is an allow-list: if a step names the versions it runs for, everything else is out.
$step_runs_for = static function (string $step_block, string $version): bool {
    if (!preg_match('/^[^\S\n]+if:.*$/m', $step_block, $m)) {
        return true; // no condition: runs on every version in the matrix
    }
    $cond = $m[0];

    if (preg_match_all('/matrix\.php\s*==\s*[\'"]([0-9.]+)[\'"]/', $cond, $inc) && [] !== $inc[1]) {
        return in_array($version, $inc[1], true);
    }

    return !preg_match('/matrix\.php\s*!=\s*[\'"]' . preg_quote($version, '/') . '[\'"]/', $cond);
};

$execution = [];
$static    = [];

foreach ($job_blocks as $block) {
    $versions = [];

    // Tier 1 / Tier 3 style: `php: ["7.4", ...]`.
    if (preg_match('/matrix:\s*(?:#[^\n]*\n\s*)*php:\s*\[([^\]]+)\]/', $block, $mm)
        && preg_match_all('/"([0-9.]+)"/', $mm[1], $vm)) {
        $versions = $vm[1];
    }

    // Tier 2 style: `include:` pairs of { wp: "6.4", php: "7.4" }. Invisible to the old
    // scan, which is why 7.4's only genuine EXECUTION coverage went uncounted.
    $is_e2e_lane = false;
    if ([] === $versions && preg_match_all('/\{\s*wp:\s*"[0-9.]+",\s*php:\s*"([0-9.]+)"\s*\}/', $block, $im)) {
        $versions    = array_unique($im[1]);
        $is_e2e_lane = true;
    }

    if ([] === $versions) continue;

    // Steps start at `      - name:`; the first chunk is the job preamble, not a step.
    //
    // Comment lines are stripped BEFORE any of the matching below, the same way
    // perf-gate-integrity-test.php strips them for the same reason. Caught in the act:
    // the first draft of this scan credited PHP 8.0 with executing the plugin because a
    // step COMMENT explaining a PHPUnit-related parse error contains the word "PHPUnit".
    // A scan for a name is satisfied by prose about that name — PITFALLS 8, reproduced
    // inside the rewrite whose entire purpose was to stop this gate over-crediting.
    // Anchored on `- ` at step indent, not `- name:`: a step whose first key is `uses:` is
    // valid YAML and would otherwise be folded into its predecessor. [ ] rather than \s so
    // the six characters cannot be satisfied across a newline.
    $steps = array_map(
        static function (string $step): string {
            return (string) preg_replace('/^\s*#.*$/m', '', $step);
        },
        preg_split('/(?=^[ ]{6}- )/m', $block)
    );

    // If the split degenerates, every step folds into one chunk and this scan silently
    // reverts to the per-block behaviour it was rewritten to replace — while still printing
    // OK. Assert the segmentation instead of trusting it.
    if (count($steps) < 3) {
        fwrite(STDERR, "FAIL: step splitting produced " . count($steps) . " chunk(s) for a job with "
            . count($versions) . " matrix version(s) — the per-step scan has reverted to per-job,\n"
            . "which is exactly the over-crediting this gate was rewritten to stop.\n");
        exit(1);
    }

    foreach ($versions as $v) {
        foreach ($steps as $step) {
            if (!$step_runs_for($step, $v)) continue;

            if (preg_match('/(vendor\/bin\/phpunit|\bphpunit\b|composer\s+test:(unit|integration|all))/', $step)) {
                $execution[$v] = true;
            } elseif (preg_match('/composer\s+test:/', $step)) {
                $static[$v] = true;
            }
        }
        // An E2E lane boots WordPress with the plugin active: execution by definition.
        if ($is_e2e_lane) $execution[$v] = true;
    }
}

// Accepted gap, declared rather than papered over. PHPUnit 10.5 requires PHP ^8.1, so no
// PHPUnit lane can exist below it; 7.4 gets its execution coverage from the Tier 2 E2E lanes
// instead. 8.0 appears in NO E2E pair, so it has static coverage only — a real hole, named
// here so it is visible in the gate's own output rather than hidden behind a green.
$execution_exempt = ['8.0' => 'PHPUnit needs PHP ^8.1 and no Tier 2 E2E pair uses 8.0; static scans only'];

$uncovered = array_diff($required_versions, array_keys($execution), array_keys($static));
if ($uncovered) {
    fwrite(STDERR, "FAIL: PHP versions claimed-supported but running NO tests of any kind in CI:\n");
    foreach ($uncovered as $v) fwrite(STDERR, "  - PHP {$v}\n");
    exit(1);
}

$no_execution = array_diff($required_versions, array_keys($execution));
$unexpected   = array_diff($no_execution, array_keys($execution_exempt));
if ($unexpected) {
    fwrite(STDERR, "FAIL: PHP versions with STATIC scans only — nothing executes the plugin, which is\n"
        . "the exact shape of the #307 fatal this gate exists to prevent:\n");
    foreach ($unexpected as $v) fwrite(STDERR, "  - PHP {$v}\n");
    fwrite(STDERR, "\nAdd a PHPUnit lane, or a Tier 2 E2E include pair, for that version.\n");
    exit(1);
}

// A stale exemption is its own defect: it would silently forgive a gap that has since closed.
$stale = array_intersect(array_keys($execution_exempt), array_keys($execution));
if ($stale) {
    fwrite(STDERR, "FAIL: exemption(s) no longer needed — these now have execution coverage, so remove\n"
        . "them from \$execution_exempt rather than leaving a permission slip behind: "
        . implode(', ', $stale) . "\n");
    exit(1);
}

// And an exemption for a version this plugin no longer supports is dead config nothing would
// otherwise complain about — it stops being scrutinised the moment it stops being reachable.
$orphaned = array_diff(array_keys($execution_exempt), $required_versions);
if ($orphaned) {
    fwrite(STDERR, "FAIL: exemption(s) for PHP version(s) outside the supported range ["
        . $floor . '-' . $ceiling . "] — delete them: " . implode(', ', $orphaned) . "\n");
    exit(1);
}

// The exempt version's excuse is that it still has STATIC coverage. Check that, rather than
// letting "exempt from execution" quietly become "exempt from everything".
foreach (array_keys($execution_exempt) as $exempt_version) {
    if (!isset($static[$exempt_version])) {
        fwrite(STDERR, "FAIL: PHP {$exempt_version} is exempt from execution coverage on the grounds that it "
            . "still runs static scans — and it no longer runs those either.\n");
        exit(1);
    }
}

ksort($execution);
ksort($static);
echo "OK: CI matrix, PHP {$floor}-{$ceiling}\n";
echo '  executes the plugin: [' . implode(', ', array_keys($execution)) . "]\n";
// $no_execution is a list of VERSIONS (array_diff preserves values, not a keyed set like
// $execution), so implode its values — array_keys() here printed the offset, "1".
echo '  static scans only  : [' . implode(', ', $no_execution) . "]\n";
foreach ($execution_exempt as $v => $why) {
    if (in_array($v, $no_execution, true)) echo "    - {$v}: {$why}\n";
}
