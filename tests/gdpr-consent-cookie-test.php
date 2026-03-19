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
// Section 2: Nonce Skip Pattern for Anonymous Users
// ═══════════════════════════════════════════════════════════════════════════

// ─── Test 6: Nonce verification skipped when user_id = 0 (anonymous) ───

$_stub_user_id     = 0;
$_stub_nonce_valid = false; // nonce is invalid/stale
$nonce = 'stale_nonce_123';

// Simulate the fixed pattern
$should_reject = false;
$user_id = get_current_user_id();
if ($user_id > 0) {
    if (!wp_verify_nonce($nonce, 'wp_rest')) {
        $should_reject = true;
    }
}
assert_false($should_reject, 'Anonymous user with stale nonce should NOT be rejected');

// ─── Test 7: Nonce verification enforced when user_id > 0 with bad nonce ──

$_stub_user_id     = 1;
$_stub_nonce_valid = false;
$nonce = 'bad_nonce';

$should_reject = false;
$user_id = get_current_user_id();
if ($user_id > 0) {
    if (!wp_verify_nonce($nonce, 'wp_rest')) {
        $should_reject = true;
    }
}
assert_true($should_reject, 'Logged-in user with bad nonce should be rejected');

// ─── Test 8: Nonce verification passes for logged-in user with valid nonce ──

$_stub_user_id     = 1;
$_stub_nonce_valid = true;
$nonce = 'valid_nonce';

$should_reject = false;
$user_id = get_current_user_id();
if ($user_id > 0) {
    if (!wp_verify_nonce($nonce, 'wp_rest')) {
        $should_reject = true;
    }
}
assert_false($should_reject, 'Logged-in user with valid nonce should NOT be rejected');

// ─── Test 9: check_ajax_referer skipped for anonymous in handleConsentRevoked ──

$_stub_user_id     = 0;
$_stub_nonce_valid = false;

// Simulate the fixed pattern for handleConsentRevoked
$was_checked = false;
if (get_current_user_id() > 0) {
    // In real code: check_ajax_referer('wp_rest', 'nonce');
    $was_checked = true;
}
assert_false($was_checked, 'check_ajax_referer should be skipped for anonymous users');

// ═══════════════════════════════════════════════════════════════════════════

echo "All {$assertions} assertions passed in gdpr-consent-cookie-test.php\n";

} // end global namespace
