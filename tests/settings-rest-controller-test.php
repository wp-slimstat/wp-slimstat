<?php
/**
 * Tests for SettingsRestController
 *
 * Verifies the REST controller validates input correctly and delegates
 * to SettingsSaveService for the actual save logic.
 *
 * @since 5.4.10
 * @see   https://github.com/wp-slimstat/wp-slimstat/issues/285
 */

declare(strict_types=1);

$assertions = 0;

function assert_same($expected, $actual, string $message): void
{
    global $assertions;
    $assertions++;

    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ")\n");
        exit(1);
    }
}

function assert_true($actual, string $message): void
{
    global $assertions;
    $assertions++;

    if (true !== $actual) {
        fwrite(STDERR, "FAIL: {$message} (expected true, got " . var_export($actual, true) . ")\n");
        exit(1);
    }
}

// ─── Stubs ──────────────────────────────────────────────────────────

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) { return trim(strip_tags((string) $str)); }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post($str) { return strip_tags((string) $str, '<p><a><strong><em><br>'); }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($str) { return strip_tags((string) $str); }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) { return $value; }
}
if (!function_exists('do_action')) {
    function do_action() {}
}
if (!function_exists('update_option')) {
    function update_option($key, $value) { return true; }
}
if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled() { return false; }
}
if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event() {}
}
if (!function_exists('is_network_admin')) {
    function is_network_admin() { return false; }
}
if (!function_exists('__')) {
    function __($text) { return $text; }
}
if (!function_exists('register_rest_route')) {
    function register_rest_route($namespace, $route, $args) {
        // Capture registered routes for testing
        global $registered_routes;
        $registered_routes[] = ['namespace' => $namespace, 'route' => $route, 'args' => $args];
    }
}
if (!function_exists('current_user_can')) {
    function current_user_can($cap) { return true; }
}
if (!function_exists('absint')) {
    function absint($val) { return abs((int) $val); }
}

// Nonce stub
$_nonce_valid = true;
if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action) {
        global $_nonce_valid;
        return $_nonce_valid;
    }
}

if (!class_exists('wp_slimstat')) {
    class wp_slimstat {
        public static $settings = [];
        public static $upload_dir = '/tmp';
        public static $wpdb;
        public static function update_option($key, $value) {}
        public static function resolve_geolocation_provider() {
            return self::$settings['geolocation_provider'] ?? false;
        }
    }
}

$wpdb_stub = new class {
    public $prefix = 'wp_';
    public function query($sql) { return true; }
};
wp_slimstat::$wpdb = $wpdb_stub;
$GLOBALS['wpdb'] = $wpdb_stub;

// Minimal WP_REST_Request stub
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        private $params = [];
        public function __construct(array $params = []) { $this->params = $params; }
        public function get_param(string $key) { return $this->params[$key] ?? null; }
    }
}

// Minimal WP_REST_Response stub
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        public $data;
        public $status;
        public function __construct($data = null, int $status = 200) {
            $this->data = $data;
            $this->status = $status;
        }
    }
}

require_once __DIR__ . '/../src/Interfaces/RestControllerInterface.php';
require_once __DIR__ . '/../src/Services/SettingsSaveService.php';
require_once __DIR__ . '/../src/Controllers/Rest/SettingsRestController.php';

use SlimStat\Controllers\Rest\SettingsRestController;

// ─── Test 1: Route registration ─────────────────────────────────────

$registered_routes = [];
$controller = new SettingsRestController();
$controller->register_routes();

assert_same(2, count($registered_routes), 'should register 2 routes (settings + probe)');
assert_same('/settings', $registered_routes[0]['route'], 'first route should be /settings');
assert_same('/settings-probe', $registered_routes[1]['route'], 'second route should be /settings-probe');
assert_same('POST', $registered_routes[0]['args']['methods'], 'settings route should accept POST');

// ─── Test 2: Save with direct JSON options ──────────────────────────

wp_slimstat::$settings = ['session_duration' => '1800'];
$_nonce_valid = true;

$request = new WP_REST_Request([
    'tab'     => 1,
    'nonce'   => 'valid_nonce',
    'options' => ['session_duration' => '3600'],
]);

$response = $controller->save_settings($request);

assert_same(200, $response->status, 'direct JSON save should return 200');
assert_true($response->data['success'], 'direct JSON save should succeed');
assert_same('3600', wp_slimstat::$settings['session_duration'], 'setting should be updated via direct JSON');

// ─── Test 3: Save with base64-encoded options ───────────────────────

wp_slimstat::$settings = ['session_duration' => '1800', 'opt_out_message' => ''];
$_nonce_valid = true;

$encoded = base64_encode(json_encode([
    'session_duration' => '7200',
    'opt_out_message'  => '<p>Test banner</p>',
]));

$request = new WP_REST_Request([
    'tab'             => 2,
    'nonce'           => 'valid_nonce',
    'encoded_options' => $encoded,
]);

$response = $controller->save_settings($request);

assert_same(200, $response->status, 'base64 save should return 200');
assert_true($response->data['success'], 'base64 save should succeed');
assert_same('7200', wp_slimstat::$settings['session_duration'], 'setting updated via base64');

// ─── Test 4: Invalid nonce returns 403 ──────────────────────────────

$_nonce_valid = false;

$request = new WP_REST_Request([
    'tab'     => 1,
    'nonce'   => 'bad_nonce',
    'options' => ['session_duration' => '999'],
]);

$response = $controller->save_settings($request);

assert_same(403, $response->status, 'invalid nonce should return 403');
assert_same(false, $response->data['success'], 'invalid nonce should not succeed');

// ─── Test 5: Invalid base64 returns 400 ─────────────────────────────

$_nonce_valid = true;

$request = new WP_REST_Request([
    'tab'             => 1,
    'nonce'           => 'valid_nonce',
    'encoded_options' => '!!!not-valid-base64!!!',
]);

$response = $controller->save_settings($request);

assert_same(400, $response->status, 'invalid base64 should return 400');

// ─── Test 6: Non-array options returns 400 ──────────────────────────

$request = new WP_REST_Request([
    'tab'     => 1,
    'nonce'   => 'valid_nonce',
    'options' => 'not-an-array',
]);

$response = $controller->save_settings($request);

assert_same(400, $response->status, 'non-array options should return 400');

// ─── Test 7: Base64 with invalid JSON returns 400 ───────────────────

$request = new WP_REST_Request([
    'tab'             => 1,
    'nonce'           => 'valid_nonce',
    'encoded_options' => base64_encode('not valid json'),
]);

$response = $controller->save_settings($request);

assert_same(400, $response->status, 'base64 with invalid JSON should return 400');

// ─── Test 8: WAF-triggering content saves correctly via base64 ──────

wp_slimstat::$settings = [
    'opt_out_message'      => '',
    'custom_css'           => '',
    'ignore_referers'      => '',
    'opt_out_cookie_names' => '',
];
$_nonce_valid = true;

$waf_trigger_options = [
    'opt_out_message'      => '<p>This site uses <a href="/privacy">cookies</a>.</p>',
    'custom_css'           => '.slimstat-report { color: #21759b; } /* comment */',
    'ignore_referers'      => 'https://spam.example.com*',
    'opt_out_cookie_names' => 'CookiePreferences-site.com="slimstat":false',
];

$encoded = base64_encode(json_encode($waf_trigger_options));

$request = new WP_REST_Request([
    'tab'             => 2,
    'nonce'           => 'valid_nonce',
    'encoded_options' => $encoded,
]);

$response = $controller->save_settings($request);

assert_same(200, $response->status, 'WAF-trigger content via base64 should save OK');
assert_true($response->data['success'], 'WAF-trigger content should succeed');
assert_same(true, strpos(wp_slimstat::$settings['opt_out_message'], '<a href="/privacy">') !== false, 'HTML link preserved via base64');
assert_same(true, strpos(wp_slimstat::$settings['custom_css'], 'color: #21759b') !== false, 'CSS preserved via base64');
assert_same(true, strpos(wp_slimstat::$settings['ignore_referers'], 'https://spam.example.com') !== false, 'URL preserved via base64');

// ─── Done ───────────────────────────────────────────────────────────

echo "All {$assertions} assertions passed in " . basename(__FILE__) . "\n";
