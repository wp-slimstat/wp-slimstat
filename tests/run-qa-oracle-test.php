<?php
/**
 * Source-level: every FAIL run-qa.sh prints must be able to reach its exit code.
 *
 * THE PR #166 REGRESSION ORACLE PRINTED FAIL AND EXITED 0.
 *
 * `tests/run-qa.sh` ends with `TOTAL_EXIT=$((E2E_EXIT + K6_EXIT))` and `exit $TOTAL_EXIT`.
 * The AJAX-invocation check — the one thing in the script that watches for PR #166's infinite
 * AJAX loop coming back — printed a red `FAIL: Excessive AJAX calls detected` and assigned
 * nothing at all. The script then summed two other variables and exited 0.
 *
 * So the oracle for the regression this whole script was written around could observe the
 * regression, say so on the terminal in red, and report success to everything downstream. Any
 * caller reading `$?` — a wrapper, a CI step, a `&&` chain — saw a pass. This is the workspace's
 * most-repeated defect wearing its plainest costume: a check that runs, reaches the right
 * conclusion, and cannot act on it.
 *
 * THE RULE. Every red FAIL print must be accounted for in one of three ways:
 *
 *   (a) the message itself interpolates a variable that feeds the final exit — the failure was
 *       already captured upstream (`... || E2E_EXIT=$?`) and the print is just reporting it;
 *   (b) a following line assigns a variable that feeds the final exit;
 *   (c) a following line exits non-zero directly.
 *
 * WHAT THIS DOES NOT ESTABLISH. Rule (a) reads an interpolation, not a data flow: a message that
 * merely mentions `$E2E_EXIT` while nothing ever assigns it would satisfy it. That is a weaker
 * claim than "the exit code is correct", and it is stated here rather than implied because the
 * alternative — a real shell dataflow analysis in PHP — is a second parser, and this workspace
 * has already recorded what two parsers that disagree cost. The defect this gate exists to catch
 * is the one that assigns and mentions nothing, and that one it catches exactly.
 *
 * Nor does it establish that run-qa.sh RUNS. It executes in no workflow and needs Local's
 * version-matched MySQL client. Fixing the exit code makes the oracle capable of failing; it
 * does not make it run.
 */

declare(strict_types=1);

$plugin_root = dirname(__DIR__);
$script      = $plugin_root . '/tests/run-qa.sh';
$failures    = [];

if (!is_file($script)) {
    fwrite(STDERR, "FAIL: tests/run-qa.sh is missing — the oracle this gate checks does not exist\n");
    exit(1);
}

$lines = explode("\n", (string) file_get_contents($script));
$n     = count($lines);

// ── The exit set: variables whose value reaches `exit` ────────────────────────────────
//
// Seeded from the final `exit $VAR` and closed over assignments, so a future
// `TOTAL_EXIT=$((E2E_EXIT + K6_EXIT + AJAX_EXIT))` extends it without editing this file.

$exit_vars = [];

foreach ($lines as $line) {
    if (preg_match('/^\s*exit\s+"?\$\{?([A-Za-z_][A-Za-z0-9_]*)\}?"?\s*$/', $line, $m)) {
        $exit_vars[$m[1]] = true;
    }
}

if (!$exit_vars) {
    $failures[] = 'run-qa.sh never exits with a variable. Either it always exits with a literal '
        . '— in which case its result is fixed regardless of what it observed — or this scan no '
        . 'longer understands the script, and everything below it is vacuous';
}

// Transitive closure: any variable appearing on the right of an assignment to a member.
// Runs to a fixpoint rather than a fixed number of passes. One pass happens to suffice for this
// script, but only because of the order its assignments appear in, and a bound chosen to be
// "obviously enough" is a number nobody can defend later.
do {
    $before = count($exit_vars);
    foreach ($lines as $line) {
        if (!preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
            continue;
        }
        if (!isset($exit_vars[$m[1]])) {
            continue;
        }
        $rhs = $m[2];

        // Arithmetic expansion drops the sigil: `TOTAL_EXIT=$((E2E_EXIT + K6_EXIT))` names two
        // variables and contains no `$VAR` at all. Reading only the sigil form closed the set to
        // {TOTAL_EXIT}, which made rule (a) reject the two correct sites and reduced this gate to
        // "every FAIL is unaccounted" — a check that fires on everything is as useless as one
        // that fires on nothing, and it took the vacuity floor above to say so.
        if (preg_match_all('/\$\(\((.*?)\)\)/s', $rhs, $arith)) {
            foreach ($arith[1] as $expr) {
                if (preg_match_all('/(?<![$\w])([A-Za-z_][A-Za-z0-9_]*)/', $expr, $bare)) {
                    foreach ($bare[1] as $ref) {
                        $exit_vars[$ref] = true;
                    }
                }
            }
        }

        if (preg_match_all('/\$\{?([A-Za-z_][A-Za-z0-9_]*)\}?/', $rhs, $refs)) {
            foreach ($refs[1] as $ref) {
                $exit_vars[$ref] = true;
            }
        }
    }
} while (count($exit_vars) > $before);

// VACUITY FLOOR. One variable is `exit $TOTAL_EXIT` alone with the closure broken.
if (count($exit_vars) < 3) {
    $failures[] = 'the exit set closed to only ' . count($exit_vars) . ' variable(s) ('
        . implode(', ', array_keys($exit_vars)) . '); expected at least 3 — the closure is not '
        . 'following assignments, so rule (a) below would reject correct code and rule (b) would '
        . 'accept nothing';
}

// ── Every red FAIL print must be accounted for ────────────────────────────────────────

$fail_sites = 0;

for ($i = 0; $i < $n; $i++) {
    // A red FAIL announcement. Colour-coded because that is what distinguishes a verdict from
    // the word appearing in a comment, a heading, or a variable name.
    if (!preg_match('/\$\{RED\}[^"\']*FAIL/', $lines[$i])) {
        continue;
    }
    $fail_sites++;
    $line_no = $i + 1;

    // (a) already-captured: the message interpolates a variable that feeds the exit.
    $accounted = false;
    if (preg_match_all('/\$\{?([A-Za-z_][A-Za-z0-9_]*)\}?/', $lines[$i], $refs)) {
        foreach ($refs[1] as $ref) {
            if (isset($exit_vars[$ref])) {
                $accounted = true;
                break;
            }
        }
    }

    // (b) assignment to an exit variable, or (c) a direct non-zero exit, in the branch below.
    // Eight lines is the whole of the longest such branch in this script; a branch longer than
    // that which sets its variable at the end is not a shape this file has, and inventing a
    // shell block parser to cover one would be a second parser for no measured defect.
    for ($k = $i; !$accounted && $k < min($n, $i + 9); $k++) {
        if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)=/', $lines[$k], $m) && isset($exit_vars[$m[1]])) {
            $accounted = true;
        }
        if (preg_match('/^\s*exit\s+([1-9][0-9]*|\$)/', $lines[$k])) {
            $accounted = true;
        }
    }

    if (!$accounted) {
        $failures[] = sprintf(
            'run-qa.sh:%d prints a red FAIL that reaches no exit code: %s. Nothing on or after '
                . 'this line assigns a variable in the exit set (%s) or exits non-zero, so the '
                . 'script prints the failure and then reports success to its caller — which is '
                . 'the whole of what an oracle is for',
            $line_no,
            trim($lines[$i]),
            implode(', ', array_keys($exit_vars))
        );
    }
}

// VACUITY FLOOR. The script has five red FAIL prints; zero means the regex stopped matching and
// a green result here would mean nothing whatsoever.
if ($fail_sites < 4) {
    $failures[] = 'found only ' . $fail_sites . ' red FAIL print(s) in run-qa.sh; expected at '
        . 'least 4 — the scan is not matching and this gate proves nothing';
}

if ($failures) {
    fwrite(STDERR, 'FAIL: run-qa.sh oracle (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo 'PASS: all ' . $fail_sites . ' red FAIL print(s) in run-qa.sh reach the exit code (exit set: '
    . implode(', ', array_keys($exit_vars)) . ")\n";
