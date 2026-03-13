<?php
/**
 * MU-Plugin: Frontend geolocation test shim for E2E tests.
 *
 * Provides two mechanisms:
 * 1. Frontend (template_redirect) triggers for testing geolocation code
 *    in non-admin context where wp-admin/includes/file.php is NOT loaded.
 * 2. Early throw injection via init hook for testing \Throwable catch blocks
 *    in the admin AJAX handler.
 * 3. Nonce endpoint for authenticating admin AJAX test requests.
 *
 * Safety: all handlers are no-op unless SLIMSTAT_E2E_TESTING is defined.
 */

// Guard: do nothing on non-test environments
if (!defined('SLIMSTAT_E2E_TESTING') || SLIMSTAT_E2E_TESTING !== true) {
    return;
}

// ── Deactivate cookie-law-info for provider test (Test 1) ──
// cookie-law-info loads wp-admin/includes/file.php on frontend, which
// defeats the include-guard test. MU-plugins load before regular plugins,
// so filtering option_active_plugins prevents it from loading.
if (!empty($_GET['test_dbip_cron']) && $_GET['test_dbip_cron'] === 'provider') {
    add_filter('option_active_plugins', function ($plugins) {
        return array_values(array_filter($plugins, function ($p) {
            return strpos($p, 'cookie-law-info') === false;
        }));
    }, 1);
}

// ── Early throw injection for admin AJAX Test 3 ──
// When _test_throw_error is posted, register a pre_http_request filter that
// throws \Error. This fires inside the real handler's updateDatabase() call.
add_action('init', function () {
    if (!empty($_POST['_test_throw_error'])) {
        add_filter('pre_http_request', function () {
            throw new \Error('test_simulated_php_error');
        }, 1);
    }
});

// ── Nonce endpoint for Test 3 ──
add_action('wp_ajax_test_get_geoip_nonce', function () {
    wp_send_json_success(['nonce' => wp_create_nonce('slimstat_geoip_action')]);
});

// ── Frontend triggers for Tests 1 & 2 ──
add_action('template_redirect', function () {
    if (empty($_GET['test_dbip_cron'])) return;

    $mode = sanitize_text_field($_GET['test_dbip_cron']);
    header('Content-Type: application/json');

    // Record whether wp_tempnam was already defined (false-pass detection)
    $had_wp_tempnam_before = function_exists('wp_tempnam');

    if ($mode === 'provider') {
        // Test 1: Direct provider call — tests include guard
        // Stub HTTP to avoid network dependency (returns WP_Error)
        add_filter('pre_http_request', function () {
            return new \WP_Error('test_stub', 'HTTP stubbed for testing');
        }, 1);

        try {
            $provider = \wp_slimstat::resolve_geolocation_provider();
            if (false === $provider) {
                echo json_encode(['success' => false, 'error' => 'provider_disabled',
                                   'had_wp_tempnam_before' => $had_wp_tempnam_before]);
                die();
            }
            $service = new \SlimStat\Services\Geolocation\GeolocationService($provider, []);
            $service->updateDatabase();
            echo json_encode(['success' => true, 'error' => null,
                               'had_wp_tempnam_before' => $had_wp_tempnam_before]);
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage(),
                               'class' => get_class($e),
                               'had_wp_tempnam_before' => $had_wp_tempnam_before]);
        }
    } elseif ($mode === 'callback_throwable') {
        // Test 2: Cron callback with forced \Error — tests \Throwable catch
        // Stub HTTP to throw \Error (not \Exception) — simulates engine-level failure
        add_filter('pre_http_request', function () {
            throw new \Error('test_simulated_php_error');
        }, 1);

        try {
            \wp_slimstat::wp_slimstat_update_geoip_database();
            // If we get here, the callback caught the \Error internally
            echo json_encode(['success' => true, 'error' => null,
                               'escaped_catch' => false,
                               'had_wp_tempnam_before' => $had_wp_tempnam_before]);
        } catch (\Throwable $e) {
            // If we get here, the callback did NOT catch the \Throwable
            echo json_encode(['success' => false, 'error' => $e->getMessage(),
                               'class' => get_class($e), 'escaped_catch' => true,
                               'had_wp_tempnam_before' => $had_wp_tempnam_before]);
        }
    }
    die();
});
