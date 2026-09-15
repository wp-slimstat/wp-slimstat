<?php
/**
 * E2E Mail Sink MU-Plugin
 *
 * Intercepts all wp_mail() calls and writes them to a JSON capture file.
 * Returns true so WordPress thinks the mail was sent (no SMTP needed).
 * Used by email-reports.spec.ts to verify Pro email report sending.
 */

/**
 * Guarded by SLIMSTAT_E2E_TESTING, like every other helper here. Ungated, this swallows EVERY wp_mail() on the site and returns success, so
 * nothing is sent and nothing reports a failure.
 * The file reaching wp-content/mu-plugins is not consent to run it: a helper
 * gets there by an interrupted test run as easily as a deliberate one.
 */
if (!defined('SLIMSTAT_E2E_TESTING') || !SLIMSTAT_E2E_TESTING) {
    return;
}

// Only active during E2E tests (presence of this file signals test mode)
add_filter( 'pre_wp_mail', function ( $null, $atts ) {
    $capture_file = WP_CONTENT_DIR . '/e2e-captured-mail.json';

    $entry = [
        'time'    => time(),
        'to'      => $atts['to'] ?? '',
        'subject' => $atts['subject'] ?? '',
        'message' => $atts['message'] ?? '',
        'headers' => $atts['headers'] ?? [],
    ];

    $existing = [];
    if ( file_exists( $capture_file ) ) {
        $raw = file_get_contents( $capture_file );
        if ( $raw ) {
            $existing = json_decode( $raw, true ) ?: [];
        }
    }

    $existing[] = $entry;
    file_put_contents( $capture_file, wp_json_encode( $existing ), LOCK_EX );

    // Return true to short-circuit wp_mail() — no actual sending
    return true;
}, 10, 2 );
