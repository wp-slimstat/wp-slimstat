<?php
/**
 * A phase does not open until the phase before it has a closed Run. Enforced, not remembered.
 *
 * ── What this replaces, and why it is not `run-blind-campaign.sh` ────────────────────────────
 *
 * The campaign plan names one mechanism for this: *"run-blind-campaign.sh must refuse to start
 * phase N+1 unless phase N shows a closed_run here."* That script was named in the plan, cited in
 * STATE.json, referenced in three handoff blocks across five Runs — and **never written.** It is
 * absent from the tree today.
 *
 * Writing it now would be worse than not writing it. Under ADR-19 the merge gate was amended to a
 * fast path and the ratified Phase 2 content is DEFERRED, so there is no phase-2 driver for a
 * campaign runner to guard. A driver written to refuse a phase nothing can start is a gate wired
 * into nothing, which is this programme's single most repeated defect (PITFALLS 31: an assertion
 * over the right bytes about a real subject that was never executed) and would be the fourth
 * instance of exactly the shape S6 and S7 exist to stop.
 *
 * So the gate is put where the invariant actually lives. The thing that can really go wrong is
 * not "a script started phase 2" — it is **`STATE.json` coming to say a phase is open, or a gate
 * passed, when the record does not support it.** That file is the campaign's machine-readable
 * truth; every handoff reads it; and until now nothing checked it against itself.
 *
 * This gate is therefore over STATE.json, it runs in `composer test:source-level` on every
 * invocation, and it can fail. `run-blind-campaign.sh` is recorded as NOT WRITTEN and NOT OWED
 * rather than left implied — see VERIFICATION-PROTOCOL.md Run 61.
 *
 * ── The standalone-checkout hole, and why it is not silent ───────────────────────────────────
 *
 * STATE.json lives in a SIBLING directory (`jaan-to/`), outside this plugin. A standalone Free
 * checkout has no such sibling, exactly as `seal-entrypoint-gate.py`'s artifact census has no
 * archive to census. Returning early there is correct — but "the gate skipped" and "the gate
 * passed" must never print the same thing, because a gate that silently does not run is this
 * workspace's most repeated defect in its own right (see CLAUDE.md on `composer test:all` and
 * `python3`). So the mode is printed, and — this is the part that matters — **the invariants are
 * exercised by fixtures on every run regardless**, so the logic is under test even where the real
 * file is absent.
 */

declare(strict_types=1);

/**
 * Every invariant, over a decoded campaign block. Returns the problems; decides nothing.
 *
 * Split out from the file it checks so the fixtures below can drive it. A checker that can only
 * be run against the one real input is a checker whose failure paths nobody has seen.
 */
function slimstat_campaign_problems(array $campaign, int $latest_run): array
{
    $problems = [];
    $closed   = $campaign['phase_closed_runs'] ?? null;

    if (!is_array($closed)) {
        return ['phase_closed_runs is absent or is not an object'];
    }

    // Numbered phases only. `planning` is a real key and is deliberately not a number.
    $numbered = [];
    foreach ($closed as $name => $run) {
        if (preg_match('/^\d+$/', (string) $name)) {
            $numbered[(int) $name] = $run;
        }
    }
    ksort($numbered);

    if ([] === $numbered) {
        return ['phase_closed_runs names no numbered phase'];
    }

    // 1. NO GAPS. Phase N closed while N-1 is open is incoherent: the whole point of the ordering
    //    is that each phase's evidence rests on the one before it.
    $seen_open = null;
    foreach ($numbered as $n => $run) {
        if (null === $run) {
            $seen_open ??= $n;
            continue;
        }
        if (null !== $seen_open) {
            $problems[] = sprintf(
                'phase %d is closed (Run %s) while phase %d is still open — a phase cannot rest on one that never closed',
                $n,
                (string) $run,
                $seen_open
            );
        }
    }

    // 2. THE WORKING PHASE IS AT MOST ONE PAST THE LAST CLOSED ONE. This is the invariant the
    //    unwritten campaign driver was supposed to enforce, and it is the one that would actually
    //    be violated: `phase` bumped in the file while the phase before it shows `null`.
    $highest_closed = 0;
    foreach ($numbered as $n => $run) {
        if (null !== $run) {
            $highest_closed = max($highest_closed, $n);
        }
    }
    $phase = $campaign['phase'] ?? null;
    if (!is_int($phase)) {
        $problems[] = 'campaign.phase is absent or is not an integer';
    } elseif ($phase > $highest_closed + 1) {
        $problems[] = sprintf(
            'campaign.phase is %d but the highest CLOSED phase is %d — phase %d was opened without closing phase %d',
            $phase,
            $highest_closed,
            $phase,
            $phase - 1
        );
    }

    // 3. A CLOSED PHASE NAMES A RUN THAT EXISTS. "Closed by Run 99" when the programme has
    //    reached Run 61 is a record nobody can check, and a closure that cites nothing is the
    //    thing this file exists to make impossible.
    foreach ($numbered as $n => $run) {
        if (null === $run) {
            continue;
        }
        if (!is_int($run) || $run < 1) {
            $problems[] = sprintf('phase %d is closed by "%s", which is not a Run number', $n, (string) $run);
        } elseif ($run > $latest_run) {
            $problems[] = sprintf(
                'phase %d claims to be closed by Run %d, but the latest Run on record is %d',
                $n,
                $run,
                $latest_run
            );
        }
    }

    // 4. A GATE CANNOT READ `passed` UNTIL THE PHASE THAT CARRIES ITS EVIDENCE IS CLOSED, and the
    //    two gates do NOT have the same precondition.
    //
    //    The first version of this check looped over both and applied phase 1 to each. That is a
    //    false generalisation: `campaign.$comment` maps the phases itself — *"2 = breadth + verdict
    //    = MERGE GATE; 3 = extension = RELEASE GATE"* — and ADR-19 pulled the MERGE gate's content
    //    forward into phase 1 while re-homing nothing about the release gate. So under the loop,
    //    `release_gate: "passed"` with phases 2 and 3 both open passed cleanly: a record saying a
    //    gate passed without the evidence the ordering requires, which is this file's stated
    //    reason to exist.
    //
    //    DECLARED as a table, on the model tests/migration-optionality-test.php sets and ADR-19
    //    cites: any gate key absent from the table fails, so adding a gate forces an explicit
    //    decision about which phase carries its evidence rather than inheriting phase 1 by
    //    accident. That inheritance is exactly how ADR-19's own D1 defect happened.
    $gate_requires = [
        'merge_gate'   => 1,   // ADR-19: the sealed comparison, the caught canary and the
                               // rehearsal are all phase-1 evidence under the fast path.
        'release_gate' => 3,   // campaign.$comment: "3 = extension = RELEASE GATE". Untouched by
                               // ADR-19, which amended the merge gate only.
    ];
    foreach ($gate_requires as $gate => $needs_phase) {
        $value = $campaign[$gate] ?? null;
        if (!in_array($value, ['pending', 'passed', 'blocked'], true)) {
            $problems[] = sprintf(
                '%s is "%s"; it must be one of pending/passed/blocked, because a typo must not read as passed',
                $gate,
                is_scalar($value) ? (string) $value : gettype($value)
            );
            continue;
        }
        if ('passed' === $value && null === ($numbered[$needs_phase] ?? null)) {
            $problems[] = sprintf(
                '%s is "passed" while phase %d — the phase carrying its evidence — is still open',
                $gate,
                $needs_phase
            );
        }
    }
    foreach (['merge_gate', 'release_gate'] as $gate) {
        if (!array_key_exists($gate, $gate_requires)) {
            $problems[] = sprintf('%s has no declared phase in this gate\'s table', $gate);
        }
    }

    return $problems;
}

// ── the fixtures, which run whether or not the real file is present ─────────────────────────
// One required-red per invariant plus a clean control. Written as whole campaign blocks rather
// than as mutations of one, so a fixture cannot pass for a reason its label does not name.
$ok = [
    'phase'             => 1,
    'phase_closed_runs' => ['planning' => 47, '0' => 48, '1' => null, '2' => null, '3' => null],
    'merge_gate'        => 'pending',
    'release_gate'      => 'pending',
];

$fixtures = [
    ['a clean, honest block', $ok, 0],
    ['phase 2 opened while phase 1 is open',
        ['phase' => 2] + $ok, 1],
    ['phase 2 closed while phase 1 is open',
        ['phase_closed_runs' => ['planning' => 47, '0' => 48, '1' => null, '2' => 61, '3' => null]] + $ok, 1],
    ['a closed phase cites a Run that has not happened',
        ['phase_closed_runs' => ['planning' => 47, '0' => 48, '1' => 999, '2' => null, '3' => null]] + $ok, 1],
    ['a closed phase cites something that is not a Run number',
        ['phase_closed_runs' => ['planning' => 47, '0' => 48, '1' => 'yes', '2' => null, '3' => null]] + $ok, 1],
    ['the merge gate is passed while phase 1 is open',
        ['merge_gate' => 'passed'] + $ok, 1],
    // The fixture the first version of this gate would have ACCEPTED: phase 1 closed, phases 2 and
    // 3 open, and the release gate reporting passed anyway. `campaign.$comment` puts the release
    // gate's evidence in phase 3.
    ['the release gate is passed with phase 1 closed but phase 3 open',
        [
            'phase'             => 2,
            'phase_closed_runs' => ['planning' => 47, '0' => 48, '1' => 61, '2' => null, '3' => null],
            'merge_gate'        => 'pending',
            'release_gate'      => 'passed',
        ], 1],
    ['the merge gate carries a value outside the vocabulary',
        ['merge_gate' => 'PASSED'] + $ok, 1],
    ['phase_closed_runs is missing entirely',
        ['phase' => 1, 'merge_gate' => 'pending', 'release_gate' => 'pending'], 1],
    ['phase 1 legitimately closed, phase 2 legitimately open',
        [
            'phase'             => 2,
            'phase_closed_runs' => ['planning' => 47, '0' => 48, '1' => 61, '2' => null, '3' => null],
            'merge_gate'        => 'pending',
            'release_gate'      => 'pending',
        ], 0],
];

$failures = [];
$reds     = 0;
foreach ($fixtures as [$label, $block, $must_fail]) {
    $problems = slimstat_campaign_problems($block, 61);
    $reds    += $must_fail;
    $failed   = [] !== $problems ? 1 : 0;
    if ($failed !== $must_fail) {
        $failures[] = sprintf(
            'fixture "%s" expected %s, got %s%s',
            $label,
            $must_fail ? 'REJECT' : 'ACCEPT',
            $failed ? 'REJECT' : 'ACCEPT',
            $failed ? ' (' . implode('; ', $problems) . ')' : ''
        );
    }
}

// ── the real file, if this checkout has one ─────────────────────────────────────────────────
// DISCRIMINATED ON THE SIBLING ROOT, NEVER ON THE LEAF FILE.
//
// `is_file($state_path)` was the first spelling, and it answers the wrong question. It degrades to
// "no jaan-to sibling in this checkout" whenever that exact path is absent — including inside the
// full workspace after a rename or a docs reorg, where the printed message would be false and the
// real check would silently vanish in the one checkout that has a real file to check. This
// workspace has moved jaan-to paths before; CLAUDE.md carries a correction of exactly that kind,
// for a block whose every command named a directory that does not exist.
//
// So: no `jaan-to/` at all means a standalone Free checkout, which is legitimate and skips. A
// `jaan-to/` that is present but whose STATE.json is missing, unparseable or campaign-less is a
// FAILURE, because in that checkout the file is supposed to be there.
$sibling    = dirname(__DIR__, 2) . '/jaan-to';
$state_path = $sibling . '/outputs/dev/v6-performance/STATE.json';
$mode       = 'FIXTURES ONLY';

if (is_dir($sibling)) {
    if (!is_file($state_path)) {
        $failures[] = sprintf(
            'the jaan-to sibling is present but %s is not — if the programme state moved, this gate moved with it and nobody updated it',
            $state_path
        );
    } else {
        $state = json_decode((string) file_get_contents($state_path), true);
        if (!is_array($state)) {
            $failures[] = 'STATE.json is present but does not parse as JSON';
        } elseif (!isset($state['campaign']) || !is_array($state['campaign'])) {
            $failures[] = 'STATE.json has no campaign block';
        } else {
            $mode = 'STATE.json';
            foreach (slimstat_campaign_problems($state['campaign'], (int) ($state['latest_run'] ?? 0)) as $problem) {
                $failures[] = 'STATE.json: ' . $problem;
            }
        }
    }
}

if ([] !== $failures) {
    fwrite(STDERR, sprintf("FAIL: campaign phase gate (%d problem(s))\n", count($failures)));
    foreach ($failures as $problem) {
        fwrite(STDERR, '  - ' . $problem . "\n");
    }
    exit(1);
}

printf(
    "PASS: campaign phase gate — %d fixtures (%d required-red), checked against %s\n",
    count($fixtures),
    $reds,
    'STATE.json' === $mode
        ? 'the real STATE.json'
        : 'FIXTURES ONLY (no jaan-to sibling in this checkout — the invariants still ran)'
);
