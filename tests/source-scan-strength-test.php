<?php
/**
 * Source-level: the shared scanner helpers are sound.
 *
 * WHY THIS EXISTS. tests/lib/source-scan.php underpins 56 assertions across 25 gating
 * tests. Two of its helpers extracted function bodies by counting `{` and `}` over RAW
 * TEXT, which counts braces inside strings, comments, regexes and heredocs. Measured
 * before the fix, on fixtures below:
 *
 *   - one `{` inside a regex string literal made slimstat_function_body('target')
 *     return target() AND neighbour() AND third() — the whole rest of the class. Every
 *     "this function's body must not contain X" assertion silently became "the rest of
 *     the file must not contain X", and every "must contain X" assertion passed when X
 *     lived in a NEIGHBOURING function.
 *
 *   - worse, and not previously recorded: asking for a function whose name appears only
 *     in a COMMENT returned a DIFFERENT function's body. `// … function ghost() …`
 *     matched the regex, then the scan walked forward to the next `{` in the file and
 *     returned real()'s body. A scan looking for ghost() was handed real() and asserted
 *     against it. That is the name-not-construct hazard living inside the very helper
 *     written to scope it — instance six, and the one underneath all the others.
 *
 * So this file is the self-test the plan requires: it fails the build if the helpers
 * ever stop distinguishing code from prose. Every case below was RED before the
 * token_get_all() rewrite and is GREEN after.
 *
 * 7.4-safe: plain functions, no autoloader, no WordPress.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$failures = [];
$checks   = 0;

/**
 * @param mixed $expected
 * @param mixed $actual
 */
function ss_assert_same(string $label, $expected, $actual): void
{
    global $failures, $checks;
    $checks++;
    if ($expected === $actual) {
        return;
    }
    $failures[] = sprintf(
        "%s\n      expected: %s\n      actual:   %s",
        $label,
        var_export($expected, true),
        var_export($actual, true)
    );
}

function ss_assert_true(string $label, bool $actual): void
{
    ss_assert_same($label, true, $actual);
}

/** Assert that $fn throws — the "could not find it" contract. */
function ss_assert_throws(string $label, callable $fn): void
{
    global $failures, $checks;
    $checks++;
    try {
        $fn();
    } catch (\Throwable $e) {
        return;
    }
    $failures[] = $label . "\n      expected a throw; the call returned normally";
}

// ---------------------------------------------------------------------------
// slimstat_function_body() — a brace in a literal must not end the body
// ---------------------------------------------------------------------------

$braces_in_literals = <<<'SRC'
<?php
class T {
    public function target() {
        $re      = '/\{unbalanced/';
        $dq      = "also {unbalanced";
        $comment = 1; // a stray { in prose
        /* and a { in a block comment */
        return 'A';
    }
    public function neighbour() {
        return 'B';
    }
    public function third() {
        return 'C';
    }
}
SRC;

$body = slimstat_function_body($braces_in_literals, 'target');
ss_assert_true('a `{` in a single-quoted string must not run the body on', false === strpos($body, "return 'B'"));
ss_assert_true('a `{` in a literal must not swallow a second neighbour', false === strpos($body, "return 'C'"));
ss_assert_true('the real body is still returned', false !== strpos($body, "return 'A'"));

// The neighbour must be reachable in its own right — over-run in the other direction
// would make this return nothing.
ss_assert_true(
    'neighbour() is extractable on its own',
    false !== strpos(slimstat_function_body($braces_in_literals, 'neighbour'), "return 'B'")
);

// ---------------------------------------------------------------------------
// A heredoc containing a brace
// ---------------------------------------------------------------------------

$heredoc = <<<'SRC'
<?php
class V {
    public function h() {
        $x = <<<TXT
        {
TXT;
        return $x;
    }
    public function after() { return 'AFTER'; }
}
SRC;

ss_assert_true(
    'a `{` inside a heredoc must not run the body on',
    false === strpos(slimstat_function_body($heredoc, 'h'), 'AFTER')
);

// ---------------------------------------------------------------------------
// The name-not-construct case: a name in prose is NOT a declaration
// ---------------------------------------------------------------------------

$name_only_in_comment = <<<'SRC'
<?php
// We deliberately no longer call function ghost() anywhere.
class U {
    public function real() { return 1; }
}
SRC;

ss_assert_throws(
    'a function named only in a COMMENT must be fatal, not another function\'s body',
    static function () use ($name_only_in_comment) {
        slimstat_function_body($name_only_in_comment, 'ghost');
    }
);

$name_only_in_string = <<<'SRC'
<?php
class W {
    public function real() {
        return 'function phantom() {';
    }
}
SRC;

ss_assert_throws(
    'a function named only in a STRING must be fatal',
    static function () use ($name_only_in_string) {
        slimstat_function_body($name_only_in_string, 'phantom');
    }
);

ss_assert_throws(
    'a genuinely absent function must be fatal, never a silent empty string',
    static function () use ($braces_in_literals) {
        slimstat_function_body($braces_in_literals, 'no_such_function_anywhere');
    }
);

// ---------------------------------------------------------------------------
// Constructs the previous implementation already handled — pin them so the
// rewrite cannot regress them
// ---------------------------------------------------------------------------

$nested = <<<'SRC'
<?php
class N {
    public function outer() {
        $map = ['a' => ['b' => 1]];
        $fn  = function () { return 2; };
        if (true) { $x = "{$map['a']['b']}"; }
        return $fn();
    }
    public function sentinel() { return 'SENTINEL'; }
}
SRC;

$outer = slimstat_function_body($nested, 'outer');
// The first of these is what pins `"{$map['a']['b']}"`: mishandled interpolation emits an
// opening curly as a token but its closing brace as a plain '}', which would close the
// enclosing `if` early and TRUNCATE the body before `return $fn();`.
ss_assert_true('nested arrays/closures/interpolation do not truncate the body', false !== strpos($outer, 'return $fn();'));
ss_assert_true('nested braces do not run the body on', false === strpos($outer, 'SENTINEL'));

// A method whose body is empty is a legitimate answer and must NOT be confused with
// "not found" — which is exactly what the old '' return made indistinguishable.
$empty_body = '<?php class E { public function nothing() {} public function s() { return "S"; } }';
ss_assert_same('an empty body returns empty, and does not throw', '', slimstat_function_body($empty_body, 'nothing'));

// ---------------------------------------------------------------------------
// slimstat_throwable_catch_bodies() — same raw-text hazard
// ---------------------------------------------------------------------------

$catches = <<<'SRC'
<?php
function guarded() {
    try {
        risky();
    } catch (\Throwable $e) {
        $pattern = '/\{/';
        record('caught');
    }
    return 'TAIL';
}
SRC;

$bodies = slimstat_throwable_catch_bodies($catches);
ss_assert_same('one Throwable catch is found', 1, count($bodies));
ss_assert_true('the catch body is captured', false !== strpos($bodies[0], "record('caught')"));
ss_assert_true(
    'a `{` inside the catch body must not run it past the closing brace',
    false === strpos($bodies[0], 'TAIL')
);

// A `catch (\Throwable ...)` written inside a comment or a string is not a catch.
$fake_catch = <<<'SRC'
<?php
function undefended() {
    // Historically this had catch (\Throwable $e) { record('x'); } — removed on purpose.
    $doc = 'catch (\Throwable $e) { still not real }';
    return risky();
}
SRC;

ss_assert_same(
    'a catch quoted in prose or a literal is not a catch',
    0,
    count(slimstat_throwable_catch_bodies($fake_catch))
);

// ---------------------------------------------------------------------------
// The FRAGMENT branch — every caller that scans an extracted body goes down it
// ---------------------------------------------------------------------------
// A fragment has no `<?php`, and token_get_all() classifies everything before an open tag
// as T_INLINE_HTML — one text blob, no PHP tokens, so a tokenised scan silently sees
// nothing. This is not hypothetical: it made the catch scanner return zero guards for
// functions that plainly have one, because failsoft-visibility-test.php scans extracted
// bodies rather than files.

$fragment = 'try { risky(); } catch (\Throwable $e) { record("x"); }';
ss_assert_same('a fragment with no open tag still tokenises as PHP', 1, count(slimstat_throwable_catch_bodies($fragment)));
ss_assert_same('a fragment round-trips byte for byte through the blanker', $fragment, slimstat_blank_comments($fragment));

// A whole FILE that opens with inline HTML must not be mistaken for a fragment. Two are
// in this tree — admin/view/partials/header.php and slimstat-pro-modal.php both start
// with an HTML comment. Mistaking one for a fragment prepends `<?php `, which lexes the
// leading HTML as PHP: a single apostrophe there swallows the rest of the file into a
// string literal, and then the blankers blank REAL CODE while the catch scanner reports
// zero guards. Silent and fail-open, which is the hazard class this whole file exists to
// close — so it must not be reintroduced by the file/fragment detector.
$html_first_file = "<!-- it's a banner -->\n<?php\nfunction real() { try { a(); } catch (\\Throwable \$e) { b(); } }\n";
ss_assert_same('a file opening with inline HTML is detected as a file', 1, count(slimstat_throwable_catch_bodies($html_first_file)));
ss_assert_true(
    'an apostrophe in that leading HTML does not swallow the code',
    false !== strpos(slimstat_strip_comments_and_strings($html_first_file), 'catch')
);
ss_assert_true(
    'and the function is still extractable from it',
    false !== strpos(slimstat_function_body($html_first_file, 'real'), 'catch')
);

// The residual ambiguity: a FRAGMENT embedding a literal `<?php` cannot be sniffed either
// way, so such a caller states which it holds. Passing false is the whole point of the
// parameter — sniffing is best-effort, declaring is exact.
$decoy = '$x = "<?php not an open tag"; try { a(); } catch (\Throwable $e) { b(); }';
ss_assert_same(
    'a fragment embedding a literal open tag is handled when it declares itself',
    1,
    count(slimstat_throwable_catch_bodies($decoy, false))
);

// ---------------------------------------------------------------------------
// slimstat_blank_comments() — offsets must survive, or allow-markers break
// ---------------------------------------------------------------------------

$commented = "<?php\n// marker: ok\n\$x = 1; /* tail */\n";
$blanked   = slimstat_blank_comments($commented);
ss_assert_same('blanking preserves byte length', strlen($commented), strlen($blanked));
ss_assert_same('blanking preserves line count', substr_count($commented, "\n"), substr_count($blanked, "\n"));
ss_assert_true('the comment text is gone', false === strpos($blanked, 'marker: ok'));
ss_assert_true('the code is untouched', false !== strpos($blanked, '$x = 1;'));

// ---------------------------------------------------------------------------
// slimstat_strip_comments_and_strings() — the guard the five known
// name-not-construct instances all needed
// ---------------------------------------------------------------------------

$prose_and_literals = <<<'SRC'
<?php
// wp_schedule_event() is retired — see the changelog.
$sql = 'SELECT suppress_errors FROM t';
wp_schedule_event($when, 'daily', 'real_hook');
SRC;

$stripped = slimstat_strip_comments_and_strings($prose_and_literals);
ss_assert_same('stripping preserves byte length', strlen($prose_and_literals), strlen($stripped));
ss_assert_true(
    'the name inside a comment is gone',
    1 === substr_count($stripped, 'wp_schedule_event')
);
ss_assert_true(
    'the name inside a string literal is gone',
    false === strpos($stripped, 'suppress_errors')
);
ss_assert_true('the real call survives', false !== strpos($stripped, "wp_schedule_event(\$when"));
ss_assert_true('string delimiters survive so offsets stay usable', false !== strpos($stripped, "'"));

// ---------------------------------------------------------------------------
// The helpers must be reachable by every consumer that claims to use them
// ---------------------------------------------------------------------------

$plugin_root = dirname(__DIR__);
$composer    = (string) file_get_contents($plugin_root . '/composer.json');
$using       = 0;
$unwired     = [];

// ONE glob and ONE read per test file, feeding both censuses below. They partition the same
// population — "opted into the library" and "did not" — so two glob expressions would be two
// definitions of "the set of gating tests", free to drift apart. That is the failure the vacuity
// floors here exist to catch, and it would be sitting inside the mechanism catching it.
$gating_tests = [];

foreach (glob($plugin_root . '/tests/*-test.php') ?: [] as $file) {
    $gating_tests[basename($file)] = (string) file_get_contents($file);
}

foreach ($gating_tests as $name => $src) {
    if (false === strpos($src, 'source-scan.php')) {
        continue;
    }
    $using++;

    // A gate that no script invokes is the same vacuity as a gate that asserts nothing,
    // one level up — and this file was the first offender: it shipped untracked and
    // absent from composer.json, so the only evidence for the tokeniser rewrite ran
    // nowhere but by hand.
    if (false === strpos($composer, 'tests/' . $name)) {
        $unwired[] = $name;
    }
}

ss_assert_same('every source-scan consumer is wired into composer.json', [], $unwired);

// Vacuity floor: a scan that derives its own coverage set needs a floor on the SET, not
// only on the walk. If the require line is ever renamed, $using drops to 0 and every
// check above would still pass while guarding nothing real.
ss_assert_true(
    sprintf('at least 20 gating tests still consume source-scan.php (found %d)', $using),
    $using >= 20
);

// ---------------------------------------------------------------------------
// …and the ones that DID NOT opt in — the hole underneath everything above
// ---------------------------------------------------------------------------
//
// Every check in this file inspects tests that already `require` source-scan.php. A scanner that
// never opted in is therefore invisible to the gate that exists to police scanners, and its
// raw-text matching is exactly the hazard this file was written to remove. The set the strength
// gate could see was the set that had already been made safe.
//
// Found by accident, which is the point: php80-syntax-scan-test.php matched `/each\s*\(/` against
// raw bytes and failed a commit because a CODE COMMENT contained the words "resolves each (table,
// column) pair". Instance seven of name-not-construct, in a file no assertion on this branch was
// capable of reading.
//
// A RATCHET, NOT A BAN. Twenty-one gating tests scan production source with a raw regex today;
// failing all of them means the rule never lands, and a rule that cannot land protects nothing.
// So the recorded state below is permitted, new offenders fail, and a file that gets FIXED must
// leave the map.
//
// SAFE VS UNSAFE DIRECTION. A raw "must NOT appear" scan fails loudly on prose — noisy, never
// silent. A raw "must appear" scan is satisfied BY prose, and passes while guarding nothing.
// Anything added here should be checked for which kind it is.
//
// A MAP, NOT A LIST, and each entry carries its reason — a flat string[] has nowhere to put one,
// and the failure message below instructs the next person to record why. The two kinds are not
// the same debt: `exempt` will never shrink and should not, while `debt` is work nobody has done
// yet. One count over both conflates them and stops describing anything.
$raw_scanners = [];
$recorded     = [
    // ── exempt: a PHP tokeniser has nothing to say about the bytes these read ──
    'ci-matrix-coverage-test.php'                => 'exempt — scans .github/workflows YAML',
    'css-selector-scope-test.php'                => 'exempt — scans CSS',
    'js-params-banner-gdpr-consistency-test.php' => 'exempt — scans JS',
    // Surfaced by the per-call-site rule below: each of these routes SOME content through a
    // helper and then scans other content raw, so the old whole-file exemption hid them.
    'migration-ui-honesty-test.php'              => 'exempt — the raw subject is JS ($js), not PHP',
    // The subject is COMMENT TEXT, already cut out by slimstat_tokenize() and filtered to
    // T_COMMENT/T_DOC_COMMENT — a tokeniser is applied, and the regex runs only on what it
    // returned. Stripping comments before matching would delete the entire subject.
    'record-citation-test.php'                   => 'exempt — the subject IS the comments, taken from the tokeniser',
    'rounding-contract-test.php'                 => 'exempt — its PHP half IS tokenised (rc_rounding_calls); the raw-text half scans JAVASCRIPT, which no PHP tokeniser can strip. Added after the ADR-17 seam fixed six PHP percentage sites and left five JS twins dividing first (ADR-17; PITFALLS 72)',
    // Every gettext call site IS tokenised. The one raw read is the plugin header's
    // `Text Domain:` line, which lives inside a doc comment — so blanking comments before
    // matching would delete the exact bytes it needs. Reading the declared domain from the
    // header rather than hardcoding it is what stops this gate and the plugin drifting apart
    // silently, so the raw read is the point rather than an oversight.
    'textdomain-single-source-test.php'          => 'exempt — the raw subject is the plugin header comment; the gettext scan itself is tokenised',

    // ── debt: PHP source matched as raw text; route through the library when next touched ──
    'access-log-author-edit-link-test.php'   => 'debt',
    'browscap-bot-safety-net-test.php'       => 'debt',
    'browscap-fileinfo-preflight-test.php'   => 'debt',
    'browscap-settings-isolation-test.php'   => 'debt',
    'browscap-wp-filesystem-test.php'        => 'debt',
    // Both are "must APPEAR" scans over raw bytes — the SILENT direction this file warns about
    // above: satisfied by the constant's name occurring in a comment, passing while guarding
    // nothing. Hidden until the per-call-site rule below replaced the whole-file exemption.
    'chart-query-budget-test.php'            => 'debt',
    'purge-archive-order-test.php'           => 'debt',
    'dtr-pton-init-test.php'                 => 'debt',
    'early-translation-test.php'             => 'debt',
    'funnels-widget-compact-test.php'        => 'debt',
    'gdpr-banner-asset-gating-test.php'      => 'debt',
    'goals-funnels-index-migration-test.php' => 'debt',
    'loose-comparison-scan-test.php'         => 'debt',
    'network-scope-handshake-test.php'       => 'debt',
    'php-implicit-nullable-test.php'         => 'debt',
    'php74-no-php80-functions-test.php'      => 'debt',
    'php82-84-forward-scan-test.php'         => 'debt',
    'shortcode-w-whitelist-test.php'         => 'debt',
    'wp-removed-core-fns-scan-test.php'      => 'debt',
    'wp70-wp-version-guard-test.php'         => 'debt',
];

foreach ($gating_tests as $name => $src) {
    // Reads a production file, and regex-matches something. `strpos` is deliberately not a
    // trigger: a substring test over a whole file is an inventory question, not a construct
    // question, and folding the two together would make the map unreadably large.
    if (false === strpos($src, 'file_get_contents')) {
        continue;
    }
    if (!preg_match("#'/(?:src|admin)/|/wp-slimstat\\.php|plugin_root \\. '/(?:src|admin)#", $src)) {
        continue;
    }
    if (false === strpos($src, 'preg_match')) {
        continue;
    }

    // A helper NAME anywhere in the file used to exempt the whole file. That is a whole-file
    // substring test answering a per-call-site question, and it was exact only while a file held
    // ONE scanner. When php80-syntax-scan-test.php gained a second, correctly routed scanner, the
    // mutation returning its FIRST scanner to raw matching began to SURVIVE — the token was still
    // present, so the file was still exempt — and composer test:mutations exited 1 for three
    // commits before anyone ran it (PITFALLS 62).
    //
    // So: TAINT, not whitelist. The polarity matters and getting it backwards is what made a
    // first attempt look impossible. Asking "is this subject PROVEN routed?" flags every derived
    // variable — `$line` from exploding a stripped buffer reads as raw — and would need a large
    // exemption list, which is a permission slip rather than a ratchet. Asking "is this subject
    // PROVABLY raw file bytes?" cannot make that mistake:
    //
    //   seed      $v = [cast] file_get_contents(...)      — raw bytes, definitely
    //   propagate $a = $b;                                 — plain alias ONLY
    //   clear     $v = <anything calling a helper>         — routed from here on
    //
    // Any function call on the right-hand side keeps the variable out of the set, so a value
    // derived through explode(), preg_split() or substr() is structurally incapable of being
    // flagged. The union with the old whole-file rule is deliberate: no file that was already
    // recorded leaves the map because of this change.
    $tainted = [];
    foreach (preg_split('/\R/', $src) as $line) {
        if (preg_match('/\$(\w+)\s*=\s*(?:\([a-z]+\)\s*)?file_get_contents\s*\(/i', $line, $m)) {
            $tainted[$m[1]] = true;
            continue;
        }
        if (preg_match('/\$(\w+)\s*=\s*\$(\w+)\s*;/', $line, $m)) {
            if (isset($tainted[$m[2]])) {
                $tainted[$m[1]] = true;
            }
            continue;
        }
        // Routed: the value now comes out of a tokeniser-backed helper, whatever it held before.
        if (preg_match('/\$(\w+)\s*=.*(?:slimstat_strip_comments_and_strings|slimstat_blank_comments|slimstat_function_body)\s*\(/', $line, $m)) {
            unset($tainted[$m[1]]);
        }
    }

    $scans_raw = false;
    if (preg_match_all('/preg_match(?:_all)?\s*\([^,]*,\s*\$(\w+)/', $src, $subjects)) {
        foreach ($subjects[1] as $subject) {
            if (isset($tainted[$subject])) {
                $scans_raw = true;
                break;
            }
        }
    }

    if (!$scans_raw) {
        // Fall back to the original whole-file question, so nothing already mapped drops out.
        foreach (['slimstat_strip_comments_and_strings', 'slimstat_blank_comments', 'slimstat_function_body'] as $helper) {
            if (false !== strpos($src, $helper)) {
                continue 2;
            }
        }
    }

    $raw_scanners[] = $name;
}

sort($raw_scanners);

ss_assert_same(
    "new raw-text scanner(s) of production source — route the content through\n"
        . "      slimstat_strip_comments_and_strings() or slimstat_blank_comments() before matching,\n"
        . '      or add the file to $recorded as "exempt — <why a tokeniser cannot apply>"',
    [],
    array_values(array_diff($raw_scanners, array_keys($recorded)))
);

// The ratchet itself, and it points the OTHER WAY from the obvious one. The first draft asserted
// `count($raw_scanners) <= count($recorded)` — which CANNOT FAIL, because the assertion above
// already requires $raw_scanners ⊆ $recorded, and a subset of a set of unique basenames is never
// larger than it. Six lines reading as the load-bearing half of a ratchet while measuring a
// tautology, written into the same change as the PITFALLS entry about guards that look like they
// work. Caught by a review agent; the gate ran green throughout.
//
// The check that does the advertised job is stale entries: a file that has been FIXED must leave
// the map. Without it the map only grows stale, a fixed scanner can silently revert to raw
// matching, and the "permission slip" the comment warns about is reachable through the mechanism
// meant to prevent it.
ss_assert_same(
    "recorded raw scanner(s) that no longer scan raw — delete them from \$recorded;\n"
        . '      a map that never shrinks stops being a ratchet and becomes a permission slip',
    [],
    array_values(array_diff(array_keys($recorded), $raw_scanners))
);

// Vacuity floor, same reason as $using above: if the detection regex goes stale, $raw_scanners
// empties, BOTH array_diff assertions above are trivially satisfied in the first direction and
// the second one fires with 21 names — so this floor is what makes the failure say "the detector
// broke" rather than "twenty-one files were fixed at once".
ss_assert_true(
    sprintf('the raw-scanner detector still finds the known offenders (found %d, expected >= 15)', count($raw_scanners)),
    count($raw_scanners) >= 15
);

// ---------------------------------------------------------------------------

if ($failures) {
    fwrite(STDERR, "FAIL: tests/lib/source-scan.php is not sound (" . count($failures) . " of {$checks} checks):\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    fwrite(STDERR, "\nThese helpers underpin 56 assertions across 25 gating tests. A helper that\n");
    fwrite(STDERR, "over-runs a function body makes every one of them assert against the wrong text.\n");
    exit(1);
}

echo "OK: source-scan helpers sound — {$checks} checks, {$using} consuming tests\n";
