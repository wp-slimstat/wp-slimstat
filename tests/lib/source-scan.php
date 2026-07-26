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
