<?php
/**
 * E2E Test MU-Plugin: Plugin lifecycle endpoints
 *
 * Provides AJAX actions to deactivate and reactivate wp-slimstat
 * for testing the plugin activation/deactivation cycle.
 *
 * Guarded by SLIMSTAT_E2E_TESTING constant.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SLIMSTAT_E2E_TESTING' ) || ! SLIMSTAT_E2E_TESTING ) {
	return;
}

add_action( 'wp_ajax_e2e_deactivate_plugin', function () {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		wp_send_json_error( 'Insufficient permissions', 403 );
	}

	deactivate_plugins( 'wp-slimstat/wp-slimstat.php' );

	wp_send_json_success( [
		'deactivated' => true,
		'is_active'   => is_plugin_active( 'wp-slimstat/wp-slimstat.php' ),
	] );
} );

add_action( 'wp_ajax_e2e_activate_plugin', function () {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		wp_send_json_error( 'Insufficient permissions', 403 );
	}

	$result = activate_plugin( 'wp-slimstat/wp-slimstat.php' );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}

	wp_send_json_success( [
		'activated' => true,
		'is_active' => is_plugin_active( 'wp-slimstat/wp-slimstat.php' ),
	] );
} );
