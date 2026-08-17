<?php
/**
 * Source-level: the mutation registry is well-formed and cannot shrink silently.
 *
 * WHY THIS IS SEPARATE FROM THE RUNNER. This is the cheap half — it validates the registry
 * without executing a mutation. The full run (`composer test:mutations`) executes each
 * gate twice and is wired into CI separately, per the plan's "blocking on changed targets
 * per PR, full registry nightly and at release".
 *
 * The floor is the anti-deletion ratchet. Without it, "0 mutations, 0 failures" is a
 * passing build — the same vacuity as a scan that asserts nothing, one level up, and this
 * programme has now hit that class four times (a scan that derives its own coverage set, a
 * gate wired into nothing, two assertions naming functions that never existed).
 *
 * 7.4-safe: plain functions, no autoloader, no WordPress.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/mutations.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

$files = slimstat_mutation_files($plugin_root);
$floor = slimstat_mutation_floor($plugin_root);

// ── Floor: the ratchet ──────────────────────────────────────────────────────
if (null === $floor) {
    $failures[] = 'tests/mutations/FLOOR is missing — without it a registry that shrinks to '
        . 'zero still passes, which is exactly the vacuity the registry exists to prevent';
    $floor = 0;
}

if (count($files) < $floor) {
    $failures[] = sprintf(
        'registry has %d mutation(s) but FLOOR is %d — a mutation was deleted. Raise the floor '
        . 'deliberately if a mutation is genuinely obsolete; never lower it to match a deletion',
        count($files),
        $floor
    );
}

// The OTHER direction, which was unenforced and made the ratchet optional. `count < floor`
// alone means adding mutations without raising FLOOR leaves them deletable at zero cost: the
// entire protective value of a new mutation is created by hand-editing a second file that
// nothing cross-checked. Deliberately NOT applied to FLOOR-NAME-ONLY, which is aspirational
// (5 of 40 gates covered, 35 owed) and where exceeding the floor is the normal state.
if (count($files) > $floor) {
    $failures[] = sprintf(
        'registry has %d mutation(s) but FLOOR is still %d — write %d into tests/mutations/FLOOR. '
        . 'Until you do, the new mutation(s) are deletable without any gate noticing, which is '
        . 'the whole thing the floor exists to prevent',
        count($files),
        $floor,
        count($files)
    );
}

if (0 === count($files)) {
    $failures[] = 'no mutation files at all';
}

// ── Every entry is well-formed and points at something real ─────────────────
$kinds           = [];
$name_only_gates = [];
$composer        = json_decode((string) file_get_contents($plugin_root . '/composer.json'), true);
$scripts         = (is_array($composer) && isset($composer['scripts'])) ? $composer['scripts'] : [];

foreach ($files as $file) {
    $id     = basename($file, '.mutation');
    $parsed = slimstat_mutation_parse($file);
    $spec   = $parsed['spec'];

    // The SAME parser the runner uses. Two parsers are two formats, and these two had
    // already drifted before either landed: this gate rejected an empty diff and the
    // runner accepted one, which would have applied as a no-op and reported SURVIVED.
    foreach ($parsed['problems'] as $problem) {
        $failures[] = "{$id}: {$problem}";
    }

    if (!empty($spec['target']) && !file_exists($plugin_root . '/' . $spec['target'])) {
        $failures[] = "{$id}: target does not exist — {$spec['target']}. A mutation against a "
            . 'moved file is INVALID, and an INVALID mutation proves nothing while looking like coverage';
    }

    // THE ORACLE, not a second grammar. The parser's empty-line check names the exact
    // corrupt spelling at write time, but a hand-rolled shape check is itself a second
    // parser of the diff format — so the acceptance question is put to the SAME binary the
    // runner will use: `git apply --check`, no gate executed, nothing written. This also
    // catches context DRIFT (a mutation whose target moved under it), which previously
    // surfaced only as INVALID in a full run — corrupt patches reached the registry twice
    // (results of 2026-08-02 and 2026-08-11) before this existed.
    if (!empty($spec['diff'])) {
        $tmp = tempnam(sys_get_temp_dir(), 'mutcheck');
        file_put_contents($tmp, $spec['diff']);
        exec(
            'cd ' . escapeshellarg($plugin_root) . ' && git apply --check ' . escapeshellarg($tmp) . ' 2>&1',
            $apply_out,
            $apply_rc
        );
        unlink($tmp);
        if (0 !== $apply_rc) {
            $failures[] = "{$id}: git apply --check rejects the diff — "
                . trim(implode(' ', array_slice($apply_out, 0, 2)))
                . ' (a patch the runner cannot apply reports INVALID, which proves nothing '
                . 'while looking like coverage)';
        }
        $apply_out = [];
    }

    // The gate must name something real in WHICHEVER form it is written. Checking only the
    // `tests/*.php` shape left `gate: composer test:typo` checked by nothing at all.
    if (!empty($spec['gate'])) {
        if (preg_match('/^composer\s+([\w:.-]+)/', $spec['gate'], $m)) {
            if (!isset($scripts[$m[1]])) {
                $failures[] = "{$id}: gate names a composer script that does not exist — {$m[1]}";
            }
        } elseif (preg_match('#(tests/[\w./-]+\.php)#', $spec['gate'], $m)) {
            if (!file_exists($plugin_root . '/' . $m[1])) {
                $failures[] = "{$id}: gate names a test that does not exist — {$m[1]}";
            }
        } else {
            $failures[] = "{$id}: gate `{$spec['gate']}` names neither a composer script nor a "
                . 'tests/*.php file, so nothing checks that it can ever run';
        }
    }

    if (!empty($spec['kind'])) {
        $kinds[$spec['kind']] = ($kinds[$spec['kind']] ?? 0) + 1;
        if ('name-only' === $spec['kind']) {
            $name_only_gates[] = $spec['gate'];
        }
    }
}

// ── name-only coverage, as a ratchet rather than a boolean ──────────────────
// "At least one exists" cannot tell 1-of-26 from 26-of-26, and cannot notice movement in
// either direction. What ratchets is how many CONSUMING GATES carry one — and the debt is
// printed, so 1-of-26 is a number on screen rather than an implication.
$consumers = [];
foreach (glob($plugin_root . '/tests/*-test.php') ?: [] as $t) {
    if (false !== strpos((string) file_get_contents($t), 'lib/source-scan.php')) {
        $consumers[basename($t)] = true;
    }
}

// Positive control: this scan derives its own denominator, which is one of the four
// recorded vacuity instances. If the grep breaks, zero consumers means "all covered" and
// the check passes loudest exactly when it is blind.
if (count($consumers) < 20) {
    $failures[] = sprintf(
        'only %d source-scan consumer(s) found — the scan is broken, not the tree',
        count($consumers)
    );
}

$covered = [];
foreach ($name_only_gates as $gate) {
    if (preg_match('#tests/([\w.-]+\.php)#', $gate, $m) && isset($consumers[$m[1]])) {
        $covered[$m[1]] = true;
    }
}

$name_only_floor = slimstat_mutation_floor($plugin_root, 'FLOOR-NAME-ONLY') ?? 0;
if (count($covered) < $name_only_floor) {
    $failures[] = sprintf(
        '%d source-scan gate(s) carry a name-only mutation but FLOOR-NAME-ONLY is %d. Every '
        . 'source-scan assertion owes one — that is the shape every recorded instance of the '
        . 'name-not-construct hazard took. Raise this file as they land; never lower it',
        count($covered),
        $name_only_floor
    );
}

// ── The runner must still be able to tell its verdicts apart ────────────────
$runner = $plugin_root . '/tests/verify/bin/run-mutations.php';
if (!is_readable($runner)) {
    $failures[] = 'tests/verify/bin/run-mutations.php is missing';
} else {
    $out = [];
    $rc  = 0;
    exec('php ' . escapeshellarg($runner) . ' --selftest 2>&1', $out, $rc);
    if (0 !== $rc) {
        $failures[] = "the runner's --selftest fails, so no verdict it produces can be trusted:\n      "
            . implode("\n      ", array_slice($out, -6));
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: mutation registry (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

printf(
    "PASS: %d mutation(s), floor %d, kinds: %s; runner selftest green\n",
    count($files),
    $floor,
    implode(', ', array_map(static function ($k, $n) { return "{$k}×{$n}"; }, array_keys($kinds), $kinds))
);
printf(
    "      name-only coverage: %d/%d source-scan gates (floor %d) — %d still owe one\n",
    count($covered),
    count($consumers),
    $name_only_floor,
    count($consumers) - count($covered)
);
