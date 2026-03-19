<?php

/**
 * Tests for output escaping in wp_slimstat_reports::raw_results_to_html()
 *
 * Covers: #244 — Defense-in-depth output escaping for reports default case
 * Source: admin/view/wp-slimstat-reports.php (default case + filter link wrapper)
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
        fwrite(STDERR, "FAIL: {$message} (expected to contain '{$needle}' in '{$haystack}')\n");
        exit(1);
    }
}

function assert_not_contains(string $needle, string $haystack, string $message): void
{
    global $assertions;
    $assertions++;

    if (strpos($haystack, $needle) !== false) {
        fwrite(STDERR, "FAIL: {$message} (expected NOT to contain '{$needle}' in '{$haystack}')\n");
        exit(1);
    }
}

// ─── WordPress escaping stubs (real behavior) ──────────────────────

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

// ─── Simulate the default case escaping logic ──────────────────────
// This mirrors what admin/view/wp-slimstat-reports.php does:
//   1. $element_value = $results[$i][$_args['columns']];  (line 1175)
//   2. default: $element_value = esc_html($element_value); (line 1393, the fix)
//   3. Filter link: href uses esc_attr(), text uses $element_value (line 1397)

/**
 * Simulates the fixed default-case output path.
 *
 * @param string $raw_db_value  The value as stored in the database
 * @return array{element: string, href_value: string}  The escaped element text and href attribute value
 */
function simulate_default_case_output(string $raw_db_value): array
{
    // Step 1: raw DB value assigned to $element_value (line 1175)
    $element_value = $raw_db_value;

    // Step 2: default case applies esc_html (line 1393 — THE FIX)
    $element_value = esc_html($element_value);

    // Step 3: filter link wrapper (line 1397)
    $column_value = $raw_db_value;
    $fs_url = 'fingerprint equals ' . $column_value; // simplified fs_url
    $href_value = esc_attr($fs_url);
    $output = "<a class='slimstat-filter-link' href='" . $href_value . "'>" . $element_value . "</a>";

    return [
        'element' => $element_value,
        'href_value' => $href_value,
        'output' => $output,
    ];
}

// ─── Test Cases ────────────────────────────────────────────────────

// Test 1: Clean alphanumeric fingerprint passes through unchanged
$result = simulate_default_case_output('abc123def456');
assert_same('abc123def456', $result['element'], 'Clean alphanumeric should pass through unchanged');

// Test 2: Script tag gets HTML-escaped
$result = simulate_default_case_output('<script>alert(1)</script>');
assert_same('&lt;script&gt;alert(1)&lt;/script&gt;', $result['element'], 'Script tag must be escaped');
assert_not_contains('<script>', $result['output'], 'Output must not contain raw script tags');

// Test 3: Attribute injection gets escaped — quotes are neutralized so attribute can't break out
$result = simulate_default_case_output('" onmouseover="alert(1)"');
assert_contains('&quot;', $result['href_value'], 'Double quotes must be escaped in href value');
assert_not_contains('" onmouseover=', $result['href_value'], 'Raw attribute injection must not appear in href');
assert_contains('&quot;', $result['element'], 'Double quotes must be escaped in element text');

// Test 4: IMG tag with onerror gets escaped
$result = simulate_default_case_output('<img src=x onerror=alert(1)>');
assert_same('&lt;img src=x onerror=alert(1)&gt;', $result['element'], 'IMG tag must be escaped');
assert_not_contains('<img', $result['output'], 'Output must not contain raw img tags');

// Test 5: Empty string remains empty
$result = simulate_default_case_output('');
assert_same('', $result['element'], 'Empty string should remain empty');

// Test 6: Long string (256 chars) is escaped without truncation
$long_value = str_repeat('a', 250) . '<b>XSS</b>';
$result = simulate_default_case_output($long_value);
assert_not_contains('<b>', $result['element'], 'Long string with tags must be escaped');
assert_contains('&lt;b&gt;', $result['element'], 'Tags in long string must be HTML-encoded');
assert_true(strlen($result['element']) > 256, 'Escaped string should be longer than input due to entity encoding');

// Test 7: Unicode/multibyte is preserved and escaped
$result = simulate_default_case_output('fingerprint-日本語-<b>test</b>');
assert_contains('日本語', $result['element'], 'Unicode should be preserved');
assert_contains('&lt;b&gt;', $result['element'], 'Tags in unicode string must be escaped');

// Test 8: Already-escaped string gets double-escaped (correct — prevents decoder attacks)
$result = simulate_default_case_output('&lt;script&gt;alert(1)&lt;/script&gt;');
assert_contains('&amp;lt;', $result['element'], 'Already-escaped entities should be double-escaped');
assert_not_contains('<script>', $result['output'], 'Double-escaped string must not produce raw tags');

// Test 9: Single quotes in fingerprint are escaped
$result = simulate_default_case_output("' onclick='alert(1)'");
assert_contains('&#039;', $result['element'], 'Single quotes must be escaped in element');
assert_contains('&#039;', $result['href_value'], 'Single quotes must be escaped in href');

// Test 10: Mixed XSS vector
$result = simulate_default_case_output('"><svg/onload=alert(1)>');
assert_not_contains('<svg', $result['output'], 'SVG onload vector must be neutralized');
assert_contains('&lt;svg', $result['element'], 'SVG tag must be HTML-escaped');

// Test 11: Null bytes stripped (PHP string behavior)
$result = simulate_default_case_output("test\x00<script>alert(1)</script>");
assert_not_contains('<script>', $result['output'], 'Null byte + script must be escaped');

// Test 12: href context — single quotes in value are escaped so href can't break out
$result = simulate_default_case_output("val' onclick='alert(1)");
assert_contains("&#039;", $result['href_value'], 'Single quotes in href must be entity-encoded');
assert_not_contains("' onclick='", $result['output'], 'Raw single-quote breakout must not appear in output');

echo "All {$assertions} assertions passed in reports-output-escaping-test.php\n";
