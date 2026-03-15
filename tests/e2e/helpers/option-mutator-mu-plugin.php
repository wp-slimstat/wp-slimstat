<?php
/**
 * MU-plugin: Safe slimstat_options mutator for E2E tests.
 *
 * Provides a WP AJAX endpoint that uses WordPress's native get_option/update_option
 * to safely manipulate PHP serialized data. Eliminates fragile regex-based manipulation.
 *
 * POST /wp-admin/admin-ajax.php?action=test_set_slimstat_option
 * Body: key=<key>&value=<value>       — set a key
 * Body: key=<key>&delete=1            — delete a key
 */
add_action('wp_ajax_test_set_slimstat_option', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('forbidden');
    }

    $key    = sanitize_text_field($_POST['key'] ?? '');
    $value  = sanitize_text_field($_POST['value'] ?? '');
    $delete = !empty($_POST['delete']);

    if (empty($key)) {
        wp_send_json_error('missing key');
    }

    $opts = get_option('slimstat_options', []);

    if ($delete) {
        unset($opts[$key]);
    } else {
        $opts[$key] = $value;
    }

    update_option('slimstat_options', $opts);
    wp_send_json_success(['key' => $key, 'value' => $value, 'delete' => $delete]);
});
