<?php
/**
 * E2E Test MU-Plugin: SlimStat Pro license fixture.
 *
 * Answers Pro's license-server calls from the slimstat_e2e_license_state option (valid, invalid,
 * domain_not_activated or unreachable), so licensed and locked runs never reach the real server.
 * Inert while the option is unset.
 *
 * POST /wp-admin/admin-ajax.php?action=e2e_license
 *   state=valid|invalid|domain_not_activated|unreachable|none
 *   keep=1     keep the stored verdict (an outage after a confirmed license)
 *   age=<sec>  backdate the stored verdict first, so the refresh is due
 *
 * Guarded by SLIMSTAT_E2E_TESTING, like every other helper here.
 */
if (!defined('SLIMSTAT_E2E_TESTING') || !SLIMSTAT_E2E_TESTING) {
    return;
}

add_filter('pre_http_request', static function ($pre, $args, $url) {
    $state = (string) get_option('slimstat_e2e_license_state', '');
    if ('' === $state || 0 !== strpos((string) $url, 'https://my.wp-slimstat.com/api/v1/license/')) {
        return $pre;
    }
    if ('unreachable' === $state) {
        return new WP_Error('http_request_failed', 'E2E fixture: license server unreachable');
    }
    $body = 'valid' === $state ? ['status' => 'valid', 'license_details' => ['status' => 'active']] : ['status' => $state];
    return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
}, 10, 3);

add_action('wp_ajax_e2e_license', static function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('forbidden', 403);
    }
    if (!class_exists('\SlimStat\Services\ProLicense') || !file_exists(WP_PLUGIN_DIR . '/wp-slimstat-pro/wp-slimstat-pro.php')) {
        wp_send_json_success(['state' => 'no-pro']);
    }
    $license = '\SlimStat\Services\ProLicense';
    $state   = sanitize_key(wp_unslash($_POST['state'] ?? ''));
    if (!in_array($state, ['valid', 'invalid', 'domain_not_activated', 'unreachable', 'none'], true)) {
        wp_send_json_error('bad state', 400);
    }
    $options = (array) get_option('slimstat_options', []);
    $key     = trim((string) ($options['slimstat_pro_license_key'] ?? ''));
    $domain  = (string) preg_replace('/^www\./i', '', (string) wp_parse_url(home_url(), PHP_URL_HOST));
    foreach (['valid', 'details'] as $kind) {
        delete_transient('wp_slimstat_pro_license_' . $kind . '_' . md5($key . '|' . $domain));
    }
    if ('none' === $state) {
        unset($options['slimstat_pro_license_key']);
        $options['slimstat_pro_license_status'] = false;
        update_option('slimstat_options', $options);
        delete_option('slimstat_e2e_license_state');
        delete_option($license::OPTION);
        wp_send_json_success(['state' => $license::state()]);
    }
    if ('' === $key) {
        $options['slimstat_pro_license_key'] = 'e2e-license-key';
        update_option('slimstat_options', $options);
    }
    update_option('slimstat_e2e_license_state', $state, false);
    $verdict = get_option($license::OPTION);
    if (empty($_POST['keep']) || !is_array($verdict)) {
        delete_option($license::OPTION);
    } elseif (($age = absint($_POST['age'] ?? 0)) > 0) {
        $verdict['tried']   = (int) ($verdict['tried'] ?? 0) - $age;
        $verdict['checked'] = (int) ($verdict['checked'] ?? 0) - $age;
        update_option($license::OPTION, $verdict, true);
    }
    wp_send_json_success(['state' => $license::refresh()]);
});
