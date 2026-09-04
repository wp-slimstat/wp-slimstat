<?php
/**
 * Source-level enforcement of the performance-gate contract.
 *
 * The nightly perf lane failed 10 consecutive nights (2026-07-18 → 07-27) and
 * produced a green "EXPLAIN gate" the entire time, because:
 *
 *   1. wp-env boots the plugin on the PHP 7.4 runtime lane, but `composer
 *      install` had left PHPUnit 10.5 in vendor/. PHPUnit registers
 *      Framework/Assert/Functions.php via Composer's files-autoload, which
 *      vendor/autoload.php require()s at boot — and its union types are a
 *      parse error on 7.4. `wp-env start` therefore never came up.
 *   2. CI exported K6_BASE_URL while every k6 script reads __ENV.BASE_URL, so
 *      k6 silently fell back to a developer's laptop URL.
 *   3. The EXPLAIN gate was `echo "[TODO]…"; exit 0` — it asserted nothing
 *      while reporting success.
 *
 * A perf gate that cannot fail is worse than no gate: it converts "unmeasured"
 * into "measured and fine". This test keeps all three holes closed.
 *
 * @see jaan-to/outputs/dev/v6-performance/ (Scorecard baseline)
 */

declare(strict_types=1);

// Adopted for sections 8-11. slimstat_ci_steps()'s docblock names THIS file as the one that
// still splits ci.yml on `- name:` — a dialect that folds a step whose first key is `uses:`
// into its predecessor, so a scan scoped to "this step" silently reads two.
//
// Sections 1-7 keep their inline splitting for now, and that is a deferral rather than a
// judgement: each is load-bearing and carries its own mutation, so re-pointing them is a change
// that owes its own required-red. The cost of the deferral is real and worth naming — §3 and
// §11 locate the SAME EXPLAIN step under different splitters, and agree only because no step
// in this file currently leads with `uses:` at six-space indent.
require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

$ci_path = $plugin_root . '/.github/workflows/ci.yml';
$ci_yaml = file_get_contents($ci_path);
if ($ci_yaml === false) {
    fwrite(STDERR, "FAIL: cannot read .github/workflows/ci.yml\n");
    exit(1);
}

// ── 1. k6 env var: what CI exports must be what the scripts read ────────────
// Split ci.yml into step blocks once; both section 1 and section 3 walk them.
$ci_steps = preg_split('/(?=^      - name:)/m', $ci_yaml) ?: [];

$perf_dir   = $plugin_root . '/tests/perf';
$k6_scripts = glob($perf_dir . '/*.js') ?: [];

if ($k6_scripts === []) {
    $failures[] = 'no k6 scripts found under tests/perf/ — the perf lane has nothing to run';
}

// __ENV reads may live in a shared helper (tests/perf/lib/env.js) rather than
// in the scripts themselves, so scan the helpers too — otherwise centralising
// env handling would look like "nothing reads this variable".
$env_readers = array_merge($k6_scripts, glob($perf_dir . '/lib/*.js') ?: []);

$read_vars = [];
foreach ($env_readers as $script) {
    $src = (string) file_get_contents($script);
    if (preg_match_all('/__ENV\.([A-Z0-9_]+)/', $src, $m)) {
        foreach ($m[1] as $var) {
            $read_vars[$var] = true;
        }
    }
    // Helpers commonly read env through a lookup table keyed by name
    // (resolve('BASE_URL')), which the __ENV.<NAME> pattern cannot see.
    if (preg_match_all('/\bresolve\(\s*[\'"]([A-Z0-9_]+)[\'"]/', $src, $rm)) {
        foreach ($rm[1] as $var) {
            $read_vars[$var] = true;
        }
    }
}

// Env names exported by any step that runs k6 (`npm run test:perf` or `k6 run`).
$exported_vars = [];
foreach ($ci_steps as $step) {
    if (!preg_match('/(npm run test:perf|k6 run)/', $step)) {
        continue;
    }
    // Everything between `env:` and the step's `run:` — tolerant of comments,
    // blank lines and quoted values without needing an alternation per shape.
    if (preg_match('/\n\s+env:\s*\n(.*?)\n\s+run:/s', $step, $em)
        && preg_match_all('/^\s+([A-Z0-9_]+):/m', $em[1], $vm)
    ) {
        foreach ($vm[1] as $var) {
            $exported_vars[$var] = true;
        }
    }
}

foreach (array_keys($exported_vars) as $exported) {
    // K6_* is not a k6 convention for user variables; k6 reads __ENV.<NAME>
    // verbatim. An exported name no script reads is a silent no-op.
    if (!isset($read_vars[$exported])) {
        $failures[] = sprintf(
            'CI exports %s to the k6 step, but no script reads __ENV.%s — '
                . 'the value is silently ignored and k6 falls back to its default host',
            $exported,
            $exported
        );
    }
}

// Unguarded on purpose: if the k6 step loses its `env:` block entirely,
// $exported_vars is empty — and that is exactly the failure to catch, not a
// reason to skip the check.
if (isset($read_vars['BASE_URL']) && !isset($exported_vars['BASE_URL'])) {
    $failures[] = 'k6 scripts read __ENV.BASE_URL but no CI step running k6 exports BASE_URL '
        . '(this is how the K6_BASE_URL typo went unnoticed)';
}

// ── 2. No developer-machine fallbacks in k6 scripts ─────────────────────────
// A localhost:10003 / hardcoded-credential fallback means a misconfigured CI
// run targets nothing and still reports timings.
foreach ($k6_scripts as $script) {
    $src  = (string) file_get_contents($script);
    $name = basename($script);

    if (preg_match('/__ENV\.\w+\s*\|\|\s*[\'"]https?:\/\/localhost:10003/', $src)) {
        $failures[] = "{$name}: falls back to http://localhost:10003 (a Local WP dev install) "
            . 'when BASE_URL is unset — CI must fail loudly instead of measuring nothing';
    }
    if (preg_match('/__ENV\.WP_(?:USER|PASS)\s*\|\|\s*[\'"]([^\'"]+)[\'"]/', $src, $cm)) {
        $failures[] = "{$name}: hardcoded credential fallback '{$cm[1]}' — "
            . 'credentials must come from the environment with no default';
    }
}

// ── 3. The EXPLAIN gate must exist, and must be able to fail ────────────────
$explain_steps = 0;
foreach ($ci_steps as $step) {
    if (!preg_match('/^      - name:\s*(.*EXPLAIN.*)$/m', $step, $nm)) {
        continue;
    }
    $explain_steps++;
    $step_name = trim($nm[1]);

    if (preg_match('/\bexit 0\b/', $step)) {
        $failures[] = "CI step \"{$step_name}\" contains an unconditional `exit 0` — "
            . 'it reports success without asserting anything';
    }
    if (preg_match('/\[TODO\]|not yet implemented/i', $step)) {
        $failures[] = "CI step \"{$step_name}\" is still a TODO placeholder";
    }
    if (!preg_match('/explain-gate\.sh/', $step)) {
        $failures[] = "CI step \"{$step_name}\" does not invoke tests/perf/explain-gate.sh";
    }
}

// Deleting the step is the cheapest way to turn the gate back into a no-op,
// so absence has to fail too — checking only the step's *contents* would let
// removal pass silently.
if ($explain_steps === 0) {
    $failures[] = 'no CI step mentions EXPLAIN — the full-table-scan gate has been removed';
}

$gate_script = $plugin_root . '/tests/perf/explain-gate.sh';
if (!file_exists($gate_script)) {
    $failures[] = 'tests/perf/explain-gate.sh does not exist (ci.yml invokes this path)';
}

// ── 4. wp-env must not boot PHP-8-only dev code on a PHP 7.4 runtime ────────
// The invariant: when wp-env starts, vendor/autoload.php must not pull code
// that the container's PHP cannot parse. PHPUnit 10.5 reaches autoload_files.php
// and its union types fatal on 7.4.
//
// Deliberately asserts the *invariant*, not one particular remedy. Two fixes
// satisfy it and both must pass: pruning dev deps before boot (--no-dev on
// install or dump-autoload), or raising the container's PHP above 8.0 via a
// .wp-env.override.json. An earlier draft of this check hard-coded the literal
// `composer install --no-dev`, which would have failed the better fix — a guard
// that makes the correct repair harder than the wrong one is a bug in the guard.
$committed_php = '0';
$wp_env_json   = @file_get_contents($plugin_root . '/.wp-env.json');
if ($wp_env_json !== false) {
    $decoded = json_decode($wp_env_json, true);
    if (is_array($decoded) && isset($decoded['phpVersion'])) {
        $committed_php = (string) $decoded['phpVersion'];
    }
}

foreach (preg_split('/(?=^  \w[\w-]*:\s*\n\s+name:)/m', $ci_yaml) as $job) {
    $wp_env_pos = strpos($job, 'wp-env start');
    if ($wp_env_pos === false) {
        continue;
    }
    preg_match('/^\s+name:\s*"?([^"\n]+)"?/m', $job, $jm);
    $job_name = isset($jm[1]) ? trim($jm[1]) : 'unnamed job';

    // Does this job install dev dependencies before booting wp-env?
    if (!preg_match('/composer install(?![^\n]*--no-dev)/', $job, $im, PREG_OFFSET_CAPTURE)
        || $im[0][1] > $wp_env_pos
    ) {
        continue; // no dev deps in vendor/ at boot — safe
    }

    $before = substr($job, 0, $wp_env_pos);

    // Remedy A — dev code pruned from the autoloader before boot.
    $pruned = (bool) preg_match('/composer (?:install|dump-autoload)[^\n]*--no-dev/', $before);

    // Remedy B — the container runs a PHP that can parse it.
    $raised = false;
    if (preg_match('/\.wp-env\.override\.json/', $before)
        && preg_match_all('/"phpVersion"\s*:\s*"?(?:%s|(\d+\.\d+))/', $before, $vm)
    ) {
        // A literal version must be >= 8.1; a "%s"/matrix placeholder is
        // resolved from the lane, which cannot be below the tested floor.
        $raised = true;
        foreach ($vm[1] as $literal) {
            if ($literal !== '' && version_compare($literal, '8.1', '<')) {
                $raised = false;
                break;
            }
        }
    }

    if (!$pruned && !$raised) {
        $failures[] = sprintf(
            'job "%s" installs dev dependencies and then runs `wp-env start`, but neither '
                . 'prunes them from the autoloader (composer install/dump-autoload --no-dev) '
                . 'nor raises the container PHP above 8.0 (.wp-env.override.json). '
                . '.wp-env.json pins phpVersion %s, where PHPUnit\'s files-autoload is a '
                . 'parse error — the container will not boot',
            $job_name,
            $committed_php
        );
    }
}

// ── 5. The wp.org deploy is gated on green CI for the tagged commit ────────
// main.yml is tag-triggered and, until the Lane I audit (Run 36), deployed with NO
// reference to CI at all: a tag on a red commit shipped. Tag workflows cannot
// `needs:` across workflow files, so the gate is a step that queries the commit's
// check runs and fails closed; this assertion pins that the step exists and that it
// can refuse (an exit 1 in the same step).
$main_path = $plugin_root . '/.github/workflows/main.yml';
$main_yaml = (string) @file_get_contents($main_path);
// Comment lines stripped BEFORE searching: a commented-out gate step still contains
// `check-runs` and `exit 1`, and review demonstrated the unstripped check passing on
// exactly that — the deploy fully ungated again while this assertion stayed green.
$main_code = (string) preg_replace('/^\s*#.*$/m', '', $main_yaml);

// The gate asks GitHub about THIS commit and refuses on anything but success. It used to be
// pinned on the literal `check-runs`, which named one endpoint rather than the property.
// That endpoint turned out to be the wrong one: the nightly cron re-runs CI on the default
// branch and every if:-skipped job records another check run against the same SHA (~700 on
// master, 69 of them `skipped Static analysis · PHPStan`), so `[...] | unique` yields
// "skipped,success" and the gate refuses every deploy. The deploy now scopes to a single
// workflow RUN for the commit instead. So this pins the three properties that make it a
// gate — it asks about GITHUB_SHA, it reads a conclusion, it can refuse — and not the URL.
$gate_properties = [
    'gh api'      => 'queries the GitHub API',
    'GITHUB_SHA'  => 'asks about the tagged commit specifically',
    'conclusion'  => 'reads the lanes\' conclusions rather than merely that they exist',
    'exit 1'      => 'can refuse the deploy',
];
if ('' === $main_yaml) {
    $failures[] = 'cannot read .github/workflows/main.yml — the deploy workflow is unauditable';
} else {
    $missing_properties = [];
    foreach ($gate_properties as $needle => $property) {
        if (false === strpos($main_code, $needle)) {
            $missing_properties[] = "{$property} (no `{$needle}` in code)";
        }
    }
    if ($missing_properties) {
        $failures[] = 'main.yml deploys to WordPress.org without a working CI gate — a tag on a red '
            . 'commit ships. The deploy job needs a step (CODE, not a comment) that: '
            . implode('; ', $missing_properties);
    }
}

// ── 6. Every k6 script is wired somewhere; an unreferenced script measures nothing ──
// Five of six scripts sat orphaned while "npm run test:perf" ran exactly one — coverage
// that reads as "perf tested" while five scenarios never execute anywhere.
// The same $k6_scripts set sections 1-2 already walk — a second glob here could
// silently drift from it. npm scripts may glob; a literal `tests/perf/*.js` loop wires
// every script at once and the strpos below honours it explicitly.
$pkg = json_decode((string) @file_get_contents($plugin_root . '/package.json'), true);
$referenced_blob = $ci_yaml . "\n" . $main_yaml . "\n"
    . json_encode($pkg['scripts'] ?? [], JSON_UNESCAPED_SLASHES);
foreach ($k6_scripts as $k6_file) {
    $base = basename($k6_file);
    if (false === strpos($referenced_blob, $base) && false === strpos($referenced_blob, 'tests/perf/*.js')) {
        $failures[] = "tests/perf/{$base} is referenced by no npm script and no workflow — "
            . 'an orphaned scenario reads as coverage while never executing';
    }
}

// ── 7. continue-on-error demands a stated reason beside it ─────────────────
// One deliberate instance exists (Tier-2 E2E, reason in the adjacent comment). The shape
// to prevent is the SILENT one: a gate that stops failing with nothing explaining why.
foreach (explode("\n", $ci_yaml) as $i => $line) {
    if (false === strpos($line, 'continue-on-error: true')) {
        continue;
    }
    // Any '#' is too weak in a file that is 47%% comment lines — a section divider
    // within six lines satisfied it. The non-brittle demand: the comment must mention
    // the SETTING it excuses (the one legitimate instance already does).
    $context = implode("\n", array_slice(explode("\n", $ci_yaml), max(0, $i - 6), 6));
    if (!preg_match('/#[^\n]*continue-on-error/', $context)) {
        $failures[] = sprintf(
            'ci.yml line %d sets continue-on-error without an adjacent comment naming the '
            . 'setting and its reason — a gate that stops failing silently is the vacuity '
            . 'this file exists to prevent',
            $i + 1
        );
    }
}

// ── 8. The XSS escaping gate runs, is blocking, and covers every blocking WP ───
//
// tests/reports-output-escaping-test.php is 56 escaping assertions over the report render
// path, and it is a member of `composer test:all` and of nothing else. No job runs test:all —
// so for the whole v6 programme it had exactly one CI home, the Tier 2 step below, and before
// that step existed it had none. Deleting those lines returns it to running nowhere, which is
// the state it was just rescued from, and nothing would have said so.
//
// The condition is checked against the MATRIX rather than against a remembered pair: it read
// `6.4 || 7.0` while a 7.1 lane was added, which would have quietly stopped covering the
// newest WordPress — the one the readme claims to be tested against.
// COMMENTS STRIPPED, ONCE, FOR EVERY SECTION BELOW. Both halves of this were demonstrated
// against the first draft, in opposite directions:
//
//   §8 gave a false PASS — the escaping condition was narrowed to drop the newest lane AND a
//      comment mentioning the dropped version was added, and the strpos found the version in
//      the comment. The defect was present and the gate was green.
//   §9 gave a false FAIL — rewording a ci.yml COMMENT from `WP_REF=trunk` to `WP_REF="trunk"`,
//      with no code touched at all, made the gate report a trunk fallback.
//
// This is the same shape ci-matrix-coverage-test.php records against itself ("a step COMMENT
// containing the word PHPUnit credited PHP 8.0 with executing the plugin") — reproduced three
// sections away from the E0 gate where I had just fixed it, in the same commit.
$ci_code = slimstat_yaml_strip_comments($ci_yaml);

$steps = slimstat_ci_steps($ci_code);

$escaping_steps = slimstat_ci_steps_containing($steps, 'reports-output-escaping-test.php');
$escaping_step  = 1 === count($escaping_steps) ? $escaping_steps[0] : '';

if ('' === $escaping_step) {
    $failures[] = 'no ci.yml step runs tests/reports-output-escaping-test.php. It is in '
        . '`composer test:all` and nothing else, and no job runs test:all — so deleting that '
        . 'step returns 56 XSS assertions to running nowhere';
} else {
    if (false !== strpos($escaping_step, 'continue-on-error')) {
        $failures[] = 'the escaping gate is soft. An XSS gate that cannot fail the build is a '
            . 'report, not a gate';
    }

    // EVERY lane that carries the suite must be among the versions it runs on — not only the
    // newest. The first version checked the newest lane alone, and a reviewer narrowed the
    // condition to `matrix.wp == '7.1'`: §8 stayed green while the XSS gate silently stopped
    // covering the lane that runs the FULL suite. Both of this section's comments then claimed
    // "every blocking version". They lied by one.
    //
    // THE BASELINE COMES FROM .wp-env.json, WHICH IS THE TRUTH. ci.yml spells `6.4` four times
    // — the matrix, the tag resolver, the override branch, the full-suite branch — and nothing
    // pinned any of them to `.wp-env.json`'s `core`, the value wp-env actually boots. The second
    // version of this section derived the baseline from one of those four copies, which made
    // §8 a consumer of the duplication rather than its pin. Now the structured value is read
    // and each copy is required to agree with it.
    $lanes = array_keys(slimstat_ci_wp_lanes($ci_yaml));

    $wp_env   = json_decode((string) file_get_contents($plugin_root . '/.wp-env.json'), true);
    $baseline = '';
    if (preg_match('/#([0-9]+\.[0-9]+)$/', (string) ($wp_env['core'] ?? ''), $cm)) {
        $baseline = $cm[1];
    }

    if (count($lanes) < 5) {
        $failures[] = sprintf('only %d Tier 2 WP lanes found — the matrix scan has stopped '
            . 'matching, so the coverage check below proves nothing', count($lanes));
    } elseif ('' === $baseline) {
        $failures[] = '.wp-env.json has no `core: WordPress/WordPress#X.Y`; §8 cannot tell which '
            . 'lane is the committed baseline, and a check with an empty must-cover set is green '
            . 'on anything';
    } else {
        if (!in_array($baseline, $lanes, true)) {
            $failures[] = sprintf('.wp-env.json boots WordPress %s but no Tier 2 lane names it; '
                . 'the committed baseline runs nowhere', $baseline);
        }

        $full_suite_steps = slimstat_ci_steps_containing($steps, 'npm run test:e2e');
        if (1 !== count($full_suite_steps)
            || !preg_match('/WP_VERSION\}?"?\s*=\s*"' . preg_quote($baseline, '/') . '"/', $full_suite_steps[0])) {
            $failures[] = sprintf('the E2E step does not run the full suite on WP %s, the version '
                . '.wp-env.json boots; the full-suite lane must be the committed baseline', $baseline);
        }

        usort($lanes, 'version_compare');
        $newest = end($lanes);

        foreach (array_unique([$newest, $baseline]) as $must) {
            if (false === strpos($escaping_step, "matrix.wp == '{$must}'")) {
                $failures[] = sprintf(
                    'the escaping gate does not run on WP %s (%s); its `if:` must include '
                        . "matrix.wp == '%s'",
                    $must,
                    $must === $newest ? 'the newest lane in the matrix' : 'the full-suite baseline lane',
                    $must
                );
            }
        }
    }
}

// The file the step runs must exist — a step naming a deleted file is a step that fails, not
// one that guards, and until now nothing here asked.
if (!is_file($plugin_root . '/tests/reports-output-escaping-test.php')) {
    $failures[] = 'tests/reports-output-escaping-test.php does not exist. The CI step and the '
        . 'composer script both name it; both would now fail rather than guard';
}

// The composer script must invoke the same path the CI step does, or renaming the file leaves
// one of them pointing at nothing while the other still looks wired. Checked on the
// `test:reports-escaping` KEY, not the whole file: a whole-file strpos was satisfied by the
// path appearing in the script's own `_comment-` description while the script itself was gone.
$composer_json = (string) file_get_contents($plugin_root . '/composer.json');
$composer      = json_decode($composer_json, true);
$escaping_script = (string) ($composer['scripts']['test:reports-escaping'] ?? '');
if (false === strpos($escaping_script, 'tests/reports-output-escaping-test.php')) {
    $failures[] = 'composer.json\'s `test:reports-escaping` script no longer invokes '
        . 'tests/reports-output-escaping-test.php — the CI step and the composer script must '
        . 'name the same file, or a rename silently unwires one of them';
}

// ── 9. A missing WordPress tag must fail, not silently become trunk ────────────
//
// `WP_REF="trunk"` behind a `::warning::` turns "this version does not exist" into a lane that
// tests something else entirely and reports green. All seven tags resolve today, so this is
// latent rather than active — which is precisely when it is cheap to close.
// TWO HALVES, because the negative alone was vacuous: deleting the tag-existence check from
// ci.yml ENTIRELY left this section green, so the guard could be removed wholesale — or
// replaced by any other silent fallback — and nothing noticed. A check that only forbids one
// spelling of a defect is not a check for the defect.
$override_steps = slimstat_ci_steps_containing($steps, '.wp-env.override.json', 'ls-remote');
$override_step  = 1 === count($override_steps) ? $override_steps[0] : '';

if ('' === $override_step) {
    $failures[] = 'no ci.yml step checks that the WordPress tag a lane names actually exists '
        . '(no `ls-remote` beside the wp-env override). Without it a lane labelled for a version '
        . 'that is not there boots something else and reports green';
} else {
    // THE MECHANISM, NOT A SPELLING. The first version forbade `WP_REF="trunk"` and
    // `WP_REF='trunk'`; a reviewer replayed it against `WP_REF=trunk` and against
    // `FALLBACK=trunk; WP_REF="${FALLBACK}"`: both green. What the step may do is assign WP_REF
    // exactly once, from the matrix — so the list of assignments IS the assertion.
    preg_match_all('/\bWP_REF=(\S*)/', $override_step, $wm);
    if ($wm[1] !== ['"${WP_VERSION}"']) {
        $failures[] = sprintf(
            'the wp-env override step assigns WP_REF as [%s]; exactly one assignment, '
                . 'WP_REF="${WP_VERSION}", is allowed. Any other is a fallback under some spelling '
                . '— trunk, a pinned tag, an indirection — and a lane running a WordPress other '
                . 'than the one it is named for is a coverage claim nobody can check',
            implode(', ', $wm[1])
        );
    }

    // AND THE MISSING-TAG BRANCH ITSELF MUST FAIL. The previous positive half looked for
    // `exit 1` anywhere in the step, and the step has two; delete the second and the first kept
    // the gate green, so "the tag check fails the lane" was certified by another branch's exit.
    if (!preg_match('/if \[ -z "\$\{wp_tag_refs\}" \]; then(.*?)\n\s*fi\b/s', $override_step, $missing_tag)) {
        $failures[] = 'the override step has no `if [ -z "${wp_tag_refs}" ]` branch — the tag-'
            . 'existence check moved or is gone';
    } elseif (false === strpos($missing_tag[1], 'exit 1')) {
        $failures[] = 'the missing-WordPress-tag branch does not `exit 1`; warning and continuing '
            . 'is the fallback under another name';
    }
}

// ── 9b. The booted WordPress is the one the lane is named for ───────────────────────────
//
// §9 reads the script's text. The defect is "the lane boots a WordPress other than the one it
// is named for", and a static count cannot see a pinned-tag fallback spelled some new way, an
// indirection two lines apart, or a stale wp-env cache serving last week's core. One step after
// `wp-env start` asks the running site — `wp core version` — and fails the lane on a mismatch.
// That is the assertion; the count above is its tripwire.
//
// "AFTER" WAS PROSE ONLY until 2026-09-04. This section asserted that exactly one step contains
// `wp core version`, that it compares, exits 1 and is not soft — never where it sits. Found in
// Pro's twin, where a reviewer swapped the check with the step that starts wp-env (the site asked
// for its version before it existed: PASS) and then moved it into a job that boots no wp-env at
// all (PASS). Both fail at RUNTIME — `wp-env run` against a stopped environment prints nothing
// and exits 1 — so this was an overstated claim rather than a silent hole, which is the only
// reason it is a note here and not a shipped defect.
//
// The window is asked PER JOB, so it cannot straddle one by construction. Free has two wp-env
// jobs (Tier 2 and Tier 3); over a flat step list, Tier 2's start pairs with Tier 3's stop the
// moment Tier 2's own stop goes missing, and a version check sitting in any job between them
// reads as "inside". The first draft recovered job identity by sniffing a two-space key in the
// span between steps — a FOURTH copy of the rule slimstat_ci_job_blocks() owns, and a divergent
// one: it rejected a key line ending in a tab, which that helper accepts, so one whitespace
// character reintroduced exactly this hazard. Split by job and the caveat stops existing.
$booted = slimstat_ci_step_indexes($steps, 'wp core version');

if (1 !== count($booted)) {
    $failures[] = sprintf('%d ci.yml step(s) check `wp core version` after wp-env starts; exactly '
        . 'one is expected. Without it nothing proves the lane booted the WordPress it is named '
        . 'for — every static check on the override script reasons about a boot nobody observed',
        count($booted));
} else {
    $booted_step = $steps[$booted[0]];

    // NOT chained to the position check below: a step that had lost its `exit 1` AND moved out of
    // the window would otherwise report only the first of the two.
    if (false === strpos($booted_step, 'WP_VERSION') || false === strpos($booted_step, 'exit 1')
        || false !== strpos($booted_step, 'continue-on-error')) {
        $failures[] = 'the `wp core version` step does not compare against WP_VERSION and `exit 1` on '
            . 'a mismatch, or is continue-on-error; printing the version is a log line, not a gate';
    }

    $host_job = null;
    foreach (slimstat_ci_job_blocks($ci_code) as $job => $job_block) {
        $job_steps = slimstat_ci_steps($job_block);
        $at        = slimstat_ci_step_indexes($job_steps, 'wp core version');
        if (!$at) {
            continue;
        }

        $host_job = $job;
        $opens    = array_values(array_filter(
            slimstat_ci_step_indexes($job_steps, 'wp-env start'),
            static fn(int $i): bool => $i < $at[0]
        ));
        $closes   = array_values(array_filter(
            slimstat_ci_step_indexes($job_steps, 'wp-env stop'),
            static fn(int $i): bool => $i > $at[0]
        ));

        if (!$opens || !$closes) {
            $failures[] = sprintf('the `wp core version` step in job `%s` is outside the running '
                . 'window: `wp-env start` before it at (%s), `wp-env stop` after it at (%s). Asking '
                . 'a site for its version before it boots, or from a job that never boots one, is '
                . 'not an observation',
                $job,
                $opens ? implode(', ', $opens) : 'none',
                $closes ? implode(', ', $closes) : 'none');
        }
        break;
    }

    if (null === $host_job) {
        $failures[] = 'the `wp core version` step belongs to no job block — slimstat_ci_job_blocks() '
            . 'cannot place it, so the window below was never asked and this section is inert';
    }
}

// ── 10. Everything the credential-holding workflow runs is pinned to a commit ──
//
// main.yml fires on `push: tags: - "*"` and holds the SVN credentials for a plugin with
// ~70,000 active installs. A moving ref there — @stable, @master, or a version tag, which is
// republished on every release — means whatever upstream points at on the day someone cuts a
// tag is what receives those credentials.
//
// Evaluated against $main_code, which §5 already stripped of comments: the obvious spelling of
// this check ("a 40-hex SHA appears somewhere") is satisfied by a SHA sitting in an
// explanatory comment, which is exactly what main.yml carries beside each pin.
preg_match_all('/^\s*-?\s*uses:\s*(\S+)/m', $main_code, $uses);
$pinned = $uses[1] ?? [];

if (count($pinned) < 2) {
    $failures[] = sprintf('only %d `uses:` line(s) found in main.yml — the scan has stopped '
        . 'reading the workflow, so the pin check below proves nothing', count($pinned));
}

foreach ($pinned as $ref) {
    if (!preg_match('/@[0-9a-f]{40}$/', $ref)) {
        $failures[] = sprintf(
            'main.yml runs `%s`, which is not pinned to a commit. This workflow holds the '
                . 'wordpress.org SVN credentials and runs on any tag push; a moving ref decides '
                . 'what gets them, and neither a branch nor a version tag is immutable',
            $ref
        );
    }
}

// ── 11. A lane that measures cost must first have something to measure ────────
//
// §3 asserts the EXPLAIN gate EXISTS, is not a TODO, carries no `exit 0`, and invokes
// explain-gate.sh — it proves the gate COULD fail. Nothing asserted that it RUNS, and it never
// has: it sits after a k6 step that failed on every dispatch this lane has ever had, and it
// carried no always(). A gate proven capable of failing, in a lane that never reaches it, is
// the recurring shape one altitude up. PITFALLS 111.
//
// And k6 itself measured a WordPress with no SlimStat tables, because Tier 2's activation step
// was never ported here — 81.79% request failures, read as a product verdict.
// SCOPED TO THE NIGHTLY JOB. The first draft scanned every step in the file and worked only
// because `init_environment()` happens to be unique to Tier 3 today — Tier 2 gaining that call
// would have satisfied the ordering check with a step in a different job entirely.
$nightly_block = '';
foreach (preg_split('/(?=^\s{2}\w+:\s*\n\s+name:\s*"Tier)/m', $ci_code) as $block) {
    if (preg_match('/name:\s*"Tier 3/', $block)) {
        $nightly_block = $block;
        break;
    }
}

if ('' === $nightly_block) {
    $failures[] = 'no Tier 3 job block found in ci.yml — the k6/EXPLAIN checks below would pass '
        . 'by having nothing to read';
}

// The hand-rolled position walk this replaced was the only other one in tests/, in the file
// that motivated extracting slimstat_ci_step_indexes() in the first place. Its two implicit
// rules — LAST k6 step, and the last activation BEFORE it — are stated here rather than
// emerging from the order of three ifs sharing one loop.
$nightly_steps = slimstat_ci_steps($nightly_block);
$k6_indexes    = slimstat_ci_step_indexes($nightly_steps, 'npm run test:perf');
$k6_index      = $k6_indexes ? (int) end($k6_indexes) : null;
$activate_idx  = null;
$explain_steps = slimstat_ci_steps_containing($nightly_steps, 'explain-gate.sh');
$explain_step  = $explain_steps ? (string) end($explain_steps) : '';

if (null !== $k6_index) {
    $before_k6 = array_filter(
        slimstat_ci_step_indexes($nightly_steps, 'init_environment()', 'wp plugin activate'),
        static fn(int $i): bool => $i < $k6_index
    );
    $activate_idx = $before_k6 ? (int) end($before_k6) : null;
}

if (null === $k6_index) {
    $failures[] = 'no ci.yml step runs `npm run test:perf` — the k6 lane has gone, and §1 and '
        . '§2 above are then asserting about scripts nothing executes';
} elseif (null === $activate_idx) {
    $failures[] = 'the k6 lane runs before any step that activates the plugin and fires '
        . 'init_environment(). wp-env auto-activates but never fires admin_init, which is what '
        . 'creates the tables — so k6 measures a WordPress where they do not exist, and reports '
        . 'the shortfall as a product failure';
} else {
    // ORDER IS NOT ENOUGH. A step that is present, in the right place, and gated off by its
    // own `if:` leaves k6 measuring the same empty install while every ordering check above
    // stays green. So the two conditions must MATCH: they run on the same lane, or the
    // guarantee is fiction. Found by perturbing the fixed tree with `if: false` and watching
    // this section pass.
    $step_condition = static function ($step) {
        return preg_match('/^\s*if:\s*(.+)$/m', (string) $step, $m) ? trim($m[1]) : '';
    };

    $k6_if       = $step_condition($nightly_steps[$k6_index]);
    $activate_if = $step_condition($nightly_steps[$activate_idx]);

    if ($k6_if !== $activate_if) {
        $failures[] = sprintf(
            'the k6 step runs on `%s` and the activation step on `%s`. A lane that reaches k6 '
                . 'without the activation measures a WordPress with no SlimStat tables, and the '
                . 'ordering check above cannot see it because the step is still there',
            $k6_if,
            $activate_if
        );
    }
}

// `'' === $explain_step` is deliberately NOT checked here: §3 already requires at least one
// step named *EXPLAIN* and that every such step invokes explain-gate.sh, so §3 green entails
// this is non-empty. A branch that cannot fail while its neighbour passes reads as coverage
// and is not.
if ('' !== $explain_step && false === strpos($explain_step, 'always()')) {
    $failures[] = 'the EXPLAIN gate does not carry always(). It sits after k6, and k6 has failed '
        . 'on every dispatch this lane has had — so the gate has never once executed. "Can fail" '
        . 'and "did run" are two assertions; §3 makes the first and this makes the second';
}

// ── Report ─────────────────────────────────────────────────────────────────
if ($failures !== []) {
    fwrite(STDERR, "FAIL: perf-gate integrity (" . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: perf-gate integrity (" . count($k6_scripts) . " k6 script(s) checked; deploy gated; no orphans; c-o-e reasoned)\n";
exit(0);
