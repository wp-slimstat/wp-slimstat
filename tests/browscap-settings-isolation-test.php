<?php
/**
 * Regression test: Browscap::update_browscap_database() must only update
 * browscap_last_modified in the DB — it must NOT write the full in-memory
 * wp_slimstat::$settings array.
 *
 * Bug: when Browscap fires its weekly version-check, it wrote the entire
 * wp_slimstat::$settings to slimstat_options. By that point in the request
 * lifecycle the consent-integration sync block (init() lines 287–296) had
 * already set use_slimstat_banner='on' in memory (derived from
 * consent_integration='slimstat_banner'). That in-memory 'on' was then
 * flushed to the DB, overwriting the 'off' the migration had just saved.
 *
 * Fix (src/Services/Browscap.php ~line 153): read the current DB option,
 * update only browscap_last_modified, write that back. Leave all other keys
 * as they are stored in the DB.
 *
 * This test simulates the write logic in isolation (no WP bootstrap needed).
 *
 * @see wp-slimstat/src/Services/Browscap.php (~line 153)
 */

declare(strict_types=1);

$assertions = 0;

function brow_assert_same($expected, $actual, string $msg): void
{
    global $assertions;
    $assertions++;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$msg} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ")\n");
        exit(1);
    }
}

/**
 * Reproduce the FIXED Browscap write logic.
 *
 * $runtime_settings  = wp_slimstat::$settings (in-memory, may have runtime-derived values)
 * $stored_settings   = current DB state (get_option('slimstat_options'))
 * $new_timestamp     = the timestamp being written
 *
 * Returns [$updated_stored, $updated_runtime] — the new DB state and the new in-memory state.
 */
function browscap_write_fixed(array $runtime_settings, array $stored_settings, int $new_timestamp): array
{
    // Fixed behaviour: only touch browscap_last_modified in the stored copy
    $runtime_settings['browscap_last_modified'] = $new_timestamp;
    $stored_settings['browscap_last_modified']  = $new_timestamp;
    // update_option('slimstat_options', $stored_settings)  ← only stored copy written
    return [$stored_settings, $runtime_settings];
}

/**
 * Reproduce the BUGGY Browscap write logic (for reference / contrast).
 */
function browscap_write_buggy(array $runtime_settings, array $stored_settings, int $new_timestamp): array
{
    $runtime_settings['browscap_last_modified'] = $new_timestamp;
    // update_option('slimstat_options', $runtime_settings)  ← entire runtime copy written
    return [$runtime_settings, $runtime_settings];
}

$new_ts = 1700000000;

// ═══════════════════════════════════════════════════════════════════════════
// TEST 1: use_slimstat_banner='off' in DB, 'on' in runtime (the failing scenario)
//
// Simulates: fresh install where migration saved 'off' but the consent-sync
// block then set in-memory to 'on'. Browscap fires; only 'off' must survive.
// ═══════════════════════════════════════════════════════════════════════════
$runtime  = ['use_slimstat_banner' => 'on',  'browscap_last_modified' => 0];
$stored   = ['use_slimstat_banner' => 'off', 'browscap_last_modified' => 0];

[$new_stored, $new_runtime] = browscap_write_fixed($runtime, $stored, $new_ts);

brow_assert_same('off', $new_stored['use_slimstat_banner'],
    'TEST 1: DB use_slimstat_banner must stay off after Browscap write (not clobbered by in-memory on)');
brow_assert_same($new_ts, $new_stored['browscap_last_modified'],
    'TEST 1: DB browscap_last_modified must be updated to new timestamp');
brow_assert_same('on', $new_runtime['use_slimstat_banner'],
    'TEST 1: runtime use_slimstat_banner must remain on (in-memory consistency for current request)');
brow_assert_same($new_ts, $new_runtime['browscap_last_modified'],
    'TEST 1: runtime browscap_last_modified must also be updated');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 2: hash_ip='off' in DB, 'on' in runtime — another setting that
//         must not be clobbered
// ═══════════════════════════════════════════════════════════════════════════
$runtime  = ['hash_ip' => 'on',  'use_slimstat_banner' => 'on',  'browscap_last_modified' => 0];
$stored   = ['hash_ip' => 'off', 'use_slimstat_banner' => 'off', 'browscap_last_modified' => 0];

[$new_stored, $new_runtime] = browscap_write_fixed($runtime, $stored, $new_ts);

brow_assert_same('off', $new_stored['hash_ip'],
    'TEST 2: DB hash_ip must stay off after Browscap write');
brow_assert_same('off', $new_stored['use_slimstat_banner'],
    'TEST 2: DB use_slimstat_banner must stay off after Browscap write');
brow_assert_same($new_ts, $new_stored['browscap_last_modified'],
    'TEST 2: DB browscap_last_modified updated');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 3: Confirm the BUGGY path would have failed TEST 1 (documents the bug)
// ═══════════════════════════════════════════════════════════════════════════
$runtime  = ['use_slimstat_banner' => 'on',  'browscap_last_modified' => 0];
$stored   = ['use_slimstat_banner' => 'off', 'browscap_last_modified' => 0];

[$buggy_stored] = browscap_write_buggy($runtime, $stored, $new_ts);

brow_assert_same('on', $buggy_stored['use_slimstat_banner'],
    'TEST 3: buggy path WOULD write on to DB (documents the root cause)');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 4: Settings that are the same in both runtime and stored are preserved
// ═══════════════════════════════════════════════════════════════════════════
$runtime = ['javascript_mode' => 'on', 'anonymize_ip' => 'off', 'hash_ip' => 'off', 'browscap_last_modified' => 0];
$stored  = ['javascript_mode' => 'on', 'anonymize_ip' => 'off', 'hash_ip' => 'off', 'browscap_last_modified' => 0];

[$new_stored] = browscap_write_fixed($runtime, $stored, $new_ts);

brow_assert_same('on',  $new_stored['javascript_mode'], 'TEST 4: javascript_mode preserved');
brow_assert_same('off', $new_stored['anonymize_ip'],    'TEST 4: anonymize_ip preserved');
brow_assert_same('off', $new_stored['hash_ip'],         'TEST 4: hash_ip preserved');
brow_assert_same($new_ts, $new_stored['browscap_last_modified'], 'TEST 4: timestamp updated');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 5: Verify the fix in the actual source file — browscap_last_modified
//         update reads from DB copy, not runtime copy
// ═══════════════════════════════════════════════════════════════════════════
$source = file_get_contents(dirname(__DIR__) . '/src/Services/Browscap.php');
if ($source === false) {
    fwrite(STDERR, "FAIL: TEST 5: could not read src/Services/Browscap.php\n");
    exit(1);
}

// The fix must NOT use wp_slimstat::$settings as the second arg to update_option
// within the browscap_last_modified block
brow_assert_same(
    false,
    (bool) preg_match(
        '/browscap_last_modified\s*=\s*\$current_timestamp[\s\S]{0,200}update_option\s*\(\s*[\'"]slimstat_options[\'"]\s*,\s*wp_slimstat::\$settings\s*\)/U',
        $source
    ),
    'TEST 5: Browscap must NOT call update_option(slimstat_options, wp_slimstat::$settings) — it must write a copy with only browscap_last_modified updated'
);

// The fix must read the stored option into a local variable before writing
brow_assert_same(
    true,
    (bool) preg_match('/get_option\s*\(\s*[\'"]slimstat_options[\'"]/', $source),
    'TEST 5b: Browscap fix must call get_option(slimstat_options) to read current DB state'
);

echo "All {$assertions} assertions passed in browscap-settings-isolation-test.php\n";
