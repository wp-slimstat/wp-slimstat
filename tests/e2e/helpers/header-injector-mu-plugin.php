<?php
/**
 * E2E Test MU-Plugin: Header Injector
 * Reads e2e-header-overrides.json from wp-content and sets $_SERVER vars.
 * Guarded by SLIMSTAT_E2E_TESTING constant.
 */
if (!defined('SLIMSTAT_E2E_TESTING') || !SLIMSTAT_E2E_TESTING) return;

add_action('muplugins_loaded', function() {
    $file = WP_CONTENT_DIR . '/e2e-header-overrides.json';
    if (!file_exists($file)) return;
    $overrides = json_decode(file_get_contents($file), true);
    if (!is_array($overrides)) return;
    foreach ($overrides as $key => $value) {
        $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $value;
    }
}, 1);
