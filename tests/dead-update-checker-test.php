<?php
/** The retired update service must not return in shipped PHP outside vendor/. */
declare(strict_types=1);

$root = dirname(__DIR__);
$entries = file($root . '/.distignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (false === $entries) {
    throw new RuntimeException('Cannot read .distignore');
}
$entries[] = '/vendor';
$paths = [];
exec('git -C ' . escapeshellarg($root) . ' ls-files --cached --others --exclude-standard', $paths, $status);
if (0 !== $status || !in_array('wp-slimstat.php', $paths, true)) {
    throw new RuntimeException('Cannot enumerate plugin source');
}
$checked = 0;
$failures = [];
foreach (array_unique($paths) as $path) {
    if (substr($path, -4) !== '.php' || !is_file($root . '/' . $path)) {
        continue;
    }
    // Match the release builder: anchored prefixes or unanchored path segments.
    foreach ($entries as $entry) {
        $entry = trim($entry);
        if ($entry === '' || $entry[0] === '#') {
            continue;
        }
        $bare = ltrim($entry, '/');
        if ($entry[0] === '/' ? ($path === $bare || strpos($path, $bare . '/') === 0) : in_array($entry, explode('/', $path), true)) {
            continue 2;
        }
    }
    $source = file_get_contents($root . '/' . $path);
    if (false === $source || strpos($source, 'wp-slimstat.com/update-checker') !== false) {
        $failures[] = $path;
    }
    ++$checked;
}
if ($checked < 100 || $failures) {
    fwrite(STDERR, "FAIL: retired update-checker URL scan ({$checked} PHP files): " . implode(', ', $failures) . "\n");
    exit(1);
}
echo "PASS: {$checked} shipped PHP files contain no retired update-checker URL\n";
