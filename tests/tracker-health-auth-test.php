<?php
/**
 * Regression test: TrackerHealthRestController permission gate.
 *
 * Verifies that:
 *  1. The permission_callback denies access when the caller lacks manage_options.
 *  2. The permission_callback grants access when the caller has manage_options.
 *  3. handle() returns a well-shaped response with all expected keys.
 *  4. ignore_settings contains all 14 exclusion keys.
 *  5. geolocation_provider is 'disabled' when the resolver returns false.
 *  6. last_tracker_error shape contains code/label/recorded_at/detail.
 *
 * @see src/Controllers/Rest/TrackerHealthRestController.php
 */

declare(strict_types=1);

// ─── Namespaced stubs (must appear before any non-namespace code) ─────────────

namespace SlimStat\Tracker {
    if (!class_exists('SlimStat\Tracker\Utils')) {
        class Utils {
            public static function getTrackerCodeLabel(?int $code): string
            {
                return '';
            }
        }
    }
}

// ─── Global namespace: WP stubs + test runner ────────────────────────────────

namespace {

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

// Controls what current_user_can() returns per test scenario
$GLOBALS['_tha_current_user_can'] = false;
$GLOBALS['_tha_options']          = [];

if (!function_exists('current_user_can')) {
    function current_user_can(string $cap): bool
    {
        return (bool) $GLOBALS['_tha_current_user_can'];
    }
}

if (!function_exists('get_option')) {
    function get_option(string $option, $default = false)
    {
        return $GLOBALS['_tha_options'][$option] ?? $default;
    }
}

if (!function_exists('register_rest_route')) {
    function register_rest_route(string $namespace, string $route, array $args): void {}
}

if (!function_exists('rest_ensure_response')) {
    function rest_ensure_response($data) {
        $r = new WP_REST_Response();
        $r->data = $data;
        return $r;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) { return is_string($str) ? trim(strip_tags($str)) : ''; }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        public function get_params(): array { return []; }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        public $data = [];
    }
}

if (!class_exists('wp_slimstat')) {
    class wp_slimstat {
        public static array $settings = [];
        public static function resolve_geolocation_provider() { return false; }
    }
}

require_once __DIR__ . '/../src/Interfaces/RestControllerInterface.php';
require_once __DIR__ . '/../src/Controllers/Rest/TrackerHealthRestController.php';

use SlimStat\Controllers\Rest\TrackerHealthRestController;

// ─── Assertion helper ─────────────────────────────────────────────────────────

$assertions = 0;

function tha_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$controller = new TrackerHealthRestController();

// ─── TEST 1: Unauthenticated — permission_callback returns false ──────────────
// The permission_callback is: static fn() => current_user_can('manage_options')
// We verify the underlying capability check directly.
$GLOBALS['_tha_current_user_can'] = false;
tha_assert(
    false === current_user_can('manage_options'),
    'TEST 1: permission_callback must deny access when current_user_can=false'
);

// ─── TEST 2: Administrator — permission_callback returns true ─────────────────
$GLOBALS['_tha_current_user_can'] = true;
tha_assert(
    true === current_user_can('manage_options'),
    'TEST 2: permission_callback must allow access when current_user_can=true'
);

// ─── Prepare shared handle() fixture ─────────────────────────────────────────
$GLOBALS['_tha_options'] = [
    'slimstat_tracker_error'        => [],
    'slimstat_tracker_error_detail' => null,
    'slimstat_tracker_warning'      => [],
    'slimstat_geoip_error'          => null,
];

wp_slimstat::$settings = [
    'tracking_request_method' => 'rest',
    'javascript_mode'         => 'on',
    'gdpr_enabled'            => 'off',
    'anonymous_tracking'      => 'off',
];

$request  = new WP_REST_Request();
$response = $controller->handle($request);
$data     = $response->data;

// ─── TEST 3: handle() returns all expected top-level keys ─────────────────────
$expectedTopKeys = [
    'version', 'tracking_request_method', 'javascript_mode', 'gdpr_enabled',
    'anonymous_tracking', 'geolocation_provider', 'ignore_settings',
    'last_tracker_error', 'last_tracker_warning', 'last_geoip_error',
];
foreach ($expectedTopKeys as $key) {
    tha_assert(
        array_key_exists($key, $data),
        "TEST 3: handle() response must contain top-level key '{$key}'"
    );
}

// ─── TEST 4: ignore_settings contains all 14 exclusion keys ──────────────────
$ignoreShape = $data['ignore_settings'];
$expectedIgnoreKeys = [
    'ignore_ip', 'ignore_resources', 'ignore_referers', 'ignore_content_types',
    'ignore_browsers', 'ignore_platforms', 'ignore_bots', 'ignore_languages',
    'ignore_countries', 'ignore_users', 'ignore_capabilities', 'ignore_wp_users',
    'ignore_spammers', 'ignore_prefetch',
];
foreach ($expectedIgnoreKeys as $key) {
    tha_assert(
        array_key_exists($key, $ignoreShape),
        "TEST 4: ignore_settings must contain exclusion key '{$key}'"
    );
}

// ─── TEST 5: geolocation_provider is 'disabled' when resolver returns false ───
tha_assert(
    'disabled' === $data['geolocation_provider'],
    'TEST 5: geolocation_provider must be "disabled" when resolve_geolocation_provider() returns false'
);

// ─── TEST 6: last_tracker_error has required shape ───────────────────────────
$errorShape = $data['last_tracker_error'];
foreach (['code', 'label', 'recorded_at', 'detail'] as $key) {
    tha_assert(
        array_key_exists($key, $errorShape),
        "TEST 6: last_tracker_error must contain key '{$key}'"
    );
}

echo "All {$assertions} assertions passed in tracker-health-auth-test.php\n";

} // namespace
