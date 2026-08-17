<?php
// Drive every scenario in scenarios.php as its own process and assert what the subject printed
// and exited with.
//
//   php tests/fp-negative/run-negatives.php            # all scenarios
//   php tests/fp-negative/run-negatives.php --verbose  # …and the full output of each
//   php tests/fp-negative/run-negatives.php <name> …   # only these
//
// Exit 0 only if every scenario matched. `baseline` is checked FIRST and a red baseline aborts
// the run: a negative test suite whose positive control is broken is a suite reporting on
// something other than the property it names.
//
// No declare(strict_types=1) and no PHP 8 syntax — same floor as everything it loads.

$here = __DIR__;
$root = dirname($here, 2);

require $here . '/scenarios.php';

$verbose = false;
$only    = [];
foreach (array_slice($argv, 1) as $arg) {
    if ('--verbose' === $arg || '-v' === $arg) {
        $verbose = true;
    } else {
        $only[] = $arg;
    }
}

$scenarios = slimstat_neg_scenarios();
if ($only) {
    $scenarios = array_intersect_key($scenarios, array_flip($only));
    if (count($scenarios) !== count($only)) {
        fwrite(STDERR, "unknown scenario name among: " . implode(', ', $only) . "\n");
        exit(2);
    }
}

// ── The shims live in a scratch tree, built here ─────────────────────────────────────────────
// A shim committed as an executable named `python3` inside the repo is a loaded gun on anybody's
// PATH; built at run time from a .sh that is not named python3, it can only be reached by a
// scenario that asks for it.
$scratch = sys_get_temp_dir() . '/slimstat-fp-negative-' . getmypid();
foreach (['shim-memo', 'shim-inert', 'empty', 'memo-cache'] as $d) {
    if (!is_dir($scratch . '/' . $d) && !mkdir($scratch . '/' . $d, 0700, true)) {
        fwrite(STDERR, "cannot create {$scratch}/{$d}\n");
        exit(2);
    }
}
copy($here . '/bin/python3-memo.sh', $scratch . '/shim-memo/python3');
copy($here . '/bin/python3-inert-order.sh', $scratch . '/shim-inert/python3');
chmod($scratch . '/shim-memo/python3', 0700);
chmod($scratch . '/shim-inert/python3', 0700);

// A mutant subject is written beside the real one (it resolves the Python reader from its own
// __DIR__) and removed on shutdown. A killed child would leave one behind, and a stale mutant in
// tests/docker/ is exactly the "superseded artifact cited as current" shape, so sweep first.
foreach (glob($root . '/tests/docker/.fp-negative-mutant-*.php') as $stale) {
    @unlink($stale);
}

$real_python3 = trim((string) shell_exec('command -v python3 2>/dev/null'));
if ('' === $real_python3) {
    fwrite(STDERR, "python3 is not on PATH; this harness drives the real Python reader\n");
    exit(2);
}

$tokens = [
    '{{SHIM_MEMO}}'  => $scratch . '/shim-memo',
    '{{SHIM_INERT}}' => $scratch . '/shim-inert',
    '{{EMPTY_DIR}}'  => $scratch . '/empty',
];

$base_env = [
    'PATH'                    => getenv('PATH'),
    'HOME'                    => getenv('HOME'),
    'TMPDIR'                  => getenv('TMPDIR') ?: '/tmp',
    'LANG'                    => 'C',
    'SLIMSTAT_REAL_PYTHON3'   => $real_python3,
    'SLIMSTAT_SHIM_CACHE'     => $scratch . '/memo-cache',
    'SLIMSTAT_INERT_READER'   => $here . '/bin/inert_order_reader.py',
    'SLIMSTAT_ORACLE_DIR'     => $root . '/tests/oracle',
];

/** Run one scenario in a child process. @return array{out:string, code:int} */
function slimstat_neg_run($name, array $scenario, array $base_env, array $tokens, $here)
{
    $env = $base_env;
    if (isset($scenario['env'])) {
        foreach ($scenario['env'] as $k => $v) {
            $env[$k] = strtr($v, $tokens);
        }
    }
    $env['SLIMSTAT_NEG_SCENARIO'] = $name;
    // The subject reads this with getenv() and casts to int, so an unset variable must arrive as
    // unset rather than as an empty string that the child's own environment might not shadow.
    if (!isset($env['SLIMSTAT_FP_FORCE_CONTROL_FAIL'])) {
        $env['SLIMSTAT_FP_FORCE_CONTROL_FAIL'] = '0';
    }

    // Both streams into ONE pipe, merged by the shell. The subject prints its CONTROLS block to
    // stdout and its FAIL block to stderr, and an assertion that could see only one of them
    // would be asserting on half the evidence — so they are captured together, in the order a
    // person at a terminal would have seen them. Draining two pipes in sequence instead would
    // have blocked on stdout while stderr filled its buffer, and would have appended all of
    // stderr after all of stdout while claiming to preserve that order.
    $cmd   = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($here . '/harness.php') . ' 2>&1';
    $pipes = [];
    $proc  = proc_open($cmd, [1 => ['pipe', 'w']], $pipes, $here, $env);
    if (!is_resource($proc)) {
        return ['out' => '(could not start the child process)', 'code' => -1];
    }
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return ['out' => $out, 'code' => proc_close($proc)];
}

/** @return string[] the reasons this scenario did not match; empty means it did */
function slimstat_neg_check(array $expect, array $result)
{
    $problems = [];
    if (isset($expect['exit']) && $result['code'] !== $expect['exit']) {
        $problems[] = sprintf('exit %d, expected %d', $result['code'], $expect['exit']);
    }
    foreach (isset($expect['contains']) ? $expect['contains'] : [] as $needle) {
        if (false === strpos($result['out'], $needle)) {
            $problems[] = 'missing: ' . $needle;
        }
    }
    foreach (isset($expect['absent']) ? $expect['absent'] : [] as $needle) {
        if (false !== strpos($result['out'], $needle)) {
            $problems[] = 'present but must not be: ' . $needle;
        }
    }
    return $problems;
}

// ── PIN THE SUBJECT ──────────────────────────────────────────────────────────────────────────
// PITFALLS 67: this exact file was being written by two sessions at once, and a review round and
// a docker matrix were both lost because the subject moved between reads. Every result below is
// a statement about a specific set of bytes, so those bytes are digested before the sweep and
// again after it, and a run whose subject moved is REFUSED rather than reported. The mutation
// scenarios write to a separate path on purpose, so they do not trip this.
$pinned_files = [
    'tests/docker/verify-export-fingerprint.php',
    'tests/bench/lib/fingerprint-v2.php',
    'tests/bench/lib/export-snapshot.php',
    'tests/bench/lib/pinned-columns.php',
    'tests/oracle/read_export.py',
    'tests/oracle/encoding_v1.py',
    'src/Schema/Schema.php',
];
$digest = function () use ($root, $pinned_files) {
    $out = [];
    foreach ($pinned_files as $rel) {
        $out[$rel] = hash_file('sha256', $root . '/' . $rel);
    }
    return $out;
};
$before = $digest();

echo "SUBJECT PINNED (sha256, before the sweep)\n";
foreach ($before as $rel => $sha) {
    printf("  %s  %s\n", $sha, $rel);
}
echo "\n";

// ── baseline first ───────────────────────────────────────────────────────────────────────────
$order = array_keys($scenarios);
if (isset($scenarios['baseline'])) {
    $order = array_merge(['baseline'], array_values(array_diff($order, ['baseline'])));
}

$failed = 0;
$ran    = 0;
foreach ($order as $name) {
    $scenario = $scenarios[$name];
    $result   = slimstat_neg_run($name, $scenario, $base_env, $tokens, $here);
    $problems = slimstat_neg_check($scenario['expect'], $result);
    $ran++;

    printf("%-30s %s  exit=%d\n", $name, $problems ? 'MISMATCH' : 'ok      ', $result['code']);
    printf("  %s\n", $scenario['why']);
    if ($problems) {
        $failed++;
        foreach ($problems as $p) {
            echo '  !! ' . $p . "\n";
        }
    }
    if ($verbose || $problems) {
        echo "  ---- output ----\n";
        foreach (explode("\n", rtrim($result['out'], "\n")) as $line) {
            echo '  | ' . $line . "\n";
        }
        echo "  ----------------\n";
    }
    echo "\n";

    if ('baseline' === $name && $problems) {
        fwrite(STDERR, "the baseline is red, so no negative result below it would mean anything. "
            . "Stopping.\n");
        exit(1);
    }
}

printf("%d scenarios, %d matched, %d mismatched\n", $ran, $ran - $failed, $failed);

$after = $digest();
$moved = [];
foreach ($after as $rel => $sha) {
    if ($sha !== $before[$rel]) {
        $moved[] = sprintf('%s: %s -> %s', $rel, substr($before[$rel], 0, 16), substr($sha, 0, 16));
    }
}
if ($moved) {
    fwrite(STDERR, "SUBJECT MOVED DURING THE SWEEP — this run is not evidence about any one "
        . "revision and is refused (PITFALLS 67):\n");
    foreach ($moved as $m) {
        fwrite(STDERR, '  - ' . $m . "\n");
    }
    exit(3);
}
echo "subject unchanged across the sweep\n";

exit($failed ? 1 : 0);
