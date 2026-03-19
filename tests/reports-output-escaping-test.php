<?php

/**
 * Integration test for output escaping in wp_slimstat_reports::raw_results_to_html()
 *
 * Covers: #243, #244 — Defense-in-depth output escaping for reports default case
 * Source: admin/view/wp-slimstat-reports.php (default case + filter link wrapper)
 *
 * This test boots a minimal stub environment, loads the real wp_slimstat_reports class,
 * calls raw_results_to_html() with test data containing XSS payloads, captures the
 * rendered HTML via ob_start()/ob_get_clean(), and asserts the output is safely escaped.
 *
 * Run: php tests/reports-output-escaping-test.php
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
    if ($actual !== true) {
        fwrite(STDERR, "FAIL: {$message} (expected true, got " . var_export($actual, true) . ")\n");
        exit(1);
    }
}

function assert_contains(string $needle, string $haystack, string $message): void
{
    global $assertions;
    $assertions++;
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "FAIL: {$message}\n  Expected to contain: '{$needle}'\n  In: " . substr($haystack, 0, 500) . "\n");
        exit(1);
    }
}

function assert_not_contains(string $needle, string $haystack, string $message): void
{
    global $assertions;
    $assertions++;
    if (strpos($haystack, $needle) !== false) {
        fwrite(STDERR, "FAIL: {$message}\n  Expected NOT to contain: '{$needle}'\n  In: " . substr($haystack, 0, 500) . "\n");
        exit(1);
    }
}

// ─── Minimal WordPress + SlimStat stubs ─────────────────────────────
// Only stubs that raw_results_to_html() actually calls are defined here.

if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', false);
}

if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8', true);
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8', true);
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url)
    {
        // Simplified: just ensure it's a string — real WP filters schemes etc.
        return (string) $url;
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post($data)
    {
        return $data;
    }
}

if (!function_exists('__')) {
    function __($text, $domain = 'default')
    {
        return $text;
    }
}

if (!function_exists('is_admin')) {
    function is_admin()
    {
        return true; // Simulate admin context — this is what triggers the filter link at line 1396
    }
}

if (!function_exists('get_option')) {
    function get_option($key, $default = false)
    {
        if ($key === 'permalink_structure') {
            return '/%postname%/';
        }
        if ($key === 'date_format') {
            return 'Y-m-d';
        }
        if ($key === 'time_format') {
            return 'H:i';
        }
        return $default;
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '')
    {
        return 'http://example.com/wp-admin/' . $path;
    }
}

if (!function_exists('sanitize_url')) {
    function sanitize_url($url)
    {
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('number_format_i18n')) {
    function number_format_i18n($number, $decimals = 0)
    {
        return number_format((float) $number, $decimals);
    }
}

if (!function_exists('is_rtl')) {
    function is_rtl()
    {
        return false;
    }
}

if (!function_exists('date_i18n')) {
    function date_i18n($format, $timestamp = false, $gmt = false)
    {
        return date($format, $timestamp ?: time());
    }
}

// ─── wp_slimstat stub ───────────────────────────────────────────────

if (!class_exists('wp_slimstat')) {
    class wp_slimstat
    {
        public static $settings = [
            'async_load'     => 'off', // Must be 'off' so raw_results_to_html doesn't early-return
            'rows_to_show'   => 20,
            'show_hits'      => 'off',
            'show_display_name' => 'off',
            'show_complete_user_agent_tooltip' => 'off',
            'limit_results'  => 0,
        ];
    }
}

// ─── wp_slimstat_db stub ────────────────────────────────────────────

if (!class_exists('wp_slimstat_db')) {
    class wp_slimstat_db
    {
        public static $debug_message = '';
        public static $pageviews     = 100;
        public static $filters_normalized = ['utime' => [], 'columns' => [], 'misc' => ['start_from' => 0]];

        /**
         * parse_filters is called by fs_url() to build URL parameters.
         * Return a minimal structure matching what the real function returns.
         */
        public static function parse_filters($filters_string)
        {
            $result = ['columns' => [], 'date' => [], 'misc' => []];

            // Parse "column operator value" triples
            if (!empty($filters_string)) {
                $parts = explode(' ', $filters_string, 3);
                if (count($parts) === 3) {
                    $result['columns'][$parts[0]] = [$parts[1], $parts[2]];
                }
            }

            return $result;
        }
    }
}

// ─── wp_slimstat_admin stub ─────────────────────────────────────────

if (!class_exists('wp_slimstat_admin')) {
    class wp_slimstat_admin
    {
        public static $current_screen = 'slimview1';
    }
}

// ─── wp_slimstat_i18n stub ──────────────────────────────────────────

if (!class_exists('wp_slimstat_i18n')) {
    class wp_slimstat_i18n
    {
        public static function get_string($code)
        {
            return $code;
        }
    }
}

// ─── Test data provider ─────────────────────────────────────────────

/**
 * Returns a callback array that raw_results_to_html() expects as $_args['raw'].
 * The callback returns $test_data when called.
 */
function make_data_callback(array $test_data): array
{
    // Store test data in a global so our callback can access it
    $GLOBALS['__test_report_data'] = $test_data;
    return ['TestDataProvider', 'getData'];
}

class TestDataProvider
{
    public static function getData($args)
    {
        return $GLOBALS['__test_report_data'];
    }
}

// ─── Load the real wp_slimstat_reports class ────────────────────────

require_once __DIR__ . '/../admin/view/wp-slimstat-reports.php';

// ─── Helper: render a single column value through raw_results_to_html() ──

/**
 * Render a single-row report with the given column and value.
 * Returns the captured HTML output.
 */
function render_column(string $column, string $value): string
{
    $test_data = [
        [$column => $value, 'counthits' => 1],
    ];

    ob_start();
    wp_slimstat_reports::raw_results_to_html([
        'columns'   => $column,
        'type'      => 'top',
        'raw'       => make_data_callback($test_data),
        'where'     => '',
        'filter_op' => 'equals',
    ]);
    return ob_get_clean();
}

// ═══════════════════════════════════════════════════════════════════
// TESTS: Exercise the REAL raw_results_to_html() with XSS payloads
// ═══════════════════════════════════════════════════════════════════

// Test 1: Clean fingerprint value passes through the default case
$html = render_column('fingerprint', 'abc123def456');
assert_contains('abc123def456', $html, 'Clean fingerprint must appear in rendered output');
assert_contains('slimstat-filter-link', $html, 'Filter link must be rendered');
assert_contains('fingerprint', $html, 'href must reference fingerprint column');

// Test 2: Script tag in fingerprint is escaped via esc_html() in default case
$html = render_column('fingerprint', '<script>alert(1)</script>');
assert_not_contains('<script>', $html, 'Raw script tag must not appear in rendered output');
assert_contains('&lt;script&gt;', $html, 'Script tag must be HTML-entity-escaped');
assert_contains('slimstat-filter-link', $html, 'Filter link must still render');

// Test 3: IMG onerror payload is escaped
$html = render_column('fingerprint', '<img src=x onerror=alert(1)>');
assert_not_contains('<img src=x', $html, 'Raw img tag must not appear in output');
assert_contains('&lt;img', $html, 'IMG tag must be HTML-escaped');

// Test 4: SVG onload payload is escaped
$html = render_column('fingerprint', '"><svg/onload=alert(1)>');
assert_not_contains('<svg', $html, 'Raw SVG tag must not appear in output');
assert_contains('&lt;svg', $html, 'SVG tag must be HTML-escaped');

// Test 5: Single-quote attribute injection is escaped
$html = render_column('fingerprint', "' onclick='alert(1)'");
assert_not_contains("onclick='alert(1)'", $html, 'Raw onclick must not appear in output');

// Test 6: The filter link href uses fs_url() (which returns esc_url() output)
// and is NOT double-escaped with esc_attr()
$html = render_column('fingerprint', 'testvalue');
// fs_url() builds URLs with &amp; separators — if esc_attr() were wrapping it,
// we'd see &amp;amp; (double-escaped). Verify NO double-escaping.
assert_not_contains('&amp;amp;', $html, 'href must NOT be double-escaped (no &amp;amp;)');

// Test 7: Empty fingerprint value renders safely
$html = render_column('fingerprint', '');
// Rendering may or may not produce output (empty value), but must not crash
assert_true(is_string($html), 'Empty fingerprint must not crash renderer');

// Test 8: Long fingerprint value (256 chars) renders without truncation in link text
$long_value = str_repeat('x', 256);
$html = render_column('fingerprint', $long_value);
assert_contains($long_value, $html, 'Full 256-char fingerprint must appear in output');

// Test 9: Unicode characters preserved in rendered output
$html = render_column('fingerprint', '日本語test');
assert_contains('日本語test', $html, 'Unicode characters must be preserved');

// Test 10: Pre-escaped HTML entities are double-escaped (correct behavior)
$html = render_column('fingerprint', '&lt;script&gt;');
assert_contains('&amp;lt;script&amp;gt;', $html, 'Pre-escaped entities must be double-escaped');
assert_not_contains('<script>', $html, 'Double-escaped string must not produce raw tags');

// Test 11: The default case applies to other unhandled columns too (e.g. 'notes')
$html = render_column('notes', '<b>bold</b>injected');
assert_not_contains('<b>bold</b>', $html, 'Notes column must also be escaped via default case');
assert_contains('&lt;b&gt;bold&lt;/b&gt;', $html, 'Notes must be HTML-escaped');

// Test 12: Null byte in value doesn't bypass escaping
$html = render_column('fingerprint', "test\x00<script>xss</script>");
assert_not_contains('<script>xss</script>', $html, 'Null byte must not bypass escaping');

echo "All {$assertions} assertions passed in reports-output-escaping-test.php\n";
