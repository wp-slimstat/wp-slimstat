<?php
/**
 * Regression test: JS params must mirror the PHP banner dual-condition guard.
 *
 * Bug: enqueue_tracker() passed use_slimstat_banner='on' to JS even when
 * gdpr_enabled='off'. The old minified JS used this flag to gate _send_pageview —
 * it entered banner-init mode, set a "ran" lock, and never called _send_pageview,
 * silently dropping every pageview for all visitors.
 *
 * Fix: guard the param with the same dual condition used by the PHP banner output
 * (lines 305-306 of wp-slimstat.php): both gdpr_enabled=on AND use_slimstat_banner=on
 * must be true before JS is told the banner is active.
 *
 * Also verifies the fresh-install default for javascript_mode is 'on' (Client mode),
 * which is required for sites running WP Rocket, W3TC, or any page-caching plugin.
 *
 * @see wp-slimstat/wp-slimstat.php:1191 (use_slimstat_banner param guard)
 * @see wp-slimstat/wp-slimstat.php:937  (javascript_mode fresh-install default)
 */

declare(strict_types=1);

$assertions = 0;

function jsbanner_assert_same($expected, $actual, string $message): void
{
    global $assertions;
    $assertions++;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ")\n");
        exit(1);
    }
}

/**
 * Reproduce the fixed param-building expression from wp-slimstat.php:1191.
 * Tests the logic directly without loading the full WP stack.
 */
function compute_banner_param(string $gdpr_enabled_param, string $use_slimstat_banner_setting): string
{
    return ('on' === $gdpr_enabled_param && 'on' === $use_slimstat_banner_setting) ? 'on' : 'off';
}

// ═══════════════════════════════════════════════════════════════════════════
// TEST 1: gdpr_enabled=on + use_slimstat_banner=on → param must be 'on'
// ═══════════════════════════════════════════════════════════════════════════
jsbanner_assert_same(
    'on',
    compute_banner_param('on', 'on'),
    'TEST 1: banner param must be on when both gdpr_enabled and use_slimstat_banner are on'
);

// ═══════════════════════════════════════════════════════════════════════════
// TEST 2: gdpr_enabled=off + use_slimstat_banner=on → param must be 'off'
//
// This is the exact bug scenario: gdpr_enabled is off (GDPR not active) but
// use_slimstat_banner=on is still in settings (e.g. migrated from old install).
// JS must receive 'off' so it does NOT enter banner-init mode.
// ═══════════════════════════════════════════════════════════════════════════
jsbanner_assert_same(
    'off',
    compute_banner_param('off', 'on'),
    'TEST 2 (bug scenario): banner param must be off when gdpr_enabled=off, even if use_slimstat_banner=on'
);

// ═══════════════════════════════════════════════════════════════════════════
// TEST 3: gdpr_enabled=off + use_slimstat_banner=off → param must be 'off'
// ═══════════════════════════════════════════════════════════════════════════
jsbanner_assert_same(
    'off',
    compute_banner_param('off', 'off'),
    'TEST 3: banner param must be off when both gdpr_enabled and use_slimstat_banner are off'
);

// ═══════════════════════════════════════════════════════════════════════════
// TEST 4: gdpr_enabled=on + use_slimstat_banner=off → param must be 'off'
//
// GDPR enabled but admin chose not to show the SlimStat banner — JS must
// not enter banner-init mode.
// ═══════════════════════════════════════════════════════════════════════════
jsbanner_assert_same(
    'off',
    compute_banner_param('on', 'off'),
    'TEST 4: banner param must be off when gdpr_enabled=on but use_slimstat_banner=off'
);

// ═══════════════════════════════════════════════════════════════════════════
// TEST 5: Fresh-install default javascript_mode must be 'on' (Client mode)
//
// Server mode requires PHP to execute per request. When page-caching plugins
// (WP Rocket, W3TC, etc.) serve cached HTML, PHP never runs, slimtrack() never
// fires, and enqueue_tracker() silently returns false without loading the JS tracker.
// Worse: the first visitor's signed ID is baked into the cached HTML; subsequent
// visitors silently update the first visitor's DB record instead of creating
// their own pageview (silent data corruption, not just zero tracking).
// Client mode works correctly regardless of caching.
// ═══════════════════════════════════════════════════════════════════════════

// Parse the actual value from wp-slimstat.php so this test catches a regression
// if the default is accidentally reverted to 'off'.
$plugin_source = file_get_contents(dirname(__DIR__) . '/wp-slimstat.php');
if ($plugin_source === false) {
    fwrite(STDERR, "FAIL: TEST 5: could not read wp-slimstat.php\n");
    exit(1);
}

preg_match("/'javascript_mode'\s*=>\s*'(on|off)'/", $plugin_source, $js_mode_matches);

jsbanner_assert_same(
    1,
    count($js_mode_matches) >= 2 ? 1 : 0,
    'TEST 5a: javascript_mode default definition must exist in wp-slimstat.php'
);

jsbanner_assert_same(
    'on',
    $js_mode_matches[1] ?? '',
    'TEST 5b: fresh-install javascript_mode default must be "on" (Client mode) — Server mode breaks all page-caching sites'
);

echo "All {$assertions} assertions passed in js-params-banner-gdpr-consistency-test.php\n";
