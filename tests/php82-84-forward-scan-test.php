<?php
/**
 * Source-level: own code is free of the cleanly-detectable PHP 8.2/8.3/8.4
 * deprecation/removal surface.
 *
 * BASELINE (zero findings expected). Covers the regexable forward-compat
 * constructs; dynamic-property writes (8.2) and partial-callable strings are
 * left to PHPStan (Phase 4B), which detects them far more reliably than a regex.
 *
 * Scope: wp-slimstat.php, admin/, src/ — excludes src/Dependencies/.
 * Allow-marker: /​* php-forward: ok *​/ on the comment line above a site.
 */

declare(strict_types=1);

$plugin_root  = dirname(__DIR__);
$deps_prefix  = $plugin_root . '/src/Dependencies';
$allow_marker = 'php-forward: ok';
$paths        = [$plugin_root . '/wp-slimstat.php', $plugin_root . '/admin', $plugin_root . '/src'];

$files = [];
foreach ($paths as $path) {
    if (is_file($path)) { if ('.php' === substr($path, -4)) $files[] = $path; continue; }
    if (!is_dir($path)) continue;
    $dir = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
    $flt = new RecursiveCallbackFilterIterator($dir, function ($f) use ($deps_prefix) {
        return 0 !== strpos($f->getPathname(), $deps_prefix . DIRECTORY_SEPARATOR);
    });
    foreach (new RecursiveIteratorIterator($flt) as $f) {
        if ('.php' === substr($f->getPathname(), -4)) $files[] = $f->getPathname();
    }
}
sort($files);
if (0 === count($files)) { fwrite(STDERR, "FAIL: scanner found zero own-code .php files\n"); exit(1); }

$patterns = [
    'utf8_encode/decode deprecated (8.2)' => '/\butf8_(?:en|de)code\s*\(/',
    '${...} string interpolation deprecated (8.2)' => '/\$\{[a-zA-Z_]/',
    'E_STRICT constant deprecated (8.4)'  => '/\bE_STRICT\b/',
    'json_validate() — 8.3 forward syntax' => '/\bjson_validate\s*\(/',
    '#[\\Override] — 8.3 forward syntax'  => '/#\[\s*\\\\?Override\s*\]/',
];

$violations = [];
foreach ($files as $file) {
    $contents = file_get_contents($file);
    if (false === $contents) continue;
    foreach ($patterns as $label => $pattern) {
        if (!preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) continue;
        foreach ($matches[0] as [$match, $offset]) {
            $line_no = substr_count($contents, "\n", 0, $offset) + 1;
            $prev    = $line_no > 1 ? explode("\n", substr($contents, 0, $offset))[$line_no - 2] : '';
            if (false !== strpos($prev, $allow_marker)) continue;
            $rel = substr($file, strlen($plugin_root) + 1);
            $violations[] = sprintf('%s:%d  [%s]  → %s', $rel, $line_no, $label, trim($match));
        }
    }
}

if ($violations) {
    fwrite(STDERR, "FAIL: PHP 8.2-8.4 deprecation/forward-syntax surface in own code:\n");
    foreach ($violations as $v) fwrite(STDERR, "  - {$v}\n");
    exit(1);
}
echo "OK: scanned " . count($files) . " own-code files, no 8.2-8.4 deprecation/forward surface\n";
