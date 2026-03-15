<?php
/**
 * E2E Test MU-Plugin: Consent Simulator
 * Reads e2e-consent-state.json and simulates wp_has_consent() responses.
 * Guarded by SLIMSTAT_E2E_TESTING constant.
 */
if (!defined('SLIMSTAT_E2E_TESTING') || !SLIMSTAT_E2E_TESTING) return;

add_action('init', function() {
    $file = WP_CONTENT_DIR . '/e2e-consent-state.json';
    if (!file_exists($file)) return;
    $state = json_decode(file_get_contents($file), true);
    if (!is_array($state)) return;

    // Override wp_has_consent if WP Consent API is active
    if (function_exists('wp_has_consent')) {
        add_filter('wp_has_consent', function($has_consent, $type) use ($state) {
            if (isset($state[$type])) return (bool) $state[$type];
            return $has_consent;
        }, 999, 2);
    }
}, 1);

// AJAX endpoint to set consent state
add_action('wp_ajax_e2e_set_consent_state', function() {
    if (!defined('SLIMSTAT_E2E_TESTING') || !SLIMSTAT_E2E_TESTING) wp_die('Forbidden', 403);
    $state = json_decode(file_get_contents('php://input'), true);
    if (!$state) $state = $_POST;
    file_put_contents(WP_CONTENT_DIR . '/e2e-consent-state.json', json_encode($state));
    wp_send_json_success();
});
