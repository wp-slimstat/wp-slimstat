<?php
/**
 * Regression test: one-shot settings migration (_migration_5460).
 *
 * Verifies that the migration block in wp_slimstat::init() correctly resets
 * the four settings that were forced to harmful defaults in v5.4.1–v5.4.5:
 *
 *   use_slimstat_banner='on'  → blocked all anonymous visitor tracking
 *   javascript_mode='off'     → baked stale stat IDs into cached HTML (v5.4.1 fingerprint only)
 *   anonymize_ip='on'         → masked IPs in DB (different from 5.3.x)
 *   hash_ip='on'              → replaced real visitor IPs with daily hashes
 *
 * The migration runs exactly once: the '_migration_5460' flag is absent from all
 * pre-5.4.6 installs; array_merge fills it with '0'; migration fires; saves '1'.
 *
 * @see wp-slimstat/wp-slimstat.php (init(), after array_merge line)
 */

declare(strict_types=1);

$assertions = 0;

function mig_assert_same($expected, $actual, string $msg): void
{
    global $assertions;
    $assertions++;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$msg} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ")\n");
        exit(1);
    }
}

/**
 * Simulate the migration block from wp_slimstat::init().
 * Returns the settings array after migration runs (or doesn't run).
 */
function run_migration(array $settings): array
{
    if ('0' === ($settings['_migration_5460'] ?? '0')) {
        $_ss_banner_was_on = ('on' === ($settings['use_slimstat_banner'] ?? 'off'));
        if ($_ss_banner_was_on) {
            $settings['use_slimstat_banner'] = 'off';
        }
        if ($_ss_banner_was_on && 'off' === ($settings['javascript_mode'] ?? 'on')) {
            $settings['javascript_mode'] = 'on';
        }
        if ('on' === ($settings['anonymize_ip'] ?? 'off')) {
            $settings['anonymize_ip'] = 'off';
        }
        if ('on' === ($settings['hash_ip'] ?? 'off')) {
            $settings['hash_ip'] = 'off';
        }
        $settings['_migration_5460'] = '1';
    }
    return $settings;
}

// ═══════════════════════════════════════════════════════════════════════════
// TEST 1: Full v5.4.1 upgrade scenario — all four bad settings present
// ═══════════════════════════════════════════════════════════════════════════
$result = run_migration([
    'use_slimstat_banner' => 'on',
    'javascript_mode'     => 'off',
    'anonymize_ip'        => 'on',
    'hash_ip'             => 'on',
    // '_migration_5460' absent (old install)
]);

mig_assert_same('off', $result['use_slimstat_banner'], 'TEST 1: use_slimstat_banner must be reset to off');
mig_assert_same('on',  $result['javascript_mode'],     'TEST 1: javascript_mode must be reset to on (banner was on = v5.4.1 fingerprint)');
mig_assert_same('off', $result['anonymize_ip'],        'TEST 1: anonymize_ip must be reset to off');
mig_assert_same('off', $result['hash_ip'],             'TEST 1: hash_ip must be reset to off');
mig_assert_same('1',   $result['_migration_5460'],     'TEST 1: migration flag must be set to 1');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 2: Migration already ran (flag = '1') — nothing must change
// ═══════════════════════════════════════════════════════════════════════════
$result = run_migration([
    'use_slimstat_banner' => 'on',   // still bad — but migration must NOT touch it
    'javascript_mode'     => 'off',
    'anonymize_ip'        => 'on',
    'hash_ip'             => 'on',
    '_migration_5460'     => '1',    // already ran
]);

mig_assert_same('on',  $result['use_slimstat_banner'], 'TEST 2: migration must be skipped when flag is 1 — settings unchanged');
mig_assert_same('off', $result['javascript_mode'],     'TEST 2: javascript_mode unchanged when migration skipped');
mig_assert_same('1',   $result['_migration_5460'],     'TEST 2: flag stays 1');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 3: New install — all values already correct, migration fires but changes nothing
// ═══════════════════════════════════════════════════════════════════════════
$result = run_migration([
    'use_slimstat_banner' => 'off',
    'javascript_mode'     => 'on',
    'anonymize_ip'        => 'off',
    'hash_ip'             => 'off',
    // '_migration_5460' absent (fresh install before flag written)
]);

mig_assert_same('off', $result['use_slimstat_banner'], 'TEST 3: use_slimstat_banner unchanged (already off)');
mig_assert_same('on',  $result['javascript_mode'],     'TEST 3: javascript_mode unchanged (already on)');
mig_assert_same('off', $result['anonymize_ip'],        'TEST 3: anonymize_ip unchanged (already off)');
mig_assert_same('off', $result['hash_ip'],             'TEST 3: hash_ip unchanged (already off)');
mig_assert_same('1',   $result['_migration_5460'],     'TEST 3: flag written to 1 even on new install');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 4: 5.3.x upgrade — banner was never set ('off'), javascript_mode was 'off'
//
// Critical: javascript_mode must NOT be reset because $banner_was_on is false.
// 5.3.x admins who chose Server mode deliberately are left alone.
// ═══════════════════════════════════════════════════════════════════════════
$result = run_migration([
    'use_slimstat_banner' => 'off',  // was never 'on' in 5.3.x
    'javascript_mode'     => 'off',  // deliberate Server mode choice from 5.3.x
    'anonymize_ip'        => 'no',   // 5.3.x stored it as 'no', not 'on'
    'hash_ip'             => 'off',  // was not set in 5.3.x, but defaults to 'off'
]);

mig_assert_same('off', $result['use_slimstat_banner'], 'TEST 4: use_slimstat_banner stays off (was never bad)');
mig_assert_same('off', $result['javascript_mode'],     'TEST 4: javascript_mode must NOT be reset for 5.3.x users (banner was off = no v5.4.1 fingerprint)');
mig_assert_same('no',  $result['anonymize_ip'],        'TEST 4: anonymize_ip stays as no (not on → migration check fails)');
mig_assert_same('off', $result['hash_ip'],             'TEST 4: hash_ip stays off');
mig_assert_same('1',   $result['_migration_5460'],     'TEST 4: flag written to 1');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 5: Partial bad state — only anonymize_ip and hash_ip bad, banner already off
//
// Covers a site where admin manually fixed the banner in 5.4.x but didn't fix IPs.
// Only the IP settings should be reset.
// ═══════════════════════════════════════════════════════════════════════════
$result = run_migration([
    'use_slimstat_banner' => 'off',  // admin already fixed this
    'javascript_mode'     => 'on',   // already correct
    'anonymize_ip'        => 'on',   // still bad
    'hash_ip'             => 'on',   // still bad
]);

mig_assert_same('off', $result['use_slimstat_banner'], 'TEST 5: banner stays off');
mig_assert_same('on',  $result['javascript_mode'],     'TEST 5: javascript_mode unchanged (banner was off = no fingerprint)');
mig_assert_same('off', $result['anonymize_ip'],        'TEST 5: anonymize_ip reset to off');
mig_assert_same('off', $result['hash_ip'],             'TEST 5: hash_ip reset to off');
mig_assert_same('1',   $result['_migration_5460'],     'TEST 5: flag written to 1');

echo "All {$assertions} assertions passed in v5460-settings-migration-test.php\n";
