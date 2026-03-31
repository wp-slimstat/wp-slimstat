<?php
/**
 * Regression test: Browscap::update_browscap_database() must call
 * WP_Filesystem() before unzip_file().
 *
 * Bug (#14843): unzip_file() requires the $wp_filesystem global to be
 * initialized via WP_Filesystem(). Without it, unzip_file() returns
 * WP_Error('fs_unavailable', 'Could not access filesystem.') and the
 * Browscap toggle silently reverts to OFF.
 *
 * Fix (src/Services/Browscap.php ~line 201): call WP_Filesystem() with
 * the upload directory as context before calling unzip_file(). Check the
 * return value and bail with error code 10 if initialization fails.
 *
 * This test verifies the fix via source-code assertions (no WP bootstrap).
 *
 * @see wp-slimstat/src/Services/Browscap.php (~line 201)
 * @see wp-admin/includes/file.php:1595 ("Assumes WP_Filesystem() has already been called")
 */

declare(strict_types=1);

$assertions = 0;

function fs_assert(bool $condition, string $msg): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

$source = file_get_contents(dirname(__DIR__) . '/src/Services/Browscap.php');
if ($source === false) {
    fwrite(STDERR, "FAIL: could not read src/Services/Browscap.php\n");
    exit(1);
}

// ═══════════════════════════════════════════════════════════════════════════
// TEST 1: WP_Filesystem() is called before the actual unzip_file() call
//
// Note: function_exists('unzip_file') also contains the string 'unzip_file('
// so we match the actual call pattern: $result = unzip_file(
// ═══════════════════════════════════════════════════════════════════════════
$pos_wp_filesystem = strpos($source, 'WP_Filesystem(');
// Match the actual unzip_file() call, not the function_exists check
preg_match('/\$result\s*=\s*unzip_file\s*\(/', $source, $m, PREG_OFFSET_CAPTURE);
$pos_unzip_call = $m[0][1] ?? false;

fs_assert(
    $pos_wp_filesystem !== false,
    'TEST 1a: WP_Filesystem() call must exist in Browscap.php'
);

fs_assert(
    $pos_unzip_call !== false,
    'TEST 1b: unzip_file() call (as $result = unzip_file(...)) must exist in Browscap.php'
);

fs_assert(
    $pos_wp_filesystem < $pos_unzip_call,
    'TEST 1c: WP_Filesystem() must appear BEFORE the actual unzip_file() call in source'
);

// ═══════════════════════════════════════════════════════════════════════════
// TEST 2: require_once for file.php appears before WP_Filesystem()
// ═══════════════════════════════════════════════════════════════════════════
$pos_require = strpos($source, "require_once ABSPATH . 'wp-admin/includes/file.php'");

fs_assert(
    $pos_require !== false,
    'TEST 2a: require_once for wp-admin/includes/file.php must exist'
);

fs_assert(
    $pos_require < $pos_wp_filesystem,
    'TEST 2b: require_once must appear BEFORE WP_Filesystem() call'
);

// ═══════════════════════════════════════════════════════════════════════════
// TEST 3: WP_Filesystem() return value is checked (negation pattern)
// ═══════════════════════════════════════════════════════════════════════════
fs_assert(
    (bool) preg_match('/if\s*\(\s*!\s*WP_Filesystem\s*\(/', $source),
    'TEST 3: WP_Filesystem() return value must be checked with negation (if (!WP_Filesystem()'
);

// ═══════════════════════════════════════════════════════════════════════════
// TEST 4: WP_Filesystem failure returns non-zero error code
// ═══════════════════════════════════════════════════════════════════════════
fs_assert(
    (bool) preg_match('/!\s*WP_Filesystem\s*\([^)]*\)\s*\)\s*\{[^}]*return\s*\[\s*10\s*,/', $source),
    'TEST 4: WP_Filesystem failure must return error code 10 (non-zero = toggle stays off)'
);

// ═══════════════════════════════════════════════════════════════════════════
// TEST 5: browscap_zip is cleaned up on WP_Filesystem failure
// ═══════════════════════════════════════════════════════════════════════════
// Extract the block between !WP_Filesystem and the closing brace
preg_match('/!\s*WP_Filesystem\s*\([^)]*\)\s*\)\s*\{(.*?)\}/s', $source, $matches);

fs_assert(
    !empty($matches[1]) && strpos($matches[1], '@unlink($browscap_zip)') !== false,
    'TEST 5: @unlink($browscap_zip) must be called inside WP_Filesystem failure block'
);

// ═══════════════════════════════════════════════════════════════════════════
// TEST 6: upload_dir is passed as context to WP_Filesystem()
// ═══════════════════════════════════════════════════════════════════════════
fs_assert(
    (bool) preg_match('/WP_Filesystem\s*\(\s*false\s*,\s*wp_slimstat::\$upload_dir\s*\)/', $source),
    'TEST 6: WP_Filesystem() must receive wp_slimstat::$upload_dir as context parameter'
);

// ═══════════════════════════════════════════════════════════════════════════
// TEST 7: function_exists guard protects the require_once
// ═══════════════════════════════════════════════════════════════════════════
fs_assert(
    (bool) preg_match("/if\s*\(\s*!\s*function_exists\s*\(\s*'unzip_file'\s*\)\s*\)/", $source),
    'TEST 7: require_once must be guarded by function_exists(\'unzip_file\') check'
);

echo "All {$assertions} assertions passed in browscap-wp-filesystem-test.php\n";
