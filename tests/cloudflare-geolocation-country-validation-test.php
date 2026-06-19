<?php

/**
 * Security test for CloudflareGeolocationProvider::locate() country validation.
 *
 * Covers: CF-IPCountry stored-XSS hardening (input side). The provider reads the
 * attacker-controlled CF-IPCountry header; sanitize_text_field() + strtoupper()
 * do NOT strip quotes, so a value like `"onload="alert(1)` survived to storage and
 * was later echoed unescaped into admin report image attributes. The provider now
 * rejects anything that is not a 2-character country code (`^[A-Z0-9]{2}$`),
 * preserving Cloudflare's special T1/A1/A2 (Tor/anon-proxy/satellite) codes while
 * dropping any quote-bearing payload before it can be stored.
 *
 * Loads the REAL provider class (not a copy) so the shipped validation is tested.
 *
 * Run: php tests/cloudflare-geolocation-country-validation-test.php
 *
 * @package WpSlimstat
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

// ─── Minimal WP stubs the provider relies on ───────────────────────
// Mirror sanitize_text_field: strip tags, trim, collapse internal whitespace.
// Crucially (like core) it does NOT remove quote characters.
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

if (!function_exists('wp_unslash')) {
    function wp_unslash($v)
    {
        return is_string($v) ? stripslashes($v) : $v;
    }
}

require_once dirname(__DIR__) . '/src/Services/Geolocation/Provider/GeoServiceProviderInterface.php';
require_once dirname(__DIR__) . '/src/Services/Geolocation/Provider/CloudflareGeolocationProvider.php';

use SlimStat\Services\Geolocation\Provider\CloudflareGeolocationProvider;

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

/**
 * Drive the real provider with a crafted CF-IPCountry header and return the
 * resolved country_code (or null). A valid CF-Ray header is always present so the
 * provider treats the request as Cloudflare-originated.
 */
function country_code_for($cf_ipcountry): ?string
{
    $_SERVER = [
        'HTTP_CF_RAY'       => '8aabbccddeeff001-SJC',
        'HTTP_CF_IPCOUNTRY' => $cf_ipcountry,
    ];
    $provider = new CloudflareGeolocationProvider(['precision' => 'country']);
    $result   = $provider->locate('203.0.113.5');
    return is_array($result) ? ($result['country_code'] ?? null) : null;
}

// Truth-table of [CF-IPCountry input, expected country_code, message]. null =
// rejected (XSS payload or non-2-alnum); a 2-char string = accepted/normalized.
$cases = [
    ['"onload=alert(1)',        null, 'double-quote breakout payload is rejected'],
    ["' onerror=alert(1)",      null, 'single-quote breakout payload is rejected'],
    ['"><svg/onload=alert(1)>', null, 'tag breakout payload is rejected'],
    ['<script>x</script>',      null, 'script payload is rejected'],
    ['USA',                     null, 'three-letter code is rejected'],
    ['U',                       null, 'one-letter code is rejected'],
    ["a8'",                     null, 'quote-bearing 3-char value is rejected'],
    ['U S',                     null, 'value with whitespace is rejected'],
    ['XX',                      null, 'unknown XX is dropped (existing behavior)'],
    ['',                        null, 'empty value is rejected'],
    ['US',                      'US', 'US is accepted'],
    ['gb',                      'GB', 'lowercase gb normalizes to GB'],
    [' de ',                    'DE', 'whitespace-padded de normalizes to DE'],
    ['T1',                      'T1', 'Cloudflare Tor code T1 is preserved'],
    ['A1',                      'A1', 'Cloudflare anonymous-proxy code A1 is preserved'],
    ['a2',                      'A2', 'Cloudflare satellite code A2 is preserved'],
];
foreach ($cases as [$input, $expected, $message]) {
    assert_same($expected, country_code_for($input), $message);
}

// ─── A non-Cloudflare request (no CF-Ray) yields no geolocation ────

$_SERVER  = ['HTTP_CF_IPCOUNTRY' => 'US'];
$provider = new CloudflareGeolocationProvider(['precision' => 'country']);
assert_same(null, $provider->locate('203.0.113.5'), 'missing CF-Ray header => provider returns null');

echo "All {$assertions} assertions passed in cloudflare-geolocation-country-validation-test.php\n";
