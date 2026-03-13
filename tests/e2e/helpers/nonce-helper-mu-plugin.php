<?php
/**
 * MU-plugin: Nonce creation helper for E2E tests.
 *
 * Provides a WP AJAX endpoint to create nonces for testing AJAX endpoints.
 *
 * POST /wp-admin/admin-ajax.php?action=test_create_nonce
 * Body: nonce_action=<action_name>
 */
add_action('wp_ajax_test_create_nonce', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('forbidden');
    }

    $nonce_action = sanitize_text_field($_POST['nonce_action'] ?? '');
    if (empty($nonce_action)) {
        wp_send_json_error('missing nonce_action');
    }

    $nonce = wp_create_nonce($nonce_action);
    wp_send_json_success(['nonce' => $nonce]);
});
