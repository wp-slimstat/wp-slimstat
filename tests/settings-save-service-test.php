<?php
/**
 * Tests for SettingsSaveService
 *
 * Verifies the extracted settings save logic works identically to the original
 * inline implementation, with correct sanitization per field type.
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

function assert_contains(string $needle, string $haystack, string $message): void
{
    global $assertions;
    $assertions++;

    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "FAIL: {$message} ('{$needle}' not found in '{$haystack}')\n");
        exit(1);
    }
}

// ─── Stubs ──────────────────────────────────────────────────────────

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

// Stub WordPress functions
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags((string) $str));
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post($str) {
        // Simplified: allow <p>, <a>, <strong>, <em> tags
        return strip_tags((string) $str, '<p><a><strong><em><br>');
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($str) {
        return strip_tags((string) $str);
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) {
        return $value;
    }
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

if (!function_exists('function_exists') || true) {
    // pll_register_string not defined — that's fine, the code checks for it
}

// Stub wp_slimstat class
if (!class_exists('wp_slimstat')) {
    class wp_slimstat
    {
        public static $settings = [];
        public static $save_context = [];
        public static $upload_dir = '/tmp';
        public static $wpdb;

        public static function update_option($key, $value, bool $is_network = false)
        {
            // No-op for tests — settings are stored in self::$settings
        }

        public static function resolve_geolocation_provider()
        {
            return self::$settings['geolocation_provider'] ?? false;
        }
    }
}

// Stub wpdb
$wpdb_stub = new class {
    public $prefix = 'wp_';
    public function query($sql) { return true; }
};
wp_slimstat::$wpdb = $wpdb_stub;
$GLOBALS['wpdb'] = $wpdb_stub;

require_once __DIR__ . '/../src/Services/SettingsSaveService.php';

use SlimStat\Services\SettingsSaveService;

// ─── Test 1: Basic text field sanitization ──────────────────────────

wp_slimstat::$settings = [
    'is_tracking' => 'on',
    'session_duration' => '1800',
];

$result = SettingsSaveService::save(1, [
    'is_tracking' => 'on',
    'session_duration' => '  3600  ',
], []);

assert_true($result['success'], 'save should return success');
assert_same('on', wp_slimstat::$settings['is_tracking'], 'toggle field preserved');
assert_same('3600', wp_slimstat::$settings['session_duration'], 'text field trimmed');

// ─── Test 2: Rich text field uses wp_kses_post ──────────────────────

wp_slimstat::$settings = ['opt_out_message' => ''];

$html_input = '<p>This site uses <a href="/privacy">cookies</a>. <script>alert(1)</script></p>';
$result = SettingsSaveService::save(2, [
    'opt_out_message' => $html_input,
], []);

assert_true($result['success'], 'save with rich_text should succeed');
assert_contains('<a href="/privacy">', wp_slimstat::$settings['opt_out_message'], 'rich_text should preserve safe HTML links');
// Script tags should be stripped by wp_kses_post
assert_same(false, strpos(wp_slimstat::$settings['opt_out_message'], '<script>') !== false, 'rich_text should strip script tags');

// ─── Test 3: Code editor field uses wp_strip_all_tags ───────────────

wp_slimstat::$settings = ['custom_css' => ''];

$css_input = '.slimstat { color: red; } <script>bad</script>';
$result = SettingsSaveService::save(3, [
    'custom_css' => $css_input,
], []);

assert_true($result['success'], 'save with code_editor should succeed');
assert_contains('color: red', wp_slimstat::$settings['custom_css'], 'code_editor should preserve CSS');
assert_same(false, strpos(wp_slimstat::$settings['custom_css'], '<script>') !== false, 'code_editor should strip all HTML tags');

// ─── Test 4: SKIP_FIELDS are not saved ──────────────────────────────

wp_slimstat::$settings = ['enable_maxmind' => 'no', 'enable_browscap' => 'no'];

$result = SettingsSaveService::save(1, [
    'enable_maxmind' => 'on',
], []);

assert_same('no', wp_slimstat::$settings['enable_maxmind'], 'enable_maxmind should be skipped (handled by special handler)');

// ─── Test 5: Settings defs take priority over built-in knowledge ────

wp_slimstat::$settings = ['custom_field' => ''];

$settings_defs = [
    1 => [
        'rows' => [
            'custom_field' => [
                'type' => 'rich_text',
            ],
        ],
    ],
];

$result = SettingsSaveService::save(1, [
    'custom_field' => '<p>HTML content</p>',
], $settings_defs);

assert_contains('<p>', wp_slimstat::$settings['custom_field'], 'settings_defs rich_text type should use wp_kses_post');

// ─── Test 6: Readonly fields in settings_defs are skipped ───────────

wp_slimstat::$settings = ['readonly_field' => 'original'];

$settings_defs = [
    1 => [
        'rows' => [
            'readonly_field' => [
                'type'     => 'text',
                'readonly' => true,
            ],
        ],
    ],
];

$result = SettingsSaveService::save(1, [
    'readonly_field' => 'modified',
], $settings_defs);

assert_same('original', wp_slimstat::$settings['readonly_field'], 'readonly fields should not be modified');

// ─── Test 7: Section header and plain-text types are skipped ────────

wp_slimstat::$settings = [];

$settings_defs = [
    1 => [
        'rows' => [
            'header_field' => [
                'type' => 'section_header',
            ],
            'info_field' => [
                'type' => 'plain-text',
            ],
        ],
    ],
];

$result = SettingsSaveService::save(1, [
    'header_field' => 'should not save',
    'info_field'   => 'should not save',
], $settings_defs);

assert_same(false, isset(wp_slimstat::$settings['header_field']), 'section_header should not be saved');
assert_same(false, isset(wp_slimstat::$settings['info_field']), 'plain-text should not be saved');

// ─── Test 8: Consent banner sync ────────────────────────────────────

wp_slimstat::$settings = [
    'consent_integration' => 'slimstat_banner',
    'use_slimstat_banner' => 'off',
];

$result = SettingsSaveService::save(2, [
    'consent_integration' => 'slimstat_banner',
], []);

assert_same('on', wp_slimstat::$settings['use_slimstat_banner'], 'use_slimstat_banner should sync to on when consent_integration is slimstat_banner');

// ─── Test 9: Consent banner sync - off ──────────────────────────────

wp_slimstat::$settings = [
    'consent_integration' => 'wp_consent_api',
    'use_slimstat_banner' => 'on',
];

$result = SettingsSaveService::save(2, [
    'consent_integration' => 'wp_consent_api',
], []);

assert_same('off', wp_slimstat::$settings['use_slimstat_banner'], 'use_slimstat_banner should sync to off when consent_integration is not slimstat_banner');

// ─── Test 10: Tracking method change triggers rewrite flush ─────────

$_rewrite_flushed = false;
// Override update_option to detect the flush trigger
// (already stubbed, we check the setting directly)
wp_slimstat::$settings = ['tracking_request_method' => 'rest'];

$result = SettingsSaveService::save(2, [
    'tracking_request_method' => 'ajax',
], []);

// The service calls update_option('slimstat_permalink_structure_updated', true)
// In tests this is a no-op, but we verify the setting was saved
assert_same('ajax', wp_slimstat::$settings['tracking_request_method'], 'tracking_request_method should be updated');

// ─── Test 11: Empty messages array on clean save ────────────────────

wp_slimstat::$settings = ['some_setting' => 'old'];

$result = SettingsSaveService::save(1, [
    'some_setting' => 'new',
], []);

assert_true($result['success'], 'clean save should succeed');
assert_same([], $result['messages'], 'clean save should have no messages');
assert_same('new', wp_slimstat::$settings['some_setting'], 'setting should be updated');

// ─── Test 12: Multiple fields in one save ───────────────────────────

wp_slimstat::$settings = [
    'opt_out_message' => '',
    'custom_css'      => '',
    'session_duration' => '1800',
];

$result = SettingsSaveService::save(1, [
    'opt_out_message'  => '<p>Banner text</p>',
    'custom_css'       => '.report { display: block; }',
    'session_duration' => '7200',
], []);

assert_true($result['success'], 'multi-field save should succeed');
assert_contains('<p>', wp_slimstat::$settings['opt_out_message'], 'rich_text preserved in multi-field save');
assert_contains('display: block', wp_slimstat::$settings['custom_css'], 'code_editor preserved in multi-field save');
assert_same('7200', wp_slimstat::$settings['session_duration'], 'text field updated in multi-field save');

// ─── Test 13: Base64-decodable content round-trips correctly ────────
// Simulates what the REST controller does with encoded_options

$original_options = [
    'opt_out_message' => '<p>This site uses <a href="/privacy">cookies</a></p>',
    'custom_css'      => '.slimstat-report { color: #21759b; font-size: 14px; }',
    'ignore_referers' => 'https://spam.example.com*',
];

// Encode like the JS does: btoa(unescape(encodeURIComponent(JSON.stringify(options))))
$json = json_encode($original_options);
$encoded = base64_encode($json);
$decoded = json_decode(base64_decode($encoded, true), true);

assert_same($original_options, $decoded, 'base64 round-trip should preserve all field values exactly');

// Now save the decoded options
wp_slimstat::$settings = ['opt_out_message' => '', 'custom_css' => '', 'ignore_referers' => ''];

$result = SettingsSaveService::save(3, $decoded, []);

assert_true($result['success'], 'save from base64-decoded options should succeed');
assert_contains('<a href="/privacy">', wp_slimstat::$settings['opt_out_message'], 'rich_text from base64 preserves HTML');
assert_contains('color: #21759b', wp_slimstat::$settings['custom_css'], 'code_editor from base64 preserves CSS');

// ─── Done ───────────────────────────────────────────────────────────

echo "All {$assertions} assertions passed in " . basename(__FILE__) . "\n";
