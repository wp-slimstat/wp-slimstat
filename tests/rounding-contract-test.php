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
 * WHAT IT DELIBERATELY DOES NOT ASSERT. `sprintf('%.Nf')` rounds ties-to-EVEN on every
 * runtime, so `1/32` prints `3.12` where the documented half-up rule says `3.13`, on the
 * percentage beside every row of every top-N report (ADR-17 §5a, "F1"). That is a real and
 * separate finding about a different mechanism; reordering the operands does not touch it, and
 * this file does not claim it does. Same for Pro's email report double-rounding ("F4").
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
        // function being scanned. Checked backwards over significant tokens only — a comment
        // between the arrow and the name would otherwise hide a method call from this filter.
        $prev = $i - 1;
        while ($prev >= 0 && is_array($tokens[$prev])
            && in_array($tokens[$prev][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $prev--;
        }
        if ($prev >= 0 && is_array($tokens[$prev])
            && in_array($tokens[$prev][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
            continue;
        }

        $open = slimstat_next_significant($tokens, $i);
        if ($open >= $count || '(' !== slimstat_token_text($tokens[$open])) {
            continue;
        }
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
        for ($k = $open + 1; $k < $close; $k++) {
            $text = slimstat_token_text($tokens[$k]);
            if (is_array($tokens[$k])) {
                continue; // only bare-string tokens can be an operator or a bracket
            }
            if ('(' === $text || '[' === $text) {
                $depth++;
            } elseif (')' === $text || ']' === $text) {
                $depth--;
            } elseif (',' === $text && 0 === $depth) {
                if ($first_end === $close) {
                    $first_end = $k;
                }
                $max_div_depth = -1;
                $min_mul_depth = PHP_INT_MAX;
            } elseif ('/' === $text) {
                $max_div_depth = max($max_div_depth, $depth);
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
    $setup   = $site['setup']($a, $b);
    $handed  = eval($setup . ' return ' . $call['first'] . ';');
    $rounded = eval($setup . ' return round(' . $call['args'] . ');');
    $evaluated++;
    $checks += 2;

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
    $want = rc_exact_half_up($a, $b, $site['places']);
    $got  = sprintf('%.' . $site['places'] . 'F', (float) $rounded);
    if ($want !== $got) {
        $failures[] = sprintf('%s [%s]: renders %s%%, but exact half-up of 100 * %d / %d at %d dp is '
            . '%s%% — surface: %s',
            $id, $ref, $got, $a, $b, $site['places'], $want, $site['surface']);
    }
}

$controls[] = [
    count($sites) === $evaluated,
    sprintf('all %d shipped percentage sites were located and their expressions EVALUATED (%d)',
        count($sites), $evaluated),
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
        if (!$call['divmul']) {
            continue;
        }
        $failures[] = sprintf('%s:%d — %s() multiplies the result of a division: `%s`. Every one of '
            . 'these percentages is a ratio of two COUNTs; divide LAST so the single division is the '
            . 'only rounding: (100 * $a) / $b (ADR-17; PITFALLS 72)',
            $rel, $call['line'], $call['name'], preg_replace('/\s+/', ' ', trim($call['first'])));
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
    'round(100 * ($a / $b), 2)'          => true,  // the mirror: operands swapped
    'sprintf("%01.4f", (100 * $c / $t))' => false, // the reordered form this gate REQUIRES
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
