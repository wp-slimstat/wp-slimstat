<?php
/** Read-only reconciliation of real browser scenarios in the isolated QA database. @license GPL-2.0-or-later */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! preg_match( '/^test_form_analytics_/', DB_NAME ) ) {
	throw new RuntimeException( 'Use a disposable Form Analytics site.' );
}
$key = $args[0] ?? '';
global $wpdb;
$id = (int) ( $args[1] ?? 0 );
if ( ! preg_match( '/^[a-z][a-z0-9]*:[1-9][0-9]*$/', $key ) ) { throw new RuntimeException( 'Invalid fixture form.' ); }
$sessions = $wpdb->prefix . 'slim_form_sessions';
$daily = $wpdb->prefix . 'slim_form_daily';
$result = array(
	'cohort' => $wpdb->get_row( $wpdb->prepare( "SELECT form, cohort, stat_id, flags, duration, fields FROM {$sessions} WHERE form = %s AND stat_id = %d", $key, $id ), ARRAY_A ),
	'totals' => $wpdb->get_row( $wpdb->prepare( "SELECT SUM(seen) seen, SUM(started) started, SUM(submitted) submitted, SUM(invalid) invalid, SUM(spam) spam, SUM(failed) failed FROM {$daily} WHERE form = %s", $key ), ARRAY_A ),
);
echo 'FORMS_QA_JSON:' . wp_json_encode( $result ) . "\n";
