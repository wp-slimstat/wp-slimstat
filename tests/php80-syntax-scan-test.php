<?php
/**
 * Source-level: own code uses no PHP 8.0+ language syntax and no 8.0-removed
 * functions, so it parses and runs on the declared 7.4 floor.
 *
 * BASELINE (zero findings expected). Complements php74-no-php80-functions-test.php
 * (which scans 8.0+ *stdlib calls*); this one scans 8.0+ *language constructs*
 * and 8.0-*removed* functions.
 *
 * Scope: wp-slimstat.php, admin/, src/ — excludes src/Dependencies/.
 * Allow-marker: /​* php80-syntax: ok *​/ on the comment line above a site.
 */

declare(strict_types=1);

$plugin_root  = dirname(__DIR__);
$deps_prefix  = $plugin_root . '/src/Dependencies';
$allow_marker = 'php80-syntax: ok';
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
    'match expression (8.0)'        => '/(?<![\w>$])match\s*\(/',
    'enum declaration (8.1)'        => '/^\s*enum\s+[A-Z]\w*/m',
    'readonly property (8.1)'       => '/\breadonly\s+(?:public|protected|private|static|\$)|(?:public|protected|private)\s+readonly\b/',
    'nullsafe operator ?-> (8.0)'   => '/\?->/',
    'each() removed in 8.0'         => '/(?<![\w>$])each\s*\(/',
    'create_function() removed 8.0' => '/\bcreate_function\s*\(/',
    'libxml_disable_entity_loader removed 8.0' => '/\blibxml_disable_entity_loader\s*\(/',
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
    fwrite(STDERR, "FAIL: PHP 8.0+ syntax / 8.0-removed function in own code (breaks the 7.4 floor):\n");
    foreach ($violations as $v) fwrite(STDERR, "  - {$v}\n");
    exit(1);
}
echo "OK: scanned " . count($files) . " own-code files, no PHP 8.0+ syntax / removed functions\n";
