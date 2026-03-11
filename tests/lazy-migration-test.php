<?php

/**
 * Tests for lazy migration of geolocation_provider from legacy enable_maxmind.
 *
 * Covers: PR #166 — admin/config/index.php:17-22
 * When geolocation_provider is not set, the lazy migration populates it
 * from the resolver for correct <select> rendering on the settings page.
 *
 * Run: php tests/lazy-migration-test.php
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

function sanitize_text_field($str)
{
    $str = (string) $str;
    $str = strip_tags($str);
    $str = trim($str);
    $str = preg_replace('/[\r\n\t ]+/', ' ', $str);
    return $str;
}

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

/**
 * Replicates the lazy migration logic from admin/config/index.php:17-22.
 * Populates geolocation_provider in $settings if not already set.
 */
function lazy_migrate(): void
{
    if (!isset(wp_slimstat::$settings['geolocation_provider'])) {
        $resolved = wp_slimstat::resolve_geolocation_provider();
        wp_slimstat::$settings['geolocation_provider'] = false !== $resolved ? $resolved : 'disable';
    }
}

// ─── Legacy MaxMind → migrates to 'maxmind' ───────────────────────

wp_slimstat::$settings = ['enable_maxmind' => 'on'];
lazy_migrate();
assert_same('maxmind', wp_slimstat::$settings['geolocation_provider'], 'legacy on migrates to maxmind');

// ─── Legacy DB-IP → migrates to 'dbip' ────────────────────────────

wp_slimstat::$settings = ['enable_maxmind' => 'no'];
lazy_migrate();
assert_same('dbip', wp_slimstat::$settings['geolocation_provider'], 'legacy no migrates to dbip');

// ─── Legacy disabled → migrates to 'disable' ──────────────────────

wp_slimstat::$settings = ['enable_maxmind' => 'disable'];
lazy_migrate();
assert_same('disable', wp_slimstat::$settings['geolocation_provider'], 'legacy disable migrates to disable');

// ─── Fresh install (no settings) → migrates to 'disable' ──────────

wp_slimstat::$settings = [];
lazy_migrate();
assert_same('disable', wp_slimstat::$settings['geolocation_provider'], 'fresh install migrates to disable');

// ─── Already set → does NOT overwrite ──────────────────────────────

wp_slimstat::$settings = ['geolocation_provider' => 'dbip', 'enable_maxmind' => 'on'];
lazy_migrate();
assert_same('dbip', wp_slimstat::$settings['geolocation_provider'], 'existing geolocation_provider is not overwritten');

// ─── Already set to disable → preserved ────────────────────────────

wp_slimstat::$settings = ['geolocation_provider' => 'disable', 'enable_maxmind' => 'on'];
lazy_migrate();
assert_same('disable', wp_slimstat::$settings['geolocation_provider'], 'existing disable is preserved even with legacy on');

echo "All {$assertions} assertions passed in lazy-migration-test.php\n";
