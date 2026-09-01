<?php
/**
 * SlimStat Analytics — PHP 7.4 source-level regression test.
 *
 * @package wp-slimstat
 * @license GPL-2.0-or-later
 *
 * Copyright (C) 2026 VeronaLabs <info@veronalabs.com>
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License, version 2, as
 * published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
 * or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License
 * for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * Source-level regression: PHP 8.0+ stdlib functions in own code.
 *
 * As of v5.4.17 the Mozart-scoped Symfony/Polyfill/Php80 bootstrap is loaded
 * from wp-slimstat.php, so the 7 PHP 8.0 stdlib functions it polyfills are
 * safe to use in own code. This test:
 *
 *   1. ASSERTS the polyfill bootstrap is still required from wp-slimstat.php
 *      (if a future commit removes that require, str_contains et al. would
 *      again fatal on PHP 7.4 — the bug that produced v5.4.14's wp-admin
 *      crash).
 *   2. SCANS own code for PHP 8.1+ stdlib functions that the bundled polyfill
 *      does NOT cover (e.g. array_is_list, enum_exists, str_decrement). Adding
 *      one of these without an explicit polyfill load would fatal on PHP 7.4.
 *
 * To allow a specific call site behind a different polyfill, add the marker
 * /​* php-polyfill: ok *​/ on the comment line immediately above.
 */

declare(strict_types=1);

$plugin_root = dirname(__DIR__);

// ── 1. Polyfill bootstrap MUST be required from wp-slimstat.php ──────────────
$boot = file_get_contents($plugin_root . '/wp-slimstat.php');
if (false === $boot) {
    fwrite(STDERR, "FAIL: cannot read wp-slimstat.php\n");
    exit(1);
}
if (!preg_match('#require_once\s+__DIR__\s*\.\s*[\'"]/src/Dependencies/Symfony/Polyfill/Php80/bootstrap\.php[\'"]#', $boot)) {
    fwrite(STDERR, "FAIL: wp-slimstat.php must `require_once __DIR__ . '/src/Dependencies/Symfony/Polyfill/Php80/bootstrap.php';`\n");
    fwrite(STDERR, "      Without it, str_contains/str_starts_with/etc. fatal on PHP 7.4.\n");
    exit(1);
}

// ── 2. Scan own code for stdlib functions NOT covered by the bundled polyfill ─

// Symfony/Polyfill/Php80 covers these 7 (verified in
// src/Dependencies/Symfony/Polyfill/Php80/bootstrap.php). They are safe to use
// in own code, so they are NOT in $forbidden_functions.
// NOT read from the shared helper, and the reason is that this file does not use it as a rule.
// The allowance here IS the absence of these names from $forbidden_functions below; $polyfilled
// only supplies a sentence on the failure path. An earlier version imported the library for it
// and justified the import with "two copies would let a Symfony bump widen the allowance while
// leaving the ban short" — true of php80-syntax-scan-test.php, which BANS them, and not of this
// file, which bans nothing with it. The import also put this file in both halves of the
// opted-in/raw-scanner partition source-scan-strength-test.php asserts. A local list feeding one
// diagnostic string is the smaller thing.
$polyfilled = ['fdiv', 'preg_last_error_msg', 'str_contains', 'str_starts_with', 'str_ends_with', 'get_debug_type', 'get_resource_id'];

// PHP 8.1+ stdlib functions with no PHP 7.4 fallback in the bundled polyfill.
// Add later-version functions here as the language evolves.
$forbidden_functions = [
    'array_is_list',     // PHP 8.1
    'enum_exists',       // PHP 8.1
    'never_returns',     // sentinel (not a real fn) — keep list non-empty so the loop runs
];

$allow_marker = 'php-polyfill: ok';
$deps_prefix  = $plugin_root . '/src/Dependencies';

$paths = [
    $plugin_root . '/wp-slimstat.php',
    $plugin_root . '/admin',
    $plugin_root . '/src',
];

$files = [];
foreach ($paths as $path) {
    if (is_file($path)) {
        if ('.php' === substr($path, -4)) $files[] = $path;
        continue;
    }
    if (!is_dir($path)) continue;
    // Prune src/Dependencies/ at descent — saves walking ~500 scoped vendor files.
    $directory = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
    $filtered  = new RecursiveCallbackFilterIterator($directory, function ($file) use ($deps_prefix) {
        return 0 !== strpos($file->getPathname(), $deps_prefix . DIRECTORY_SEPARATOR);
    });
    foreach (new RecursiveIteratorIterator($filtered) as $file) {
        if ('.php' === substr($file->getPathname(), -4)) $files[] = $file->getPathname();
    }
}
sort($files);

if (0 === count($files)) {
    fwrite(STDERR, "FAIL: scanner found zero own-code .php files\n");
    exit(1);
}

$violations = [];
foreach ($files as $file) {
    $contents = file_get_contents($file);
    if (false === $contents) continue;
    foreach ($forbidden_functions as $fn) {
        // The lookbehind used to exclude a preceding backslash outright, which silently
        // exempted the ROOT-QUALIFIED spelling: `\str_contains(` is the same global function
        // and just as fatal on the 7.4 floor, and this scan could not see it. Now the leading
        // `\` is part of the match and only a NAMESPACED call is excluded — in `Foo\str_contains(`
        // the backslash is preceded by a word character, and starting at the name instead is
        // blocked by the `\\` still in the class. `->fn(`, `::fn(` and `$fn(` stay excluded.
        $pattern = '/(?<![\w>:$\\\\])\\\\?' . preg_quote($fn, '/') . '\s*\(/';
        if (!preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) continue;
        foreach ($matches[0] as [$match, $offset]) {
            $line_no = substr_count($contents, "\n", 0, $offset) + 1;
            $line    = strtok(substr($contents, $offset), "\n");
            $prev    = $line_no > 1 ? explode("\n", substr($contents, 0, $offset))[$line_no - 2] : '';
            if (false !== strpos($prev, $allow_marker)) continue;
            $stripped = ltrim($line);
            if (0 === strpos($stripped, '//') || 0 === strpos($stripped, '*')) continue;
            $rel = substr($file, strlen($plugin_root) + 1);
            $violations[] = sprintf('%s:%d  → %s(...)  %s', $rel, $line_no, $fn, trim($line));
        }
    }
}

if ($violations) {
    fwrite(STDERR, "FAIL: PHP 8.1+ stdlib functions found in own code (no polyfill, fatals on PHP 7.4):\n");
    foreach ($violations as $v) fwrite(STDERR, "  - {$v}\n");
    fwrite(STDERR, "\nFix: rewrite to a PHP 7.4-compatible equivalent, OR add /* php-polyfill: ok */ on the line above if you've separately loaded a polyfill.\n");
    fwrite(STDERR, "Note: PHP 8.0 stdlib (" . implode(', ', $polyfilled) . ") IS polyfilled — safe to use.\n");
    exit(1);
}

echo "OK: polyfill bootstrap loaded; scanned " . count($files) . " own-code files, no un-polyfilled PHP 8.1+ stdlib calls found\n";
