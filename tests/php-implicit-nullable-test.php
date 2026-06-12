<?php
/**
 * Source-level regression: implicit-nullable parameters in own code.
 *
 * `function f(Type $x = null)` emits E_DEPRECATED on PHP 8.1+ and will become
 * a fatal in PHP 9.0. The fix is `?Type $x = null`. This test scans own code
 * and fails on any new implicit-nullable signature.
 *
 * Allow-marker: /​* php-implicit-nullable: ok *​/ on the comment line above
 * the function declaration (for the few sites that cannot be tightened without
 * dropping PHP 7.4 — `mixed` was added in 8.0).
 */

declare(strict_types=1);

$plugin_root = dirname(__DIR__);
$allow_marker = 'php-implicit-nullable: ok';
$deps_prefix  = $plugin_root . '/src/Dependencies';

$paths = [
    $plugin_root . '/admin',
    $plugin_root . '/src',
];

$files = [];
foreach ($paths as $path) {
    if (!is_dir($path)) continue;
    $directory = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
    $filtered  = new RecursiveCallbackFilterIterator($directory, function ($file) use ($deps_prefix) {
        return strpos($file->getPathname(), $deps_prefix . DIRECTORY_SEPARATOR) !== 0;
    });
    foreach (new RecursiveIteratorIterator($filtered) as $file) {
        if (substr($file->getPathname(), -4) === '.php') $files[] = $file->getPathname();
    }
}
sort($files);

// Matches `Type $name = null` (the deprecated form) but NOT `?Type $name = null`.
// Type can be a single class/builtin (\Foo\Bar, Exception, string, array, etc).
// Excludes `mixed` since it doesn't accept the implicit-nullable transform.
$pattern = '/(?<![?|&\w\\\\])(?P<type>(?:\\\\?[A-Z]\w*(?:\\\\[A-Z]\w*)*|array|string|int|float|bool|callable|iterable|object))\s+\$\w+\s*=\s*null\b/';

$violations = [];
foreach ($files as $file) {
    $contents = file_get_contents($file);
    if ($contents === false) continue;
    if (!preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) continue;
    foreach ($matches[0] as $i => [$match, $offset]) {
        $line_no = substr_count($contents, "\n", 0, $offset) + 1;
        $line    = strtok(substr($contents, $offset), "\n");
        $prev    = $line_no > 1 ? explode("\n", substr($contents, 0, $offset))[$line_no - 2] : '';
        if (strpos($prev, $allow_marker) !== false) continue;
        $stripped = ltrim($line);
        if (strpos($stripped, '//') === 0 || strpos($stripped, '*') === 0) continue;
        $rel = substr($file, strlen($plugin_root) + 1);
        $violations[] = sprintf('%s:%d  → %s  in: %s', $rel, $line_no, $match, trim($line));
    }
}

if ($violations) {
    fwrite(STDERR, "FAIL: implicit-nullable params in own code (E_DEPRECATED on PHP 8.1+):\n");
    foreach ($violations as $v) fwrite(STDERR, "  - {$v}\n");
    fwrite(STDERR, "\nFix: change `Type \$x = null` to `?Type \$x = null`.\n");
    fwrite(STDERR, "Or mark allowed (e.g. mixed-style \$default) with /* php-implicit-nullable: ok */ above the line.\n");
    exit(1);
}

echo "OK: scanned " . count($files) . " own-code files, no implicit-nullable params found\n";
