<?php
/**
 * E2E Test MU-Plugin: Early Textdomain Logger
 * Logs when wp-slimstat textdomain is loaded and what hook it fires on.
 * Guarded by SLIMSTAT_E2E_TESTING constant.
 */
if (!defined('SLIMSTAT_E2E_TESTING') || !SLIMSTAT_E2E_TESTING) return;

$GLOBALS['e2e_textdomain_log'] = [];

add_action('load_textdomain', function($domain, $mofile) {
    if ($domain === 'wp-slimstat' || $domain === 'wp-slimstat-pro') {
        $GLOBALS['e2e_textdomain_log'][] = [
            'domain' => $domain,
            'hook' => current_filter(),
            'current_action' => current_action(),
            'init_started' => did_action('init') > 0,
            'time' => microtime(true),
            'mofile' => $mofile,
        ];
    }
}, 1, 2);

// AJAX endpoint to read the log
add_action('wp_ajax_e2e_get_textdomain_log', function() {
    wp_send_json_success($GLOBALS['e2e_textdomain_log'] ?? []);
});

// Also expose via REST for frontend tests
add_action('rest_api_init', function() {
    register_rest_route('e2e/v1', '/textdomain-log', [
        'methods' => 'GET',
        'callback' => function() {
            return new WP_REST_Response($GLOBALS['e2e_textdomain_log'] ?? [], 200);
        },
        'permission_callback' => '__return_true',
    ]);
});
