<?php
/** Isolated QA only. Never shipped in the plugin. @license GPL-2.0-or-later */
if ( ! defined( 'ABSPATH' ) || ! defined( 'DB_NAME' ) || 0 !== strpos( DB_NAME, 'test_form_analytics_' ) ) { return; }
// Tests never send mail. Form-plugin outcomes remain real, including their validations.
add_filter( 'pre_wp_mail', static function () { return 'failed' !== get_option( 'slimstat_forms_qa_outcome', '' ); } );
add_filter( 'wpcf7_spam', static function ( $spam ) { return $spam || 'spam' === get_option( 'slimstat_forms_qa_outcome', '' ); } );
// Real builder submissions remain local; deny webhooks and telemetry in QA.
add_filter( 'pre_http_request', static function ( $pre ) { return defined( 'WP_CLI' ) && WP_CLI ? $pre : new WP_Error( 'forms_qa_offline', 'External requests are disabled in QA.' ); } );
$GLOBALS['slimstat_forms_qa_queries'] = 0;
add_filter( 'query', static function ( $sql ) {
	if ( preg_match( '/slim_form_|slimstat_forms_/', $sql ) ) { ++$GLOBALS['slimstat_forms_qa_queries']; }
	return $sql;
} );
add_action( 'send_headers', static function () { ob_start(); } );
add_action( 'shutdown', static function () {
	if ( ! headers_sent() ) { header( 'X-Forms-QA-Queries: ' . $GLOBALS['slimstat_forms_qa_queries'] ); }
}, 0 );
