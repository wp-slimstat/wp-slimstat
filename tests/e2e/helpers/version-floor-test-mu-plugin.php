<?php
/**
 * E2E Test MU-Plugin: Version Floor Test Helper
 * Exposes SLIMSTAT_ANALYTICS_VERSION via AJAX for version compatibility checks.
 * Guarded by SLIMSTAT_E2E_TESTING constant.
 */
if (!defined('SLIMSTAT_E2E_TESTING') || !SLIMSTAT_E2E_TESTING) return;

add_action('wp_ajax_e2e_get_slimstat_version', function() {
    $pro_active = class_exists('WpSlimstatProPlugin')
        || class_exists('SlimStatPro\\Plugin')
        || is_plugin_active('wp-slimstat-pro/wp-slimstat-pro.php');
    $pro_version = null;
    if ($pro_active && function_exists('get_plugin_data')) {
        $pro_file = WP_PLUGIN_DIR . '/wp-slimstat-pro/wp-slimstat-pro.php';
        if (file_exists($pro_file)) {
            $data = get_plugin_data($pro_file, false, false);
            $pro_version = $data['Version'] ?? null;
        }
    }
    wp_send_json_success([
        'version' => defined('SLIMSTAT_ANALYTICS_VERSION') ? SLIMSTAT_ANALYTICS_VERSION : null,
        'pro_version' => $pro_version,
        'pro_active' => $pro_active,
    ]);
});
