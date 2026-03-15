<?php

/**
 * Tests for legacy enable_maxmind ↔ geolocation_provider sync mapping.
 *
 * Covers: PR #166 — admin/config/index.php:857-864
 * The config save handler syncs enable_maxmind when geolocation_provider is saved.
 * This test validates the mapping contract that the sync code must follow.
 *
 * Run: php tests/legacy-sync-mapping-test.php
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

/**
 * Replicates the sync logic from admin/config/index.php:857-864.
 * If this logic changes in the source, this function must be updated too.
 *
 * @param string $provider The geolocation_provider value being saved
 * @return string The enable_maxmind value that should be synced
 */
function sync_enable_maxmind(string $provider): string
{
    if ('maxmind' === $provider) {
        return 'on';
    } elseif ('dbip' === $provider || 'cloudflare' === $provider) {
        return 'no';
    } elseif ('disable' === $provider) {
        return 'disable';
    }
    // Unknown provider — should not happen with allowlist, but return current default
    return 'disable';
}

// ─── Forward sync: geolocation_provider → enable_maxmind ───────────

assert_same('on', sync_enable_maxmind('maxmind'), 'maxmind should sync to enable_maxmind=on');
assert_same('no', sync_enable_maxmind('dbip'), 'dbip should sync to enable_maxmind=no');
assert_same('no', sync_enable_maxmind('cloudflare'), 'cloudflare should sync to enable_maxmind=no');
assert_same('disable', sync_enable_maxmind('disable'), 'disable should sync to enable_maxmind=disable');

// ─── Reverse contract: enable_maxmind → resolver output ────────────
// Validates that legacy → resolver → sync produces a stable round-trip.

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

// Round-trip: legacy enable_maxmind → resolver → sync → enable_maxmind should be stable
$round_trips = [
    'on'      => 'on',       // on → maxmind → sync → on
    'no'      => 'no',       // no → dbip → sync → no
    'disable' => 'disable',  // disable → false → (handled by disable branch) → disable
];

foreach ($round_trips as $legacy_value => $expected_synced) {
    wp_slimstat::$settings = ['enable_maxmind' => $legacy_value];
    $resolved = wp_slimstat::resolve_geolocation_provider();

    // Map resolver output to provider string for sync function
    $provider_for_sync = false === $resolved ? 'disable' : $resolved;
    $synced = sync_enable_maxmind($provider_for_sync);

    assert_same($expected_synced, $synced, "round-trip: enable_maxmind={$legacy_value} → resolve → sync should produce {$expected_synced}");
}

echo "All {$assertions} assertions passed in legacy-sync-mapping-test.php\n";
