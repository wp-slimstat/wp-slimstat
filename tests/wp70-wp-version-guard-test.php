<?php
/**
 * Source-level: own code does not read the raw $wp_version global.
 *
 * PINS FIX (Phase 2). WP 6.7+ documents wp_get_wp_version() /
 * is_wp_version_compatible() as the canonical accessors; the raw global is
 * fragile (hardening plugins blank it) and the only reader was a dead
 * version_compare('3.3') gate unreachable below the 5.6 floor. This scanner is
 * RED before the dead branch is removed and green after; it then guards
 * re-introduction of a raw $wp_version read.
 *
 * Scope: wp-slimstat.php, admin/, src/ — excludes src/Dependencies/.
 * Allow-marker: /​* wp-version-read: ok *​/ on the comment line above a site.
 */

declare(strict_types=1);

$plugin_root  = dirname(__DIR__);
$deps_prefix  = $plugin_root . '/src/Dependencies';
$allow_marker = 'wp-version-read: ok';

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

// $GLOBALS['wp_version'] or a bare $wp_version read (not a method/property access).
$pattern = '/\$GLOBALS\s*\[\s*[\'"]wp_version[\'"]\s*\]|(?<![\w>])\$wp_version\b/';

$violations = [];
foreach ($files as $file) {
    $contents = file_get_contents($file);
    if (false === $contents) continue;
    if (!preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) continue;
    foreach ($matches[0] as [$match, $offset]) {
        $line_no = substr_count($contents, "\n", 0, $offset) + 1;
        $prev    = $line_no > 1 ? explode("\n", substr($contents, 0, $offset))[$line_no - 2] : '';
        if (false !== strpos($prev, $allow_marker)) continue;
        $rel = substr($file, strlen($plugin_root) + 1);
        $violations[] = sprintf('%s:%d  → %s', $rel, $line_no, trim($match));
    }
}

if ($violations) {
    fwrite(STDERR, "FAIL: raw \$wp_version read (use is_wp_version_compatible() / wp_get_wp_version()):\n");
    foreach ($violations as $v) fwrite(STDERR, "  - {$v}\n");
    exit(1);
}

echo "OK: scanned " . count($files) . " own-code files, no raw \$wp_version reads found\n";
