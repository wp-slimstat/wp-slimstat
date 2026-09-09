<?php
/**
 * Shared helpers for the source-level regression tests.
 *
 * These tests assert facts about the SOURCE TEXT (not runtime behaviour), which is
 * the only way to pin architectural constraints such as "this bootstrap function
 * must not reference an autoloaded class". They previously each carried their own
 * function-body regex; the variants disagreed about tab vs. space indentation and
 * about how far a function body extends, so some silently matched nothing and one
 * scanned to end-of-file. One implementation now serves all of them.
 *
 * 7.4-safe: plain functions, no autoloader, no WordPress.
 */

declare(strict_types=1);

/**
 * Text of a named function/method's body. Throws when there is no such definition.
 *
 * TOKENISED, not brace-counted over raw text: a `{` inside a string, comment, regex or
 * heredoc used to run the body on into the functions that follow, and a name appearing
 * only in a comment returned a DIFFERENT function's body entirely. Both failures, and
 * the fixtures that prove them, live in tests/source-scan-strength-test.php.
 *
 * NOT FOUND IS FATAL. Returning '' was indistinguishable from a genuinely empty body, so
 * a rename silently emptied every assertion about it and the suite stayed green. An
 * empty body still returns ''; only absence throws.
 *
 * Callers for which absence is a legitimate answer — "this class may not define the
 * method at all" — want slimstat_find_function_body() instead, which returns null. Make
 * that choice at the call site: an optional lookup should be visible where it is made.
 *
 * @throws RuntimeException when $name is not DEFINED in $source (a mention in a comment
 *                          or a string is not a definition).
 */
function slimstat_function_body(string $source, string $name): string
{
    $body = slimstat_find_function_body($source, $name);

    if (null === $body) {
        throw new RuntimeException(sprintf(
            'source-scan: no definition of %s() found. A mention in a comment or a string is '
            . 'not a definition — if the function was renamed, the assertion reading it is now vacuous.',
            $name
        ));
    }

    return $body;
}

/**
 * Body of $name, or null when $source contains no such definition.
 *
 * The optional form. slimstat_function_body() is the default and should stay the default:
 * of the consuming gates, all but two assert about a function they require to exist, and
 * for those a silent '' is how an assertion becomes vacuous without anyone noticing.
 */
function slimstat_find_function_body(string $source, string $name): ?string
{
    $tokens = slimstat_tokenize($source);
    $count  = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || T_FUNCTION !== $tokens[$i][0]) {
            continue;
        }

        $n = slimstat_next_significant($tokens, $i);

        // `function &foo()` — the by-reference marker sits between the keyword and the
        // name, and 8.1 split the ampersand into its own token types, so compare text
        // rather than a token id.
        if ($n < $count && '&' === slimstat_token_text($tokens[$n])) {
            $n = slimstat_next_significant($tokens, $n);
        }
        if ($n >= $count || !is_array($tokens[$n]) || T_STRING !== $tokens[$n][0] || $name !== $tokens[$n][1]) {
            continue;
        }

        // `use function Foo\bar;` also reads as T_FUNCTION + T_STRING. A definition is
        // followed by its parameter list; an import is followed by `;` or `,`.
        $after = slimstat_next_significant($tokens, $n);
        if ($after >= $count || '(' !== slimstat_token_text($tokens[$after])) {
            continue;
        }

        $paren = slimstat_token_paren_end($tokens, $after, $count);
        if (null === $paren) {
            continue;
        }

        // Step over any return type, then decide between a body and a bare `;` —
        // abstract and interface methods are declarations with no body to return, and
        // taking the next block would hand back the FOLLOWING method's.
        $brace = null;
        for ($k = $paren + 1; $k < $count; $k++) {
            $text = slimstat_token_text($tokens[$k]);
            if (';' === $text) {
                break;
            }
            if ('{' === $text) {
                $brace = $k;
                break;
            }
        }
        if (null === $brace) {
            continue;
        }

        // The opening brace is already known, so only the matching close is looked up.
        $range = slimstat_token_block_range($tokens, $brace, $count);
        if (null === $range) {
            continue;
        }

        return slimstat_token_text_range($tokens, $brace + 1, $range[1]);
    }

    return null;
}

/**
 * token_get_all() over a whole file OR a bare fragment.
 *
 * Callers pass both: a file read from disk, and the body of one function extracted by
 * slimstat_function_body(). A fragment has no `<?php`, and token_get_all() classifies
 * everything before an open tag as T_INLINE_HTML — so a tokenised scan over a fragment
 * sees ONE text blob and no PHP tokens at all.
 *
 * That is fail-open, and it bit immediately: the catch-block scanner returned zero
 * guards for functions that plainly have `} catch (\Throwable $e) {`, because
 * failsoft-visibility-test.php scans extracted bodies rather than files. A regex over
 * raw text did not care; a tokeniser does.
 *
 * The synthetic open tag is dropped, so concatenating the returned tokens reproduces
 * $source byte for byte and offset-preserving callers stay correct.
 */
function slimstat_tokenize(string $source, ?bool $is_file = null): array
{
    if (null === $is_file) {
        // Sniff: a whole file contains a real open-tag TOKEN. Anchoring on `^\s*<\?`
        // instead — the obvious test — is wrong for a file that opens with inline HTML,
        // and two are in this tree (admin/view/partials/header.php and
        // slimstat-pro-modal.php both start with an HTML comment). Those would be
        // treated as fragments, get `<?php ` prepended, and have their leading HTML
        // lexed as PHP: one apostrophe in that comment and everything after it becomes
        // a string literal, so the blankers would blank REAL CODE and the catch scanner
        // would return zero guards. Silent and fail-open — the exact hazard class this
        // file exists to close, reintroduced by a different route.
        //
        // The residual ambiguity is a FRAGMENT that embeds a literal `<?php` in a
        // string. That cannot be sniffed either way, so such a caller must say so.
        $is_file = false;
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && T_OPEN_TAG === $token[0]) {
                $is_file = true;
                break;
            }
        }
    }

    if ($is_file) {
        return token_get_all($source);
    }

    // A fragment has no `<?php`, and token_get_all() classifies everything before an open
    // tag as T_INLINE_HTML — one text blob, no PHP tokens, so a tokenised scan silently
    // sees nothing. The synthetic tag is dropped, so concatenating the returned tokens
    // reproduces $source byte for byte and offset-preserving callers stay correct.
    $tokens = token_get_all('<?php ' . $source);
    array_shift($tokens);

    return $tokens;
}

/** Source text of a token, whether it arrived as an array or a bare string. */
function slimstat_token_text($token): string
{
    return is_array($token) ? $token[1] : $token;
}

/**
 * Last segment of a possibly-qualified class name: `\Foo\Throwable` -> `Throwable`.
 *
 * PHP 8.0 stopped emitting qualified names as T_NS_SEPARATOR + T_STRING runs and now
 * emits one T_NAME_FULLY_QUALIFIED / T_NAME_QUALIFIED / T_NAME_RELATIVE token carrying
 * the whole name. Matching on T_STRING alone therefore silently stopped seeing
 * `\Throwable` on 8.x — the tokeniser change turns a strict scan into a vacuous one.
 */
function slimstat_last_name_segment(string $name): string
{
    $pos = strrpos($name, '\\');

    return false === $pos ? $name : substr($name, $pos + 1);
}

/**
 * The token types that can carry a function NAME, as an isset()-able map.
 *
 * PHP 7.4 emits only T_STRING. 8.0+ collapses a qualified name into ONE token —
 * T_NAME_FULLY_QUALIFIED (`\round`), T_NAME_QUALIFIED (`Foo\round`) or T_NAME_RELATIVE
 * (`namespace\round`) — so the set is assembled with defined() rather than written as a literal,
 * and a scanner that checks T_STRING alone is blind to every qualified call on 8.x.
 *
 * The `static` memo is NOT a cost optimisation and should not be read as one: measured at THIS
 * repo's call frequency (162 invocations per run) it saves 0.03 ms across the whole run, two
 * orders of magnitude below the ~5.5 ms the MAP itself wins at the call site. It is here because
 * the helper is shared by two gates in this repo, which is the only reason building it once is
 * worth a line.
 *
 * (An earlier version of this sentence placed the memo an order of magnitude below a +0.386 ms
 * figure it said the call site cited for the map. Wrong twice: 0.386 ms is PRO's number, measured
 * over pro's 31,544-token corpus, and pro's call site attributes it to the `||` CHAIN this map
 * replaced — not to the map. Free's own call site cites −5.55 ms and 0.343 ms and never 0.386.
 * That is PITFALLS 104 sitting one paragraph above the commit that filed PITFALLS 104. Written
 * without quote marks on purpose: tests/record-citation-test.php checks quoted spans in comments
 * naming a record, and quoting my own superseded prose there would be a citation of nothing.)
 *
 * IN THE LIB, not in a gate, and that is the point. STATE.json harness_debt_run53 NOW counts SIX
 * hand-rolled copies of this predicate across the two repos (listing four clause counts, 4/3/1/1,
 * for the six); the revision this move discharged — STATE.json AT 19c57f6, where the entry still
 * reasoned about TWO — said plainly:
 *
 *   "Cost is NOT the reason to do it … the duplication is. A slimstat_name_token_types() in
 *    tests/lib/source-scan.php fixes both in one move."
 *
 * A copy in a test file would have adopted the shape and left that reason undischarged.
 *
 * THE REVISION IS NAMED ON PURPOSE. An earlier version of this paragraph quoted that sentence
 * against the CURRENT STATE.json, where it no longer appears — the same commit that fixed the
 * duplication also rewrote the entry, so the quote and the "SIX copies" figure came from two
 * different revisions spliced together. A reader grepping STATE.json for the quote got nothing,
 * in the docblock whose subject is misquotation. Verified now: 0 matches at HEAD, 1 at 19c57f6.
 *
 * Its natural neighbour is slimstat_last_name_segment(): every consumer calls that on the
 * matched token's text one line later. They are two halves of one hazard.
 *
 * @return array<int, true> Token type => true.
 */
function slimstat_name_token_types(): array
{
    static $types = null;

    if (null === $types) {
        $types = [T_STRING => true];
        foreach (['T_NAME_FULLY_QUALIFIED', 'T_NAME_QUALIFIED', 'T_NAME_RELATIVE'] as $const) {
            if (defined($const)) {
                $types[constant($const)] = true;
            }
        }
    }

    return $types;
}

/**
 * Strip comment lines from a YAML document, so a check for CODE cannot be satisfied by prose.
 *
 * Every gate that reads .github/workflows/ci.yml needs this, and each one that skipped it has
 * been wrong in the same direction: `strpos($ci, 'npx wp-env install-path')` is TRUE when the
 * only occurrence is a comment explaining that the command used to be missing. So is a check for
 * an upload path that appears solely in a commented-out step. Prose is not configuration.
 *
 * There are two hand-rolled copies of this line in the tree already
 * (ci-matrix-coverage-test.php, perf-gate-integrity-test.php). They are not migrated here
 * because both are load-bearing gates with their own mutations and this is not the commit to
 * re-point them; new callers use this one.
 */
function slimstat_yaml_strip_comments(string $yaml): string
{
    return (string) preg_replace('/^\s*#.*$/m', '', $yaml);
}

/**
 * Split a GitHub Actions workflow into step blocks.
 *
 * Splits on six-space `- `, NOT on `- name:`. The difference is not stylistic: a step whose
 * first key is `uses:` rather than `name:` folds into its predecessor under the `- name:`
 * dialect, so an assertion scoped to "this step" silently reads two. ci-matrix-coverage-test.php
 * documents that in its own comment and splits correctly; perf-gate-integrity-test.php still
 * uses `- name:`. This is the correct dialect, extracted so a third one does not appear.
 *
 * @return string[] One entry per step; the first is whatever preceded the first step.
 */
function slimstat_ci_steps(string $yaml): array
{
    return preg_split('/(?=^[ ]{6}- )/m', $yaml) ?: [];
}

/** Concatenated source text of $tokens over the half-open range [$from, $to). */
function slimstat_token_text_range(array $tokens, int $from, int $to): string
{
    $out = '';
    for ($i = $from; $i < $to; $i++) {
        $out .= slimstat_token_text($tokens[$i]);
    }

    return $out;
}

/**
 * Enumerate this plugin's own PHP files under $paths, excluding vendored code.
 *
 * Eight source-level tests carried a byte-identical copy of this walk before it was
 * extracted, and the copies had already drifted — one lost its `sort()`, so its
 * failure output was in filesystem order and irreproducible between machines.
 * Sorted here so a failure list is stable.
 *
 * @param string[] $paths       Files or directories to scan.
 * @param string   $deps_prefix Directory whose subtree is skipped (vendored code).
 * @return string[]
 */
function slimstat_own_php_files(array $paths, string $deps_prefix): array
{
    $files = [];

    foreach ($paths as $path) {
        if (is_file($path)) {
            if ('.php' === substr($path, -4)) {
                $files[] = $path;
            }
            continue;
        }
        if (!is_dir($path)) {
            continue;
        }

        $directory = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
        $filtered  = new RecursiveCallbackFilterIterator($directory, function ($file) use ($deps_prefix) {
            return 0 !== strpos($file->getPathname(), $deps_prefix . DIRECTORY_SEPARATOR);
        });
        foreach (new RecursiveIteratorIterator($filtered) as $file) {
            $name = $file->getPathname();
            if ('.php' === substr($name, -4)) {
                $files[] = $name;
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * The repo-relative form of a path produced by slimstat_own_php_files().
 *
 * A PREFIX strip, never `str_replace($plugin_root, '', $file)`: str_replace removes
 * every occurrence, and the root string can also occur INSIDE the path — a checkout
 * mounted at /w turned admin/view/wp-slimstat-db.php into admin/viewp-slimstat-db.php,
 * and the mangled $rel silently missed its DDL exemption. A relative path that feeds
 * an allowlist or exemption lookup must come from here, not from an inline strip.
 */
function slimstat_rel_path(string $plugin_root, string $file): string
{
    return ltrim(substr($file, strlen($plugin_root)), '/');
}

/**
 * Index of the next token that is not whitespace or a comment.
 *
 * Skipping comments matters: five assertions in this suite have passed by matching a
 * name that appeared in a docblock, and a scanner that stops at `T_WHITESPACE` alone
 * is defeated by `function init /* note *​/ (`.
 */
function slimstat_next_significant(array $tokens, int $i): int
{
    $count = count($tokens);
    $i++;
    while ($i < $count && is_array($tokens[$i])
        && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
        $i++;
    }

    return $i;
}

/**
 * Byte-range of the brace block that opens at or after $from, as [openIndex, closeIndex].
 *
 * Returns null when no balanced block is found before $limit.
 *
 * `T_CURLY_OPEN` and `T_DOLLAR_OPEN_CURLY_BRACES` are counted because `"{$x}"`
 * inside a block would otherwise close it early — the interpolation form emits an
 * opening curly as a token but its closing brace as a plain '}'. Omitting them is a
 * silent mis-match, not an error, which is why this lives in one place.
 *
 * @return array{0:int,1:int}|null
 */
function slimstat_token_block_range(array $tokens, int $from, int $limit): ?array
{
    $depth = 0;
    $open  = null;

    for ($k = $from; $k < $limit; $k++) {
        if ('{' === $tokens[$k]) {
            if (0 === $depth) {
                $open = $k;
            }
            $depth++;
        } elseif ('}' === $tokens[$k]) {
            $depth--;
            if (0 === $depth && null !== $open) {
                return [$open, $k];
            }
        } elseif (is_array($tokens[$k])
            && in_array($tokens[$k][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            $depth++;
        }
    }

    return null;
}

/**
 * Is $name called at the STATEMENT LEVEL of the block spanning [$open, $close]?
 *
 * "Statement level" means brace depth 0 inside the block — the call runs on every pass through
 * it, rather than only when some branch is taken. That distinction is the whole question for a
 * loop that must record progress as it goes: a call reachable only from
 * `if ($failed) { … }` looks identical to a containment check and is not the same property.
 *
 * SHARES slimstat_token_block_range()'s brace-token set, deliberately and by type. The first
 * version of this walk lived inline in a gate and compared token TEXT: `T_CURLY_OPEN` (`"{$x}"`)
 * has the text `{` so it matched by accident, but `T_DOLLAR_OPEN_CURLY_BRACES` (`"${x}"`) has the
 * text `${` and did NOT — while its closing brace is a plain `}` that decremented anyway. One
 * `${…}` anywhere in the block drove the depth to −1, after which a call inside an `if` read as
 * statement level and the gate went green on the defect it existed to catch. Demonstrated on a
 * fixture, not reasoned about. Two consumers disagreeing about which tokens open a brace is the
 * failure this library's header describes, which is why the answer lives here once.
 *
 * @param array<int, array{0:int,1:string,2:int}|string> $tokens token_get_all() output
 */
function slimstat_statement_level_call(array $tokens, int $open, int $close, string $name): bool
{
    $depth = 0;

    for ($k = $open + 1; $k < $close; $k++) {
        $token = $tokens[$k];

        if ('{' === $token) {
            $depth++;
            continue;
        }
        if ('}' === $token) {
            $depth--;
            continue;
        }
        if (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            $depth++;
            continue;
        }
        if (0 === $depth && is_array($token) && $name === slimstat_last_name_segment($token[1])) {
            return true;
        }
    }

    return false;
}

/**
 * TOKEN-INDEX ranges [[open, close], ...] of every `{ ... }` block whose `if` condition
 * calls $guard.
 *
 * Extracted from activation-hooks-registered-test.php when subsite-table-hook-test.php
 * grew a second copy — and the copies had ALREADY drifted in the way this library's
 * header warns about: one tested containment with the token index, the other with a
 * cumulative BYTE offset, against ranges that are token indexes. Two consumers
 * disagreeing about one helper's units is a containment check that can never fire.
 *
 * Ranges are token indexes; test membership with the token's index, never a byte
 * offset. Callers decide their own vacuity policy — a file known to contain guarded
 * blocks should fail when this returns [] (see the call sites), because with no ranges
 * every "not inside a guarded block" assertion passes vacuously.
 *
 * @param array<int, array{0:int,1:string,2:int}|string> $tokens token_get_all() output
 * @return array<int, array{0:int,1:int}>
 */
function slimstat_guarded_block_ranges(array $tokens, string $guard = 'is_admin'): array
{
    $count  = count($tokens);
    $ranges = [];

    for ($i = 0; $i < $count; $i++) {
        // `elseif` too: a guard on the second branch of an if/elseif is a guard. Missed until
        // no-unguarded-error-log-test.php adopted this helper; pinned in source-scan-strength.
        if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], [T_IF, T_ELSEIF], true)) {
            continue;
        }

        $cond_end = slimstat_token_paren_end($tokens, $i, $count);
        if (null === $cond_end) {
            continue;
        }

        // Every name-shaped token, not T_STRING alone — and this one is in the LIBRARY, so it
        // is the blind spot with the widest reach. Both consumers use these ranges for a
        // must-be-ABSENT question ("this registration is not inside an is_admin() block"), so
        // a single `\is_admin()` guard makes its block invisible, containment answers false,
        // and the gate reports PASS on the C38 defect it exists to catch. The `[] === $ranges`
        // vacuity check does not save it: that fires only if EVERY block goes invisible.
        $guard_types = slimstat_name_token_types();
        $calls_guard = false;
        for ($k = $i; $k < $cond_end; $k++) {
            if (is_array($tokens[$k])
                && isset($guard_types[$tokens[$k][0]])
                && $guard === slimstat_last_name_segment($tokens[$k][1])) {
                $calls_guard = true;
                break;
            }
        }
        if (!$calls_guard) {
            continue;
        }

        $range = slimstat_token_block_range($tokens, $cond_end, $count);
        if (null !== $range) {
            $ranges[] = $range;
        }
    }

    return $ranges;
}

/**
 * An argument list split on TOP-LEVEL commas, one token array per positional slot.
 *
 * $open is the `(` and $close the matching `)` — pass slimstat_token_paren_end()'s answer.
 *
 * ── Why positional, and why this is not "collect the string literals" ────────────────────────
 *
 * A scanner that walks the argument list gathering `T_CONSTANT_ENCAPSED_STRING` in order looks
 * equivalent and is not. Given `foo($suffix, 'vid_hash', 'key')` it yields ['vid_hash', 'key'],
 * so slot 0 reads as `vid_hash` — a value that belongs to slot 1. Every assertion built on that
 * map is then checking the wrong pair, and the gate PASSES while the obligation it exists to
 * enforce is unmet. That shape was written for tests/upgrade-index-convergence-test.php and
 * caught in review before it landed; this exists so the next caller cannot rebuild it.
 *
 * Nested calls and array literals are stepped over, so `foo(bar('x'), 'y')` has 'y' in slot 1.
 *
 * @return array<int, array<int, array|string>> one entry per argument, in source order
 */
function slimstat_call_args(array $tokens, int $open, int $close): array
{
    $depth = 0;
    $args  = [[]];

    for ($k = $open + 1; $k < $close; $k++) {
        $text = slimstat_token_text($tokens[$k]);

        if ('(' === $text || '[' === $text) {
            $depth++;
        } elseif (')' === $text || ']' === $text) {
            $depth--;
        } elseif (0 === $depth && ',' === $text) {
            $args[] = [];
            continue;
        }

        $args[count($args) - 1][] = $tokens[$k];
    }

    return $args;
}

/**
 * The single string literal an argument slot consists of, or null.
 *
 * Null when the slot holds a variable, a constant, a concatenation, or anything else that is not
 * exactly one quoted string — which is the honest answer for a scanner deciding whether it can
 * resolve the argument statically. Whitespace and comments are ignored so formatting does not
 * change the verdict.
 */
function slimstat_arg_string(array $arg): ?string
{
    $found = null;

    foreach ($arg as $token) {
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        if (is_array($token) && T_CONSTANT_ENCAPSED_STRING === $token[0] && null === $found) {
            $found = trim($token[1], "'\"");
            continue;
        }

        return null; // a second token in this slot: not a bare literal
    }

    return $found;
}

/**
 * Index of the `)` closing the parenthesis group that opens at or after $from.
 *
 * Returns null when unbalanced before $limit.
 */
function slimstat_token_paren_end(array $tokens, int $from, int $limit): ?int
{
    $depth = 0;

    for ($k = $from; $k < $limit; $k++) {
        if ('(' === $tokens[$k]) {
            $depth++;
        } elseif (')' === $tokens[$k]) {
            $depth--;
            if (0 === $depth) {
                return $k;
            }
        }
    }

    return null;
}

/**
 * Bodies of every `catch (… Throwable …) { … }` block in $source.
 *
 * TOKENISED for the same reason as slimstat_function_body(), and this one was actively
 * fail-open: the old regex ran over raw text, so a `catch (\Throwable $e)` quoted in a
 * COMMENT or sitting inside a STRING counted as a real guard.
 *
 * On the CURRENT tree both implementations agree — 12 catches across the 8 guarded
 * functions — so this is a latent hazard here, not a live miscount. It is demonstrated
 * on a fixture in tests/source-scan-strength-test.php, where a function carrying no
 * guard at all but describing the one it used to have reports two. That matters because
 * tests/failsoft-visibility-test.php asserts fail-soft guards exist by counting these,
 * so the functions most likely to carry such a comment are exactly the ones a raw-text
 * count would credit falsely.
 *
 * Matching is on the caught TYPE LIST rather than on one literal spelling, so
 * `catch (RuntimeException | \Throwable $e)` counts and `catch (MyThrowableThing $e)`
 * does not.
 *
 * @return string[]
 */
function slimstat_throwable_catch_bodies(string $source, ?bool $is_file = null): array
{
    $tokens = slimstat_tokenize($source, $is_file);
    $count  = count($tokens);
    $bodies = [];

    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || T_CATCH !== $tokens[$i][0]) {
            continue;
        }

        $open_paren = slimstat_next_significant($tokens, $i);
        if ($open_paren >= $count || '(' !== slimstat_token_text($tokens[$open_paren])) {
            continue;
        }

        $paren = slimstat_token_paren_end($tokens, $open_paren, $count);
        if (null === $paren) {
            continue;
        }

        $catches_throwable = false;
        for ($k = $open_paren + 1; $k < $paren; $k++) {
            if (is_array($tokens[$k]) && 'Throwable' === slimstat_last_name_segment($tokens[$k][1])) {
                $catches_throwable = true;
                break;
            }
        }
        if (!$catches_throwable) {
            continue;
        }

        $range = slimstat_token_block_range($tokens, $paren + 1, $count);
        if (null === $range) {
            continue;
        }

        $bodies[] = slimstat_token_text_range($tokens, $range[0] + 1, $range[1]);
        $i        = $range[1];
    }

    return $bodies;
}

/**
 * Blank out comments, preserving every byte offset and line number.
 *
 * Raw-text scanners match prose, and the code that FIXES a defect is exactly where
 * that defect gets described. Measured twice in one session: Pro's runtime-null
 * scanner reported a fix as the defect because the new docblock quoted the removed
 * expression, and the cron scanner below reported a RETIRED hook as scheduled
 * because the comment explaining the retirement quoted wp_schedule_event().
 *
 * Offsets are preserved rather than the comments removed, so a caller can match
 * against the blanked text and still index into the ORIGINAL — which matters when
 * allow-markers live in comments, i.e. in exactly what this blanks out.
 */
function slimstat_blank_comments(string $source, ?bool $is_file = null): string
{
    return slimstat_blank_token_types($source, [T_COMMENT, T_DOC_COMMENT], $is_file);
}

/**
 * Blank the listed token types, preserving every byte offset and line number.
 *
 * The two blankers differ ONLY in which token ids count as "not code", so the
 * offset-preserving walk lives here once rather than being a property each of them has
 * to get right independently.
 *
 * Offsets are preserved rather than the text removed, so a caller can match against the
 * blanked text and still index into the ORIGINAL — which matters precisely because
 * allow-markers live in comments, i.e. in exactly what this blanks out.
 *
 * T_CONSTANT_ENCAPSED_STRING keeps its delimiters and blanks only the content, so a
 * scanner can still tell that a string was there at all. The tokeniser never emits one
 * without both delimiters, so no length guard is needed.
 *
 * @param int[] $blank Token ids whose text is replaced by equivalent whitespace.
 */
function slimstat_blank_token_types(string $source, array $blank, ?bool $is_file = null): string
{
    $out = '';

    foreach (slimstat_tokenize($source, $is_file) as $token) {
        if (!is_array($token)) {
            $out .= $token;
            continue;
        }
        if (!in_array($token[0], $blank, true)) {
            $out .= $token[1];
            continue;
        }

        $text = $token[1];
        $out .= T_CONSTANT_ENCAPSED_STRING === $token[0]
            ? $text[0] . slimstat_blanked_like(substr($text, 1, -1)) . substr($text, -1)
            : slimstat_blanked_like($text);
    }

    return $out;
}

/**
 * Whitespace of the same byte length and line count as $text.
 *
 * Shared by the two blankers so "preserves offsets" is one implementation rather than a
 * property each of them has to get right independently.
 */
function slimstat_blanked_like(string $text): string
{
    $newlines = substr_count($text, "\n");

    return str_repeat("\n", $newlines) . str_repeat(' ', strlen($text) - $newlines);
}

/**
 * Blank comments AND string contents, preserving every byte offset and line number.
 *
 * This is the guard for the standing "a name is not a use" hazard. All known instances
 * matched a name that appeared in prose or in a literal rather than as the construct
 * under test:
 *
 *   1. HTTP_REFERER inside the docblock explaining its removal
 *   2. wp_schedule_event() inside the comment explaining the retirement
 *   3. the DECLARATION of MAX_LOG_BYTES / $sql_truncated, whose every USE was deletable
 *   4. suppress_errors — which the RESTORING call also contains
 *   5. OptionClaim — which compareAndSwap() also mentions
 *
 * slimstat_blank_comments() handles 1-2. Cases 3-5 additionally need literals gone,
 * because the surviving mention is inside a string. Offsets are preserved rather than
 * the text removed so a caller can match against the stripped text and still index into
 * the ORIGINAL — which matters precisely because allow-markers live in comments, i.e. in
 * what this blanks out.
 *
 * The delimiters are kept and only the CONTENT is blanked, so a scanner can still tell
 * that a string was there at all.
 */
function slimstat_strip_comments_and_strings(string $source, ?bool $is_file = null): string
{
    return slimstat_blank_token_types($source, [
        T_COMMENT,
        T_DOC_COMMENT,
        T_ENCAPSED_AND_WHITESPACE,   // body of a "..." or heredoc with interpolation
        T_INLINE_HTML,               // text outside <?php, which is not code either
        T_CONSTANT_ENCAPSED_STRING,
    ], $is_file);
}

/**
 * The stdlib functions Symfony's Php80 polyfill supplies — one list, two questions.
 *
 * Shipped code MAY use these: wp-slimstat.php requires the polyfill bootstrap, so they exist on
 * the 7.4 floor. Code loaded WITHOUT that bootstrap may not — tests/docker/report-answers.php is
 * required by a standalone gate with no WordPress and no autoloader, where a call parses fine and
 * fatals when made.
 *
 * ONE CONSUMER TODAY, deliberately: php80-syntax-scan-test.php, which BANS these names in the one
 * file loaded without the bootstrap. Two sibling copies exist and are knowingly left alone —
 * php74-no-php80-functions-test.php holds its own for a diagnostic sentence (it bans nothing with
 * it, so the drift argument does not reach it), and tests/Unit/Polyfill/Php80PolyfillLoadedTest.php
 * has a PHPUnit data provider. Recorded rather than claimed fixed: "one list" would be a tidier
 * sentence than the tree supports.
 *
 * RESTATES the bootstrap rather than deriving from it, which is the weaker half of this helper.
 * src/Dependencies/Symfony/Polyfill/Php80/bootstrap.php is 58 lines of one repeating
 * `if (!function_exists('X')) { function X(...) }` shape, so a guard-name-matches-declared-name
 * regex would return exactly these seven from the authority itself. Not done here because a
 * derivation over a scoper output that silently returns [] turns the ban into a loop over nothing
 * — a gate that passes while banning nothing — so it needs a count floor in the same change, and
 * that is a seam of its own rather than a line in this one.
 *
 * @return string[]
 */
function slimstat_php80_polyfilled_functions(): array
{
    return ['fdiv', 'preg_last_error_msg', 'str_contains', 'str_starts_with', 'str_ends_with', 'get_debug_type', 'get_resource_id'];
}

/**
 * Run one PHP script in a child process and hand back everything it produced.
 *
 * For the driver/harness shape: a test that must load a file which declares functions or
 * classes at file scope can only do so once per process, so each case runs in its own child
 * and reports back over STDOUT. uninstall.php and wp-slimstat.php are both that kind of file.
 *
 * The script path is a parameter because `__FILE__` here would be this library, not the caller.
 *
 * The selector is passed as an explicit environment array rather than through putenv(),
 * because putenv() leaking into proc_open's default (null) environment is not guaranteed
 * across platforms and SAPIs, and a child that silently ran the wrong case would make every
 * assertion downstream vacuous. `$_ENV` is merged in for whatever the parent exports —
 * measured as usually empty under the default `variables_order`, so the child starts near
 * enough clean; nothing in these harnesses depends on an inherited variable, and PHP_BINARY
 * is absolute.
 *
 * Decoding is left to the caller: one consumer wants JSON, another only an exit code.
 *
 * @param  string                $script Absolute path to the script to run.
 * @param  array<string,string>  $env    Variables the child must see.
 * @return array{stdout:string,stderr:string,exit:int}|null Null when the process could not start.
 */
function slimstat_spawn_child(string $script, array $env): ?array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process     = proc_open(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script),
        $descriptors,
        $pipes,
        null,
        $env + $_ENV
    );

    if (!is_resource($process)) {
        return null;
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => (int) proc_close($process)];
}

/**
 * The Tier 2 WordPress lanes a CI workflow declares, as version => php.
 *
 * Four hand-rolled copies of this regex existed across three gates, two of them added in the
 * same commit that added this helper, and two carried their own `< 5` vacuity floor hardcoding
 * a number the matrix had already passed. They also disagreed about what to capture: one took
 * the WP version, one the PHP version, one both.
 *
 * COMMENTS ARE STRIPPED FIRST. A matrix entry quoted in a comment — "was { wp: 7.1 }" — is
 * prose about a lane, not a lane, and every gate that reads this file has been fooled by that
 * shape at least once.
 *
 * @return array<string,string> e.g. ['5.6' => '7.4', … , '7.1' => '8.3'], insertion-ordered
 */
function slimstat_ci_wp_lanes(string $yaml): array
{
    $code  = slimstat_yaml_strip_comments($yaml);
    $lanes = [];

    if (preg_match_all('/\{\s*wp:\s*"(\d+\.\d+)"\s*,\s*php:\s*"(\d+\.\d+)"\s*\}/', $code, $m, PREG_SET_ORDER)) {
        foreach ($m as $pair) {
            $lanes[$pair[1]] = $pair[2];
        }
    }

    return $lanes;
}

/**
 * Collapse every whitespace run to one space, and trim.
 *
 * For comparing a span quoted in PHP — where the author wraps it to fit a docblock or an array
 * literal — against the same span in Markdown, where it wraps at a different column. Neither
 * wrapping is meaningful and a byte compare fails on both.
 *
 * There are two hand-rolled `preg_replace('/\s+/', ' ', …)` copies in record-citation-test.php
 * already, without the trim and without /u. They are not migrated here: that file has a declared
 * twin in wp-slimstat-pro whose header permits exactly five divergences, so re-pointing it needs
 * a matching pro_ helper in the same sitting. New callers use this one.
 */
function slimstat_collapse_ws(string $text): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $text));
}

/**
 * The programme's standing records, and whether this checkout can see them.
 *
 * `jaan-to/` is a SIBLING of the plugin, not part of it, so a standalone checkout — which is what
 * every CI lane has — cannot see the records at all. Every gate that cites them needs the same
 * three things: where they live, which files are citable, and whether to skip.
 *
 * DISCRIMINATED ON THE SIBLING ROOT, NEVER ON THE LEAF DIRECTORY, and that is the whole reason
 * this is a function. Keying on the records directory answers the wrong question: it degrades to
 * "there is no jaan-to here" whenever that one path moves, then prints that as fact and exits 0
 * in the one checkout that does have records to check. This workspace has moved jaan-to paths
 * before. So: no `jaan-to/` at all is a standalone checkout and skips; a `jaan-to/` that is
 * present but has no records directory is a PROBLEM, because there the records are supposed to be.
 *
 * Extracted at the fourth copy. record-citation-test.php (free and its pro twin) and
 * campaign-phase-gate-test.php keep their inline versions — already in two spellings — because
 * each is a load-bearing gate with its own mutations, and the pro twin bounds how far free's copy
 * may drift. New callers use this one, which is what makes "the same set of records" true by
 * construction rather than by a comment saying it is.
 *
 * @return array{standalone:bool,dir:string,records:array<string,string>,problems:array<int,string>}
 */
function slimstat_standing_records(string $plugin_root): array
{
    $repo_root = dirname($plugin_root);
    $dir       = $repo_root . '/jaan-to/outputs/dev/v6-performance';

    $records = [];
    foreach (['STATE.json', 'PITFALLS.md', 'DECISIONS.md', 'VERIFICATION-PROTOCOL.md', 'EXPECTED-DIFFS.md'] as $name) {
        $records[$name] = $dir . '/' . $name;
    }

    $standalone = !is_dir($repo_root . '/jaan-to');
    $problems   = [];

    if (!$standalone && !is_dir($dir)) {
        $problems[] = sprintf('the jaan-to sibling is present but %s is not — if the programme '
            . 'records moved, the gate reading them moved with them and nobody updated it', $dir);
    }

    return ['standalone' => $standalone, 'dir' => $dir, 'records' => $records, 'problems' => $problems];
}

/**
 * Constructs in $php newer than PHP $floor_id, by token scan. One implementation for every
 * "what does this file need to parse" ratchet, so a construct added to the table is added for all.
 *
 * `since` IS WHEN A CONSTRUCT BECAME MEANINGFUL, NOT WHEN IT BECAME PARSEABLE — and the two
 * differ. `: void` is recorded as 7.1, the version that reserved the word; PHP 7.0 parses it
 * as a class-named return type and moves on. A real interpreter (php:7.0-cli, run by a
 * reviewer) parsed wp-slimstat.php while this table said it could not. So a floor taken from
 * this scan is a RATCHET on what may be added to a file, never a statement of where the file
 * stops parsing. Only an interpreter says that; php-floor-test.php §5 pins one into CI.
 *
 * A denylist, stated as such: it finds the constructs someone thought to list. 7.0–7.4 only:
 * php80-syntax-scan-test.php owns 8.0+ over the same files with its own allow-marker, and a
 * previous version of this table carried 8.x rows that no caller ever consumed — and defended
 * them with a claim ("text matching checks the same on every lane") that was false, since on 7.4
 * `#[` tokenises as a comment and `match`/`enum` as plain strings.
 *
 * @return array<int, array{line:int, construct:string, since:int}>
 */
function slimstat_php_constructs_newer_than(string $php, int $floor_id): array
{
    $found  = [];
    $tokens = token_get_all($php);
    $count  = count($tokens);

    $operators = [
        '??'  => ['null coalescing operator', 70000],
        '<=>' => ['spaceship operator', 70000],
        '??=' => ['null coalescing assignment', 70400],
    ];

    // Type names and the version each became one. `void` is return-only in the grammar, so no
    // parseable file has it in a parameter position — one table serves both places.
    $type_since = [
        'int' => 70000, 'float' => 70000, 'string' => 70000, 'bool' => 70000,
        'iterable' => 70100, 'void' => 70100, 'object' => 70200,
    ];

    /** Token $k names a type (T_STRING/T_ARRAY) and the next significant token is a variable. */
    $typed_variable = static function (int $k) use ($tokens, $count): bool {
        if ($k >= $count || !is_array($tokens[$k]) || !in_array($tokens[$k][0], [T_STRING, T_ARRAY], true)) {
            return false;
        }
        $v = slimstat_next_significant($tokens, $k);

        return $v < $count && is_array($tokens[$v]) && T_VARIABLE === $tokens[$v][0];
    };

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        $text  = slimstat_token_text($token);
        $lower = strtolower($text);
        $line  = is_array($token) ? $token[2] : 0;

        if (isset($operators[$text])) {
            $found[] = ['line' => $line, 'construct' => $operators[$text][0], 'since' => $operators[$text][1]];
            continue;
        }

        // `fn` only as a token in its own right (T_FN) and not a member name: `$o->fn()` is a call.
        if (is_array($token) && 'fn' === $lower && T_STRING !== $token[0]) {
            $prev = $i > 0 ? $tokens[$i - 1] : null;
            if (!is_array($prev) || !in_array($prev[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                $found[] = ['line' => $line, 'construct' => 'arrow function', 'since' => 70400];
                continue;
            }
        }

        // Typed property: visibility, optional `static`, optional `?`, a type, then a variable.
        if (is_array($token) && in_array($token[0], [T_PUBLIC, T_PRIVATE, T_PROTECTED, T_VAR], true)) {
            $j = slimstat_next_significant($tokens, $i);
            if ($j < $count && is_array($tokens[$j]) && T_STATIC === $tokens[$j][0]) {
                $j = slimstat_next_significant($tokens, $j);
            }
            if ($j < $count && '?' === slimstat_token_text($tokens[$j])) {
                $j = slimstat_next_significant($tokens, $j);
            }
            if ($typed_variable($j)) {
                $found[] = ['line' => $tokens[$j][2], 'construct' => 'typed property', 'since' => 70400];
            }
            continue;
        }

        if (!is_array($token) || T_FUNCTION !== $token[0]) {
            continue;
        }

        // Signature: skip the name (absent for a closure) to reach `(`, then walk to its match.
        $open = slimstat_next_significant($tokens, $i);
        if ($open < $count && '(' !== slimstat_token_text($tokens[$open])) {
            $open = slimstat_next_significant($tokens, $open);
        }
        if ($open >= $count || '(' !== slimstat_token_text($tokens[$open])) {
            continue;
        }
        $close = slimstat_token_paren_end($tokens, $open, $count);
        if (null === $close) {
            continue;
        }

        for ($k = $open + 1; $k < $close; $k++) {
            if ('?' === slimstat_token_text($tokens[$k])) {
                // Anchored on the variable that follows, because a default value may hold a
                // ternary, which every runtime parses.
                if ($typed_variable(slimstat_next_significant($tokens, $k))) {
                    $found[] = ['line' => $line, 'construct' => 'nullable parameter type', 'since' => 70100];
                }
                continue;
            }
            if (is_array($tokens[$k]) && T_STRING === $tokens[$k][0] && isset($type_since[strtolower($tokens[$k][1])])) {
                $found[] = [
                    'line'      => $tokens[$k][2],
                    'construct' => 'parameter type `' . $tokens[$k][1] . '`',
                    'since'     => $type_since[strtolower($tokens[$k][1])],
                ];
            }
        }

        $after = slimstat_next_significant($tokens, $close);
        if ($after < $count && ':' === slimstat_token_text($tokens[$after])) {
            $t     = slimstat_next_significant($tokens, $after);
            $since = 70000;
            $name  = 'return type declaration';
            if ($t < $count && '?' === slimstat_token_text($tokens[$t])) {
                $since = 70100;
                $name  = 'nullable return type';
            } elseif ($t < $count && is_array($tokens[$t]) && isset($type_since[strtolower($tokens[$t][1])])) {
                $since = $type_since[strtolower($tokens[$t][1])];
                $name  = 'return type `' . $tokens[$t][1] . '`';
            }
            $found[] = ['line' => $line, 'construct' => $name, 'since' => $since];
        }
    }

    return array_values(array_filter($found, static function (array $f) use ($floor_id): bool {
        return $f['since'] > $floor_id;
    }));
}

/**
 * Does a ci.yml step run for one value of a matrix key? Both conditional forms, both quotes.
 *
 * Lifted from ci-matrix-coverage-test.php, where it was a private closure keyed to `matrix.php`,
 * when ci-lane-executes-something-test.php needed the identical rule for `matrix.wp` as well.
 * The history that shaped it is worth keeping with it: the first draft understood only the
 * exclusion form (`matrix.php != 'X'`), so a step gated by the INCLUSION form
 * (`matrix.php == '8.2'`) was credited to every version in the matrix — a PHPUnit step that
 * ran on 8.2 alone satisfied 7.4 and 8.0. `==` is an allow-list: if a step names the versions
 * it runs for, everything else is out.
 *
 * $step must already be comment-stripped; a commented-out `if:` is not a condition.
 */
function slimstat_ci_step_runs_for(string $step, string $matrix_key, string $value): bool
{
    if (!preg_match('/^[^\S\n]+if:.*$/m', $step, $m)) {
        return true; // no condition: runs on every value in the matrix
    }
    $cond = $m[0];
    $key  = preg_quote($matrix_key, '/');

    if (preg_match_all('/matrix\.' . $key . '\s*==\s*[\'"]([0-9.]+)[\'"]/', $cond, $inc) && [] !== $inc[1]) {
        return in_array($value, $inc[1], true);
    }

    return !preg_match('/matrix\.' . $key . '\s*!=\s*[\'"]' . preg_quote($value, '/') . '[\'"]/', $cond);
}

/**
 * ci.yml split into its top-level job blocks, keyed by job id.
 *
 * Three gates carried the same `(?=^\s{2}\w+:\s*\n\s+name:\s*"Tier)` split. Anchoring on the
 * name convention meant a job with a matrix and a name not starting "Tier" vanished from every
 * consumer — the exact shape of empty lane ci-lane-executes-something exists to catch. This
 * splits on any two-space key; consumers select by what the block contains (a matrix, a name).
 * Keys under `on:` (`push:`, `pull_request:`) come through too and are filtered the same way.
 *
 * @return array<string, string> job id => block text
 */
function slimstat_ci_job_blocks(string $ci_code): array
{
    $blocks = [];
    foreach (preg_split('/(?=^  [A-Za-z_][\w-]*:[ \t]*$)/m', $ci_code) ?: [] as $chunk) {
        if (preg_match('/^  ([A-Za-z_][\w-]*):[ \t]*$/m', $chunk, $m)) {
            $blocks[$m[1]] = $chunk;
        }
    }

    return $blocks;
}

/**
 * The matrix cells one job block declares, as [matrix key, value] pairs.
 *
 * `php: ["7.4", …]` lists are keyed `php`; Tier 2's `include:` pairs are keyed `wp`, because
 * that is the key the job's own step conditions use. Both consumers of this used to carry both
 * regexes; the include-pair one was the fifth copy of what slimstat_ci_wp_lanes() already does.
 *
 * @return array<int, array{0:string, 1:string}>
 */
function slimstat_ci_matrix_cells(string $block): array
{
    if (preg_match('/matrix:\s*php:\s*\[([^\]]+)\]/', $block, $mm) && preg_match_all('/"([0-9.]+)"/', $mm[1], $vm)) {
        return array_map(static fn(string $v): array => ['php', $v], $vm[1]);
    }

    return array_map(static fn(string $wp): array => ['wp', $wp], array_keys(slimstat_ci_wp_lanes($block)));
}

/**
 * POSITIONS of every step containing ALL the needles. Order inside a job is a property in its
 * own right: "the step that checks the booted version" is a check only if it runs AFTER the step
 * that boots it and BEFORE the one that stops it. Returning step TEXT throws that away at the
 * helper boundary, and §9b spent its whole life claiming the ordering in prose while asserting
 * only that both steps existed — a reviewer swapped them in Pro's twin and the gate stayed green.
 *
 * $steps is a preg_split list, so the keys are already positions; no cast, which on a non-list
 * array would quietly turn a string key into 0, i.e. "the first step".
 *
 * @return int[]
 */
function slimstat_ci_step_indexes(array $steps, string ...$needles): array
{
    $hits = [];
    foreach ($steps as $i => $step) {
        foreach ($needles as $needle) {
            if (false === strpos($step, $needle)) {
                continue 2;
            }
        }
        $hits[] = $i;
    }

    return $hits;
}

/**
 * Every step whose text contains ALL of the needles. The caller asserts on the count — eight
 * gates carried a loop that silently took the FIRST match, so a second step containing the same
 * command in another job was invisible to the check meant to pin it.
 *
 * Kept as the text-returning view because eight gates read it; new callers that care about WHERE
 * a step sits want slimstat_ci_step_indexes() above.
 *
 * @return string[]
 */
function slimstat_ci_steps_containing(array $steps, string ...$needles): array
{
    return array_map(static fn(int $i): string => $steps[$i], slimstat_ci_step_indexes($steps, ...$needles));
}

/**
 * One field of a plugin header or readme header block (`* Version: 6.0.0` / `Stable tag: 6.0.0`).
 * Seven readers used two regex shapes for the same lines; the two `Requires PHP` readers already
 * captured differently. Read RAW — the header is a comment, so blanking would remove it.
 */
function slimstat_header_field(string $source, string $field): ?string
{
    return preg_match('/^\s*\*?\s*' . preg_quote($field, '/') . ':\s*(\S+)\s*$/m', $source, $m) ? $m[1] : null;
}

/**
 * A file's content at a git revision, or null when the ref/path does not resolve or the tree is
 * not a repository. The revision is EXPLICIT: a ratchet that compares against the last commit
 * must not be handed the index, or a staged bump passes its own descent check.
 */
function slimstat_git_show(string $root, string $rev, string $path): ?string
{
    $out = @shell_exec('git -C ' . escapeshellarg($root) . ' show ' . escapeshellarg($rev . ':' . $path) . ' 2>/dev/null');

    return (null === $out || '' === $out) ? null : $out;
}
