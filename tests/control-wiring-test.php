<?php
/**
 * Source-level: the control-wiring analyser can tell a wired control from a dead one.
 *
 * WHY THIS EXISTS. `tests/docker/reachability/analyse-controls.php` is the deterministic half of
 * the S3 reachability protocol — it decides whether each control in a CONTROLS gate is REACHED
 * and whether its failure reaches the terminal `exit(1)`. The protocol's LLM half and its live
 * half both run only inside a docker matrix. This one needs no database and runs in
 * milliseconds, so it is the half that can guard every commit — and an analyser nothing tests is
 * exactly the shape it was written to catch.
 *
 * WHAT IT PINS, and each case is here because the analyser FAILED it once:
 *
 *   interpolation  `"{$x}"` emits its opening curly as the array token T_CURLY_OPEN and its
 *                  closing one as a plain `'}'`. A walker that pushes only on the bare-string
 *                  `'{'` therefore drains its own frame stack, `array_pop([])` is a silent
 *                  no-op, and from that point every call site reads as top-level. The real
 *                  subject holds eleven such tokens and the analyser passed it anyway — by luck
 *                  of where the mutation's closure landed. Case D is that luck removed.
 *   comments       skipping only T_WHITESPACE means `$control /* note * / (` is not recognised
 *                  as a call at all. The control does not read as broken; it VANISHES, and the
 *                  summary still says PASS. Case E pins that a hidden control is still counted.
 *   by value       `use ($failures)` compiles, runs, appends to a copy and changes nothing the
 *                  terminal can see. Case F.
 *   the floor      a gate that quietly went from nine controls to two still exits 0. Case G
 *                  ratchets the real subject's count against tests/docker/reachability/
 *                  CONTROL-FLOOR, which run-rollup-floor.sh reads from the same file.
 *
 * It drives the analyser as a SUBPROCESS rather than including it: the file is a script with a
 * driver at the bottom, and a test that included it would be testing a different entry point
 * from the one the protocol runs.
 *
 * 7.4-safe: bare PHP, no WordPress, no database, no vendor autoloader.
 */

declare(strict_types=1);

$plugin_root = dirname(__DIR__);
$analyser    = $plugin_root . '/tests/docker/reachability/analyse-controls.php';
$subject     = $plugin_root . '/tests/docker/verify-export-fingerprint.php';
$floor_file  = $plugin_root . '/tests/docker/reachability/CONTROL-FLOOR';
$failures    = [];
$checks      = 0;

/** Run the analyser over a file and return [exit code, decoded JSON or null]. */
function cw_analyse(string $analyser, string $path): array
{
    $out = [];
    $rc  = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($analyser) . ' ' . escapeshellarg($path)
        . ' --json 2>/dev/null', $out, $rc);
    $decoded = json_decode(implode("\n", $out), true);

    return [$rc, is_array($decoded) ? $decoded : null];
}

/** The control numbers a report says are broken, by relation. */
function cw_broken(?array $report, string $relation): array
{
    if (null === $report || !isset($report['controls'])) {
        return [];
    }
    $out = [];
    foreach ($report['controls'] as $c) {
        if ('reachability' === $relation && !$c['reachable']) {
            $out[] = (int) $c['n'];
        } elseif ('exit-effect' === $relation && $c['reachable'] && !$c['exit_effective']) {
            $out[] = (int) $c['n'];
        }
    }
    sort($out);

    return $out;
}

// ── The fixtures ────────────────────────────────────────────────────────────────────────────
// Written here rather than committed as files, so a case cannot drift from the sentence
// explaining it, and so the ONLY difference between the clean case and each broken one is the
// line the case is about.

$prologue = <<<'PHP'
<?php
$controls = $failures = [];
$control = function ($ok, $n, $name, $detail) use (&$controls, &$failures) {
    $controls[] = sprintf('[%s] %d %s %s', $ok ? 'OK' : '!!', $n, $name, $detail);
    if (!$ok) {
        $failures[] = $name;
    }
};

PHP;

$epilogue = <<<'PHP'

if ($failures) {
    exit(1);
}
echo "PASS\n";
PHP;

$cases = [
    'A-clean' => [
        'body'   => "\$control(true, 1, 'ONE', 'a');\n\$control(true, 2, 'TWO', 'b');\n",
        'verdict' => 'PASS',
        'unreachable' => [],
        'ineffective' => [],
        'controls' => 2,
        'why' => 'two ordinary top-level controls through one by-reference renderer',
    ],
    'B-severed' => [
        'body'   => "\$control(true, 1, 'ONE', 'a');\n"
            . "\$orphan = function () use (\$control) { \$control(true, 2, 'TWO', 'b'); };\n",
        'verdict' => 'FAIL',
        'unreachable' => [2],
        'ineffective' => [],
        'controls' => 2,
        'why' => 'control 2 sits in a closure nothing invokes',
    ],
    'C-advisory' => [
        'body'   => "\$control(true, 1, 'ONE', 'a');\n"
            . "\$control_advisory = function (\$ok, \$n, \$name, \$detail) use (&\$controls) {\n"
            . "    \$controls[] = \$name;\n};\n"
            . "\$control_advisory(true, 2, 'TWO', 'b');\n",
        'verdict' => 'FAIL',
        'unreachable' => [],
        'ineffective' => [2],
        'controls' => 2,
        'why' => 'control 2 runs but its renderer records into nothing the exit is guarded on',
    ],
    'D-severed-after-interpolation' => [
        // The regression case. The interpolation is BEFORE the severed control, so a walker that
        // loses its frame stack on T_CURLY_OPEN reports control 2 as reachable and the whole
        // analysis as PASS — a false clear from the one instrument that must not give one.
        'body'   => "\$x = 'v';\n\$msg = \"value {\$x} here\";\n\$control(true, 1, 'ONE', \$msg);\n"
            . "\$orphan = function () use (\$control) { \$control(true, 2, 'TWO', 'b'); };\n",
        'verdict' => 'FAIL',
        'unreachable' => [2],
        'ineffective' => [],
        'controls' => 2,
        'why' => 'the same severed control, behind a string interpolation',
    ],
    'E-comment-between' => [
        'body'   => "\$control(true, 1, 'ONE', 'a');\n"
            . "\$control /* still a control */ (true, 2, 'TWO', 'b');\n",
        'verdict' => 'PASS',
        'unreachable' => [],
        'ineffective' => [],
        'controls' => 2,
        'why' => 'a comment between the callable and its parenthesis must not hide the control',
    ],
    'F-by-value' => [
        'body'   => "\$byval = function (\$ok, \$n, \$name, \$detail) use (&\$controls, \$failures) {\n"
            . "    \$controls[] = \$name;\n    \$failures[] = \$name;\n};\n"
            . "\$control(true, 1, 'ONE', 'a');\n\$byval(true, 2, 'TWO', 'b');\n",
        'verdict' => 'FAIL',
        'unreachable' => [],
        'ineffective' => [2],
        'controls' => 2,
        'why' => 'the renderer appends to a COPY of the guard, so the exit never sees it',
    ],
];

$tmp = sys_get_temp_dir() . '/slimstat-control-wiring-' . getmypid();
@mkdir($tmp, 0700, true);

foreach ($cases as $id => $case) {
    $path = $tmp . '/' . $id . '.php';
    file_put_contents($path, $prologue . $case['body'] . $epilogue);
    // Matched on the SUCCESS string, not on the word "error": `php -l` answers "No syntax errors
    // detected", which contains it. The first version of this line rejected every valid fixture
    // and reported six analyser failures that were entirely its own.
    $lint = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1');
    if (false === strpos($lint, 'No syntax errors detected')) {
        $failures[] = sprintf('fixture %s does not parse (%s) — the case says nothing about the analyser',
            $id, trim($lint));
        continue;
    }

    list($rc, $report) = cw_analyse($analyser, $path);
    $checks++;

    if (null === $report) {
        $failures[] = sprintf('%s: the analyser returned no JSON (exit %d)', $id, $rc);
        continue;
    }
    if ($case['verdict'] !== $report['verdict']) {
        $failures[] = sprintf('%s (%s): expected verdict %s, got %s', $id, $case['why'], $case['verdict'], $report['verdict']);
    }
    if ($case['controls'] !== $report['summary']['declared']) {
        $failures[] = sprintf('%s (%s): expected %d controls to be FOUND, got %d — a control the analyser '
            . 'cannot see is not a control it cleared', $id, $case['why'], $case['controls'], $report['summary']['declared']);
    }
    $unreachable = cw_broken($report, 'reachability');
    if ($case['unreachable'] !== $unreachable) {
        $failures[] = sprintf('%s (%s): expected unreachable %s, got %s', $id, $case['why'],
            json_encode($case['unreachable']), json_encode($unreachable));
    }
    $ineffective = cw_broken($report, 'exit-effect');
    if ($case['ineffective'] !== $ineffective) {
        $failures[] = sprintf('%s (%s): expected exit-ineffective %s, got %s', $id, $case['why'],
            json_encode($case['ineffective']), json_encode($ineffective));
    }
    @unlink($path);
}
@rmdir($tmp);

// ── G — the real subject, and its floor ─────────────────────────────────────────────────────
$checks++;
list($rc, $report) = cw_analyse($analyser, $subject);
if (null === $report) {
    $failures[] = 'the analyser returned no JSON for the real subject';
} else {
    if ('PASS' !== $report['verdict']) {
        $failures[] = sprintf('%s is not wired: %s', basename($subject), implode('; ', $report['failures']));
    }
    // The guard is DERIVED by the analyser. Asserting the value here is what proves the
    // derivation ran — a null guard would fail every control above for a reason that reads like
    // a wiring defect rather than like an analyser that could not find the exit.
    if ('$failures' !== ($report['guard'] ?? '')) {
        $failures[] = sprintf('the analyser derived the terminal guard as %s; the subject guards exit(1) on $failures',
            var_export($report['guard'] ?? null, true));
    }
    $floor = is_file($floor_file) ? (int) trim((string) file_get_contents($floor_file)) : 0;
    if ($floor < 1) {
        $failures[] = 'tests/docker/reachability/CONTROL-FLOOR is missing or unreadable — without it a '
            . 'subject that quietly lost seven controls still passes';
    } elseif ($report['summary']['declared'] < $floor) {
        $failures[] = sprintf('%s declares %d controls but CONTROL-FLOOR is %d — a control was deleted. '
            . 'Raise the floor deliberately if one is genuinely obsolete; never lower it to match a deletion',
            basename($subject), $report['summary']['declared'], $floor);
    } elseif ($report['summary']['declared'] > $floor) {
        $failures[] = sprintf('%s declares %d controls but CONTROL-FLOOR is still %d — write %d into it. '
            . 'Until you do, the new control is deletable without any gate noticing',
            basename($subject), $report['summary']['declared'], $floor, $report['summary']['declared']);
    }
}

printf("SLIMSTAT-CONTROL-WIRING-TEST cases=%d failures=%d\n", $checks, count($failures));
if ($failures) {
    fwrite(STDERR, 'FAIL: the control-wiring analyser does not draw the lines it claims (' . count($failures) . ")\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}
echo "PASS: the analyser separates reached from severed and exit-effective from advisory, "
    . "sees a control behind a comment and behind a string interpolation, and the real subject "
    . "is wired at its declared floor\n";
