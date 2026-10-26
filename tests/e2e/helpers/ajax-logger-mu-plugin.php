<?php
/**
 * MU-Plugin: GeoIP AJAX call logger for QA testing.
 * Installed temporarily by Playwright tests to count AJAX handler invocations.
 *
 * Logs to wp-content/geoip-ajax-calls.log with JSON entries.
 */
/**
 * Guarded by SLIMSTAT_E2E_TESTING, like every other helper here. Ungated, this records admin-ajax traffic to a file under wp-content.
 * The file reaching wp-content/mu-plugins is not consent to run it: a helper
 * gets there by an interrupted test run as easily as a deliberate one.
 */
if (!defined('SLIMSTAT_E2E_TESTING') || !SLIMSTAT_E2E_TESTING) {
    return;
}

add_action('wp_ajax_slimstat_update_geoip_database', function () {
    $entry = json_encode([
        'time'    => microtime(true),
        'user'    => get_current_user_id(),
        'referer' => isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '',
        'ip'      => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
    ]) . "\n";
    file_put_contents(WP_CONTENT_DIR . '/geoip-ajax-calls.log', $entry, FILE_APPEND | LOCK_EX);
}, 1); // priority 1: runs before SlimStat's handler
