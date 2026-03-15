<?php
/**
 * E2E Test MU-Plugin: Rewrite flush endpoint
 *
 * Provides an AJAX action to flush WordPress rewrite rules.
 * Needed for adblock bypass E2E tests where the obfuscated
 * /request/{hash}/ rewrite rule must be registered and flushed.
 *
 * Guarded by SLIMSTAT_E2E_TESTING constant.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'SLIMSTAT_E2E_TESTING' ) || ! SLIMSTAT_E2E_TESTING ) {
    return;
}

add_action( 'wp_ajax_e2e_flush_rewrite_rules', function () {
    flush_rewrite_rules( true );
    wp_send_json_success( [ 'flushed' => true ] );
} );
