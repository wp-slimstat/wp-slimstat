<?php

/**
 * Integration test for output escaping in wp_slimstat_reports::raw_results_to_html()
 *
 * Covers: #243, #244 — Defense-in-depth output escaping for reports default case
 * Source: admin/view/wp-slimstat-reports.php (default case + filter link wrapper)
 *
 * This test boots a minimal WordPress core environment so the real esc_url() is available,
 * loads the real wp_slimstat_reports class, calls raw_results_to_html() with test data
 * containing XSS payloads, captures the rendered HTML via ob_start()/ob_get_clean(), and
 * asserts the output is safely escaped.
 *
 * Run: php tests/reports-output-escaping-test.php
 */

declare(strict_types=1);

$default_wp_load_path = dirname(__DIR__, 4) . '/wp-load.php';
$wp_load_override     = getenv('TESTS_WP_LOAD_PATH');
$wp_load_path         = (false !== $wp_load_override && '' !== trim($wp_load_override)) ? trim($wp_load_override) : $default_wp_load_path;

if (is_dir($wp_load_path)) {
    $wp_load_path = rtrim($wp_load_path, '/\\') . '/wp-load.php';
}

if (!file_exists($wp_load_path)) {
    fwrite(STDERR, "ERROR: wp-load.php not found at {$wp_load_path}. Set TESTS_WP_LOAD_PATH to an absolute wp-load.php path or WordPress root.\n");
    exit(1);
}

if (!defined('SHORTINIT')) {
    define('SHORTINIT', true);
}

if (!defined('WP_ADMIN')) {
    define('WP_ADMIN', true);
}

require_once $wp_load_path;
require_once ABSPATH . WPINC . '/http.php';
require_once ABSPATH . WPINC . '/kses.php';
// SHORTINIT does not load the block parser stack needed by the default pre_kses hook.
remove_all_filters('pre_kses');

if (!defined('DOING_AJAX')) {
    define('DOING_AJAX', false);
}

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
// Only the functions/classes that the report renderer reaches in this test are stubbed.

if (!function_exists('__')) {
    function __($text, $domain = 'default')
    {
        return $text;
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '')
    {
        return 'http://example.com/wp-admin/' . $path;
    }
}

if (!function_exists('is_rtl')) {
    function is_rtl()
    {
        return false;
    }
}

if (!class_exists('wp_slimstat')) {
    class wp_slimstat
    {
        public static $settings = [
            'async_load'                     => 'off',
            'rows_to_show'                   => 20,
            'show_hits'                      => 'off',
            'show_display_name'              => 'off',
            'show_complete_user_agent_tooltip' => 'off',
            'limit_results'                  => 0,
        ];
    }
}

if (!class_exists('wp_slimstat_db')) {
    class wp_slimstat_db
    {
        public static $debug_message      = '';
        public static $pageviews          = 100;
        public static $filters_normalized = ['utime' => [], 'columns' => [], 'misc' => ['start_from' => 0]];

        /**
         * Mirror the real parser's split-then-decode behavior so delimiter bugs surface here.
         */
        public static function parse_filters($filters_string)
        {
            $result = ['columns' => [], 'date' => [], 'misc' => []];

            if (!empty($filters_string)) {
                foreach (explode('&&&', $filters_string) as $match) {
                    if (!preg_match('/([^\s]+)\s([^\s]+)\s(.+)?/', urldecode($match), $parts)) {
                        continue;
                    }

                    if (isset($parts[1], $parts[2])) {
                        $result['columns'][$parts[1]] = [$parts[2], $parts[3] ?? ''];
                    }
                }
            }

            return $result;
        }
    }
}

if (!class_exists('wp_slimstat_admin')) {
    class wp_slimstat_admin
    {
        public static $current_screen = 'slimview1';
    }
}

if (!class_exists('wp_slimstat_i18n')) {
    class wp_slimstat_i18n
    {
        public static function get_string($code)
        {
            return $code;
        }
    }
}

function make_data_callback(array $test_data): array
{
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

require_once __DIR__ . '/../admin/view/wp-slimstat-reports.php';

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

function extract_href(string $html): string
{
    if (preg_match("/href='([^']+)'/", $html, $matches)) {
        return $matches[1];
    }

    return '';
}

// Test 1: Clean fingerprint value passes through the default case.
$html = render_column('fingerprint', 'abc123def456');
assert_contains('abc123def456', $html, 'Clean fingerprint must appear in rendered output');
assert_contains('slimstat-filter-link', $html, 'Filter link must be rendered');
assert_contains('fingerprint', $html, 'href must reference fingerprint column');

// Test 2: Script tag in fingerprint is escaped via esc_html() in the default case.
$html = render_column('fingerprint', '<script>alert(1)</script>');
assert_not_contains('<script>', $html, 'Raw script tag must not appear in rendered output');
assert_contains('&lt;script&gt;', $html, 'Script tag must be HTML-entity-escaped');
assert_contains('slimstat-filter-link', $html, 'Filter link must still render');

// Test 3: IMG onerror payload is escaped.
$html = render_column('fingerprint', '<img src=x onerror=alert(1)>');
assert_not_contains('<img src=x', $html, 'Raw img tag must not appear in output');
assert_contains('&lt;img', $html, 'IMG tag must be HTML-escaped');

// Test 4: SVG onload payload is escaped.
$html = render_column('fingerprint', '"><svg/onload=alert(1)>');
assert_not_contains('<svg', $html, 'Raw SVG tag must not appear in output');
assert_contains('&lt;svg', $html, 'SVG tag must be HTML-escaped');

// Test 5: Single-quote attribute injection is escaped.
$html = render_column('fingerprint', "' onclick='alert(1)'");
assert_not_contains("onclick='alert(1)'", $html, 'Raw onclick must not appear in output');

// Test 6: The filter link href uses real esc_url() output and is not double-escaped.
$html = render_column('fingerprint', 'testvalue');
$href = extract_href($html);
assert_contains('&#038;fs%5Bfingerprint%5D=', $href, 'Real esc_url() must HTML-escape query separators');
assert_not_contains('&amp;amp;', $html, 'href must not be double-escaped');

// Test 7: Filter values are pre-encoded before fs_url() so parse_filters preserves delimiters.
$html = render_column('fingerprint', 'alpha&&&beta=gamma');
$href = extract_href($html);
assert_not_contains('alpha&&&beta', $href, 'Raw filter delimiter must not leak into href');
assert_contains('alpha%26%26%26beta%253Dgamma', $href, 'Delimiter and equals sign must survive the parse_filters round-trip');

// Test 8: Empty fingerprint value renders safely.
$html = render_column('fingerprint', '');
assert_true(is_string($html), 'Empty fingerprint must not crash renderer');

// Test 9: Long fingerprint value (256 chars) renders without truncation in link text.
$long_value = str_repeat('x', 256);
$html = render_column('fingerprint', $long_value);
assert_contains($long_value, $html, 'Full 256-char fingerprint must appear in output');

// Test 10: Unicode characters are preserved in rendered output.
$html = render_column('fingerprint', '日本語test');
assert_contains('日本語test', $html, 'Unicode characters must be preserved');

// Test 11: Pre-escaped HTML entities remain inert under the real esc_html() behavior.
$html = render_column('fingerprint', '&lt;script&gt;');
assert_contains('&lt;script&gt;', $html, 'Pre-escaped entities must remain escaped');
assert_not_contains('<script>', $html, 'Escaped entities must not produce raw tags');

// Test 12: The default case applies to other unhandled columns too.
$html = render_column('notes', '<b>bold</b>injected');
assert_not_contains('<b>bold</b>', $html, 'Notes column must also be escaped via the default case');
assert_contains('&lt;b&gt;bold&lt;/b&gt;', $html, 'Notes must be HTML-escaped');

// Test 13: Null bytes do not bypass escaping.
$html = render_column('fingerprint', "test\x00<script>xss</script>");
assert_not_contains('<script>xss</script>', $html, 'Null byte must not bypass escaping');

echo "All {$assertions} assertions passed in reports-output-escaping-test.php\n";
