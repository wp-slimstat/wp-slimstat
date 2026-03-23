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
/**
 * Simulate wp_has_consent() availability for testing.
 */
$GLOBALS['_test_wp_has_consent_available'] = false;
function test_wp_has_consent_exists(): bool
{
    return $GLOBALS['_test_wp_has_consent_available'];
}

function run_migration(array $settings): array
{
    if ('0' === ($settings['_migration_5460'] ?? '0')) {
        // Save ORIGINAL banner value before consent-intent detection modifies it
        $_ss_banner_was_on_original = ('on' === ($settings['use_slimstat_banner'] ?? 'off'));

        // --- Consent intent detection ---
        $_had_opt_out_banner  = ('on' === ($settings['display_opt_out'] ?? 'no'));
        $_had_opt_out_cookies = !empty(trim($settings['opt_out_cookie_names'] ?? ''));
        $_had_opt_in_cookies  = !empty(trim($settings['opt_in_cookie_names'] ?? ''));
        $_current_integration = $settings['consent_integration'] ?? '';
        $_has_third_party_cmp = in_array($_current_integration, ['wp_consent_api', 'real_cookie_banner'], true);

        if ($_has_third_party_cmp) {
            $settings['gdpr_enabled'] = 'on';
        } elseif ($_had_opt_out_banner || $_had_opt_out_cookies || $_had_opt_in_cookies) {
            $settings['gdpr_enabled'] = 'on';
            $settings['use_slimstat_banner'] = 'on';
            if ($_had_opt_in_cookies && test_wp_has_consent_exists()) {
                $settings['consent_integration'] = 'wp_consent_api';
            } else {
                $settings['consent_integration'] = 'slimstat_banner';
            }
        } else {
            $settings['gdpr_enabled'] = 'off';
            $settings['consent_integration'] = '';
            $settings['use_slimstat_banner'] = 'off';
        }

        if ('off' === $settings['gdpr_enabled']
            && 'off' === ($settings['set_tracker_cookie'] ?? 'on')) {
            $settings['set_tracker_cookie'] = 'on';
        }

        // Existing banner/IP/JS-mode resets — use ORIGINAL banner value
        $_ss_banner_was_on = $_ss_banner_was_on_original;
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

mig_assert_same('off', $result['use_slimstat_banner'], 'TEST 1: use_slimstat_banner must be off (GDPR disabled)');
mig_assert_same('on',  $result['javascript_mode'],     'TEST 1: javascript_mode must be reset to on (original banner was on = v5.4.1 fingerprint)');
mig_assert_same('off', $result['anonymize_ip'],        'TEST 1: anonymize_ip must be reset to off');
mig_assert_same('off', $result['hash_ip'],             'TEST 1: hash_ip must be reset to off');
mig_assert_same('off', $result['gdpr_enabled'],        'TEST 1: gdpr_enabled off (no old consent intent)');
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

// ═══════════════════════════════════════════════════════════════════════════
// TEST 6: v5.3.x upgrade with NO consent config → GDPR off, pure v5.3.x
// ═══════════════════════════════════════════════════════════════════════════
$result = run_migration([
    'use_slimstat_banner' => 'off',
    'javascript_mode'     => 'on',
    'anonymize_ip'        => 'no',
    'hash_ip'             => 'off',
    'display_opt_out'     => 'no',
    'opt_in_cookie_names' => '',
    'opt_out_cookie_names'=> '',
    'set_tracker_cookie'  => 'on',
]);

mig_assert_same('off', $result['gdpr_enabled'],        'TEST 6: gdpr_enabled must be off (no consent intent)');
mig_assert_same('',    $result['consent_integration'],  'TEST 6: consent_integration must be empty');
mig_assert_same('off', $result['use_slimstat_banner'],  'TEST 6: use_slimstat_banner must be off');
mig_assert_same('on',  $result['set_tracker_cookie'],   'TEST 6: set_tracker_cookie stays on');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 7: v5.3.x upgrade with display_opt_out='on' → GDPR on + banner
// ═══════════════════════════════════════════════════════════════════════════
$result = run_migration([
    'use_slimstat_banner' => 'off',
    'javascript_mode'     => 'on',
    'anonymize_ip'        => 'no',
    'display_opt_out'     => 'on',
    'opt_in_cookie_names' => '',
    'opt_out_cookie_names'=> '',
]);

mig_assert_same('on',              $result['gdpr_enabled'],        'TEST 7: gdpr_enabled must be on (opt-out intent)');
mig_assert_same('slimstat_banner', $result['consent_integration'],  'TEST 7: consent_integration mapped to slimstat_banner');
mig_assert_same('on',              $result['use_slimstat_banner'],  'TEST 7: use_slimstat_banner must be on');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 8: v5.3.x upgrade with opt_in_cookie_names → GDPR on
// ═══════════════════════════════════════════════════════════════════════════
$result = run_migration([
    'use_slimstat_banner' => 'off',
    'display_opt_out'     => 'no',
    'opt_in_cookie_names' => 'my_consent_cookie=yes',
    'opt_out_cookie_names'=> '',
]);

mig_assert_same('on',              $result['gdpr_enabled'],        'TEST 8: gdpr_enabled on (opt-in intent)');
mig_assert_same('slimstat_banner', $result['consent_integration'],  'TEST 8: consent_integration mapped to slimstat_banner (no WP Consent API)');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 9: v5.3.x with opt_in + WP Consent API available → uses wp_consent_api
// ═══════════════════════════════════════════════════════════════════════════
$GLOBALS['_test_wp_has_consent_available'] = true;
$result = run_migration([
    'use_slimstat_banner' => 'off',
    'display_opt_out'     => 'no',
    'opt_in_cookie_names' => 'cookieyes-consent=yes',
    'opt_out_cookie_names'=> '',
]);

mig_assert_same('on',              $result['gdpr_enabled'],        'TEST 9: gdpr_enabled on');
mig_assert_same('wp_consent_api',  $result['consent_integration'],  'TEST 9: auto-detected WP Consent API');
$GLOBALS['_test_wp_has_consent_available'] = false;

// ═══════════════════════════════════════════════════════════════════════════
// TEST 10: v5.4.x upgrade with defaults (no consent intent) → GDPR off
//
// v5.4.0 set consent_integration='slimstat_banner' + use_slimstat_banner='on' as defaults
// but this was not a deliberate user choice. Per plan: these users get v5.3.x-like behavior.
// ═══════════════════════════════════════════════════════════════════════════
$result = run_migration([
    'use_slimstat_banner'  => 'on',
    'javascript_mode'      => 'off',
    'anonymize_ip'         => 'on',
    'hash_ip'              => 'on',
    'gdpr_enabled'         => 'on',
    'consent_integration'  => 'slimstat_banner',
    'set_tracker_cookie'   => 'off',
    'display_opt_out'      => 'no',
    'opt_in_cookie_names'  => '',
    'opt_out_cookie_names' => '',
]);

mig_assert_same('off', $result['gdpr_enabled'],        'TEST 10: gdpr_enabled off (no old consent intent)');
mig_assert_same('',    $result['consent_integration'],  'TEST 10: consent_integration empty');
mig_assert_same('off', $result['use_slimstat_banner'],  'TEST 10: banner off');
mig_assert_same('on',  $result['set_tracker_cookie'],   'TEST 10: set_tracker_cookie restored to on');
mig_assert_same('on',  $result['javascript_mode'],      'TEST 10: javascript_mode reset to client');
mig_assert_same('off', $result['anonymize_ip'],         'TEST 10: anonymize_ip off');
mig_assert_same('off', $result['hash_ip'],              'TEST 10: hash_ip off');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 11: v5.4.x with third-party CMP (wp_consent_api) → preserved
// ═══════════════════════════════════════════════════════════════════════════
$result = run_migration([
    'consent_integration'  => 'wp_consent_api',
    'gdpr_enabled'         => 'on',
    'use_slimstat_banner'  => 'off',
    'display_opt_out'      => 'no',
    'opt_in_cookie_names'  => '',
]);

mig_assert_same('on',              $result['gdpr_enabled'],        'TEST 11: gdpr_enabled preserved');
mig_assert_same('wp_consent_api',  $result['consent_integration'],  'TEST 11: CMP preserved');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 12: v5.3.x → v5.4.x → v5.4.6 chain: old opt-out data survives
// ═══════════════════════════════════════════════════════════════════════════
$result = run_migration([
    'gdpr_enabled'         => 'on',
    'consent_integration'  => 'slimstat_banner',
    'use_slimstat_banner'  => 'on',
    'display_opt_out'      => 'on',         // survived from v5.3.x
    'opt_out_cookie_names' => 'my_optout=true',  // survived from v5.3.x
    'opt_in_cookie_names'  => '',
]);

mig_assert_same('on',              $result['gdpr_enabled'],        'TEST 12: gdpr_enabled on (old opt-out detected)');
mig_assert_same('slimstat_banner', $result['consent_integration'],  'TEST 12: slimstat_banner');
mig_assert_same('on',              $result['use_slimstat_banner'],  'TEST 12: banner on');

echo "All {$assertions} assertions passed in v5460-settings-migration-test.php\n";
