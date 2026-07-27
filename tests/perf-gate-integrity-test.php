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

// ── Report ─────────────────────────────────────────────────────────────────
if ($failures !== []) {
    fwrite(STDERR, "FAIL: perf-gate integrity (" . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: perf-gate integrity (" . count($k6_scripts) . " k6 script(s) checked)\n";
exit(0);
