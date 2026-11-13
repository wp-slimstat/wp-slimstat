<?php
/**
 * E2E Test MU-Plugin: Simulate unzip_file() failure for Browscap tests.
 *
 * When the sentinel file wp-content/e2e-block-browscap-unzip.json exists,
 * this hooks into the 'pre_unzip_file' filter to force a WP_Error return
 * whenever the target path contains 'browscap-db.zip'.
 *
 * Sentinel JSON format: { "mode": "unzip_fail" | "corrupt_zip" | "fs_method_block" }
 *   - unzip_fail:      Forces unzip_file() to return WP_Error (simulates missing ZipArchive)
 *   - corrupt_zip:     Returns an HTML download before WordPress opens the archive
 *   - fs_method_block: Returns '' from filesystem_method filter, causing WP_Filesystem() to
 *                      return false. Tests the error code 10 path added to fix #14843.
 */

/**
 * Guarded by SLIMSTAT_E2E_TESTING, like every other helper here. Ungated, this breaks the browscap download path.
 * The file reaching wp-content/mu-plugins is not consent to run it: a helper
 * gets there by an interrupted test run as easily as a deliberate one.
 */
if (!defined('SLIMSTAT_E2E_TESTING') || !SLIMSTAT_E2E_TESTING) {
    return;
}

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_filter( 'pre_unzip_file', function ( $result, $file, $to, $needed_dirs = [], $required_space = 0 ) {
    $sentinel = WP_CONTENT_DIR . '/e2e-block-browscap-unzip.json';

    if ( ! file_exists( $sentinel ) ) {
        return $result;
    }

    // Only intercept browscap zip extractions
    if ( false === strpos( $file, 'browscap-db.zip' ) ) {
        return $result;
    }

    $config = json_decode( file_get_contents( $sentinel ), true );
    $mode   = $config['mode'] ?? 'unzip_fail';

    if ( 'corrupt_zip' === $mode ) {
        return $result; // Corruption is injected at the HTTP boundary below.
    }

    // Default: simulate missing ZipArchive / extraction failure
    return new \WP_Error(
        'e2e_simulated_unzip_failure',
        'Simulated unzip failure: ZipArchive extension not available (E2E test)'
    );
}, 10, 5 );

// pre_unzip_file runs after ZipArchive opens the file; corrupt the HTTP stream instead.
add_filter( 'pre_http_request', function ( $result, $args, $url ) {
    $sentinel = WP_CONTENT_DIR . '/e2e-block-browscap-unzip.json';
    if ( 'https://github.com/slimstat/browscap-cache/archive/master.zip' !== $url || ! file_exists( $sentinel ) ) {
        return $result;
    }
    $config = json_decode( file_get_contents( $sentinel ), true );
    if ( 'corrupt_zip' !== ( $config['mode'] ?? '' ) || empty( $args['filename'] ) ) {
        return $result;
    }
    if ( false === file_put_contents( $args['filename'], '<html><body>GitHub rate limit exceeded</body></html>' ) ) {
        return new \WP_Error( 'e2e_corrupt_download_write_failed', 'Could not create corrupt download fixture.' );
    }
    return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => '', 'filename' => $args['filename'] ];
}, 10, 3 );

/**
 * fs_method_block mode: hook the 'filesystem_method' filter to return an empty
 * string, which causes WP_Filesystem() to return false (get_filesystem_method
 * returns falsy → WP_Filesystem bails at file.php:2182). This tests the new
 * error code 10 path in Browscap::update_browscap_database().
 *
 * Uses the same sentinel file as the pre_unzip_file hook above.
 */
add_filter( 'filesystem_method', function ( $method ) {
    $sentinel = WP_CONTENT_DIR . '/e2e-block-browscap-unzip.json';

    if ( ! file_exists( $sentinel ) ) {
        return $method;
    }

    $config = json_decode( file_get_contents( $sentinel ), true );

    if ( 'fs_method_block' === ( $config['mode'] ?? '' ) ) {
        return ''; // Forces WP_Filesystem() to return false
    }

    return $method;
} );
