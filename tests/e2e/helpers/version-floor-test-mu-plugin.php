<?php
/**
 * E2E Test MU-Plugin: Version Floor Test Helper
 * Exposes SLIMSTAT_ANALYTICS_VERSION via AJAX for version compatibility checks.
 * Guarded by SLIMSTAT_E2E_TESTING constant.
 */
if (!defined('SLIMSTAT_E2E_TESTING') || !SLIMSTAT_E2E_TESTING) return;

add_action('wp_ajax_e2e_get_slimstat_version', function() {
    wp_send_json_success([
        'version' => defined('SLIMSTAT_ANALYTICS_VERSION') ? SLIMSTAT_ANALYTICS_VERSION : null,
        'pro_version' => defined('SLIMSTAT_PRO_VERSION') ? SLIMSTAT_PRO_VERSION : null,
        'pro_active' => class_exists('SlimStatPro\\Plugin'),
    ]);
});
