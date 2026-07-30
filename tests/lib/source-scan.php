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
 * Return the body of a named PHP function/method, or '' when it cannot be found.
 *
 * Brace-matched rather than regex-terminated, so it is immune to indentation style
 * and to nested closures/arrays — wp-slimstat.php mixes tabs and 4-space indents in
 * the same class, which is exactly what defeated the earlier patterns.
 */
function slimstat_function_body(string $source, string $name): string
{
    if (!preg_match('/function\s+' . preg_quote($name, '/') . '\s*\(/', $source, $m, PREG_OFFSET_CAPTURE)) {
        return '';
    }

    $open = strpos($source, '{', $m[0][1]);
    if (false === $open) {
        return '';
    }

    $depth  = 0;
    $length = strlen($source);
    for ($i = $open; $i < $length; $i++) {
        $char = $source[$i];
        if ('{' === $char) {
            $depth++;
        } elseif ('}' === $char) {
            $depth--;
            if (0 === $depth) {
                return substr($source, $open + 1, $i - $open - 1);
            }
        }
    }

    return '';
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
 * Return the bodies of every `catch (\Throwable $e) { ... }` block in $source.
 *
 * @return string[]
 */
function slimstat_throwable_catch_bodies(string $source): array
{
    $bodies = [];
    $offset = 0;

    while (preg_match('/catch\s*\(\s*\\\\?Throwable\s+\$\w+\s*\)\s*\{/', $source, $m, PREG_OFFSET_CAPTURE, $offset)) {
        $open   = $m[0][1] + strlen($m[0][0]) - 1;
        $depth  = 0;
        $length = strlen($source);

        for ($i = $open; $i < $length; $i++) {
            if ('{' === $source[$i]) {
                $depth++;
            } elseif ('}' === $source[$i]) {
                $depth--;
                if (0 === $depth) {
                    $bodies[] = substr($source, $open + 1, $i - $open - 1);
                    break;
                }
            }
        }

        $offset = $m[0][1] + strlen($m[0][0]);
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
function slimstat_blank_comments(string $source): string
{
    $out = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && (T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0])) {
            $newlines = substr_count($token[1], "\n");
            $out .= str_repeat("\n", $newlines) . str_repeat(' ', strlen($token[1]) - $newlines);
            continue;
        }
        $out .= is_array($token) ? $token[1] : $token;
    }

    return $out;
}
