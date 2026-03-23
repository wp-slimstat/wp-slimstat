<?php
/**
 * Regression test: do_not_track must block tracking regardless of gdpr_enabled.
 *
 * Bug: canTrack() returned early at the "if (!$gdprEnabled)" check before ever
 * reaching the DNT header inspection. Sites with gdpr_enabled=off and
 * do_not_track=on had their DNT setting silently ignored.
 *
 * Fix (src/Utils/Consent.php): Move the DNT check to the very top of canTrack(),
 * before the GDPR early-return, and return false immediately (no filter override).
 *
 * This test simulates the canTrack() decision logic in isolation (no WP bootstrap).
 *
 * @see wp-slimstat/src/Utils/Consent.php (canTrack method)
 */

declare(strict_types=1);

$assertions = 0;

function dnt_assert_same($expected, $actual, string $msg): void
{
    global $assertions;
    $assertions++;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$msg} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ")\n");
        exit(1);
    }
}

/**
 * Reproduce the FIXED canTrack() DNT logic.
 *
 * Returns true if tracking is allowed, false if blocked by DNT or consent.
 * Simplified to only the GDPR / DNT branching — consent details omitted.
 */
function can_track_fixed(array $settings, string $dnt_header): bool
{
    // DNT check — before GDPR early-return
    $respectDnt = ('on' === ($settings['do_not_track'] ?? 'off'));
    if ($respectDnt && '1' === $dnt_header) {
        return false;
    }

    $gdprEnabled = ('on' === ($settings['gdpr_enabled'] ?? 'on'));
    if (!$gdprEnabled) {
        return true; // simplified: no filter, no consent check
    }

    // GDPR on path (simplified: assume consent given for this test scope)
    return true;
}

/**
 * Reproduce the BUGGY canTrack() logic (for documentation / contrast).
 */
function can_track_buggy(array $settings, string $dnt_header): bool
{
    $gdprEnabled = ('on' === ($settings['gdpr_enabled'] ?? 'on'));
    if (!$gdprEnabled) {
        return true; // returns before DNT check
    }

    $respectDnt = ('on' === ($settings['do_not_track'] ?? 'off'));
    if ($respectDnt && '1' === $dnt_header) {
        return false;
    }

    return true;
}

// ═══════════════════════════════════════════════════════════════════════════
// TEST 1: gdpr=off, do_not_track=on, DNT header=1 → must be blocked (THE BUG)
// ═══════════════════════════════════════════════════════════════════════════
$settings = ['gdpr_enabled' => 'off', 'do_not_track' => 'on'];

dnt_assert_same(
    false,
    can_track_fixed($settings, '1'),
    'TEST 1: do_not_track=on + DNT:1 must block tracking even when gdpr_enabled=off'
);

// ═══════════════════════════════════════════════════════════════════════════
// TEST 2: gdpr=off, do_not_track=on, no DNT header → must allow tracking
// ═══════════════════════════════════════════════════════════════════════════
dnt_assert_same(
    true,
    can_track_fixed($settings, ''),
    'TEST 2: do_not_track=on but no DNT header — tracking must be allowed'
);

// ═══════════════════════════════════════════════════════════════════════════
// TEST 3: gdpr=on, do_not_track=on, DNT header=1 → must be blocked (already worked)
// ═══════════════════════════════════════════════════════════════════════════
$settings_gdpr_on = ['gdpr_enabled' => 'on', 'do_not_track' => 'on'];

dnt_assert_same(
    false,
    can_track_fixed($settings_gdpr_on, '1'),
    'TEST 3: do_not_track=on + DNT:1 blocks tracking when gdpr_enabled=on'
);

// ═══════════════════════════════════════════════════════════════════════════
// TEST 4: gdpr=off, do_not_track=off, DNT header=1 → must allow tracking (setting off)
// ═══════════════════════════════════════════════════════════════════════════
$settings_dnt_off = ['gdpr_enabled' => 'off', 'do_not_track' => 'off'];

dnt_assert_same(
    true,
    can_track_fixed($settings_dnt_off, '1'),
    'TEST 4: do_not_track=off — DNT header must be ignored, tracking allowed'
);

// ═══════════════════════════════════════════════════════════════════════════
// TEST 5: Confirm the BUGGY path would have failed TEST 1 (documents the bug)
// ═══════════════════════════════════════════════════════════════════════════
$settings_bug = ['gdpr_enabled' => 'off', 'do_not_track' => 'on'];

dnt_assert_same(
    true, // buggy: returns true (allows tracking) despite DNT:1
    can_track_buggy($settings_bug, '1'),
    'TEST 5: buggy path WOULD allow tracking with gdpr=off + DNT:1 (documents root cause)'
);

// ═══════════════════════════════════════════════════════════════════════════
// TEST 6: Source-level assertion — DNT check appears before !$gdprEnabled check
// ═══════════════════════════════════════════════════════════════════════════
$source = file_get_contents(dirname(__DIR__) . '/src/Utils/Consent.php');
if ($source === false) {
    fwrite(STDERR, "FAIL: TEST 6: could not read src/Utils/Consent.php\n");
    exit(1);
}

$pos_dnt      = strpos($source, 'do_not_track');
$pos_gdpr     = strpos($source, '!$gdprEnabled');

dnt_assert_same(
    true,
    $pos_dnt !== false && $pos_gdpr !== false && $pos_dnt < $pos_gdpr,
    'TEST 6: do_not_track check must appear before !$gdprEnabled check in Consent.php'
);

echo "All {$assertions} assertions passed in dnt-gdpr-independence-test.php\n";
