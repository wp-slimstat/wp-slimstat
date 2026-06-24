<?php
/**
 * Source-level: own code does not loosely compare a wp_slimstat setting against
 * 0 / '' / "" (PHP 8.0 changed `0 == ''` from true to false).
 *
 * PINS FIX (Phase 2). An admin-form setting saved as an empty string and
 * compared with `0 == ...` resolved to the default-fallback on PHP 7 but not on
 * PHP 8, silently changing the rendered value. This scanner is RED before the
 * fix (empty()/strict form) and green after; it then guards re-introduction.
 *
 * Scope: wp-slimstat.php, admin/, src/ — excludes src/Dependencies/.
 * Allow-marker: /​* php-loose-compare: ok *​/ on the comment line above a site.
 */

declare(strict_types=1);

$plugin_root  = dirname(__DIR__);
$deps_prefix  = $plugin_root . '/src/Dependencies';
$allow_marker = 'php-loose-compare: ok';

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

// Loose equality of a settings value against 0/''/"" — the PHP-8.0 break class.
// Matches both operand orders; does NOT match empty()/=== forms or other literals.
$patterns = [
    "literal == \$settings" => '/(?:0|\'\'|"")\s*==\s*wp_slimstat::\$settings\[/',
    "\$settings == literal" => '/wp_slimstat::\$settings\[[^\]]+\]\s*==\s*(?:0|\'\'|"")/',
];

$violations = [];
foreach ($files as $file) {
    $contents = file_get_contents($file);
    if (false === $contents) continue;
    foreach ($patterns as $pattern) {
        if (!preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) continue;
        foreach ($matches[0] as [$match, $offset]) {
            $line_no = substr_count($contents, "\n", 0, $offset) + 1;
            $prev    = $line_no > 1 ? explode("\n", substr($contents, 0, $offset))[$line_no - 2] : '';
            if (false !== strpos($prev, $allow_marker)) continue;
            $rel = substr($file, strlen($plugin_root) + 1);
            $violations[] = sprintf('%s:%d  → %s', $rel, $line_no, trim($match));
        }
    }
}

if ($violations) {
    fwrite(STDERR, "FAIL: loose comparison of a setting against 0/'' (PHP 8.0 `0 == ''` flip):\n");
    foreach ($violations as $v) fwrite(STDERR, "  - {$v}\n");
    fwrite(STDERR, "\nFix: use empty(\$x) or a strict === / (int) cast so PHP 7 and 8 agree.\n");
    exit(1);
}

echo "OK: scanned " . count($files) . " own-code files, no PHP-8.0 loose-comparison sites found\n";
