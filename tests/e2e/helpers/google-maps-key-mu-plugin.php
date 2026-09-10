<?php
/**
 * MU-plugin: supply a Google Maps API key to Pro's MaxMind details view.
 *
 * MaxMindDetailsAddon renders the map only when a site owner has filtered a key in
 * (`slimstat_pro_google_maps_api_key`, Pro 792f59d — a distributed plugin must not
 * ship someone else's key). No key, no embed. Without this helper the map branch is
 * unreachable from the suite, so the only honest assertion left is "no embed" and the
 * branch a paying site actually runs stays untested — which is how the coordinates
 * spec came to assert an embed that the shipped build never emits.
 *
 * The key comes from the SlimStat setting `e2e_google_maps_api_key`, which no shipped
 * code reads and which the option mutator already knows how to set and restore. Absent
 * that setting this filter returns exactly what it was given, so installing the helper
 * globally changes nothing.
 */
if (!defined('SLIMSTAT_E2E_TESTING') || !SLIMSTAT_E2E_TESTING) {
    return;
}

add_filter('slimstat_pro_google_maps_api_key', function ($key) {
    if (!class_exists('wp_slimstat') || !is_array(wp_slimstat::$settings)) {
        return $key;
    }
    $configured = isset(wp_slimstat::$settings['e2e_google_maps_api_key'])
        ? (string)wp_slimstat::$settings['e2e_google_maps_api_key']
        : '';

    return '' !== $configured ? $configured : $key;
});
