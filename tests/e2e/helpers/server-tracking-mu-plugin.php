<?php
/**
 * MU-Plugin: E2E Server-Side Tracking Test Endpoint
 *
 * Exposes an AJAX action that calls wp_slimstat::slimtrack_server()
 * so Playwright can trigger and verify programmatic tracking.
 *
 * Also exposes an action that calls the regular slimtrack() wrapper
 * for comparison testing.
 *
 * Deployed and removed automatically by the test runner.
 *
 * @since 5.4.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Guard: only load if E2E testing constant is set
if ( ! defined( 'SLIMSTAT_E2E_TESTING' ) || ! SLIMSTAT_E2E_TESTING ) {
	return;
}

/**
 * AJAX handler: trigger slimtrack_server() programmatically.
 * Accepts optional 'marker' param to tag the pageview for DB correlation.
 */
add_action( 'wp_ajax_e2e_slimtrack_server', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	$marker = isset( $_POST['marker'] ) ? sanitize_text_field( wp_unslash( $_POST['marker'] ) ) : '';

	// Inject marker into the resource so we can find it in the DB
	if ( $marker ) {
		$_SERVER['REQUEST_URI'] = '/e2e-server-tracking-test/?marker=' . $marker;
	}

	try {
		$result = \wp_slimstat::slimtrack_server();
	} catch ( \Throwable $e ) {
		// Return error details but with success=true so the test can inspect
		wp_send_json_success( [
			'result'           => false,
			'error'            => $e->getMessage(),
			'was_programmatic' => \wp_slimstat::$is_programmatic_tracking,
			'marker'           => $marker,
		] );
		return;
	}

	wp_send_json_success( [
		'result'           => $result,
		'was_programmatic' => \wp_slimstat::$is_programmatic_tracking,
		'marker'           => $marker,
	] );
} );

/**
 * AJAX handler: trigger regular slimtrack() (non-programmatic).
 * For comparison with slimtrack_server().
 */
add_action( 'wp_ajax_e2e_slimtrack_regular', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	$marker = isset( $_POST['marker'] ) ? sanitize_text_field( wp_unslash( $_POST['marker'] ) ) : '';

	if ( $marker ) {
		$_SERVER['REQUEST_URI'] = '/e2e-regular-tracking-test/?marker=' . $marker;
	}

	try {
		$result = \wp_slimstat::slimtrack();
	} catch ( \Throwable $e ) {
		wp_send_json_success( [
			'result'           => false,
			'error'            => $e->getMessage(),
			'was_programmatic' => \wp_slimstat::$is_programmatic_tracking,
			'marker'           => $marker,
		] );
		return;
	}

	wp_send_json_success( [
		'result'           => $result,
		'was_programmatic' => \wp_slimstat::$is_programmatic_tracking,
		'marker'           => $marker,
	] );
} );

/**
 * AJAX handler: check the current state of $is_programmatic_tracking flag.
 * Used to verify re-entrant call state restoration.
 */
add_action( 'wp_ajax_e2e_check_programmatic_flag', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized', 403 );
	}

	wp_send_json_success( [
		'is_programmatic_tracking' => \wp_slimstat::$is_programmatic_tracking,
	] );
} );
