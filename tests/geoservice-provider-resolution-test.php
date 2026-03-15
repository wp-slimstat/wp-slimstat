<?php

/**
 * Tests for GeoService::isGeoIPEnabled() and isMaxMindEnabled()
 *
 * Covers: PR #166 — wiring GeoService to resolve_geolocation_provider()
 * Source: src/Services/GeoService.php:65-74
 *
 * Run: php tests/geoservice-provider-resolution-test.php
 */

declare(strict_types=1);

namespace SlimStat\Dependencies\GeoIp2\Database {
    // Stub the Reader class so GeoService.php can be loaded
    class Reader
    {
    }
}

namespace {
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

    // WordPress function mocks
    function sanitize_text_field($str)
    {
        $str = (string) $str;
        $str = strip_tags($str);
        $str = trim($str);
        $str = preg_replace('/[\r\n\t ]+/', ' ', $str);
        return $str;
    }

    function wp_unslash($value)
    {
        return $value;
    }

    // wp_slimstat stub with real resolver logic
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

    // Load real GeoService
    require_once __DIR__ . '/../src/Services/GeoService.php';

    use SlimStat\Services\GeoService;

    function set_and_create(array $settings): GeoService
    {
        $settings += ['enable_maxmind' => 'disable', 'maxmind_license_key' => '', 'geolocation_country' => 'on'];
        wp_slimstat::$settings = $settings;
        return new GeoService();
    }

    // ─── MaxMind provider ──────────────────────────────────────────

    $svc = set_and_create(['geolocation_provider' => 'maxmind', 'enable_maxmind' => 'on']);
    assert_same(true, $svc->isGeoIPEnabled(), 'maxmind: isGeoIPEnabled should be true');
    assert_same(true, $svc->isMaxMindEnabled(), 'maxmind: isMaxMindEnabled should be true');

    // ─── DB-IP provider ────────────────────────────────────────────

    $svc = set_and_create(['geolocation_provider' => 'dbip', 'enable_maxmind' => 'no']);
    assert_same(true, $svc->isGeoIPEnabled(), 'dbip: isGeoIPEnabled should be true');
    assert_same(false, $svc->isMaxMindEnabled(), 'dbip: isMaxMindEnabled should be false');

    // ─── Cloudflare provider (no DB needed) ────────────────────────

    $svc = set_and_create(['geolocation_provider' => 'cloudflare', 'enable_maxmind' => 'no']);
    assert_same(true, $svc->isGeoIPEnabled(), 'cloudflare: isGeoIPEnabled should be true (Cloudflare is a valid geolocation provider)');
    assert_same(false, $svc->isMaxMindEnabled(), 'cloudflare: isMaxMindEnabled should be false');

    // ─── Disabled ──────────────────────────────────────────────────

    $svc = set_and_create(['geolocation_provider' => 'disable', 'enable_maxmind' => 'disable']);
    assert_same(false, $svc->isGeoIPEnabled(), 'disabled: isGeoIPEnabled should be false');
    assert_same(false, $svc->isMaxMindEnabled(), 'disabled: isMaxMindEnabled should be false');

    // ─── Legacy MaxMind (no geolocation_provider) ──────────────────

    $svc = set_and_create(['enable_maxmind' => 'on']);
    assert_same(true, $svc->isGeoIPEnabled(), 'legacy maxmind: isGeoIPEnabled should be true');
    assert_same(true, $svc->isMaxMindEnabled(), 'legacy maxmind: isMaxMindEnabled should be true');

    // ─── Legacy DB-IP ──────────────────────────────────────────────

    $svc = set_and_create(['enable_maxmind' => 'no']);
    assert_same(true, $svc->isGeoIPEnabled(), 'legacy dbip: isGeoIPEnabled should be true');
    assert_same(false, $svc->isMaxMindEnabled(), 'legacy dbip: isMaxMindEnabled should be false');

    // ─── Legacy disabled ───────────────────────────────────────────

    $svc = set_and_create(['enable_maxmind' => 'disable']);
    assert_same(false, $svc->isGeoIPEnabled(), 'legacy disabled: isGeoIPEnabled should be false');
    assert_same(false, $svc->isMaxMindEnabled(), 'legacy disabled: isMaxMindEnabled should be false');

    // ─── Fresh install ─────────────────────────────────────────────

    $svc = set_and_create([]);
    assert_same(false, $svc->isGeoIPEnabled(), 'fresh install: isGeoIPEnabled should be false');
    assert_same(false, $svc->isMaxMindEnabled(), 'fresh install: isMaxMindEnabled should be false');

    echo "All {$assertions} assertions passed in geoservice-provider-resolution-test.php\n";
}
