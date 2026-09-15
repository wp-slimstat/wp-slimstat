<?php
// Is each control in a CONTROLS gate actually REACHED, and does its failure actually change the
// EXIT CODE?
//
//   php tests/docker/reachability/analyse-controls.php <subject.php> [--json]
//
// WHY THIS EXISTS. A 42KB gate is exactly where a control can be present, correct, and never
// executed. This programme has now shipped four of that shape in a row — a gate wired into
// nothing, a fixture that could not tell whether the guard it named was there, a read path that
// structurally could not observe the property its rule was about, and a paragraph announcing a
// mechanism that was never written (PITFALLS 31, 61, 63, 64). Every one of them was found by
// running something, and none by reading.
//
// So this reads the subject with the PHP tokeniser rather than with regexes over prose, and
// answers two questions per control:
//
//   REACHABLE       is the `$control(...)` call site in the file's top-level statement stream —
//                   possibly inside try/if/foreach, which the interpreter enters — or is it
//                   inside a function or closure DECLARATION, where it runs only if something
//                   calls it? A call site nested in a declaration is reported unreachable and
//                   the declaration chain is printed, because "it is in the file" is the exact
//                   evidence-shaped non-evidence this file exists to refuse.
//   EXIT-EFFECTIVE  does the callable that renders the control also record into the variable the
//                   terminal `exit(1)` is guarded on? A control that renders a line into a list
//                   nobody reads is dead in the only sense that matters.
//
// WHAT IT IS NOT. It is a static reading, so it cannot see a call site that is reachable in the
// token stream but unreachable in fact (`if (false)` written as a runtime constant, an early
// return above it, an exception thrown on every path). The live control self-test in
// run-rollup-floor.sh is what covers that: it forces each control to fail in turn and requires
// the run to exit 1 naming exactly that control. This file is the cheap regression gate — it
// runs in CI with no database — and the self-test is the measurement. Where they disagree, the
// self-test wins, because it is the one that executed.
//
// ITS OWN KILLABILITY is not asserted here either. tests/docker/reachability/ carries two
// pre-declared differential mutations — one severing a control's call chain, one leaving a
// control executing while disconnecting its failure from the exit status — and the gate is only
// evidence about the subject once it has been shown to go red under both.
//
// WHERE IT RUNS. `composer test:control-wiring`, inside `composer test:source-level`, on every
// CI lane — no database, milliseconds. It said "it runs in CI" for a while before that was true,
// which is PITFALLS 31 reproduced inside the file written to catch that class; the composer entry
// is what makes the sentence checkable. `tests/docker/reachability/run-gate.sh` drives the
// differential protocol over this same binary rather than owning a second copy of it.
//
// 7.4-safe: plain functions, no autoloader, no WordPress.

declare(strict_types=1);

// The tokeniser facts this file must not re-derive live in tests/lib/source-scan.php, and the
// reason they live in one place is written on them: `"{$x}"` emits its OPENING curly as the array
// token T_CURLY_OPEN and its CLOSING one as a plain `'}'`, so a walker that pushes only on the
// bare-string `'{'` drains its own frame stack and then answers "top level" to everything. This
// subject contains eleven such tokens. Skipping only T_WHITESPACE has the matching failure:
// `$control /* note */ (` stops being recognised as a call at all, and a control that vanishes
// from the report is worse than one reported broken, because the summary still says PASS.
require_once dirname(__DIR__, 2) . '/lib/source-scan.php';

/**
 * Frames the tokeniser is inside at a given point. 'decl' frames (function/closure/arrow-fn
 * bodies) are the ones that break reachability from the top level; every other brace is a block
 * the interpreter walks into on its own.
 *
 * @return array{controls: array, callables: array, terminal: array, tokens: array}
 */
function fpr_analyse(string $source): array
{
    $tokens = token_get_all($source);
    $n      = count($tokens);

    // ── Pass 1: frame tracking + call-site collection ───────────────────────────────────────
    $frames    = [];   // stack of ['kind' => 'decl'|'block', 'label' => string, 'line' => int]
    $pending   = null; // a declaration seen but whose '{' has not arrived yet
    $controls  = [];
    $callables = [];
    $terminal  = ['found' => false];

    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];

        if (is_array($t)) {
            $id   = $t[0];
            $text = $t[1];
            $line = $t[2];

            if (T_FUNCTION === $id || T_FN === $id) {
                // The name, if any, is the next significant token; anonymous ones are the
                // closures this file cares about.
                $label = 'closure';
                $j     = slimstat_next_significant($tokens, $i);
                if ($j < $n && is_array($tokens[$j]) && T_STRING === $tokens[$j][0]) {
                    $label = 'function ' . $tokens[$j][1];
                }
                $pending = ['kind' => 'decl', 'label' => $label, 'line' => $line];
                continue;
            }

            // `if ($var)` — remembered so the terminal exit can name the variable it answers to
            // instead of this file naming one it believes in. Only the single-variable form is
            // recognised, because that is the only form whose guard is unambiguous; anything
            // else leaves the frame unmarked and the terminal reports `guard: null`, which the
            // driver treats as a failure rather than as a default.
            if (T_IF === $id) {
                $open = slimstat_next_significant($tokens, $i);
                if ($open < $n && '(' === $tokens[$open]) {
                    $args = fpr_call_arguments($tokens, $open, $n);
                    if (1 === count($args)) {
                        $sig = fpr_significant($args[0]);
                        if (1 === count($sig) && is_array($sig[0]) && T_VARIABLE === $sig[0][0]) {
                            $pending = [
                                'kind'  => 'block',
                                'label' => 'if ' . $sig[0][1],
                                'line'  => $line,
                                'guard' => ltrim($sig[0][1], '$'),
                            ];
                        }
                    }
                }
                continue;
            }

            // `"{$x}"` and `"${x}"`: the OPENING curly arrives as one of these array tokens while
            // the matching close is a plain `'}'`. Pushed as a block frame so the pop below has
            // something to take, which is the whole of the bug this guards against — array_pop()
            // on an empty stack is a silent no-op, so the miscount does not surface as an error,
            // it surfaces as every later call site reading as top-level.
            if (T_CURLY_OPEN === $id || T_DOLLAR_OPEN_CURLY_BRACES === $id) {
                $frames[] = ['kind' => 'block', 'label' => 'interpolation', 'line' => $line];
                continue;
            }

            // A control call site, recognised by SHAPE rather than by the callable's NAME:
            // a variable invoked with at least three arguments whose second is an integer
            // literal and whose third is a quoted string. The first version matched a `$control`
            // name prefix, which is the property a real defect is least likely to preserve — a
            // control quietly routed through `$render(...)` would have vanished from the report
            // entirely, and a control the analyser cannot see is not a control it cleared. The
            // callable's name is still RECORDED verbatim, because that is what makes a second
            // renderer visible as a different callable instead of silently as the same one.
            if (T_VARIABLE === $id) {
                $k = slimstat_next_significant($tokens, $i);
                if ($k < $n && '(' === $tokens[$k]) {
                    $args = fpr_call_arguments($tokens, $k, $n);
                    if (count($args) >= 3) {
                        $number = fpr_int_literal($args[1]);
                        $name   = fpr_string_literal($args[2]);
                        if (null !== $number && null !== $name) {
                            $controls[] = [
                                'n'         => $number,
                                'name'      => $name,
                                'line'      => $line,
                                'callable'  => $text,
                                'enclosing' => fpr_frame_labels($frames),
                            ];
                        }
                    }
                    continue;
                }
                // `$name = function (...)` — a definition. Every one is recorded; only those a
                // control call site actually goes through are ever looked up.
                if ($k < $n && '=' === $tokens[$k]) {
                    $fn = slimstat_next_significant($tokens, $k);
                    if ($fn < $n && is_array($tokens[$fn]) && in_array($tokens[$fn][0], [T_FUNCTION, T_FN], true)) {
                        $callables[$text] = ['line' => $line, 'start' => $i];
                    }
                }
                continue;
            }

            // The terminal: `exit(<int>)`. Every one is recorded with the frames it sits in, so
            // an exit moved inside a declaration reads as what it is.
            if (T_EXIT === $id) {
                $k    = slimstat_next_significant($tokens, $i);
                $code = null;
                if ($k < $n && '(' === $tokens[$k]) {
                    $args = fpr_call_arguments($tokens, $k, $n);
                    if (1 === count($args)) {
                        $code = fpr_int_literal($args[0]);
                    }
                }
                if (null !== $code && $code > 0) {
                    // The guard is DERIVED, not assumed: the innermost enclosing frame that was
                    // opened by `if ($var)` names the variable this exit answers to. An earlier
                    // version carried a comment saying exactly this above the line
                    // `$guard = 'failures';`, which is the PITFALLS 64 shape — a mechanism
                    // announced in prose — sitting inside the file written to catch that class.
                    $guard = null;
                    for ($f = count($frames) - 1; $f >= 0; $f--) {
                        if (isset($frames[$f]['guard'])) {
                            $guard = $frames[$f]['guard'];
                            break;
                        }
                    }
                    $terminal = [
                        'found'     => true,
                        'line'      => $line,
                        'code'      => $code,
                        'guard'     => $guard,
                        'enclosing' => fpr_frame_labels($frames),
                    ];
                }
            }
            continue;
        }

        if ('{' === $t) {
            $frames[] = $pending ?: ['kind' => 'block', 'label' => 'block', 'line' => 0];
            $pending  = null;
            continue;
        }
        if ('}' === $t) {
            array_pop($frames);
            continue;
        }
        // A `;` closing an abstract/interface method declaration would leave $pending dangling.
        if (';' === $t) {
            $pending = null;
        }
    }

    return ['controls' => $controls, 'callables' => $callables, 'terminal' => $terminal, 'tokens' => $tokens];
}

/** Labels of the frames a token sits inside, outermost first. */
function fpr_frame_labels(array $frames): array
{
    $out = [];
    foreach ($frames as $f) {
        $out[] = $f['kind'] . ':' . $f['label'] . ($f['line'] ? '@' . $f['line'] : '');
    }
    return $out;
}

/** True when no enclosing frame is a declaration — i.e. the interpreter gets here on its own. */
function fpr_top_level(array $labels): bool
{
    foreach ($labels as $l) {
        if (0 === strpos($l, 'decl:')) {
            return false;
        }
    }
    return true;
}

/**
 * Split a call's arguments at $open (the '(' token index) into per-argument token lists.
 *
 * @return array<int, array> one entry per top-level argument
 */
function fpr_call_arguments(array $tokens, int $open, int $n): array
{
    $depth = 0;
    $args  = [];
    $cur   = [];
    for ($i = $open; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) {
            if ('(' === $t || '[' === $t || '{' === $t) {
                $depth++;
                if (1 === $depth) {
                    continue;   // the opening paren itself is not part of an argument
                }
            } elseif (')' === $t || ']' === $t || '}' === $t) {
                $depth--;
                if (0 === $depth) {
                    $args[] = $cur;
                    return $args;
                }
            } elseif (',' === $t && 1 === $depth) {
                $args[] = $cur;
                $cur    = [];
                continue;
            }
        }
        if ($depth >= 1) {
            $cur[] = $t;
        }
    }
    return $args;   // unbalanced; caller sees too few arguments and skips
}

/**
 * An argument's tokens with whitespace AND comments removed. Comments matter: an argument written
 * `4 /* SERVER-SIDE * /` is one literal to PHP and two tokens to a naive filter, and the control
 * would then read as unparseable rather than as control 4.
 */
function fpr_significant(array $arg): array
{
    $sig = [];
    foreach ($arg as $t) {
        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $sig[] = $t;
    }
    return $sig;
}

/** The sole token an argument is, when it is exactly one token of $type. */
function fpr_sole_token(array $arg, int $type): ?array
{
    $sig = fpr_significant($arg);
    return (1 === count($sig) && is_array($sig[0]) && $type === $sig[0][0]) ? $sig[0] : null;
}

/** The integer an argument is, when it is exactly one integer literal. */
function fpr_int_literal(array $arg): ?int
{
    $t = fpr_sole_token($arg, T_LNUMBER);
    return null === $t ? null : (int) $t[1];
}

/** The string an argument is, when it is exactly one quoted literal. */
function fpr_string_literal(array $arg): ?string
{
    $t = fpr_sole_token($arg, T_CONSTANT_ENCAPSED_STRING);
    return null === $t ? null : trim($t[1], "'\"");
}

/**
 * Does the callable defined at token $start write into $guard, and does it capture it by
 * reference? Both are required: a `use ($failures)` by VALUE compiles, runs, appends to a copy
 * and changes nothing the terminal can see — the quietest possible way to disconnect a control.
 *
 * @return array{writes: bool, by_reference: bool}
 */
function fpr_callable_effect(array $tokens, int $start, string $guard): array
{
    $n            = count($tokens);
    $writes       = false;
    $by_reference = false;
    $depth        = 0;
    $entered      = false;
    $in_use       = false;

    for ($i = $start; $i < $n; $i++) {
        $t = $tokens[$i];

        if (is_array($t) && defined('T_USE') && T_USE === $t[0] && !$entered) {
            $in_use = true;
            continue;
        }
        if ($in_use && is_array($t) && T_VARIABLE === $t[0] && ltrim($t[1], '$') === $guard) {
            // `&` immediately before the variable, whitespace allowed. PHP 8.1 split the
            // ampersand into T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG and its NOT_ sibling, so on
            // 8.1+ this arrives as an ARRAY token and a `'&' === $token` comparison silently
            // reports every by-reference capture as by-value. Compared by TEXT, which is the one
            // form both tokenisers agree on — the floor here is 7.4 and the CI ceiling is 8.4.
            for ($j = $i - 1; $j > $start; $j--) {
                if (is_array($tokens[$j])
                    && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $by_reference = ('&' === (is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j]));
                break;
            }
        }

        if (!is_array($t)) {
            if ('{' === $t) {
                $depth++;
                if (!$entered) {
                    $entered = true;
                    $in_use  = false;
                }
                continue;
            }
            if ('}' === $t) {
                $depth--;
                if ($entered && 0 === $depth) {
                    break;
                }
                continue;
            }
            if (')' === $t) {
                $in_use = false;
            }
            continue;
        }

        if ($entered && T_VARIABLE === $t[0] && ltrim($t[1], '$') === $guard) {
            // `$guard[] =` or `$guard =` — an append or an assignment. Reading it does not count.
            $j = slimstat_next_significant($tokens, $i);
            if ($j < $n && '[' === $tokens[$j] && isset($tokens[$j + 1]) && ']' === $tokens[$j + 1]) {
                $writes = true;
            }
        }
    }

    return ['writes' => $writes, 'by_reference' => $by_reference];
}

// ── Driver ──────────────────────────────────────────────────────────────────────────────────

$argv_in = $argv;
array_shift($argv_in);
$as_json = false;
$subject = null;
foreach ($argv_in as $a) {
    if ('--json' === $a) {
        $as_json = true;
    } else {
        $subject = $a;
    }
}
if (null === $subject) {
    $subject = dirname(__DIR__) . '/verify-export-fingerprint.php';
}
if (!is_file($subject)) {
    fwrite(STDERR, "no such subject: {$subject}\n");
    exit(2);
}

$source = (string) file_get_contents($subject);
$found  = fpr_analyse($source);
$tokens = $found['tokens'];   // ONE tokenise of a 70KB file, not two

// The guard the terminal exit is written on, DERIVED from the subject rather than assumed: the
// innermost `if ($var)` frame enclosing the terminal `exit(<n>)` names it. An earlier version of
// this file carried exactly the sentence above over the line `$guard = 'failures';` — prose
// describing a mechanism, in the analyser written to catch that class (PITFALLS 64). A subject
// whose terminal is not guarded on a single variable yields null here, and that is a FAILURE
// below rather than a fallback: an analyser that cannot find the exit's guard cannot say
// anything about whether a control reaches it.
$guard = isset($found['terminal']['guard']) ? $found['terminal']['guard'] : null;

$callable_effects = [];
foreach ($found['callables'] as $var => $meta) {
    $callable_effects[$var] = (null === $guard)
        ? ['writes' => false, 'by_reference' => false, 'line' => $meta['line']]
        : fpr_callable_effect($tokens, $meta['start'], $guard) + ['line' => $meta['line']];
}

$report = [];
foreach ($found['controls'] as $c) {
    $callable  = $c['callable'];
    $effect    = isset($callable_effects[$callable]) ? $callable_effects[$callable] : null;
    $reachable = fpr_top_level($c['enclosing']);
    $reasons   = [];

    if (!$reachable) {
        $reasons[] = 'the call site is inside ' . implode(' > ', array_filter($c['enclosing'], function ($l) {
            return 0 === strpos($l, 'decl:');
        })) . ', so it runs only if something calls that declaration';
    }
    if (null === $effect) {
        $reasons[] = "no definition of {$callable} was found in this file, so what it does with a failure is unknown";
    } elseif (null === $guard) {
        $reasons[] = 'the terminal exit is not guarded on a single variable, so no callable can be shown to reach it';
    } elseif (!$effect['writes']) {
        $reasons[] = "{$callable} never appends to \${$guard}, so a failed control cannot reach the terminal exit";
    } elseif (!$effect['by_reference']) {
        $reasons[] = "{$callable} captures \${$guard} BY VALUE, so its appends are discarded when it returns";
    }

    $report[] = [
        'n'               => $c['n'],
        'name'            => $c['name'],
        'line'            => $c['line'],
        'callable'        => $callable,
        'enclosing'       => $c['enclosing'],
        'reachable'       => $reachable,
        'exit_effective'  => $reachable && null !== $guard && null !== $effect
            && $effect['writes'] && $effect['by_reference'],
        'reasons'         => $reasons,
    ];
}

usort($report, function ($a, $b) {
    return $a['n'] - $b['n'];
});

$numbers    = array_map(function ($r) { return $r['n']; }, $report);
$duplicates = array_values(array_diff_assoc($numbers, array_unique($numbers)));

$failures = [];
if (!$report) {
    $failures[] = 'no control call sites were found at all — either the subject changed shape or this analyser is looking for the wrong thing, and both are failures';
}
if (!$found['terminal']['found']) {
    $failures[] = 'no `exit(<non-zero>)` was found, so nothing this file records can change the exit status';
} elseif (!fpr_top_level($found['terminal']['enclosing'])) {
    $failures[] = 'the terminal exit is inside ' . implode(' > ', $found['terminal']['enclosing']) . ', so it does not run on the ordinary path';
}
if ($duplicates) {
    $failures[] = 'control numbers are not distinct: ' . implode(', ', $duplicates)
        . ' appears more than once, so a run naming one of them does not say which';
}
foreach ($report as $r) {
    if (!$r['reachable']) {
        $failures[] = sprintf('CONTROL %d (%s) at line %d is UNREACHABLE: %s', $r['n'], $r['name'], $r['line'], implode('; ', $r['reasons']));
    } elseif (!$r['exit_effective']) {
        $failures[] = sprintf('CONTROL %d (%s) at line %d is EXIT-INEFFECTIVE: %s', $r['n'], $r['name'], $r['line'], implode('; ', $r['reasons']));
    }
}

$out = [
    'subject'   => $subject,
    'sha256'    => hash('sha256', $source),
    'bytes'     => strlen($source),
    'analyser'  => 'tests/docker/reachability/analyse-controls.php',
    'guard'     => '$' . $guard,
    'terminal'  => $found['terminal'],
    'callables' => $callable_effects,
    'controls'  => $report,
    'summary'   => [
        'declared'       => count($report),
        'reachable'      => count(array_filter($report, function ($r) { return $r['reachable']; })),
        'exit_effective' => count(array_filter($report, function ($r) { return $r['exit_effective']; })),
    ],
    'failures'  => $failures,
    'verdict'   => $failures ? 'FAIL' : 'PASS',
];

if ($as_json) {
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} else {
    printf("SLIMSTAT-CONTROL-WIRING subject=%s sha256=%s controls=%d reachable=%d exit_effective=%d verdict=%s\n",
        basename($subject), substr($out['sha256'], 0, 16), $out['summary']['declared'],
        $out['summary']['reachable'], $out['summary']['exit_effective'], $out['verdict']);
    foreach ($report as $r) {
        printf("  [%s] %d %-13s line %-4d via %s%s\n",
            ($r['reachable'] && $r['exit_effective']) ? 'OK' : '!!',
            $r['n'], (string) $r['name'], $r['line'], $r['callable'],
            $r['reasons'] ? ' — ' . implode('; ', $r['reasons']) : '');
    }
}

// Under --json, stdout carries the JSON document and NOTHING else. The trailer went to stdout
// once, and every consumer's json_decode() failed on "Extra data" — a parse error that reads as
// a broken analyser rather than as a formatting slip, which is the worst way for this particular
// file to fail given what it is for.
if ($failures) {
    fwrite(STDERR, "FAIL: the control wiring is not what the subject claims (" . count($failures) . ")\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}
$pass = "PASS: all {$out['summary']['declared']} controls are reached from the top-level statement stream "
    . "and record into \${$guard}, which guards exit({$found['terminal']['code']})\n";
if ($as_json) {
    fwrite(STDERR, $pass);
} else {
    echo $pass;
}
