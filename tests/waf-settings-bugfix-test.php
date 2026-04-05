<?php
/**
 * Tests for WAF Settings Bugfixes (Issues 1-5)
 *
 * These tests verify fixes for critical/high issues found in the
 * REST API settings save implementation (PR #286).
 *
 * @since 5.4.10
 * @see   https://github.com/wp-slimstat/wp-slimstat/pull/286
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

function assert_false($actual, string $message): void
{
    global $assertions;
    $assertions++;
    if (false !== $actual) {
        fwrite(STDERR, "FAIL: {$message} (expected false, got " . var_export($actual, true) . ")\n");
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
if (!function_exists('__')) {
    function __($text) { return $text; }
}

// Track which option function was used for persistence
$_test_persist_method = null;

if (!function_exists('is_network_admin')) {
    function is_network_admin() { return false; }
}

// Stub wp_slimstat class
if (!class_exists('wp_slimstat')) {
    class wp_slimstat
    {
        public static $settings = [];
        public static $upload_dir = '/tmp';
        public static $wpdb;
        public static $save_context = [];

        public static function update_option($key, $value, bool $is_network = false)
        {
            global $_test_persist_method;
            $_test_persist_method = $is_network ? 'update_site_option' : 'update_option';
        }

        public static function resolve_geolocation_provider()
        {
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

require_once __DIR__ . '/../src/Services/SettingsSaveService.php';

use SlimStat\Services\SettingsSaveService;

// ═══════════════════════════════════════════════════════════════════
// Issue 1: Multisite — is_network parameter propagation
// ═══════════════════════════════════════════════════════════════════

// ─── Test 1.1: Network flags set when is_network=true ───────────

wp_slimstat::$settings = ['some_setting' => 'old'];

$result = SettingsSaveService::save(1, [
    'some_setting' => 'new',
    'addon_network_settings_some_setting' => 'on',
], [], true); // is_network = true

assert_true($result['success'], 'Issue 1.1: save with is_network should succeed');
assert_same('on', wp_slimstat::$settings['addon_network_settings_some_setting'] ?? 'MISSING',
    'Issue 1.1: network override flag should be set when is_network=true');

// ─── Test 1.2: Network flags NOT set when is_network=false ──────

wp_slimstat::$settings = ['other_setting' => 'val'];

$result = SettingsSaveService::save(1, [
    'other_setting' => 'val2',
    'addon_network_settings_other_setting' => 'on',
], [], false); // is_network = false

assert_false(isset(wp_slimstat::$settings['addon_network_settings_other_setting']),
    'Issue 1.2: network override flag should NOT be set when is_network=false');

// ─── Test 1.3: Persist uses network option when is_network=true ──

global $_test_persist_method;
$_test_persist_method = null;
wp_slimstat::$settings = ['test_field' => 'a'];

SettingsSaveService::save(1, ['test_field' => 'b'], [], true);

assert_same('update_site_option', $_test_persist_method,
    'Issue 1.3: should use update_site_option when is_network=true');

// ─── Test 1.4: Persist uses blog option when is_network=false ───

$_test_persist_method = null;
wp_slimstat::$settings = ['test_field' => 'a'];

SettingsSaveService::save(1, ['test_field' => 'b'], [], false);

assert_same('update_option', $_test_persist_method,
    'Issue 1.4: should use update_option when is_network=false');

// ═══════════════════════════════════════════════════════════════════
// Issue 2: Save context for Pro filter compatibility
// ═══════════════════════════════════════════════════════════════════

// ─── Test 2.1: Save context is set before slimstat_save_options ──

// Override apply_filters to capture context
$_captured_context = null;

// We can't easily override apply_filters since it's already defined.
// Instead, check that wp_slimstat::$save_context is set before save returns.
wp_slimstat::$save_context = [];
wp_slimstat::$settings = ['field' => 'val'];

SettingsSaveService::save(2, ['field' => 'new'], []);

assert_true(!empty(wp_slimstat::$save_context), 'Issue 2.1: save_context should be set during save');
assert_same(2, wp_slimstat::$save_context['tab'] ?? null, 'Issue 2.1: save_context should contain tab number');
assert_true(isset(wp_slimstat::$save_context['via']), 'Issue 2.1: save_context should contain source (via)');

// ═══════════════════════════════════════════════════════════════════
// Issue 3: Settings definitions passed for Pro addon fields
// ═══════════════════════════════════════════════════════════════════

// ─── Test 3.1: Pro addon code_editor field uses wp_strip_all_tags ─

wp_slimstat::$settings = ['addon_heatmap_custom_css' => ''];

$pro_settings_defs = [
    7 => [
        'rows' => [
            'addon_heatmap_custom_css' => [
                'type'            => 'textarea',
                'use_code_editor' => 'css',
            ],
        ],
    ],
];

$css_with_html = '.heatmap { color: red; } <script>bad</script>';
$result = SettingsSaveService::save(7, [
    'addon_heatmap_custom_css' => $css_with_html,
], $pro_settings_defs);

assert_true($result['success'], 'Issue 3.1: save with Pro settings_defs should succeed');
assert_contains('color: red', wp_slimstat::$settings['addon_heatmap_custom_css'],
    'Issue 3.1: Pro code_editor field should preserve CSS');
assert_same(false, strpos(wp_slimstat::$settings['addon_heatmap_custom_css'], '<script>') !== false,
    'Issue 3.1: Pro code_editor field should strip HTML tags');

// ─── Test 3.2: Without settings_defs, Pro CSS field gets sanitize_text_field ─
// This demonstrates the problem when REST doesn't pass settings_defs

wp_slimstat::$settings = ['addon_heatmap_custom_css' => ''];

$result = SettingsSaveService::save(7, [
    'addon_heatmap_custom_css' => '.heatmap { color: red; }',
], []); // No settings_defs — REST path without fix

// With no settings_defs AND field not in built-in lists,
// sanitize_text_field is used which strips { } and other chars
// This test verifies the field is saved (even if sanitized differently)
assert_true($result['success'], 'Issue 3.2: save without settings_defs should succeed');

// ═══════════════════════════════════════════════════════════════════
// Issue 4 & 5: JS-only (page reload, HTML in messages)
// These are verified via E2E tests, not PHP unit tests.
// Document here for completeness.
// ═══════════════════════════════════════════════════════════════════

// ─── Test 4.1: Save messages with HTML are returned correctly ────
// (Verifies the PHP side returns HTML messages — JS rendering is separate)

wp_slimstat::$settings = ['db_indexes' => 'no'];

$result = SettingsSaveService::save(6, [
    'db_indexes' => 'on',
], []);

assert_true(count($result['messages']) > 0, 'Issue 4.1: DB index toggle should return a message');
assert_contains('<a href=', $result['messages'][0], 'Issue 4.1: DB index message should contain HTML link');

// ═══════════════════════════════════════════════════════════════════

echo "All {$assertions} assertions passed in " . basename(__FILE__) . "\n";
