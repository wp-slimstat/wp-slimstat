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

foreach (glob($plugin_root . '/tests/*-test.php') ?: [] as $file) {
    $name = basename($file);
    if (false === strpos((string) file_get_contents($file), 'source-scan.php')) {
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
