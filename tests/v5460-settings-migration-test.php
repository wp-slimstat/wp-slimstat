<?php
/**
 * Regression test: the one-shot settings migration (_migration_5460).
 *
 * Exercises SlimStat\Migration\LegacySettings5460::apply() — THE code init() runs — not a copy.
 * Until 2026-09-05 this file carried its own transcription of the init() block and asserted
 * against that; the transcription and production had drifted in three places and the
 * transcription was the one passing. See the class docblock for the three, and for the
 * regression the first of them hid.
 *
 * The four settings v5.4.0–v5.4.5 forced to harmful defaults:
 *
 *   use_slimstat_banner='on'  → blocked all anonymous visitor tracking
 *   javascript_mode='off'     → baked stale stat IDs into cached HTML
 *   anonymize_ip='on'         → masked IPs in the DB
 *   hash_ip='on'              → replaced real visitor IPs with daily hashes
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Migration/LegacySettings5460.php';

use SlimStat\Migration\LegacySettings5460;

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

define('TEST_SLIMSTAT_VERSION', '5.4.6');

/**
 * @param bool $fresh   Whether the options row existed before the request. Defaults to an
 *                      UPGRADER, which is what every pre-existing fixture in this file describes.
 * @param bool $has_api function_exists('wp_has_consent').
 */
function run_migration(array $settings, string $current = TEST_SLIMSTAT_VERSION, bool $fresh = false, bool $has_api = false): array
{
    return LegacySettings5460::apply($settings, $current, $fresh, $has_api)['settings'];
}

// ═══════════════════════════════════════════════════════════════════════════
// TEST 1: Full v5.4.1 upgrade scenario — all four bad settings present, flag absent
// ═══════════════════════════════════════════════════════════════════════════
$result = run_migration([
    'use_slimstat_banner' => 'on',
    'javascript_mode'     => 'off',
    'anonymize_ip'        => 'on',
    'hash_ip'             => 'on',
    // '_migration_5460' absent (old install)
]);

mig_assert_same('off', $result['use_slimstat_banner'], 'TEST 1: use_slimstat_banner must be off (GDPR disabled)');
mig_assert_same('on',  $result['javascript_mode'],     'TEST 1: javascript_mode must be reset to on');
mig_assert_same('off', $result['anonymize_ip'],        'TEST 1: anonymize_ip must be reset to off');
mig_assert_same('off', $result['hash_ip'],             'TEST 1: hash_ip must be reset to off');
mig_assert_same('off', $result['gdpr_enabled'],        'TEST 1: gdpr_enabled off (no old consent intent)');
mig_assert_same(TEST_SLIMSTAT_VERSION, $result['_migration_5460'], 'TEST 1: flag set to the running version');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 2: Migration already ran (flag = current version) — nothing must change
// ═══════════════════════════════════════════════════════════════════════════
$result = run_migration([
    'use_slimstat_banner' => 'on',
    'javascript_mode'     => 'off',
    'anonymize_ip'        => 'on',
    'hash_ip'             => 'on',
    '_migration_5460'     => TEST_SLIMSTAT_VERSION,
]);

mig_assert_same('on',  $result['use_slimstat_banner'], 'TEST 2: skipped when flag matches version — settings unchanged');
mig_assert_same('off', $result['javascript_mode'],     'TEST 2: javascript_mode unchanged when skipped');
mig_assert_same(TEST_SLIMSTAT_VERSION, $result['_migration_5460'], 'TEST 2: flag stays at version');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 3: FRESH install — values already correct, migration fires, changes nothing
// ═══════════════════════════════════════════════════════════════════════════
$result = run_migration([
    'use_slimstat_banner' => 'off',
    'javascript_mode'     => 'on',
    'anonymize_ip'        => 'off',
    'hash_ip'             => 'off',
], TEST_SLIMSTAT_VERSION, true);

mig_assert_same('off', $result['use_slimstat_banner'], 'TEST 3: unchanged (already off)');
mig_assert_same('on',  $result['javascript_mode'],     'TEST 3: unchanged (already on)');
mig_assert_same('off', $result['anonymize_ip'],        'TEST 3: unchanged (already off)');
mig_assert_same('off', $result['hash_ip'],             'TEST 3: unchanged (already off)');
mig_assert_same(TEST_SLIMSTAT_VERSION, $result['_migration_5460'], 'TEST 3: flag written even on a fresh install');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 4: 5.3.x upgrade with a deliberate Server-mode choice.
//
// PRODUCTION RESETS javascript_mode UNCONDITIONALLY for any pre-5.4.7 upgrader — "server-side
// mode was a v5.4.0 default, not a user choice" — and has since 2026-03. The transcription this
// file used to carry asserted the OPPOSITE ("5.3.x admins who chose Server mode deliberately
// are left alone"), and passed, because it was asserting about itself. This test now pins what
// ships. Whether a 5.3.x deliberate choice should survive is a product question, recorded
// here rather than re-decided in a test.
// ═══════════════════════════════════════════════════════════════════════════
$result = run_migration([
    'use_slimstat_banner' => 'off',
    'javascript_mode'     => 'off',
    'anonymize_ip'        => 'no',
    'hash_ip'             => 'off',
]);

mig_assert_same('off', $result['use_slimstat_banner'], 'TEST 4: use_slimstat_banner stays off (was never bad)');
mig_assert_same('on',  $result['javascript_mode'],     'TEST 4: javascript_mode IS reset on any pre-5.4.7 upgrader — what production does');
mig_assert_same('no',  $result['anonymize_ip'],        'TEST 4: anonymize_ip stays as no (not on → no reset)');
mig_assert_same('off', $result['hash_ip'],             'TEST 4: hash_ip stays off');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 5: Partial bad state — only the IP settings still bad
// ═══════════════════════════════════════════════════════════════════════════
$result = run_migration([
    'use_slimstat_banner' => 'off',
    'javascript_mode'     => 'on',
    'anonymize_ip'        => 'on',
    'hash_ip'             => 'on',
]);

mig_assert_same('off', $result['use_slimstat_banner'], 'TEST 5: banner stays off');
mig_assert_same('on',  $result['javascript_mode'],     'TEST 5: javascript_mode unchanged');
mig_assert_same('off', $result['anonymize_ip'],        'TEST 5: anonymize_ip reset to off');
mig_assert_same('off', $result['hash_ip'],             'TEST 5: hash_ip reset to off');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 6: v5.3.x upgrade with NO consent config → GDPR off, pure v5.3.x
// ═══════════════════════════════════════════════════════════════════════════
$result = run_migration([
    'use_slimstat_banner'  => 'off',
    'javascript_mode'      => 'on',
    'anonymize_ip'         => 'no',
    'hash_ip'              => 'off',
    'display_opt_out'      => 'no',
    'opt_in_cookie_names'  => '',
    'opt_out_cookie_names' => '',
    'set_tracker_cookie'   => 'on',
]);

mig_assert_same('off', $result['gdpr_enabled'],        'TEST 6: gdpr_enabled off (no consent intent)');
mig_assert_same('',    $result['consent_integration'], 'TEST 6: consent_integration empty');
mig_assert_same('off', $result['use_slimstat_banner'], 'TEST 6: use_slimstat_banner off');
mig_assert_same('on',  $result['set_tracker_cookie'],  'TEST 6: set_tracker_cookie stays on');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 7: v5.3.x upgrade with display_opt_out='on' → GDPR on + banner
// ═══════════════════════════════════════════════════════════════════════════
$result = run_migration([
    'use_slimstat_banner'  => 'off',
    'javascript_mode'      => 'on',
    'anonymize_ip'         => 'no',
    'display_opt_out'      => 'on',
    'opt_in_cookie_names'  => '',
    'opt_out_cookie_names' => '',
]);

mig_assert_same('on',              $result['gdpr_enabled'],        'TEST 7: gdpr_enabled on (opt-out intent)');
mig_assert_same('slimstat_banner', $result['consent_integration'], 'TEST 7: mapped to slimstat_banner');
mig_assert_same('on',              $result['use_slimstat_banner'], 'TEST 7: use_slimstat_banner on');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 8: v5.3.x upgrade with opt_in_cookie_names → GDPR on
// ═══════════════════════════════════════════════════════════════════════════
$result = run_migration([
    'use_slimstat_banner'  => 'off',
    'display_opt_out'      => 'no',
    'opt_in_cookie_names'  => 'my_consent_cookie=yes',
    'opt_out_cookie_names' => '',
]);

mig_assert_same('on',              $result['gdpr_enabled'],        'TEST 8: gdpr_enabled on (opt-in intent)');
mig_assert_same('slimstat_banner', $result['consent_integration'], 'TEST 8: slimstat_banner (no WP Consent API)');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 9: v5.3.x with opt_in + WP Consent API available → uses wp_consent_api
// ═══════════════════════════════════════════════════════════════════════════
$result = run_migration([
    'use_slimstat_banner'  => 'off',
    'display_opt_out'      => 'no',
    'opt_in_cookie_names'  => 'cookieyes-consent=yes',
    'opt_out_cookie_names' => '',
], TEST_SLIMSTAT_VERSION, false, true);

mig_assert_same('on',             $result['gdpr_enabled'],        'TEST 9: gdpr_enabled on');
mig_assert_same('wp_consent_api', $result['consent_integration'], 'TEST 9: auto-detected WP Consent API');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 10: v5.4.x upgrade with defaults (no consent intent) → GDPR off, all resets
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

mig_assert_same('off', $result['gdpr_enabled'],        'TEST 10: gdpr_enabled off');
mig_assert_same('',    $result['consent_integration'], 'TEST 10: consent_integration empty');
mig_assert_same('off', $result['use_slimstat_banner'], 'TEST 10: banner off');
mig_assert_same('on',  $result['set_tracker_cookie'],  'TEST 10: set_tracker_cookie restored');
mig_assert_same('on',  $result['javascript_mode'],     'TEST 10: javascript_mode reset to client');
mig_assert_same('off', $result['anonymize_ip'],        'TEST 10: anonymize_ip off');
mig_assert_same('off', $result['hash_ip'],             'TEST 10: hash_ip off');

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

mig_assert_same('on',             $result['gdpr_enabled'],        'TEST 11: gdpr_enabled preserved');
mig_assert_same('wp_consent_api', $result['consent_integration'], 'TEST 11: CMP preserved');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 12: v5.3.x → v5.4.x → v5.4.6 chain: old opt-out data survives
// ═══════════════════════════════════════════════════════════════════════════
$result = run_migration([
    'gdpr_enabled'         => 'on',
    'consent_integration'  => 'slimstat_banner',
    'use_slimstat_banner'  => 'on',
    'display_opt_out'      => 'on',
    'opt_out_cookie_names' => 'my_optout=true',
    'opt_in_cookie_names'  => '',
]);

mig_assert_same('on',              $result['gdpr_enabled'],        'TEST 12: gdpr_enabled on (old opt-out detected)');
mig_assert_same('slimstat_banner', $result['consent_integration'], 'TEST 12: slimstat_banner');
mig_assert_same('on',              $result['use_slimstat_banner'], 'TEST 12: banner on');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 13: Downgrade→re-upgrade: flag has an older version → re-runs
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
    '_migration_5460'      => '5.4.5',
]);

mig_assert_same(TEST_SLIMSTAT_VERSION, $result['_migration_5460'], 'TEST 13: flag advances after re-run');
mig_assert_same('off', $result['gdpr_enabled'], 'TEST 13: re-ran and set gdpr_enabled=off');
mig_assert_same('off', $result['anonymize_ip'], 'TEST 13: anonymize_ip reset');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 14: Flag '1' from old code treated as < current version → re-runs
// ═══════════════════════════════════════════════════════════════════════════
$result = run_migration([
    'use_slimstat_banner'  => 'on',
    'javascript_mode'      => 'off',
    'anonymize_ip'         => 'on',
    'hash_ip'              => 'on',
    'display_opt_out'      => 'no',
    'opt_in_cookie_names'  => '',
    'opt_out_cookie_names' => '',
    '_migration_5460'      => '1',
]);

mig_assert_same(TEST_SLIMSTAT_VERSION, $result['_migration_5460'], 'TEST 14: old "1" flag triggers re-run');
mig_assert_same('off', $result['anonymize_ip'], 'TEST 14: migration re-ran');

// ═══════════════════════════════════════════════════════════════════════════
// TESTS 15-17 (S2): a >= 5.4.7 install must never have its consent rewritten
// ═══════════════════════════════════════════════════════════════════════════
$result = run_migration([
    'gdpr_enabled'         => 'on',
    'use_slimstat_banner'  => 'on',
    'consent_integration'  => 'slimstat_banner',
    'display_opt_out'      => 'no',
    'opt_in_cookie_names'  => '',
    'opt_out_cookie_names' => '',
    '_migration_5460'      => '5.5.0',
], '6.0.0');

mig_assert_same('on', $result['gdpr_enabled'],                     'TEST 15: gdpr_enabled survives the upgrade');
mig_assert_same('on', $result['use_slimstat_banner'],              'TEST 15: use_slimstat_banner survives');
mig_assert_same('slimstat_banner', $result['consent_integration'], 'TEST 15: consent_integration survives');
mig_assert_same('6.0.0', $result['_migration_5460'],               'TEST 15: flag still advances');

$result = run_migration([
    'gdpr_enabled'         => 'off',
    'use_slimstat_banner'  => 'off',
    'consent_integration'  => '',
    'display_opt_out'      => 'on',
    'opt_in_cookie_names'  => '',
    'opt_out_cookie_names' => '',
    '_migration_5460'      => '5.5.1',
], '6.0.0');

mig_assert_same('off', $result['gdpr_enabled'],        'TEST 16: a stale display_opt_out must not force GDPR back on');
mig_assert_same('off', $result['use_slimstat_banner'], 'TEST 16: nor the banner');
mig_assert_same('',    $result['consent_integration'], 'TEST 16: nor the integration');

$result = run_migration([
    'gdpr_enabled'         => 'off',
    'use_slimstat_banner'  => 'off',
    'consent_integration'  => '',
    'display_opt_out'      => 'on',
    'opt_in_cookie_names'  => '',
    'opt_out_cookie_names' => '',
    '_migration_5460'      => '5.4.6',
], '6.0.0');

mig_assert_same('on', $result['gdpr_enabled'],        'TEST 17: a pre-5.4.7 install still maps its legacy consent intent');
mig_assert_same('on', $result['use_slimstat_banner'], 'TEST 17: and still gets the banner');

// ═══════════════════════════════════════════════════════════════════════════
// TESTS 18-20: THE REGRESSION. Flag '0' means "never ran" — for a fresh install AND for every
// upgrader from 5.4.0–5.4.5, who never had the key. Only the caller can tell them apart.
// ═══════════════════════════════════════════════════════════════════════════

// TEST 18 — a 5.4.0 site upgrading straight to 6.0.0. Flag '0' (key never existed), options
// row present. This is the cohort the resets exist for. Under the `'0' !== $ran` guard they
// were skipped, and v5.5.0 in the field had no such guard: a 6.0.0 regression.
$verdict = LegacySettings5460::apply([
    'use_slimstat_banner'  => 'on',
    'javascript_mode'      => 'off',
    'anonymize_ip'         => 'on',
    'hash_ip'              => 'on',
    'set_tracker_cookie'   => 'off',
    'display_opt_out'      => 'no',
    'opt_in_cookie_names'  => '',
    'opt_out_cookie_names' => '',
    '_migration_5460'      => '0',
], '6.0.0', false, false);

mig_assert_same(true,  $verdict['ran'],                              'TEST 18: the migration ran');
mig_assert_same('on',  $verdict['settings']['set_tracker_cookie'],   'TEST 18: set_tracker_cookie restored for a 5.4.0 → 6.0.0 upgrader');
mig_assert_same('on',  $verdict['settings']['javascript_mode'],      'TEST 18: javascript_mode reset for a 5.4.0 → 6.0.0 upgrader');
mig_assert_same('off', $verdict['settings']['anonymize_ip'],         'TEST 18: anonymize_ip reset');
mig_assert_same('off', $verdict['settings']['hash_ip'],              'TEST 18: hash_ip reset');
mig_assert_same(true,  $verdict['ip_notice'],                        'TEST 18: the IP-storage notice is owed');

// TEST 19 — a FRESH install with the same flag '0'. The resets must NOT run: there are no
// broken settings, and the IP notice would be a lie on a site with no data.
$verdict = LegacySettings5460::apply([
    'use_slimstat_banner'  => 'off',
    'javascript_mode'      => 'on',
    'anonymize_ip'         => 'on',   // a fresh default some builds shipped; not "broken by 5.4.x"
    'hash_ip'              => 'off',
    '_migration_5460'      => '0',
], '6.0.0', true, false);

mig_assert_same(true,  $verdict['ran'],                       'TEST 19: fresh install still stamps the flag');
mig_assert_same('on',  $verdict['settings']['anonymize_ip'],  'TEST 19: fresh install: anonymize_ip left alone');
mig_assert_same(false, $verdict['ip_notice'],                 'TEST 19: fresh install: no IP notice');
mig_assert_same('6.0.0', $verdict['settings']['_migration_5460'], 'TEST 19: flag stamped');

// TEST 20 — an upgrader whose flag already holds a pre-5.4.7 version. Resets still run: the
// fresh-install discriminator must not have narrowed the path that already worked.
$verdict = LegacySettings5460::apply([
    'javascript_mode'  => 'off',
    'hash_ip'          => 'on',
    '_migration_5460'  => '5.4.5',
], '6.0.0', false, false);

mig_assert_same('on',  $verdict['settings']['javascript_mode'], 'TEST 20: flagged pre-5.4.7 upgrader still reset');
mig_assert_same('off', $verdict['settings']['hash_ip'],         'TEST 20: hash_ip still reset');

// ═══════════════════════════════════════════════════════════════════════════
// SOURCE-LEVEL: init() must DELEGATE to the class. The whole point of the extraction is that
// this file tests what ships; an inline copy creeping back into init() would put the two
// parsers back one file apart, which is how the regression above survived six months.
// ═══════════════════════════════════════════════════════════════════════════
// COMMENTS BLANKED. The comment above the call in init() names the old guard while explaining
// why it is gone, and a raw scan found it there — the inverse of PITFALLS 112: a comment
// satisfying a must-be-ABSENT check. Only code counts.
require_once __DIR__ . '/lib/source-scan.php';
$boot = slimstat_blank_comments((string) file_get_contents(dirname(__DIR__) . '/wp-slimstat.php'));
mig_assert_same(1, substr_count($boot, 'LegacySettings5460::apply('), 'init() calls LegacySettings5460::apply() exactly once');
mig_assert_same(false, strpos($boot, "version_compare(\$_migration_ran, '5.4.7'"), 'the inline 5.4.7 boundary is gone from wp-slimstat.php');
mig_assert_same(false, strpos($boot, "'0' !== \$_migration_ran"), 'the flag-based fresh-install guard is gone from wp-slimstat.php');

echo "All {$assertions} assertions passed in v5460-settings-migration-test.php\n";
