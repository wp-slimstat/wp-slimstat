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
 * Source-level regression: PHP 8.0+ functions must not appear in own code.
 *
 * Until v5.4.14, admin/index.php called str_contains() (PHP 8.0+) without
 * a polyfill load, fataling every wp-admin page on real PHP 7.4 hosts. The
 * gap: PHPUnit 10.5 requires PHP >= 8.1 so CI's 7.4 lane was lint-only, and
 * php -l does not verify function existence. This vanilla-PHP test runs on
 * the 7.4 lane and greps own code for PHP 8.0+ stdlib functions with no
 * polyfill.
 *
 * To allow a specific call site (e.g. behind a polyfill load), add the
 * marker /​* php-polyfill: ok *​/ on the comment line immediately above.
 */

declare(strict_types=1);

$plugin_root = dirname(__DIR__);

// PHP 8.0+ stdlib functions with no PHP 7.4 fallback. Scoped to 8.0 (the
// version mismatch that produced the fatal). Add later-version functions
// here when they appear in own code, not preemptively.
$forbidden_functions = [
    'str_contains',
    'str_starts_with',
    'str_ends_with',
    'fdiv',
    'get_debug_type',
    'preg_last_error_msg',
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
        $pattern = '/(?<![>:$\\\\])\b' . preg_quote($fn, '/') . '\s*\(/';
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
    fwrite(STDERR, "FAIL: PHP 8.0+ functions found in own code (would fatal on PHP 7.4):\n");
    foreach ($violations as $v) fwrite(STDERR, "  - {$v}\n");
    fwrite(STDERR, "\nTo allow a specific call site, add /* php-polyfill: ok */ on the line above.\n");
    exit(1);
}

echo "OK: scanned " . count($files) . " own-code files, no PHP 8.0+ stdlib calls found\n";
