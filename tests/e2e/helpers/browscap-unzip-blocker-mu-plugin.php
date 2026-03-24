<?php
/**
 * E2E Test MU-Plugin: Simulate unzip_file() failure for Browscap tests.
 *
 * When the sentinel file wp-content/e2e-block-browscap-unzip.json exists,
 * this hooks into the 'unzip_file_pre' filter to force a WP_Error return
 * whenever the target path contains 'browscap-db.zip'.
 *
 * Sentinel JSON format: { "mode": "unzip_fail" | "corrupt_zip" }
 *   - unzip_fail:  Forces unzip_file() to return WP_Error (simulates missing ZipArchive)
 *   - corrupt_zip: Replaces the downloaded zip content with HTML before extraction
 */

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
        // Overwrite the zip with HTML to simulate a corrupt/non-zip download
        file_put_contents( $file, '<html><body>GitHub rate limit exceeded</body></html>' );
        // Return null to let WP try (and fail) to unzip the corrupt file
        return null;
    }

    // Default: simulate missing ZipArchive / extraction failure
    return new \WP_Error(
        'e2e_simulated_unzip_failure',
        'Simulated unzip failure: ZipArchive extension not available (E2E test)'
    );
}, 10, 5 );
