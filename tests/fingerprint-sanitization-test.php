<?php

/**
 * Tests for fingerprint input sanitization (strict alphanumeric regex).
 *
 * Covers: #244 — CVE-2026-1238 defense-in-depth input hardening
 * Sources:
 *   - src/Controllers/Rest/TrackingRestController.php::sanitize_fingerprint_param()
 *   - src/Tracker/Tracker.php (line ~357)
 *   - src/Tracker/Utils.php (line ~377)
 *   - src/Tracker/Ajax.php (line ~324, reference implementation)
 *
 * Run: php tests/fingerprint-sanitization-test.php
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

// ─── WordPress stub ────────────────────────────────────────────────

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str)
    {
        $str = (string) $str;
        $str = strip_tags($str);
        $str = trim($str);
        $str = preg_replace('/[\r\n\t ]+/', ' ', $str);
        return $str;
    }
}

// ─── Replicate the sanitization logic under test ───────────────────
// This is the exact logic used in TrackingRestController::sanitize_fingerprint_param(),
// Tracker.php, and Utils.php.

function sanitize_fingerprint(string $value): string
{
    $value = preg_replace('/[^a-zA-Z0-9\-_]/', '', $value);
    if (strlen($value) > 256) {
        $value = substr($value, 0, 256);
    }
    return sanitize_text_field($value);
}

// ─── Test Cases ────────────────────────────────────────────────────

// Test 1: Valid FingerprintJS v4 hash (32-char hex) passes through unchanged
$result = sanitize_fingerprint('a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6');
assert_same('a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6', $result, 'Valid 32-char hex fingerprint should pass through');

// Test 2: Valid fingerprint with dashes and underscores
$result = sanitize_fingerprint('fp-abc123_def456');
assert_same('fp-abc123_def456', $result, 'Dashes and underscores should be preserved');

// Test 3: Script tag completely stripped
$result = sanitize_fingerprint('<script>alert(1)</script>');
assert_same('scriptalert1script', $result, 'Script tag chars should be stripped, only alphanumeric remains');

// Test 4: IMG onerror payload stripped
$result = sanitize_fingerprint('"><img src=x onerror=alert(1)>');
assert_same('imgsrcxonerroralert1', $result, 'IMG onerror payload should be stripped to alphanumeric');

// Test 5: Single quotes stripped
$result = sanitize_fingerprint("' onclick='alert(1)'");
assert_same('onclickalert1', $result, 'Single quotes should be stripped');

// Test 6: Double quotes stripped
$result = sanitize_fingerprint('" onmouseover="alert(1)"');
assert_same('onmouseoveralert1', $result, 'Double quotes should be stripped');

// Test 7: Empty string
$result = sanitize_fingerprint('');
assert_same('', $result, 'Empty string should remain empty');

// Test 8: Special characters only (result: empty after regex)
$result = sanitize_fingerprint('!@#$%^&*()+=[]{}|;:,.<>?/');
assert_same('', $result, 'All special chars should be stripped');

// Test 9: Length truncation at 256
$long_value = str_repeat('a', 300);
$result = sanitize_fingerprint($long_value);
assert_same(256, strlen($result), 'Result should be truncated to 256 chars');
assert_same(str_repeat('a', 256), $result, 'Truncated value should be first 256 chars');

// Test 10: Exactly 256 chars passes through
$exact_value = str_repeat('b', 256);
$result = sanitize_fingerprint($exact_value);
assert_same($exact_value, $result, '256-char value should pass through unchanged');

// Test 11: Unicode stripped (only ASCII alphanumeric allowed)
$result = sanitize_fingerprint('fp-日本語-test');
assert_same('fp--test', $result, 'Unicode chars should be stripped');

// Test 12: SQL injection payload stripped
$result = sanitize_fingerprint("Robert'); DROP TABLE wp_slim_stats;--");
assert_same('RobertDROPTABLEwp_slim_stats--', $result, 'SQL injection special chars stripped');

// Test 13: Null byte stripped
$result = sanitize_fingerprint("test\x00payload");
assert_same('testpayload', $result, 'Null byte should be stripped');

// Test 14: SVG onload payload stripped
$result = sanitize_fingerprint('"><svg/onload=alert(1)>');
assert_same('svgonloadalert1', $result, 'SVG onload payload should be stripped');

// Test 15: Base64-encoded payload (alphanumeric chars preserved, + and = stripped)
$result = sanitize_fingerprint('PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==');
assert_same('PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg', $result, 'Base64 alpha chars preserved, padding stripped');

// Test 16: Mixed valid fingerprint with trailing XSS
$result = sanitize_fingerprint('abc123<script>alert(1)</script>');
assert_same('abc123scriptalert1script', $result, 'Valid prefix preserved, tag chars stripped');

echo "All {$assertions} assertions passed in fingerprint-sanitization-test.php\n";
