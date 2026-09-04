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

require_once __DIR__ . '/lib/source-scan.php';  // hoisted: the condition rule now lives in the lib and is called above the old require
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
$job_blocks = slimstat_ci_job_blocks($ci_yaml); // any two-space key, not the "Tier" naming convention

// Does this step run for $version? BOTH conditional forms, and both quote styles.
//
// The first draft only understood `matrix.php != 'X'`. GitHub Actions also takes the
// inclusion form, `matrix.php == '8.2'`, which this file's own nightly wp-env / k6 / EXPLAIN
// steps all use — and an inclusion-gated step was credited to EVERY version in the matrix,
// so a PHPUnit step that runs on 8.2 alone would have satisfied 7.4 and 8.0. That is the
// same over-crediting the rewrite exists to end, reintroduced through the other operator.
// `==` is an allow-list: if a step names the versions it runs for, everything else is out.
// The rule lives in the lib (slimstat_ci_step_runs_for, with fixtures in source-scan-strength);
// the history above is why it understands both operators.

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
            if (!slimstat_ci_step_runs_for($step, 'php', $v)) continue;

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

// ── The Tier 2 lane installs the plugins the suite gates on ───────────────────────────────
//
// .wp-env.json declares four plugins. The CI override used to rewrite that to `["."]`, and the
// siblings were not checked out, so between 49 and 130 of the 724 E2E tests referenced or
// gated on plugins the lane did not have. They did not FAIL — they self-skipped, so nothing
// went red and the lane looked healthy.
//
// That matters most for what comes next. A quarantine census taken on that configuration
// measures CI setup rather than test health, and a ceiling derived from it locks the wrong
// denominator in permanently: every later plugin addition would lower the ceiling with no
// defect fixed. So this runs BEFORE the census, and it is why E0 is first in that workstream.
//
// An omission is allowed, but only as a DECLARATION with a reason — the same shape Pro's
// $standalone list uses. "Pro is absent" then reads as a decision someone made, which it is,
// rather than as an accident nobody noticed.
require_once __DIR__ . '/lib/source-scan.php';

$wp_env_path = $plugin_root . '/.wp-env.json';
$wp_env      = json_decode((string) file_get_contents($wp_env_path), true);

if (!is_array($wp_env) || !isset($wp_env['plugins']) || !is_array($wp_env['plugins'])) {
    fwrite(STDERR, "FAIL: .wp-env.json declares no plugins array — this gate reads it as the\n"
        . "authority on what the E2E suite expects to be installed.\n");
    exit(1);
}

// Keyed by the plugin's directory basename, which is the one spelling shared by a sibling
// path ("../wp-consent-api"), a wordpress.org zip URL, and a slug.
$declared_plugins = [];
foreach ($wp_env['plugins'] as $entry) {
    if ('.' === $entry) {
        continue; // the plugin under test; the lane always has it
    }
    $declared_plugins[] = basename(rtrim((string) $entry, '/'));
}

// A PARSE GUARD, not a census. It was `count($declared_plugins) < 3`, calibrated to today's
// exact number — which made the orphan check below unreachable: removing ../wp-slimstat-pro
// from .wp-env.json, the one realistic way to orphan its omission, exited HERE instead, with a
// message blaming the parser for a deliberate edit. A floor doing two jobs does the second
// one badly.
if ([] === $wp_env['plugins'] || !in_array('.', $wp_env['plugins'], true)) {
    fwrite(STDERR, "FAIL: .wp-env.json's plugins array does not include \".\" — the plugin under\n"
        . "test. The scan has stopped reading the file it treats as the authority.\n");
    exit(1);
}

// Declared omissions: what the lane deliberately does not install, and why. A name here is a
// claim someone has to defend in review; a name missing from BOTH here and the lane is the
// silent gap this gate exists to end.
$ci_plugin_omissions = [
    'wp-slimstat-pro' => 'a private repository. Installing it in CI means giving this lane a '
        . 'deploy key, which is a decision about credentials rather than about coverage — so '
        . 'it stays out, and the E2E census carves the Pro-gated specs out of its denominator '
        . 'rather than counting them as skips nobody chose.',
];

// COMMENTS STRIPPED, AND SCOPED TO THE STEP THAT BUILDS THE SET. Both halves were learned the
// hard way, in this order: the first draft searched the whole Tier 2 block WITH its comments,
// and dropping the consent plugins from the lane left it green — because another step's
// comment mentions them by name. That is this file's own recorded defect (see the step-split
// note above, where a comment containing "PHPUnit" credited PHP 8.0 with executing the plugin),
// reproduced in the gate written after it.
$override_step = '';
foreach (slimstat_ci_steps(slimstat_yaml_strip_comments($ci_yaml)) as $step) {
    if (false !== strpos($step, '.wp-env.override.json') && false !== strpos($step, 'WP_ENV_PHP_VERSION')) {
        $override_step = $step;
        break;
    }
}

if ('' === $override_step) {
    fwrite(STDERR, "FAIL: no ci.yml step builds a Tier 2 .wp-env.override.json — the plugin-set\n"
        . "check below would pass by having nothing to read.\n");
    exit(1);
}

$missing = [];
foreach ($declared_plugins as $slug) {
    if (false !== strpos($override_step, $slug)) {
        continue;
    }
    if (isset($ci_plugin_omissions[$slug])) {
        continue;
    }
    $missing[] = $slug;
}

if ($missing) {
    fwrite(STDERR, "FAIL: .wp-env.json declares plugin(s) the Tier 2 E2E lane does not install,\n"
        . "and which are not declared omissions:\n");
    foreach ($missing as $slug) {
        fwrite(STDERR, "  - {$slug}\n");
    }
    fwrite(STDERR, "Install them in the lane, or add them to \$ci_plugin_omissions WITH A REASON.\n"
        . "Specs that gate on a plugin the lane lacks do not fail — they self-skip, so the suite\n"
        . "stays green while a tenth of it never runs.\n");
    exit(1);
}

// An omission for a plugin nothing declares any more is a permission slip for a decision
// nobody is making — the same staleness $execution_exempt is checked for above.
$orphan_omissions = array_diff(array_keys($ci_plugin_omissions), $declared_plugins);
if ($orphan_omissions) {
    fwrite(STDERR, "FAIL: \$ci_plugin_omissions names plugin(s) .wp-env.json no longer declares —\n"
        . "delete them rather than leaving an excuse behind: " . implode(', ', $orphan_omissions) . "\n");
    exit(1);
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
