<?php
/**
 * Regression: GDPRService::getBannerHtml() must render cleanly when the
 * `gdpr_theme_mode` setting is absent (issue #325 hardening).
 *
 * Before the fix, `$this->settings['gdpr_theme_mode']` was read unguarded, so a
 * settings array without that key emitted an "Undefined array key" warning on
 * PHP 8. This test constructs the service without the key and fails on ANY
 * warning/notice/deprecation, and asserts no theme class leaks into the markup.
 *
 * 7.4-safe: plain PHP, no PHPUnit, no vendor autoload.
 */

declare(strict_types=1);

// GDPRService evaluates `const COOKIE_DURATION = 365 * DAY_IN_SECONDS` at class
// load, so the constant must exist before the require.
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

$assertions = 0;

function assert_true($cond, $message)
{
    global $assertions;
    $assertions++;
    if ($cond !== true) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function assert_contains($needle, $haystack, $message)
{
    global $assertions;
    $assertions++;
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "FAIL: {$message} (expected to find '{$needle}')\n");
        exit(1);
    }
}

function assert_not_contains($needle, $haystack, $message)
{
    global $assertions;
    $assertions++;
    if (strpos($haystack, $needle) !== false) {
        fwrite(STDERR, "FAIL: {$message} (did not expect '{$needle}')\n");
        exit(1);
    }
}

// Any warning/notice/deprecation from the render path is a failure — this is how
// the "no undefined-key warning" contract is enforced.
set_error_handler(function ($errno, $errstr) {
    fwrite(STDERR, "FAIL: PHP diagnostic emitted during banner render: {$errstr}\n");
    exit(1);
});

// No consent decision cookie → getBannerHtml() proceeds past its early return.
$_COOKIE = [];

// Minimal WordPress stubs (global namespace; GDPRService calls them unqualified).
if (!function_exists('__'))                 { function __($text, $domain = 'default') { return $text; } }
if (!function_exists('esc_html'))           { function esc_html($text) { return $text; } }
if (!function_exists('esc_attr'))           { function esc_attr($text) { return $text; } }
if (!function_exists('wp_kses'))            { function wp_kses($string, $allowed) { return $string; } }
if (!function_exists('wp_kses_post'))       { function wp_kses_post($string) { return $string; } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($str) { return $str; } }
if (!function_exists('wp_unslash'))         { function wp_unslash($value) { return $value; } }
if (!function_exists('apply_filters'))      { function apply_filters($hook, $value) { return $value; } }
// Intentionally do NOT define pll__(): GDPRService guards it with function_exists().

require_once __DIR__ . '/../src/Services/GDPRService.php';

// Case 1 — theme mode ABSENT: no warning (enforced by the handler), no theme class.
$service = new \SlimStat\Services\GDPRService(['use_slimstat_banner' => 'on']);
$html    = $service->getBannerHtml();

assert_true($html !== '', 'banner renders when no consent decision has been made');
assert_contains('slimstat-gdpr-banner', $html, 'banner container is present');
assert_not_contains('gdpr-dark-mode', $html, 'no theme class when gdpr_theme_mode is absent');
assert_not_contains('gdpr-light-mode', $html, 'no theme class when gdpr_theme_mode is absent');

// Case 2 — theme mode set: the positive path still emits the theme class.
$serviceDark = new \SlimStat\Services\GDPRService([
    'use_slimstat_banner' => 'on',
    'gdpr_theme_mode'     => 'dark',
]);
$htmlDark = $serviceDark->getBannerHtml();
assert_contains('gdpr-dark-mode', $htmlDark, 'dark theme class present when gdpr_theme_mode=dark');

restore_error_handler();

fwrite(STDOUT, "OK: {$assertions} assertions passed (GDPR banner renders warning-free without gdpr_theme_mode)\n");
exit(0);
