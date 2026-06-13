<?php
/**
 * Source-level: own code calls no WordPress core function that has been
 * removed or long-deprecated through the supported WP floor→current
 * (5.6 → 7.0). Calling a removed function fatals; a deprecated one emits a
 * _deprecated_function notice.
 *
 * BASELINE (zero findings expected). Covers the WP-version axis of the audit.
 * Scope: wp-slimstat.php, admin/, src/ — excludes src/Dependencies/.
 * Allow-marker: /​* wp-core-fn: ok *​/ on the comment line above a site.
 */

declare(strict_types=1);

$plugin_root  = dirname(__DIR__);
$deps_prefix  = $plugin_root . '/src/Dependencies';
$allow_marker = 'wp-core-fn: ok';
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

// WP core functions removed or long-deprecated (pre-5.6 era). Calling any of
// these signals a compatibility break against the 5.6→7.0 support window.
$removed_fns = [
    'screen_icon', 'get_currentuserinfo', 'wp_get_sites', 'attribute_escape',
    'js_escape', 'get_settings', 'wp_load_image', 'wp_get_http',
    'get_the_author_email', 'clean_url', 'wp_specialchars', 'funky_javascript_fix',
    'get_alloptions',
    // NB: sanitize_url() is intentionally NOT listed — deprecated in 2.8 but
    // RE-INTRODUCED in WP 6.1 as a valid esc_url_raw() alias; own code uses it.
];

$violations = [];
foreach ($files as $file) {
    $contents = file_get_contents($file);
    if (false === $contents) continue;
    foreach ($removed_fns as $fn) {
        $pattern = '/(?<![\w>$])' . preg_quote($fn, '/') . '\s*\(/';
        if (!preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) continue;
        foreach ($matches[0] as [$match, $offset]) {
            $line_no = substr_count($contents, "\n", 0, $offset) + 1;
            $prev    = $line_no > 1 ? explode("\n", substr($contents, 0, $offset))[$line_no - 2] : '';
            if (false !== strpos($prev, $allow_marker)) continue;
            $rel = substr($file, strlen($plugin_root) + 1);
            $violations[] = sprintf('%s:%d  → %s()', $rel, $line_no, $fn);
        }
    }
}

if ($violations) {
    fwrite(STDERR, "FAIL: removed/long-deprecated WP core function in own code:\n");
    foreach ($violations as $v) fwrite(STDERR, "  - {$v}\n");
    exit(1);
}
echo "OK: scanned " . count($files) . " own-code files, no removed/deprecated WP core functions\n";
