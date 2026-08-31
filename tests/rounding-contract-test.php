<?php
/**
 * Source-level: every rounded percentage divides LAST, so its value does not depend on the
 * interpreter it was computed on.
 *
 * WHY THIS EXISTS — ADR-17, and PITFALLS 72 whose *interpretation* ADR-17 inverts.
 *
 * Every percentage in these reports is a ratio of two integer COUNTs. The exact mathematical
 * value is therefore a rational number, and `round()`'s documented default mode
 * (`PHP_ROUND_HALF_UP`, "rounds num away from zero when it is half way there") is a statement
 * about THAT number — not about whichever double a particular sequence of float operations
 * happened to produce on the way to it.
 *
 * The shipped shape was:
 *
 *     round(($a / $b) * 100, $p)      // divide, THEN multiply
 *
 * `$a / $b` is a double. Multiplying it by 100 is a second rounding, and for a whole class of
 * ordinary ratios it lands one ULP BELOW the exact half:
 *
 *     (23 / 40) * 100  ==  57.49999999999999289457      <- what round() was handed
 *     (100 * 23) / 40  ==  57.50000000000000000000      <- exact; one correctly-rounded division
 *
 * PHP <= 8.3's `round()` "pre-rounded" its argument, which put the lost boundary back and made
 * the report correct BY COMPENSATION. PHP 8.4 removed the pre-rounding — correctly, as an
 * upstream bug fix in its own terms (php-src UPGRADING, PHP 8.4, Changed Functions) — and the
 * plugin's latent defect became visible: 23 of 40 funnel visitors rendered 57% on 8.4/8.5 and
 * 58% on 7.4-8.3. The compensation was removed before the defect was.
 *
 * ADR-17 enumerated 12,507,500 (a, b) pairs per shape on seven interpreters: 1,177 reachable
 * pairs diverge, the smallest reduced ones being 23/40 (0 dp), 23/80 (1 dp) and 23/160 (2 dp).
 * Those three are the fixtures below. They are not curiosities — "23 of 40 step-one visitors
 * reached step two" is an ordinary funnel.
 *
 * WHAT THIS FILE ASSERTS, and why each half is here:
 *
 *   1. ARITHMETIC, over the real source bytes. For each site it locates the actual `round()`
 *      call in the shipped file, binds the fixture integers to the operands the source names,
 *      and EVALUATES THE SHIPPED EXPRESSION. It then checks two things:
 *        (a) the double handed to `round()` is the exact value of 100a/b. This is
 *            VERSION-INVARIANT — it is false on 7.4 exactly as it is false on 8.5 — which is
 *            what makes this gate a statement about the plugin rather than about the runtime.
 *        (b) the rounded output equals exact half-up over the integer inputs. This one is
 *            runtime-dependent while the defect is present (it is masked on <= 8.3) and is the
 *            user-visible claim.
 *      The expected values come from INTEGER arithmetic — `intdiv`/`%` on a*100*10^p and b —
 *      never from calling `round()` and recording what came back. A fixture blessed from the
 *      implementation proves the implementation equals itself.
 *
 *   2. A REPO-WIDE RATCHET. Every `round()` / `sprintf()` / `number_format*()` call in shipped
 *      PHP is tokenised, and a multiplication that CONSUMES the result of a division is a
 *      failure wherever it appears. Six sites is what ADR-17 counted; the scan is what keeps
 *      the seventh from arriving unnoticed. It is depth-aware, so `round($x / (7 * 86400))`
 *      — a division by a computed constant, which is fine — is not flagged.
 *
 *   3. NO SECOND ROUNDING RULE, repo-wide. `sprintf`/`printf` with a `%.Nf` conversion applied
 *      to an expression containing a division is a failure wherever it appears — that is
 *      sprintf being used as a rounding function, which it is not. New with #334/#335.
 *
 * ── WHAT IT USED TO SAY IT DELIBERATELY DID NOT ASSERT, AND NOW DOES ────────────────────────
 *
 * Until 2026-08-31 this section read: *"`sprintf('%.Nf')` rounds ties-to-EVEN on every runtime,
 * so `1/32` prints `3.12` where the documented half-up rule says `3.13`, on the percentage
 * beside every row of every top-N report (ADR-17 §5a, 'F1') … this file does not claim it does.
 * Same for Pro's email report double-rounding ('F4')."* That was honest and correct while it
 * stood. Both are fixed now — E5/E6 below assert F1, Pro's sibling asserts F4 — and assertion 3
 * stops either coming back at a site nobody thought to add to the table.
 *
 * ── THE LIMIT THIS FILE STILL HAS, stated rather than left to be discovered ─────────────────
 *
 * Assertion (b) — "renders exact half-up" — can only tell half-up from ties-to-even on a
 * fixture where the two rules DISAGREE. Measured: of the eight sites below, only **E5 and E6**
 * carry such a fixture (1/32 = 3.125 at 2 dp). E1 (23/160), E3/E3' (23/80) and E4/E4'/E4''
 * (23/40) render identically under both rules at their precisions, so for those six (b) checks
 * precision and operand order but NOT the rounding rule. Their fixtures were chosen for
 * ADR-17's boundary property and still serve assertion (a); giving them tie fixtures too means
 * a second fixture per site, which is owed work and not done here. Assertion 3 covers the gap
 * repo-wide in the meantime — it is the reason that gap is tolerable.
 *
 * 7.4-safe: bare PHP, no PHPUnit, no WordPress, no vendor autoloader.
 *
 * Run: php tests/rounding-contract-test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];
$checks      = 0;

// ── The oracle: exact arithmetic over the integer inputs ─────────────────────────────────────
//
// No float appears in any of these three functions. That is the point: the whole defect is a
// float standing in for a rational, so an expectation computed with a float would be measuring
// the bug against itself.

/** Greatest common divisor, for the representability guard below. */
function rc_gcd(int $x, int $y): int
{
    while (0 !== $y) {
        [$x, $y] = [$y, $x % $y];
    }

    return $x < 0 ? -$x : $x;
}

/**
 * Exact half-up (away from zero) of 100·$a/$b at $places, as a decimal STRING.
 *
 * Returned as a string, and compared as a string, so that the ASSERTION itself never puts the
 * answer back through a float. Non-negative inputs only — every ratio here is a count over a
 * count — and "half up" and "half away from zero" coincide there. Negative input is refused
 * rather than silently handled, because a helper that quietly rounds -0.5 the wrong way is the
 * kind of thing that gets discovered by a report, not by a test.
 */
function rc_exact_half_up(int $a, int $b, int $places): string
{
    if ($a < 0 || $b <= 0 || $places < 0) {
        throw new InvalidArgumentException('rc_exact_half_up: counts are non-negative and b > 0');
    }
    $scale = (int) (10 ** $places);
    $n     = 100 * $a * $scale;
    $q     = intdiv($n, $b);
    $r     = $n % $b;

    // The whole rule, in integers: the remainder is at or past the half when 2r >= b.
    if (2 * $r >= $b) {
        $q++;
    }

    if (0 === $places) {
        return (string) $q;
    }

    return intdiv($q, $scale) . '.' . str_pad((string) ($q % $scale), $places, '0', STR_PAD_LEFT);
}

/**
 * The exact decimal expansion of 100·$a/$b, TRUNCATED to $digits places, by long division.
 *
 * Truncated rather than rounded, so that for a value whose expansion terminates this is the
 * exact value padded with zeros — directly comparable with `sprintf('%.NF', $double)`, which
 * prints the true decimal expansion of the binary double.
 */
function rc_exact_expansion(int $a, int $b, int $digits): string
{
    $n   = 100 * $a;
    $out = (string) intdiv($n, $b);
    $r   = $n % $b;

    if ($digits <= 0) {
        return $out;
    }
    $frac = '';
    for ($i = 0; $i < $digits; $i++) {
        $r   *= 10;
        $frac .= (string) intdiv($r, $b);
        $r    %= $b;
    }

    return $out . '.' . $frac;
}

/**
 * Is 100·$a/$b exactly representable as a double?
 *
 * True when the reduced denominator is a power of two and the reduced numerator fits in 53
 * bits. This guard is load-bearing, not decoration: assertion (a) below compares a double
 * against an EXACT decimal string, and for a value like 100·1/3 no double equals it, so the
 * comparison would fail for a reason that has nothing to do with the operand order. A fixture
 * that cannot support the assertion is refused loudly rather than compared weakly.
 */
function rc_is_exactly_representable(int $a, int $b): bool
{
    $n = 100 * $a;
    $d = $b;
    $g = rc_gcd($n, $d);
    if ($g > 0) {
        $n = intdiv($n, $g);
        $d = intdiv($d, $g);
    }
    while (0 === $d % 2) {
        $d = intdiv($d, 2);
    }

    return 1 === $d && $n <= (1 << 53);
}

// ── The tokeniser: rounding call sites, and the shape that loses the boundary ────────────────


/**
 * The functions that actually round HALF-UP — deliberately NOT the scan set.
 *
 * The scan set (`$names` below) is "calls this gate looks at" and includes sprintf/printf. Those
 * do NOT round half-up; that they round ties-to-EVEN is the entire premise of this file. Reusing
 * the scan set as the rounder set made the nested-call guard suppress
 * `sprintf('%.2f', sprintf('%s', $a / $b))`, which prints 3.12 for 1/32 — the exact wrong answer
 * the guard's own failure message quotes. Measured, then separated.
 *
 * number_format/number_format_i18n ARE here: measured, number_format() rounds half-up, the same
 * rule as round(), so formatting an already-divided value through it is not a second rule.
 */
function rc_half_up_rounders(): array
{
    return ['round' => true, 'number_format' => true, 'number_format_i18n' => true];
}

/**
 * Is $tokens[$i] a genuine call to one of $names — not `$o->round(`, `Foo::round(`,
 * `function round(` or `new round(`?
 *
 * Extracted so the nested-call guard asks the question the OUTER scanner already answers. Two
 * spellings of "is this a call" in one function is how `$obj->round($a / $b)` came to suppress a
 * division that nothing had rounded.
 */
function rc_is_call_to(array $tokens, int $i, array $names, int $count): bool
{
    if (!is_array($tokens[$i])) {
        return false;
    }
    if (!isset($names[strtolower(slimstat_last_name_segment((string) $tokens[$i][1]))])) {
        return false;
    }
    $prev = $i - 1;
    while ($prev >= 0 && is_array($tokens[$prev])
        && in_array($tokens[$prev][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
        $prev--;
    }
    if ($prev >= 0 && is_array($tokens[$prev])
        && in_array($tokens[$prev][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
        return false;
    }
    $open = slimstat_next_significant($tokens, $i);

    return $open < $count && '(' === slimstat_token_text($tokens[$open]);
}

/**
 * Every `round()` / `sprintf()` / `printf()` / `number_format*()` call in $source.
 *
 * Tokenised, not regexed. Two reasons, both of which a raw-text scan gets wrong in this tree:
 * `sprintf('%d/%d', …)` carries a `/` inside a string literal, and several files carry the old
 * shape quoted inside a COMMENT explaining why it was changed. The tokeniser sees neither.
 *
 * @return list<array{name:string,line:int,args:string,first:string,divmul:bool}>
 */
function rc_rounding_calls(string $source): array
{
    $names  = ['round' => true, 'sprintf' => true, 'printf' => true,
        'number_format' => true, 'number_format_i18n' => true];
    // Pure and constant, so hoisting it out of the per-token loop below cannot
    // change an answer — it was being rebuilt once per token.
    $rounders = rc_half_up_rounders();
    $tokens = slimstat_tokenize($source);
    $count  = count($tokens);
    $calls  = [];

    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i])) {
            continue;
        }
        // T_NAME_FULLY_QUALIFIED / T_NAME_QUALIFIED as well as T_STRING, and the name taken
        // through slimstat_last_name_segment(). On PHP 8.0+ `\round(...)` arrives as ONE token
        // and was skipped before the depth walk ever ran, while PHP 7.4 tokenised it as
        // T_NS_SEPARATOR + T_STRING and scanned it — so this scan's answer depended on the
        // interpreter, in a file whose eval assertion is deliberately VERSION-INVARIANT.
        //
        // The helper and this exact idiom already existed (tests/lib/source-scan.php:190,
        // tests/surplus-argument-scan-test.php:203 — byte-identical to these three lines),
        // added when the same tokeniser change hid 242 call sites across 38 files from another
        // gate. This was the next gate not using them.
        //
        // NOT at parity with pro's sibling, and saying so rather than implying otherwise. Pro
        // checks T_STRING + T_NAME_FULLY_QUALIFIED and normalises with ltrim(), so `Foo\round(`
        // is blind there and seen here; pro has no equivalent of slimstat_last_name_segment().
        // Pro is the entirely-namespaced plugin, i.e. the half where a qualified call is MORE
        // likely, so the weaker copy is on the wrong side. Tracked in STATE.json
        // harness_debt_run53; the provenance header's "fix both in the same sitting" is
        // discharged for the hazard, not yet for the coverage.
        $is_name = T_STRING === $tokens[$i][0]
            || (defined('T_NAME_FULLY_QUALIFIED') && T_NAME_FULLY_QUALIFIED === $tokens[$i][0])
            || (defined('T_NAME_QUALIFIED') && T_NAME_QUALIFIED === $tokens[$i][0])
            || (defined('T_NAME_RELATIVE') && T_NAME_RELATIVE === $tokens[$i][0]);
        if (!$is_name) {
            continue;
        }
        $name = strtolower(slimstat_last_name_segment($tokens[$i][1]));
        if (!isset($names[$name])) {
            continue;
        }

        // `$this->round(`, `Foo::round(`, `function round(` and `new round(` are not the
        // function being scanned. Answered by the SAME helper the nested-call guard uses —
        // keeping a second inline copy here is what the extraction was supposed to end, and
        // leaving it would mean the two halves of this function could drift apart again.
        if (!rc_is_call_to($tokens, $i, $names, $count)) {
            continue;
        }

        $open = slimstat_next_significant($tokens, $i);
        $close = slimstat_token_paren_end($tokens, $open, $count);
        if (null === $close) {
            continue;
        }

        // ── The shape question, answered with paren DEPTH ──────────────────────────────────
        //
        // The defect is not "a `/` and a `*` in the same expression". It is a multiplication
        // that consumes the RESULT of a division. In `($a / $b) * 100` the `/` sits one level
        // deeper than the `*`, so the `*` sees the quotient. In `$x / (7 * 86400)` the `*` is
        // DEEPER than the `/`, so it happens first and no boundary is lost — and that shape is
        // in this tree (src/Helpers/DataBuckets.php), so a scan that flagged it would have to
        // be weakened or exempted, and an exemption list is how a scan stops meaning anything.
        //
        // So: flag a `*` at depth d only when some earlier `/` in the same ARGUMENT sat at
        // depth >= d. Reset at every top-level comma, because arguments are separate
        // expressions and `sprintf('%d/%d', $a * $b)` is not this defect.
        $depth        = 0;
        $max_div_depth = -1;
        $divmul       = false;
        $first_end     = $close;
        $min_mul_depth = PHP_INT_MAX;
        // `fmtround`: sprintf/printf used as a ROUNDING function — a `%.Nf` conversion applied
        // to an expression containing a division. That is issues #334/#335's mechanism, and
        // until 2026-08-31 this scanner could not see it: it checked operand ORDER only, and
        // the shape table below listed `sprintf("%01.4f", (100 * $c / $t))` as a shape the gate
        // REQUIRES. So the exact defect that had just been fixed at four sites could be
        // reintroduced at a fifth and every control would stay green.
        $fmt_conv       = false;   // the format literal carries a %…f conversion
        $div_in_value   = false;   // a BARE `/` after the format literal — see below
        // Depths at which a NESTED rounding call is open. A division inside one of those has
        // already been rounded, so `sprintf('%.2f', round($a / $b, 2))` is round-then-format —
        // correct, and the very remediation this gate's failure message recommends. Without
        // this the rule flags its own advice, and a check that fails on correct code is a check
        // somebody relaxes.
        $round_depths   = [];
        for ($k = $open + 1; $k < $close; $k++) {
            $text = slimstat_token_text($tokens[$k]);
            if (is_array($tokens[$k])) {
                // The format literal is the FIRST argument and is a string token, so it is only
                // ever seen here. Matching on the token means `'%d/%d'`'s slash is a string and
                // not an operator — the same reason this loop skips array tokens below.
                if ($k < $first_end
                    && T_CONSTANT_ENCAPSED_STRING === $tokens[$k][0]
                    && preg_match('/%[-+ 0\']*[0-9]*(?:\.[0-9]+)?[fF]/', $text)
                ) {
                    $fmt_conv = true;
                }
                // slimstat_last_name_segment(), not a second ltrim(): this function already
                // normalises the OUTER call's name that way, and the docblock above argues at
                // length that ltrim is the weaker copy. Two spellings in one function is how
                // `Foo\round(` ends up recognised as an outer call and missed as a nested one.
                //
                // The LOOKAHEAD, not a `$pending_round` flag set here and cleared at the next
                // bracket: measured, such a flag survives any token in between, so
                // `sprintf('%.2f', round + ($a / $b))` attached it to an unrelated group's
                // paren and suppressed a real division. Ask the question at the only moment it
                // has an answer.
                if (rc_is_call_to($tokens, $k, $rounders, $count)) {
                    $round_depths[$depth + 1] = true; // that '(' will open one level deeper
                }
                continue; // only bare-string tokens can be an operator or a bracket
            }
            if ('(' === $text || '[' === $text) {
                $depth++;
            } elseif (')' === $text || ']' === $text) {
                unset($round_depths[$depth]);
                $depth--;
            } elseif (',' === $text && 0 === $depth) {
                if ($first_end === $close) {
                    $first_end = $k;
                }
                $max_div_depth = -1;
                $min_mul_depth = PHP_INT_MAX;
            } elseif ('/' === $text) {
                $max_div_depth = max($max_div_depth, $depth);
                // Only a division that has NOT already been rounded: $round_depths holds the
                // depths of any enclosing round()/number_format*() call.
                if ($first_end !== $close && !$round_depths) {
                    $div_in_value = true; // a bare division in an argument after the format
                }
                // ...and the MIRROR. A multiplication already seen at a STRICTLY SHALLOWER
                // depth is `100 * ($a / $b)` — the same defect with the operands swapped, and
                // the shape somebody writes applying ADR-17's remediation halfway, putting the
                // 100 first without moving the parens. Strictly shallower is what separates it
                // from the fix: the naive rule ("any * at depth <= any /") flags
                // `(100 * $c / $t)`, which is the reordered form this gate exists to require.
                // Pro carries the same half (wp-slimstat-pro bd950f8); without it the two
                // siblings answered this question differently.
                if ($min_mul_depth < $depth) {
                    $divmul = true;
                }
            } elseif ('*' === $text) {
                $min_mul_depth = min($min_mul_depth, $depth);
                if ($max_div_depth >= $depth) {
                    $divmul = true;
                }
            }
        }

        $calls[] = [
            // The NORMALISED name. Recording the raw token would store '\round' for a
            // fully-qualified call, and the site matcher below reads 'round' — so the gate
            // would report "found 0", i.e. "the site is gone", about a site that is present
            // and correct. ('round' and not 'sprintf': $call['name'] has exactly two readers,
            // the site matcher and a failure-message string, and only 'round' is compared.)
            'name'   => $name,
            'line'   => (int) $tokens[$i][2],
            'args'   => slimstat_token_text_range($tokens, $open + 1, $close),
            'first'  => trim(slimstat_token_text_range($tokens, $open + 1, $first_end)),
            'divmul' => $divmul,
            // sprintf/printf being used to ROUND a ratio, rather than to format an
            // already-rounded one. Issues #334/#335's mechanism, made scannable.
            'fmtround' => ('sprintf' === $name || 'printf' === $name) && $fmt_conv && $div_in_value,
        ];
    }

    return $calls;
}

// ── CONTROLS — printed before any result, per VERIFICATION-PROTOCOL ──────────────────────────
//
// Each line is a way this file could have been vacuous. The first two are the ones that matter:
// if the two shapes produced the same double on this interpreter, every fixture below would be
// unable to tell them apart and the gate would be green for the wrong reason.

$controls    = [];
$div_first   = sprintf('%.20F', (23 / 40) * 100);
$mul_first   = sprintf('%.20F', (100 * 23) / 40);
$exact_23_40 = rc_exact_expansion(23, 40, 20);

$controls[] = [
    $div_first !== $exact_23_40,
    sprintf('the divide-first shape is measurably wrong on THIS interpreter: (23/40)*100 = %s, exact = %s',
        $div_first, $exact_23_40),
];
$controls[] = [
    $mul_first === $exact_23_40,
    sprintf('the multiply-first shape is exact on THIS interpreter: (100*23)/40 = %s', $mul_first),
];

// The oracle reproduces values derived BY HAND, so a broken oracle cannot quietly agree with a
// broken implementation. Every expected string here was worked out on paper from the integers:
// 100·23/40 = 57.5 -> 58; 100·23/80 = 28.75 -> 28.8; 100·23/160 = 14.375 -> 14.38;
// 100·1/3 = 33.333… -> 33.33; 100·2/3 = 66.666… -> 66.67; 100·1/32 = 3.125 -> 3.13.
$oracle_cases = [
    ['58',    rc_exact_half_up(23, 40, 0)],
    ['28.8',  rc_exact_half_up(23, 80, 1)],
    ['14.38', rc_exact_half_up(23, 160, 2)],
    ['33.33', rc_exact_half_up(1, 3, 2)],
    ['66.67', rc_exact_half_up(2, 3, 2)],
    ['3.13',  rc_exact_half_up(1, 32, 2)],
    ['12.5',  rc_exact_half_up(1, 8, 1)],
    ['0.0',   rc_exact_half_up(0, 40, 1)],
    ['100',   rc_exact_half_up(40, 40, 0)],
    ['57.50000000000000000000', rc_exact_expansion(23, 40, 20)],
    ['33.33333', rc_exact_expansion(1, 3, 5)],
];
$oracle_bad = [];
foreach ($oracle_cases as [$want, $got]) {
    if ($want !== $got) {
        $oracle_bad[] = "{$want} != {$got}";
    }
}
$controls[] = [
    [] === $oracle_bad,
    sprintf('the integer oracle reproduces %d hand-derived values%s', count($oracle_cases),
        $oracle_bad ? ' — MISMATCH: ' . implode(', ', $oracle_bad) : ''),
];

/**
 * Why $site's fixtures cannot support its assertions, or null if they can.
 *
 * PURE on purpose, so the CONTROLS block can exercise every reason on a deliberately broken
 * site. A control nobody has watched go red is this file's own signature defect.
 *
 * TWO fixtures, because no single pair does both jobs — measured:
 *
 *   23/160 @2dp   divide-first LOSES precision (14.374999…)   both rules render 14.38
 *    1/32  @2dp   divide-first is EXACT                       3.12 vs 3.13 — separates them
 *
 * So `a`/`b` must make the operand-order defect visible, and `tie_a`/`tie_b` must separate
 * half-up from ties-to-even. `tie_a` is OPTIONAL: the six ADR-17 sites predate it and their
 * (b) limitation is recorded in the header rather than papered over.
 *
 * @param array{a:int,b:int,places:int,tie_a?:int,tie_b?:int} $site
 */
function rc_fixture_problem(array $site): ?string
{
    $a      = $site['a'];
    $b      = $site['b'];
    $places = $site['places'];

    if (!rc_is_exactly_representable($a, $b)) {
        return sprintf('fixture %d/%d cannot support the exactness assertion — 100·a/b is not a '
            . 'dyadic rational, so NO double equals it and the check would fail for a reason '
            . 'unrelated to operand order. Pick an on-boundary pair whose reduced denominator is '
            . 'a power of two', $a, $b);
    }

    // The ADR-17 fixture must make the defect VISIBLE. If divide-first is exact on this pair,
    // assertion (a) cannot fail — which is what a tie-only fixture silently did to E5/E6.
    if (sprintf('%.20F', ($a / $b) * 100) === sprintf('%.20F', (100 * $a) / $b)) {
        return sprintf('fixture %d/%d is exact under divide-first too, so assertion (a) could not '
            . 'fail on it. Pick a pair where (a/b)*100 lands below (100*a)/b', $a, $b);
    }

    if (!isset($site['tie_a'])) {
        return null; // no tie fixture declared — the header records what (b) then cannot do
    }

    $tie_a = $site['tie_a'];
    $tie_b = $site['tie_b'];

    // Checked, because the tie pair is fed to the same exact-expansion oracle as a/b and a
    // non-dyadic one would fail assertion (b) for a reason unrelated to the rounding rule.
    if (!rc_is_exactly_representable($tie_a, $tie_b)) {
        return sprintf('tie fixture %d/%d is not a dyadic rational', $tie_a, $tie_b);
    }

    if (sprintf('%.' . $places . 'F', (float) rc_exact_expansion($tie_a, $tie_b, $places + 1))
        === rc_exact_half_up($tie_a, $tie_b, $places)) {
        return sprintf('tie fixture %d/%d is not on a rounding tie at %d dp — half-up and '
            . 'ties-to-even render it identically, so assertion (b) could not fail. Pick a dyadic '
            . 'pair whose exact expansion ends in a 5 at %d places, with an EVEN digit before it '
            . '(an odd one makes both rules round up)', $tie_a, $tie_b, $places, $places + 1);
    }

    return null;
}

// ── The sites ────────────────────────────────────────────────────────────────────────────────
//
// Located by the OPERAND NAMES rather than by line number: a line number in a test is a
// citation that rots the first time somebody adds an early return above it, and it rots
// silently — the assertion then reads a different expression and still passes. Exactly one
// call per site must match, and zero-or-many is a hard failure rather than a skip.
//
// `setup` binds the fixture integers to the names the SHIPPED expression uses, so the thing
// being evaluated is the shipped bytes and not a copy of them living in this file.

$sites = [
    'E1 goal conversion rate' => [
        'file'    => 'admin/view/wp-slimstat-db.php',
        'needles' => ['$uniques', '$total_visitors'],
        'places'  => 2,
        'a'       => 23,
        'b'       => 160,
        'surface' => 'Goals dashboard widget (printed raw) and Pro CSV export via get_goals_raw()',
        'setup'   => static function (int $a, int $b): string {
            return "\$uniques = {$a}; \$total_visitors = {$b};";
        },
    ],
    'E3 funnel step %' => [
        'file'    => 'admin/view/wp-slimstat-db.php',
        'needles' => ['$visitor_count', '$step1_count'],
        'places'  => 1,
        'a'       => 23,
        'b'       => 80,
        'surface' => 'funnel step percentage, printed at 1 dp by funnel-bars.php and reports.php; also CSV + email report',
        'setup'   => static function (int $a, int $b): string {
            return "\$visitor_count = {$a}; \$step1_count = {$b};";
        },
    ],
    "E3' funnel drop-off %" => [
        'file'    => 'admin/view/partials/goals-funnels/funnel-bars.php',
        'needles' => ['$dropoff', '$steps[$index - 1]'],
        'places'  => 1,
        'a'       => 23,
        'b'       => 80,
        'surface' => 'the "↓ N dropped (X%)" line under each funnel step',
        'setup'   => static function (int $a, int $b): string {
            return "\$dropoff = {$a}; \$index = 1; \$steps = [0 => ['visitors' => {$b}], 1 => ['visitors' => 0]];";
        },
    ],
    'E4 funnel bar width (reports)' => [
        'file'    => 'admin/view/wp-slimstat-reports.php',
        'needles' => ["\$step['visitors']", '$step1'],
        'places'  => 0,
        'a'       => 23,
        'b'       => 40,
        'surface' => 'CSS width of the funnel bar in the Goals & Funnels report',
        'setup'   => static function (int $a, int $b): string {
            return "\$step = ['visitors' => {$a}]; \$step1 = {$b};";
        },
    ],
    "E4' funnel bar width (partial)" => [
        'file'    => 'admin/view/partials/goals-funnels/funnel-bars.php',
        'needles' => ['$visitors', '$step_one_visitors'],
        'places'  => 0,
        'a'       => 23,
        'b'       => 40,
        'surface' => 'CSS width + progressbar fill of each funnel step (SSR twin of goals-funnels.js)',
        'setup'   => static function (int $a, int $b): string {
            return "\$visitors = {$a}; \$step_one_visitors = {$b};";
        },
    ],
    "E4' adminbar sparkline height" => [
        'file'    => 'admin/index.php',
        'needles' => ['$count', '$max_count'],
        'places'  => 0,
        'a'       => 23,
        'b'       => 40,
        'surface' => 'CSS height of each bar in the admin-bar 30-minute chart',
        'setup'   => static function (int $a, int $b): string {
            return "\$count = {$a}; \$max_count = {$b};";
        },
    ],

    // ── E5/E6: the ties-to-even sites (issue #334) ──────────────────────────────────────────
    //
    // These two shipped `sprintf('%01.2f', …)` until this change. The docblock's "WHAT IT
    // DELIBERATELY DOES NOT ASSERT" section named that mechanism and left it unfixed on
    // purpose; it is fixed now, so the exemption becomes an assertion.
    //
    // sprintf is a FORMATTER, not a rounding function: `%.Nf` rounds ties-to-EVEN on every
    // runtime. round() is half-up. The fixture below is chosen to be exactly on a tie so the
    // two rules disagree — 100 × 1 / 32 is exactly 3.125, which sprintf prints `3.12` and
    // round() gives `3.13`. That is issue #334's own reproduction, and it is a dyadic rational
    // so rc_is_exactly_representable() accepts it and assertion (a) stays meaningful.
    //
    // Reverting either site to sprintf turns this gate red twice over: the matcher finds zero
    // round() calls ("the site is gone"), and were it to find one, assertion (b) would compare
    // 3.12 against 3.13. Both were watched failing before the fix landed.
    'E5 top-N percentage column' => [
        'file'    => 'admin/view/wp-slimstat-reports.php',
        'needles' => ['$percentage_raw'],
        'places'  => 2,
        // TWO fixtures, for the reason Pro's sibling records at length: no single pair can serve
        // both assertions. 23/160 makes divide-first LOSE precision, so (a) can fail on it, but
        // 14.375 renders 14.38 under both rounding rules. 1/32 is exactly 3.125 — 3.12 vs 3.13,
        // so (b) can fail on it — but is exact under divide-first, so (a) cannot. The first
        // version of this change used 1/32 for both and silently made (a) unfalsifiable here.
        'a'       => 23,
        'b'       => 160,
        'tie_a'   => 1,
        'tie_b'   => 32,
        'surface' => 'the percentage beside every row of every top-N report — the most-seen number in the product',
        // The division is on the line above in the shipped file, so it is reproduced here
        // exactly as that line writes it: (100 * counthits) / pageviews, left-associative.
        'setup'   => static function (int $a, int $b): string {
            return "\$percentage_raw = (100 * {$a}) / {$b};";
        },
    ],
    'E6 new-visitors rate' => [
        'file'    => 'admin/view/wp-slimstat-db.php',
        'needles' => ['$new_visitors', '$total_human_hits'],
        'places'  => 2,
        // Same split as E5, same reason.
        'a'       => 23,
        'b'       => 160,
        'tie_a'   => 1,
        'tie_b'   => 32,
        'surface' => 'the new-visitor rate in the traffic-sources summary (dashboard + Pro email report)',
        'setup'   => static function (int $a, int $b): string {
            return "\$new_visitors = {$a}; \$total_human_hits = {$b};";
        },
    ],
];

$evaluated = 0;
foreach ($sites as $id => $site) {
    $path = $plugin_root . '/' . $site['file'];
    $src  = @file_get_contents($path);
    if (false === $src) {
        $failures[] = "{$id}: cannot read {$site['file']} — the site this gate is about is not there";
        continue;
    }

    $matches = [];
    foreach (rc_rounding_calls($src) as $call) {
        if ('round' !== $call['name']) {
            continue;
        }
        $all = true;
        foreach ($site['needles'] as $needle) {
            if (false === strpos($call['args'], $needle)) {
                $all = false;
                break;
            }
        }
        if ($all) {
            $matches[] = $call;
        }
    }

    // Zero matches is the vacuity this programme keeps rediscovering: the gate would print
    // nothing, count nothing, and exit 0. One match is the only acceptable answer.
    if (1 !== count($matches)) {
        $failures[] = sprintf('%s: expected exactly ONE round() in %s naming %s, found %d. '
            . 'An assertion that cannot find its subject is not an assertion that passed',
            $id, $site['file'], implode(' + ', $site['needles']), count($matches));
        continue;
    }

    $call = $matches[0];
    $a    = $site['a'];
    $b    = $site['b'];
    $ref  = $site['file'] . ':' . $call['line'];

    if (!rc_is_exactly_representable($a, $b)) {
        $failures[] = sprintf('%s: fixture %d/%d cannot support the exactness assertion — 100·a/b is '
            . 'not a dyadic rational, so NO double equals it and the check below would fail for a '
            . 'reason unrelated to operand order. Pick an on-boundary pair whose reduced denominator '
            . 'is a power of two', $id, $a, $b);
        continue;
    }

    // eval() ON PURPOSE, and the reason is the whole design of this file. The alternative —
    // re-typing the expression here as a "mirror" of the source — is what tests/avg-duration-
    // format-test.php does, and a mirror tests the copy: it stays green while the shipped line
    // drifts away from it. What is evaluated here is the exact byte range the tokeniser cut out
    // of the shipped file, with the fixture integers bound to the names that file uses. There is
    // no untrusted input: the string comes from a file already in this repository, on a
    // developer/CI machine, in a script that WordPress never loads. The two calls are separated
    // so the pre-round double can be observed on its own — that observation is assertion (a),
    // and it is the only part of this gate that is version-invariant.
    // (b) runs on the site's TIE fixture where it declares one, because assertion (b) can only
    // separate half-up from ties-to-even on a pair where the two rules disagree — and such a
    // pair is, for these ratios, never also one where divide-first loses precision. One fixture
    // cannot serve both, and using one silently disarms whichever assertion it does not suit.
    // Sites with no `tie_a` keep the single fixture and the (b) limitation the header records.
    $tie_a = isset($site['tie_a']) ? $site['tie_a'] : $a;
    $tie_b = isset($site['tie_b']) ? $site['tie_b'] : $b;

    $handed  = eval($site['setup']($a, $b) . ' return ' . $call['first'] . ';');
    $rounded = eval($site['setup']($tie_a, $tie_b) . ' return round(' . $call['args'] . ');');
    $evaluated++;
    $checks += 2;

    // One cause, one failure. The reasons live in rc_fixture_problem(), which is PURE so the
    // CONTROLS block can run it on deliberately broken sites and watch each reason fire —
    // inline, these guards only ever met fixtures that pass, so none had been seen red from
    // inside this file. Pro's sibling learned this the expensive way: an equivalent fixture
    // swap there was caught only because a registered mutation reported INVALID, and free has
    // no mutation on this gate at all.
    $problem = rc_fixture_problem($site);
    if (null !== $problem) {
        $failures[] = "{$id}: {$problem}";
        continue;
    }

    // (a) VERSION-INVARIANT. The value handed to round() must BE the ratio, not a double that
    //     has already lost it. False on 7.4 exactly as it is false on 8.5.
    $handed_str = sprintf('%.20F', (float) $handed);
    $exact_str  = rc_exact_expansion($a, $b, 20);
    if ($handed_str !== $exact_str) {
        $failures[] = sprintf('%s [%s]: round() was handed %s, but 100 * %d / %d is exactly %s — the '
            . 'division ran first, so the exact value was gone before round() could see it. Multiply '
            . 'first: (100 * $a) / $b (ADR-17; PITFALLS 72)',
            $id, $ref, $handed_str, $a, $b, $exact_str);
    }

    // (b) THE USER-VISIBLE CLAIM. Masked on PHP <= 8.3 by the pre-rounding 8.4 removed, which is
    //     precisely why (a) exists beside it and is not redundant with it.
    $want = rc_exact_half_up($tie_a, $tie_b, $site['places']);
    $got  = sprintf('%.' . $site['places'] . 'F', (float) $rounded);
    if ($want !== $got) {
        $failures[] = sprintf('%s [%s]: renders %s%%, but exact half-up of 100 * %d / %d at %d dp is '
            . '%s%% — surface: %s',
            $id, $ref, $got, $tie_a, $tie_b, $site['places'], $want, $site['surface']);
    }
}

// The 8 is HARD-CODED, and that is the point. `count($sites) === $evaluated` takes both sides
// from $sites: delete a site and it prints "(7 of 7)" and passes, under a label saying "all".
// A census is a fact about the product, so it belongs here as a number somebody has to come and
// change on purpose. Pro's sibling made this correction first.
$controls[] = [
    8 === count($sites) && 8 === $evaluated,
    sprintf('all shipped percentage sites were located and their expressions EVALUATED '
        . '(%d declared, %d evaluated, 8 expected)', count($sites), $evaluated),
];

// Every reason rc_fixture_problem() can give, exercised on a site that breaks exactly that one
// property — plus the shipped sites, which must report none. Without this the guards in the site
// loop would only ever meet fixtures that pass, and a guard nobody has watched go red is the
// shape this file's header is about. Each probe breaks ONE property, so a reason that stops
// firing shows up as a named case rather than as a count.
$rc_guard_probes = [
    'a/b not dyadic'            => [['a' => 1,  'b' => 3,   'places' => 2], 'not a dyadic rational'],
    'a/b exact divide-first'    => [['a' => 1,  'b' => 4,   'places' => 2], 'exact under divide-first'],
    'tie not dyadic'            => [['a' => 23, 'b' => 160, 'places' => 2, 'tie_a' => 1,  'tie_b' => 3],   'tie fixture 1/3 is not a dyadic'],
    'tie does not discriminate' => [['a' => 23, 'b' => 160, 'places' => 2, 'tie_a' => 23, 'tie_b' => 160], 'not on a rounding tie'],
    // A site with NO tie fixture is legal — the six ADR-17 sites are exactly that — so this
    // must return null. Without it, making the tie half mandatory would pass unnoticed.
    'no tie fixture is legal'   => [['a' => 23, 'b' => 160, 'places' => 2], null],
];
$rc_guard_bad = [];
foreach ($rc_guard_probes as $rc_what => [$rc_probe, $rc_needle]) {
    $rc_got = rc_fixture_problem($rc_probe);
    if (null === $rc_needle) {
        if (null !== $rc_got) {
            $rc_guard_bad[] = "{$rc_what}: expected no problem, got '{$rc_got}'";
        }
        continue;
    }
    if (null === $rc_got || false === strpos($rc_got, $rc_needle)) {
        $rc_guard_bad[] = "{$rc_what}: expected a reason containing '{$rc_needle}', got "
            . (null === $rc_got ? 'null (the fixture was accepted)' : "'{$rc_got}'");
    }
}
foreach ($sites as $rc_id => $rc_site) {
    $rc_got = rc_fixture_problem($rc_site);
    if (null !== $rc_got) {
        $rc_guard_bad[] = "shipped site {$rc_id} reports a fixture problem: {$rc_got}";
    }
}

$controls[] = [
    [] === $rc_guard_bad,
    sprintf('all %d fixture-problem reasons fire on a site that breaks exactly that property, '
        . 'and no shipped site reports one', count($rc_guard_probes))
        . ([] === $rc_guard_bad ? '' : ' — ' . implode('; ', $rc_guard_bad)),
];

// EVERY member of rc_half_up_rounders() must actually round HALF-UP. The set is hardcoded — there
// is nothing in this repo to derive it from, and a derived set would be a second scanner to get
// wrong — but until now its membership was asserted only in prose. A name added to it silently
// widens what the guard suppresses, so the claim is executed instead: each member must reproduce
// this file's own integer oracle on the tie fixture. sprintf/printf would FAIL this, which is the
// whole reason they are not in the set.
$rc_rounder_bad     = [];
$rc_rounder_skipped = [];
$rc_rounder_names   = array_keys(rc_half_up_rounders());
$rc_rounder_checked = 0;
$rc_tie_want        = rc_exact_half_up(1, 32, 2);   // '3.13'
$rc_tie_value       = (100 * 1) / 32;       // exactly 3.125
foreach ($rc_rounder_names as $rc_fn) {
    if (!function_exists($rc_fn)) {
        // NAMED, not silently skipped, and the count below is not derived from the set being
        // skipped. A silent `continue` plus a "(N checked)" label taken from the same array is
        // the tautology the hard-coded site census exists to refuse: delete or typo a member
        // and the label just prints a smaller number, in green.
        $rc_rounder_skipped[] = $rc_fn;
        continue;
    }
    $rc_rounder_checked++;
    // try/catch, because a wrong-ARITY name is exactly what a careless addition looks like:
    // floor() takes one argument, so calling it with two is an ArgumentCountError that would
    // kill the run with exit 255 instead of reporting a clean red. Measured, then guarded — a
    // control that fatals is a control whose message nobody reads.
    try {
        $rc_got = (string) $rc_fn($rc_tie_value, 2);
    } catch (Throwable $rc_e) {
        $rc_rounder_bad[] = "{$rc_fn}(3.125, 2) threw " . get_class($rc_e) . ' — it does not take '
            . '(value, places), so it is not a rounding function of the shape this set assumes';
        continue;
    }
    if ($rc_tie_want !== $rc_got) {
        $rc_rounder_bad[] = "{$rc_fn}(3.125, 2) = {$rc_got}, want {$rc_tie_want} — not half-up, so "
            . 'it must not be in rc_half_up_rounders(): the nested-call guard would suppress a '
            . 'division this function never rounded';
    }

    // A SECOND value, below the half, and it is not decoration. Measured: the tie alone admits a
    // ceiling — `ceil($v * 100) / 100` also returns 3.13 on 3.125 — and a ceiling is loose in
    // exactly the direction this control exists to police. 3.121 separates them: half-up gives
    // 3.12, always-up gives 3.13. Ties at 0 dp and negatives add nothing, because rc_exact_half_up
    // refuses negative input by design, so half-away-from-zero is unobservable in this domain.
    //
    // Compared with a normalising sprintf rather than (string): `(string) round(50.0, 2)` is "50"
    // where the oracle says "50.00", so the raw cast agrees with the oracle only at values where
    // the two representations happen to coincide. 3.125 is one of those; 3.121 is too, but the
    // normalisation is what makes that a property of the code rather than of the fixture.
    $rc_low_want = rc_exact_half_up(3121, 100000, 2);   // 3.121 -> '3.12'
    try {
        $rc_low_got = sprintf('%.2F', (float) $rc_fn(3.121, 2));
    } catch (Throwable $rc_e) {
        $rc_low_got = 'threw ' . get_class($rc_e);
    }
    if ($rc_low_want !== $rc_low_got) {
        $rc_rounder_bad[] = "{$rc_fn}(3.121, 2) = {$rc_low_got}, want {$rc_low_want} — it does not "
            . 'round DOWN below the half, so it is not half-up (a ceiling passes the 3.125 tie)';
    }
}

// The ONLY member allowed to go unchecked here is number_format_i18n, because it is WordPress's.
// Pinned as an exact set: without this, a member that vanished from PHP — or a typo — would be
// skipped and the control would still pass, having executed the claim for nobody.
if (['number_format_i18n'] !== $rc_rounder_skipped) {
    $rc_rounder_bad[] = 'unchecked members were ['
        . implode(', ', $rc_rounder_skipped) . '], expected exactly [number_format_i18n] — a name '
        . 'this control cannot execute is a name whose half-up claim nothing verifies';
}

$controls[] = [
    [] === $rc_rounder_bad,
    sprintf('every member of rc_half_up_rounders() reproduces exact half-up on the tie fixture '
        . '(%d of %d checked; not defined outside WordPress: %s)',
        $rc_rounder_checked, count($rc_rounder_names),
        $rc_rounder_skipped ? implode(', ', $rc_rounder_skipped) : 'none')
        . ([] === $rc_rounder_bad ? '' : ' — ' . implode('; ', $rc_rounder_bad)),
];

// ── The ratchet: no seventh site, anywhere in shipped PHP ────────────────────────────────────

$deps_prefix = $plugin_root . '/src/Dependencies';
$files       = slimstat_own_php_files(
    [$plugin_root . '/admin', $plugin_root . '/src', $plugin_root . '/views', $plugin_root . '/wp-slimstat.php'],
    $deps_prefix
);

$scanned = 0;
foreach ($files as $file) {
    $rel = slimstat_rel_path($plugin_root, $file);
    foreach (rc_rounding_calls((string) file_get_contents($file)) as $call) {
        $scanned++;

        // NO SEVENTH ROUNDING RULE. sprintf/printf is a FORMATTER: `%.Nf` rounds ties-to-EVEN
        // on every runtime, while everything else in this plugin rounds half-up. Issues
        // #334/#335 were four sites that used it to round anyway, and this gate could not see
        // them — it checked operand ORDER only, and its own shape table listed
        // `sprintf("%01.4f", (100 * $c / $t))` as a shape it REQUIRED. So the defect was
        // re-enterable at a fifth site with every control still green. It is not now.
        if ($call['fmtround']) {
            $failures[] = sprintf('%s:%d — %s() is being used to ROUND a ratio: `%s`. `%%.Nf` rounds '
                . 'ties-to-EVEN, so this disagrees with round() on exactly the boundary values the '
                . 'rest of the product rounds up (1 in 32 is exactly 3.125%%: 3.12 here, 3.13 '
                . 'everywhere else). Round first, then format: number_format_i18n(round(…, $p), $p) '
                . '(issues #334/#335)',
                $rel, $call['line'], $call['name'], preg_replace('/\s+/', ' ', $call['args']));
        }

        if (!$call['divmul']) {
            continue;
        }
        // The WHOLE argument list, not just the first — printing only the first showed a
        // maintainer `"%01.4f"`, an expression containing neither a division nor a
        // multiplication. Pro fixed this first; same sitting, same fix here.
        $failures[] = sprintf('%s:%d — %s() multiplies the result of a division: `%s`. Every one of '
            . 'these percentages is a ratio of two COUNTs; divide LAST so the single division is the '
            . 'only rounding: (100 * $a) / $b (ADR-17; PITFALLS 72)',
            $rel, $call['line'], $call['name'], preg_replace('/\s+/', ' ', $call['args']));
    }
}

// -- The JS twins, because the PHP scan structurally cannot see them -------------------------
//
// THIS BLOCK EXISTS BECAUSE THE FIX ABOVE BROKE SOMETHING, and it is worth saying so plainly.
// funnel-bars.php calls itself "the SSR twin of goals-funnels.js"; goals-funnels.js carries
// "Keep ... identical to funnel-bars.php (SSR) - anti-drift" beside the same two percentages.
// ADR-17 reordered the PHP half of those pairs and left the JS half dividing first, so for the
// length of one edit the same funnel rendered 58% from the server and 57% after an AJAX refresh:
// the same data, on the same screen, disagreeing on reload. Math.round is not the culprit any
// more than round() was - `(a / b) * 100` lands one ULP below the exact half in V8 exactly as it
// does in PHP, and unlike PHP no version of V8 ever compensated. Five JS sites were involved and
// the PHP scan above could never have seen one of them.
//
// This is a REGEX over JavaScript, not a tokeniser, and it is written to be honest about that.
// It matches the one shape the contract forbids - a division whose result is multiplied by 100 -
// over a NAMED list of files, so a false positive lands somewhere a reader can check by hand. A
// JS parser here would be a second implementation of a rule the PHP side already owns.
$js_files = [
    'admin/assets/js/goals-funnels.js',      // twins of funnel-bars.php:34 and :46
    'admin/assets/js/adminbar-realtime.js',  // twin of admin/index.php's $height_pct
    'admin/assets/js/migration.js',          // the migration progress bar
    'admin/index.php',                       // inline JS: T_INLINE_HTML to the PHP tokeniser
];
$js_scanned = 0;
$js_missing = [];
$js_per_file = [];   // a total is not coverage — see the control below
foreach ($js_files as $rel) {
    $path = $plugin_root . '/' . $rel;
    if (!is_file($path)) {
        $js_missing[] = $rel;
        continue;
    }
    // Comments are BLANKED, not skipped, and the difference is a hole I put there and then
    // measured. The first version skipped any line whose trimmed form began `//` or `*`, on the
    // reasoning that several of the new comments legitimately QUOTE the forbidden shape. Two
    // constructed lines walked straight through it: `*/ var pct = …(done / total) * 100…` and
    // `* var pct = …` are both real code to a JS engine and both begin with `*`. Blanking the
    // comment SPANS while preserving newlines keeps the line numbers honest and leaves no
    // line-shaped exemption for code to hide behind. PITFALLS 32's shape: a scanner that decides
    // what not to look at will eventually decline to look at the thing it was written for.
    $src = (string) file_get_contents($path);
    $src = preg_replace_callback('~/\*.*?\*/~s',
        function ($m) { return preg_replace('/[^\n]/', ' ', $m[0]); }, $src);
    $src = preg_replace('~//[^\n]*~', '', $src);

    foreach (explode("\n", $src) as $i => $line) {
        $js_scanned++;
        if (false !== strpos($line, '100')) {
            $js_per_file[$rel] = ($js_per_file[$rel] ?? 0) + 1;
        }
        // TWO spellings, because JS is left-associative and `a / b * 100` IS the defect — it is
        // also the form somebody writes who is NOT copying the old line. The first version
        // matched only the parenthesised `(a / b) * 100`, i.e. exactly the five spellings ADR-17
        // had just fixed and nothing else: a ratchet that recognises history rather than the
        // rule. `100 * a / b` is explicitly NOT matched — that is the correct form.
        if (preg_match('~\(\s*[^()\n]*/[^()\n]*\)\s*\*\s*100~', $line)
            || preg_match('~(?<!100\s\*\s)(?<!100\*)[A-Za-z_$][\w.$\[\]]*\s*/\s*[^;,)\n]*?\*\s*100~', $line)) {
            $failures[] = sprintf('%s:%d - a JS site divides before multiplying: `%s`. Its PHP twin '
                . 'divides last, so one screen would round two ways and the value would change on '
                . 'refresh. Multiply first: (100 * a) / b (ADR-17; PITFALLS 72)',
                $rel, $i + 1, trim($line));
        }
    }
}

// The same anti-blindness argument as the PHP scan below, for the same reason: a file list that
// silently matched nothing would pass, and a silent pass is how the PHP half came to be fixed
// alone in the first place.
// PER FILE, on lines that could actually match — not a total. The first version asserted
// `$js_scanned >= 400` across all four, and admin/index.php alone supplies 4,255 of the 5,902:
// empty all three .js files to zero bytes and the control still passed. That is the near-vacuity
// the PHP control below it was written against, one file later. A file contributing no candidate
// line is a file this scan is not reading.
$js_barren = [];
foreach ($js_files as $rel) {
    if (($js_per_file[$rel] ?? 0) < 1) {
        $js_barren[] = $rel;
    }
}
$controls[] = [
    empty($js_missing) && empty($js_barren),
    sprintf('the JS twin scan is not blind: %s (%d line(s) total)%s%s',
        implode(', ', array_map(
            function ($f) use ($js_per_file) { return basename($f) . '=' . ($js_per_file[$f] ?? 0); },
            $js_files)),
        $js_scanned,
        $js_missing ? ' - MISSING: ' . implode(', ', $js_missing) : '',
        $js_barren ? ' - NO CANDIDATE LINES: ' . implode(', ', $js_barren) : ''),
];

// A scan that derives its own subject set will derive the compliant one and report full
// coverage of it (PITFALLS 32). If the tokeniser breaks, zero call sites means zero
// violations and this file is at its loudest exactly when it is blind.
$controls[] = [
    $scanned >= 40 && count($files) >= 50,
    sprintf('the scan is not blind: %d rounding/formatting call site(s) across %d shipped PHP files',
        $scanned, count($files)),
];

// The scanner reads a rounding call however its name is written, and refuses the shapes that
// only look like one.
//
// WHY THIS EXISTS. Measured on this tree, four branches of rc_rounding_calls() could be deleted
// with every control still printing PASS and the gate still exiting 0:
//
//   * the T_NAME_* clause           -> scanned 355 falls to 353, and nothing reads that number
//                                      closely enough to notice (the census floor is >= 40)
//   * the normalised 'name' key     -> no trace at all
//   * the top-level-comma reset     -> no trace
//   * the ->/::/function/new filter -> no trace
//
// ⚠ THE FIRST BULLET IS NOW STALE, AND IT IS LEFT ABOVE RATHER THAN EDITED AWAY because it is
// the reason this block was written. Re-measured 2026-08-31 by deleting the T_NAME_* clause on
// the real file: the gate exits **1**. This $rc_shapes table is what kills it — it names
// `\round(…)` and `Foo\round(…)` as shapes that MUST register, and without the clause neither
// does. The mutation described as surviving is killed by the block the description motivated.
//
// This matters beyond tidiness: the stale sentence was cited as evidence that the census floor
// needed tightening to an exact count. It does not — the hole that argument rests on is closed,
// and the paragraph below still holds. Measured baseline `scanned` is 354 (355 before issue
// #334's fix removed one call site); the mutant reads 352.
//
// The depth rule is the exception and needs no synthetic subject: this plugin ships the legal
// `$x / (7 * 86400)` shape in src/Helpers/DataBuckets.php, so degrading `>= $depth` to `>= 0`
// already fails the gate against real code. Pro needed nine synthetic subjects because pro's
// tree holds no legal instance; free needs these because free's tree holds no ILLEGAL one.
//
// The census floor above is deliberately NOT tightened to an exact 355 instead. Pro can hold an
// exact count over 21 files; free walks 127 files and 355 call sites, where an exact number
// churns on every unrelated sprintf() anyone adds.
$rc_shapes = [
    'round(($a / $b) * 100, 2)'          => true,  // the shape the plugin shipped
    '\round(($a / $b) * 100, 2)'         => true,  // ONE T_NAME_FULLY_QUALIFIED token on 8.0+
    'Foo\round(($a / $b) * 100, 2)'      => true,  // T_NAME_QUALIFIED
    // OUTER-scanner subjects for qualified names. The three sprintf rows further down exercise
    // the NESTED guard; these exercise $is_name + the name normalisation at the top of
    // rc_rounding_calls(). They are separate halves and were asymmetric until 2026-08-31: a
    // T_NAME_RELATIVE clause here was vacuous (deleting it left the gate green), and pro's outer
    // scanner saw neither shape at all while its nested guard saw both.
    'namespace\\round(($a / $b) * 100, 2)' => true,  // T_NAME_RELATIVE
    'round(100 * ($a / $b), 2)'          => true,  // the mirror: operands swapped
    // divmul=false is correct — the operands ARE in ADR-17's order. What makes this shape a
    // defect is the FORMATTER doing the rounding, which is `fmtround` below, not `divmul`.
    // This entry used to be commented "the reordered form this gate REQUIRES", which read as a
    // blessing of the very shape issues #334/#335 are about.
    'sprintf("%01.4f", (100 * $c / $t))' => false,
    'round($x / (7 * 86400), 2)'         => false, // divide by a computed constant — exemption
    'round($a / $b, 2 * 3)'              => false, // multiplication in a LATER argument
    'round(2 * 3, ($a / $b))'            => false, // the mirror half's comma reset
    '$o->round(($a / $b) * 100, 2)'      => null,  // a METHOD — must not register as a call
    'Foo::round(($a / $b) * 100, 2)'     => null,  // a static call — must not register
];
$rc_shape_bad = [];
foreach ($rc_shapes as $rc_expr => $rc_want) {
    $rc_calls = rc_rounding_calls('<?php ' . $rc_expr . ';');
    if (null === $rc_want) {
        if ([] !== $rc_calls) {
            $rc_shape_bad[] = $rc_expr . ': must not register as a call, got ' . count($rc_calls);
        }
        continue;
    }
    if (1 !== count($rc_calls)) {
        $rc_shape_bad[] = $rc_expr . ': the scanner found ' . count($rc_calls) . ' call(s), not 1';
        continue;
    }
    if ($rc_want !== $rc_calls[0]['divmul']) {
        $rc_shape_bad[] = $rc_expr . ': want divmul=' . var_export($rc_want, true)
            . ', got ' . var_export($rc_calls[0]['divmul'], true);
    }
    // The RECORDED name, not merely the fact that something was recorded. Without this,
    // reverting 'name' to the raw token leaves NO trace anywhere in the gate: a fully-qualified
    // site would record '\round', the site matcher reads 'round', and the gate would report
    // "found 0" — "the site is gone" — about a site that is present and correct.
    $rc_want_name = (false === strpos($rc_expr, 'sprintf')) ? 'round' : 'sprintf';
    if ($rc_want_name !== $rc_calls[0]['name']) {
        $rc_shape_bad[] = $rc_expr . ": recorded name '" . $rc_calls[0]['name']
            . "', want '" . $rc_want_name . "'";
    }
}

// The `fmtround` branch needs its own subjects, because free's tree contains no ILLEGAL
// instance any more — the four sites #334/#335 fixed were the only ones, so without these the
// branch could be deleted and every control would still print PASS. (Same reasoning as the
// divmul shapes above; free needs synthetic subjects where pro needed them for the mirror.)
$rc_fmt_shapes = [
    // The defect: a %.Nf conversion applied to an expression containing a division.
    'sprintf("%01.2f", (100 * $c) / $t)'          => true,
    'sprintf("%01.4f", (100 * $c / $t))'          => true,
    'printf("%.1f", $a / $b)'                     => true,
    // NOT the defect, and each of these would be a false positive that gets the branch relaxed:
    'sprintf("%01.2f", $already_rounded)'         => false, // formatting, no division
    'sprintf("%d/%d", $a, $b)'                    => false, // the slash is inside a STRING
    'sprintf("%s", $a / $b)'                      => false, // no float conversion
    'round((100 * $c) / $t, 2)'                   => false, // not a formatter at all
    'number_format_i18n(round($a / $b, 2), 2)'    => false, // the shape this gate REQUIRES
    // Round-then-format IS correct, in either spelling. Flagging these would make the rule fail
    // on the very remediation its own failure message recommends — and a check that reds on
    // correct code is a check somebody relaxes. Found by review, not by the tree.
    'sprintf("%.2f", round($a / $b, 2))'          => false,
    'sprintf("%.2f", number_format($a / $b, 2))'  => false,
    // The three shapes the guard let through until review measured them. Each prints the WRONG
    // answer at runtime — sprintf('%.2f', sprintf('%s', 100*1/32)) is "3.12" where half-up is
    // "3.13" — while the guard reported "already rounded". A nested sprintf does not round, and
    // a method or static named `round` is not PHP's round().
    'sprintf("%.2f", sprintf("%s", $a / $b))'    => true,
    'sprintf("%.2f", $obj->round($a / $b))'      => true,
    'sprintf("%.2f", MyCls::round($a / $b))'     => true,
    // The divergence corpus. Until 2026-08-31 free answered these with slimstat_last_name_segment()
    // and pro with ltrim($name, '\\') — which strips only a LEADING backslash — so the two gates
    // gave OPPOSITE verdicts on exactly these three, with pro (the namespaced repo) wrong. Caught
    // by a human diffing two files, by nothing either file ran. They are pinned here because they
    // are the only class of input the siblings have ever disagreed on.
    'sprintf("%.2f", Ns\\round($a / $b, 2))'               => false,
    'sprintf("%.2f", namespace\\round($a / $b, 2))'        => false,
    'sprintf("%.2f", \\Ns\\Sub\\number_format($a / $b, 2))' => false,
];
foreach ($rc_fmt_shapes as $rc_expr => $rc_want) {
    $rc_calls = rc_rounding_calls('<?php ' . $rc_expr . ';');
    if (!$rc_calls) {
        $rc_shape_bad[] = $rc_expr . ': the scanner found no call at all';
        continue;
    }
    // number_format_i18n(round(...)) registers two calls; the OUTER one is $rc_calls[0].
    $rc_got = false;
    foreach ($rc_calls as $rc_call) {
        $rc_got = $rc_got || $rc_call['fmtround'];
    }
    if ($rc_want !== $rc_got) {
        $rc_shape_bad[] = $rc_expr . ': want fmtround=' . var_export($rc_want, true)
            . ', got ' . var_export($rc_got, true);
    }
}
$controls[] = [
    [] === $rc_shape_bad,
    'the scanner reads every rounding call shape it claims to, and no others'
        . ([] === $rc_shape_bad ? '' : ' — ' . implode('; ', $rc_shape_bad)),
];

// ── Report ───────────────────────────────────────────────────────────────────────────────────

echo "CONTROLS\n";
$controls_ok = true;
foreach ($controls as [$ok, $label]) {
    $controls_ok = $controls_ok && $ok;
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $label);
}
if (!$controls_ok) {
    $failures[] = 'a CONTROL failed — the result below says nothing until it is fixed';
}
echo "\n";

printf("SLIMSTAT-ROUNDING-CONTRACT-TEST sites=%d checks=%d scanned=%d failures=%d\n",
    count($sites), $checks, $scanned, count($failures));

if ($failures) {
    fwrite(STDERR, 'FAIL: the rounding contract is not held (' . count($failures) . ")\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: every shipped percentage divides last, its double is the exact ratio on every "
    . "interpreter, and its rendered value is exact half-up over the integer counts\n";
