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
    // `pro_active` above cannot tell "Pro is running" from "Pro is switched on and dead".
    // WpSlimstatProPlugin is DECLARED by including the main file, and the requirement check that
    // can refuse to boot runs after that declaration, inside a constructor whose exceptions
    // init() swallows -- so all three of its disjuncts stay true on a Pro that loaded no
    // provider, registered no hook, and (on an interactive admin request) has already asked
    // WordPress to deactivate it. Pro 3.0.0 21204b6c was in exactly that state for a whole
    // census, and every Pro spec either skipped on it or failed for the wrong reason.
    //
    // So report the two facts separately. `pro_activated` is WordPress's opinion; `pro_booted`
    // is the plugin's own behaviour, taken from the only signal that requires the service
    // providers to have actually run: UserOverviewAddon registers report id slim_p8_01 on
    // slimstat_reports_info from its constructor (Pro 2.0.0 and 3.0.0 alike), and it is
    // corroborated by free's degradation store, where a failed Pro boot writes a `pro_` key.
    // A spec that finds pro_activated && !pro_booted must FAIL, never skip: that IS the defect.
    // Whether Pro exists on this machine at all. The Free CI lanes are a DECLARED
    // omission (ci.yml: Pro is a private repo, no deploy key), so "not installed" is the
    // ONLY state in which a Pro spec may legally skip. Installed-but-not-booted must fail.
    $pro_installed = file_exists(WP_PLUGIN_DIR . '/wp-slimstat-pro/wp-slimstat-pro.php');
    $pro_activated = function_exists('is_plugin_active')
        && is_plugin_active('wp-slimstat-pro/wp-slimstat-pro.php');
    $option = (class_exists('wp_slimstat') && defined('wp_slimstat::DEGRADATION_OPTION'))
        ? wp_slimstat::DEGRADATION_OPTION
        : 'slimstat_degradations';
    $pro_degradations = [];
    foreach (array_keys((array) get_option($option, [])) as $key) {
        if (strpos((string) $key, 'pro_') === 0) { $pro_degradations[] = $key; }
    }
    $reports = $pro_activated ? (array) apply_filters('slimstat_reports_info', []) : [];

    wp_send_json_success([
        'version' => defined('SLIMSTAT_ANALYTICS_VERSION') ? SLIMSTAT_ANALYTICS_VERSION : null,
        'pro_version' => $pro_version,
        'pro_active' => $pro_active,
        'pro_installed' => $pro_installed,
        'pro_activated' => $pro_activated,
        'pro_booted' => $pro_activated && !$pro_degradations && isset($reports['slim_p8_01']),
        'pro_degradations' => $pro_degradations,
    ]);
});
