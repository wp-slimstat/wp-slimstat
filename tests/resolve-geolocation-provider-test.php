<?php

/**
 * Tests for wp_slimstat::resolve_geolocation_provider()
 *
 * Covers: PR #166 — GeoIP infinite AJAX loop fix
 * Source: wp-slimstat.php:359-379
 *
 * Run: php tests/resolve-geolocation-provider-test.php
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

// Mock WordPress sanitize_text_field: strip tags, trim whitespace, remove extra spaces
function sanitize_text_field($str)
{
    $str = (string) $str;
    $str = strip_tags($str);
    $str = trim($str);
    $str = preg_replace('/[\r\n\t ]+/', ' ', $str);
    return $str;
}

/**
 * Minimal wp_slimstat stub with the real resolve_geolocation_provider() logic.
 * Copied from wp-slimstat.php:359-379 (commit 7ba56338).
 */
class wp_slimstat
{
    public static $settings = [];

    public static function resolve_geolocation_provider()
    {
        if (isset(self::$settings['geolocation_provider'])) {
            $p = sanitize_text_field(self::$settings['geolocation_provider']);
            if ('disable' === $p) {
                return false;
            }
            if (in_array($p, ['maxmind', 'dbip', 'cloudflare'], true)) {
                return $p;
            }
            // Invalid/empty value — fall through to legacy flag
        }
        $em = self::$settings['enable_maxmind'] ?? 'disable';
        if ('on' === $em) {
            return 'maxmind';
        }
        if ('no' === $em) {
            return 'dbip';
        }
        return false;
    }
}

// Helper to reset settings between tests
function set_settings(array $settings): void
{
    wp_slimstat::$settings = $settings;
}

// ─── New geolocation_provider setting (explicit) ───────────────────

set_settings(['geolocation_provider' => 'maxmind', 'enable_maxmind' => 'on']);
assert_same('maxmind', wp_slimstat::resolve_geolocation_provider(), 'explicit maxmind returns maxmind');

set_settings(['geolocation_provider' => 'dbip', 'enable_maxmind' => 'no']);
assert_same('dbip', wp_slimstat::resolve_geolocation_provider(), 'explicit dbip returns dbip');

set_settings(['geolocation_provider' => 'cloudflare', 'enable_maxmind' => 'no']);
assert_same('cloudflare', wp_slimstat::resolve_geolocation_provider(), 'explicit cloudflare returns cloudflare');

set_settings(['geolocation_provider' => 'disable', 'enable_maxmind' => 'disable']);
assert_same(false, wp_slimstat::resolve_geolocation_provider(), 'explicit disable returns false');

// ─── New setting takes priority over legacy flag ───────────────────

set_settings(['geolocation_provider' => 'dbip', 'enable_maxmind' => 'on']);
assert_same('dbip', wp_slimstat::resolve_geolocation_provider(), 'new setting overrides legacy: dbip over on');

set_settings(['geolocation_provider' => 'disable', 'enable_maxmind' => 'on']);
assert_same(false, wp_slimstat::resolve_geolocation_provider(), 'new setting overrides legacy: disable over on');

// ─── Invalid / malformed geolocation_provider falls to legacy ──────

set_settings(['geolocation_provider' => '', 'enable_maxmind' => 'on']);
assert_same('maxmind', wp_slimstat::resolve_geolocation_provider(), 'empty string falls through to legacy on');

set_settings(['geolocation_provider' => '', 'enable_maxmind' => 'no']);
assert_same('dbip', wp_slimstat::resolve_geolocation_provider(), 'empty string falls through to legacy no');

set_settings(['geolocation_provider' => 'invalid_value', 'enable_maxmind' => 'on']);
assert_same('maxmind', wp_slimstat::resolve_geolocation_provider(), 'invalid value falls through to legacy on');

set_settings(['geolocation_provider' => 'invalid_value', 'enable_maxmind' => 'disable']);
assert_same(false, wp_slimstat::resolve_geolocation_provider(), 'invalid value + disabled legacy returns false');

set_settings(['geolocation_provider' => '<script>alert(1)</script>', 'enable_maxmind' => 'no']);
assert_same('dbip', wp_slimstat::resolve_geolocation_provider(), 'XSS payload is sanitized and falls through to legacy');

set_settings(['geolocation_provider' => 'MAXMIND', 'enable_maxmind' => 'on']);
assert_same('maxmind', wp_slimstat::resolve_geolocation_provider(), 'uppercase MAXMIND is not in allowlist, falls to legacy on');

set_settings(['geolocation_provider' => 'MAXMIND', 'enable_maxmind' => 'disable']);
assert_same(false, wp_slimstat::resolve_geolocation_provider(), 'uppercase MAXMIND + disabled legacy returns false');

// ─── Whitespace trimming (sanitize_text_field trims) ───────────────

set_settings(['geolocation_provider' => ' maxmind ', 'enable_maxmind' => 'no']);
assert_same('maxmind', wp_slimstat::resolve_geolocation_provider(), 'whitespace-padded maxmind is trimmed and matched');

set_settings(['geolocation_provider' => ' disable ', 'enable_maxmind' => 'on']);
assert_same(false, wp_slimstat::resolve_geolocation_provider(), 'whitespace-padded disable is trimmed and returns false');

// ─── Legacy flag only (no geolocation_provider set) ────────────────

set_settings(['enable_maxmind' => 'on']);
assert_same('maxmind', wp_slimstat::resolve_geolocation_provider(), 'legacy on returns maxmind');

set_settings(['enable_maxmind' => 'no']);
assert_same('dbip', wp_slimstat::resolve_geolocation_provider(), 'legacy no returns dbip');

set_settings(['enable_maxmind' => 'disable']);
assert_same(false, wp_slimstat::resolve_geolocation_provider(), 'legacy disable returns false');

// ─── Fresh install (neither setting exists) ────────────────────────

set_settings([]);
assert_same(false, wp_slimstat::resolve_geolocation_provider(), 'empty settings (fresh install) returns false');

set_settings(['some_other_setting' => 'value']);
assert_same(false, wp_slimstat::resolve_geolocation_provider(), 'unrelated settings only returns false');

// ─── Edge: enable_maxmind has unexpected value ─────────────────────

set_settings(['enable_maxmind' => 'yes']);
assert_same(false, wp_slimstat::resolve_geolocation_provider(), 'unexpected legacy value "yes" returns false');

set_settings(['enable_maxmind' => '']);
assert_same(false, wp_slimstat::resolve_geolocation_provider(), 'empty legacy value returns false');

// ─── Edge: geolocation_provider is set but not a string ────────────
// sanitize_text_field casts to string, so numeric values become strings

set_settings(['geolocation_provider' => 0, 'enable_maxmind' => 'on']);
assert_same('maxmind', wp_slimstat::resolve_geolocation_provider(), 'numeric 0 is set (isset=true), sanitized to "0", not in allowlist, falls to legacy');

set_settings(['geolocation_provider' => null]);
// null makes isset() return false, so falls to legacy
assert_same(false, wp_slimstat::resolve_geolocation_provider(), 'null geolocation_provider: isset returns false, falls to legacy default');

echo "All {$assertions} assertions passed in resolve-geolocation-provider-test.php\n";
