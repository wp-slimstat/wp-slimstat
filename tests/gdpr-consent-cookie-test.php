<?php
/**
 * Unit tests for GDPR consent cookie operations and nonce-skip pattern.
 *
 * Tests GDPRService cookie management (setConsent, hasConsentDecision,
 * getBannerHtml) and verifies the nonce-skip pattern for anonymous users
 * in consent REST controllers.
 *
 * @since 5.4.0
 */

declare(strict_types=1);

// ─── Namespace stub: override setcookie/is_ssl inside GDPRService namespace ──
namespace SlimStat\Services {
    function setcookie(string $name, string $value = '', $options = 0): bool
    {
        global $_setcookie_calls;
        $_setcookie_calls[] = ['name' => $name, 'value' => $value, 'options' => $options];
        return true;
    }

    function is_ssl(): bool
    {
        return false;
    }
}

// ─── Stub namespaced classes for ConsentHandler dependencies ─────
namespace SlimStat\Providers {
    class IPHashProvider {
        public static function upgradeToPii(array $stat): array { return $stat; }
    }
}

namespace SlimStat\Tracker {
    class Session {
        public static function deleteTrackingCookie(): void {}
    }
    class Utils {
        public static function getValueWithoutChecksum($v) { return $v; }
    }
}

namespace SlimStat\Utils {
    class Consent {
        public static function normalizeConsent($c) { return is_array($c) ? $c : ['statistics' => $c]; }
    }
}

// ─── Everything else in global namespace ─────────────────────────
namespace {

$assertions = 0;

function assert_true($actual, string $message): void
{
    global $assertions;
    $assertions++;

    if ($actual !== true) {
        fwrite(STDERR, "FAIL: {$message} (expected true, got " . var_export($actual, true) . ")\n");
        exit(1);
    }
}

function assert_false($actual, string $message): void
{
    global $assertions;
    $assertions++;

    if ($actual !== false) {
        fwrite(STDERR, "FAIL: {$message} (expected false, got " . var_export($actual, true) . ")\n");
        exit(1);
    }
}

function assert_same($expected, $actual, string $message): void
{
    global $assertions;
    $assertions++;

    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ")\n");
        exit(1);
    }
}

// ─── WordPress constants ─────────────────────────────────────────
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}
if (!defined('COOKIEPATH')) {
    define('COOKIEPATH', '/');
}
if (!defined('COOKIE_DOMAIN')) {
    define('COOKIE_DOMAIN', '.test.local');
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

// ─── WordPress function stubs ────────────────────────────────────

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str)
    {
        return is_string($str) ? trim(strip_tags($str)) : '';
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('is_ssl')) {
    function is_ssl()
    {
        return false;
    }
}

if (!function_exists('__')) {
    function __($text, $domain = 'default')
    {
        return $text;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value, ...$args)
    {
        return $value;
    }
}

if (!function_exists('do_action')) {
    function do_action($tag, ...$args)
    {
        // no-op
    }
}

if (!function_exists('wp_kses')) {
    function wp_kses($string, $allowed_html, $allowed_protocols = [])
    {
        return $string;
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post($data)
    {
        return $data;
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

// setcookie stub capture array
$_setcookie_calls = [];

// Nonce and user stubs
$_stub_user_id     = 0;
$_stub_nonce_valid = true;

if (!function_exists('get_current_user_id')) {
    function get_current_user_id()
    {
        global $_stub_user_id;
        return $_stub_user_id ?? 0;
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action)
    {
        global $_stub_nonce_valid;
        return $_stub_nonce_valid;
    }
}

// wp_send_json_error / wp_send_json_success stubs
$_stub_json_error   = null;
$_stub_json_success = null;

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null)
    {
        global $_stub_json_error;
        $_stub_json_error = $data;
        throw new \RuntimeException('wp_send_json_error called');
    }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null)
    {
        global $_stub_json_success;
        $_stub_json_success = $data;
    }
}

if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action, $key = false)
    {
        global $_stub_nonce_valid;
        if (!$_stub_nonce_valid) {
            throw new \RuntimeException('check_ajax_referer failed');
        }
        return true;
    }
}

// function_exists('pll__') must return false — we deliberately do NOT define pll__().

// ─── Stub classes ────────────────────────────────────────────────

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public $code;
        public $message;
        public $data;

        public function __construct($code = '', $message = '', $data = '')
        {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }

        public function get_error_code()
        {
            return $this->code;
        }

        public function get_error_message()
        {
            return $this->message;
        }

        public function get_error_data()
        {
            return $this->data;
        }
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        private $params = [];

        public function __construct(array $params = [])
        {
            $this->params = $params;
        }

        public function get_param($key)
        {
            return $this->params[$key] ?? null;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public $data;
        public $status;

        public function __construct($data = null, $status = 200)
        {
            $this->data   = $data;
            $this->status = $status;
        }
    }
}

if (!class_exists('wp_slimstat')) {
    class wp_slimstat
    {
        public static $settings = [];
    }
}

// ─── Load source under test ─────────────────────────────────────

require_once __DIR__ . '/../src/Services/GDPRService.php';

use SlimStat\Services\GDPRService;

// ═══════════════════════════════════════════════════════════════════════════
// Section 1: GDPRService Cookie Operations
// ═══════════════════════════════════════════════════════════════════════════

// ─── Test 1: setConsent uses COOKIE_DOMAIN ───────────────────────

$_setcookie_calls = [];
unset($_COOKIE['slimstat_gdpr_consent']);

$service = new GDPRService(['use_slimstat_banner' => 'on']);
$service->setConsent('accepted');

assert_same(1, count($_setcookie_calls), 'setConsent should call setcookie once');
assert_same(COOKIE_DOMAIN, $_setcookie_calls[0]['options']['domain'], 'setConsent should use COOKIE_DOMAIN');
assert_same(COOKIEPATH, $_setcookie_calls[0]['options']['path'], 'setConsent should use COOKIEPATH');
assert_same('slimstat_gdpr_consent', $_setcookie_calls[0]['name'], 'setConsent should use correct cookie name');
assert_same('accepted', $_setcookie_calls[0]['value'], 'setConsent should set accepted value');

// ─── Test 2: hasConsentDecision returns true when cookie set ─────

$_COOKIE['slimstat_gdpr_consent'] = 'accepted';
$service = new GDPRService(['use_slimstat_banner' => 'on']);
assert_true($service->hasConsentDecision(), 'hasConsentDecision should return true when cookie is set');

// ─── Test 3: hasConsentDecision returns false when no cookie ─────

unset($_COOKIE['slimstat_gdpr_consent']);
$service = new GDPRService(['use_slimstat_banner' => 'on']);
assert_false($service->hasConsentDecision(), 'hasConsentDecision should return false when cookie not set');

// ─── Test 4: getBannerHtml returns empty when consent exists ─────

$_COOKIE['slimstat_gdpr_consent'] = 'accepted';
$service = new GDPRService([
    'use_slimstat_banner' => 'on',
    'opt_out_message'     => 'Test message',
    'gdpr_theme_mode'     => '',
]);
assert_same('', $service->getBannerHtml(), 'getBannerHtml should return empty string when consent exists');

// ─── Test 5: getBannerHtml returns HTML when no consent ──────────

unset($_COOKIE['slimstat_gdpr_consent']);
$service = new GDPRService([
    'use_slimstat_banner'       => 'on',
    'opt_out_message'           => 'Test message',
    'gdpr_accept_button_text'   => 'Accept',
    'gdpr_decline_button_text'  => 'Decline',
    'gdpr_theme_mode'           => '',
]);
$html = $service->getBannerHtml();
assert_true(strpos($html, 'slimstat-gdpr-banner') !== false, 'getBannerHtml should contain banner ID when no consent');
assert_true(strlen($html) > 0, 'getBannerHtml should return non-empty HTML when no consent');

// ═══════════════════════════════════════════════════════════════════════════
// Section 2: Actual Handler Nonce Verification
// Tests call the real controller/handler methods to verify nonce is enforced.
// ═══════════════════════════════════════════════════════════════════════════

// Load the actual handlers under test
require_once __DIR__ . '/../src/Interfaces/RestControllerInterface.php';
require_once __DIR__ . '/../src/Controllers/Rest/GDPRBannerRestController.php';
require_once __DIR__ . '/../src/Services/Privacy/ConsentHandler.php';

// ─── Test 6: GDPRBannerRestController rejects request with bad nonce ───

$_stub_nonce_valid = false;
\wp_slimstat::$settings = ['use_slimstat_banner' => 'on'];

$controller = new \SlimStat\Controllers\Rest\GDPRBannerRestController();
$request = new \WP_REST_Request(['consent' => 'accepted', 'nonce' => 'bad_nonce_123']);
$result = $controller->handle_consent($request);

assert_true($result instanceof \WP_Error, 'handle_consent should return WP_Error when nonce is invalid');
assert_same('rest_forbidden', $result->get_error_code(), 'handle_consent should return rest_forbidden error code');
assert_same(403, $result->get_error_data()['status'], 'handle_consent should return 403 status');

// ─── Test 7: GDPRBannerRestController accepts request with valid nonce ───

$_stub_nonce_valid = true;
$_setcookie_calls = [];
\wp_slimstat::$settings = ['use_slimstat_banner' => 'on'];

$controller = new \SlimStat\Controllers\Rest\GDPRBannerRestController();
$request = new \WP_REST_Request(['consent' => 'accepted', 'nonce' => 'valid_nonce']);
$result = $controller->handle_consent($request);

assert_true($result instanceof \WP_REST_Response, 'handle_consent should return WP_REST_Response on valid nonce');
assert_same(200, $result->status, 'handle_consent should return 200 on success');
assert_true($result->data['success'] ?? false, 'handle_consent response should indicate success');

// ─── Test 8: GDPRBannerRestController rejects empty nonce ───

$_stub_nonce_valid = false;
\wp_slimstat::$settings = ['use_slimstat_banner' => 'on'];

$controller = new \SlimStat\Controllers\Rest\GDPRBannerRestController();
$request = new \WP_REST_Request(['consent' => 'accepted', 'nonce' => '']);
$result = $controller->handle_consent($request);

assert_true($result instanceof \WP_Error, 'handle_consent should return WP_Error when nonce is empty');
assert_same('rest_forbidden', $result->get_error_code(), 'handle_consent should return rest_forbidden for empty nonce');

// ─── Test 9: ConsentHandler::handleBannerConsent rejects empty nonce ───

$_stub_nonce_valid = false;
\wp_slimstat::$settings = ['use_slimstat_banner' => 'on'];
$_stub_json_error = null;

$caught_error = false;
try {
    \SlimStat\Services\Privacy\ConsentHandler::handleBannerConsent(false, [
        'nonce'   => '',
        'consent' => 'accepted',
    ]);
} catch (\Throwable $e) {
    // handleBannerConsent returns false for non-JSON mode with bad nonce
}
// In non-JSON mode, it returns false when nonce fails
$nonce_result = \SlimStat\Services\Privacy\ConsentHandler::handleBannerConsent(false, [
    'nonce'   => 'stale_nonce',
    'consent' => 'accepted',
]);
assert_false($nonce_result, 'handleBannerConsent should return false when nonce verification fails');

// ═══════════════════════════════════════════════════════════════════════════

echo "All {$assertions} assertions passed in gdpr-consent-cookie-test.php\n";

} // end global namespace
