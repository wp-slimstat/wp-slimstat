<?php
/**
 * Regression test for slimstat_can_track filter context parameter.
 *
 * This test ensures that filter-based consent integrations can distinguish
 * between normal browser hits and server-side/programmatic calls (e.g., from slimtrack_server()).
 *
 * @see https://github.com/wp-slimstat/wp-slimstat/pull/184#discussion_r2930493335
 * @since 5.4.4
 */

declare(strict_types=1);

$assertions = 0;

function assert_true($actual, string $message): void
{
    global $assertions;
    $assertions++;

    if ($actual !== true) {
        fwrite(STDERR, "FAIL: {$message} (expected true, got " . var_export($actual, true) . ")\n");
        exit(1);
    }
}

function assert_false($actual, string $message): void
{
    global $assertions;
    $assertions++;

    if ($actual !== false) {
        fwrite(STDERR, "FAIL: {$message} (expected false, got " . var_export($actual, true) . ")\n");
        exit(1);
    }
}

function assert_same($expected, $actual, string $message): void
{
    global $assertions;
    $assertions++;

    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message} (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ")\n");
        exit(1);
    }
}

// ─── WordPress function stubs ────────────────────────────────────
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) { return is_string($str) ? trim(strip_tags($str)) : ''; }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return is_string($value) ? stripslashes($value) : $value; }
}

// Filter registry for our stub
$_filter_callbacks = [];

if (!function_exists('add_filter')) {
    function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) {
        global $_filter_callbacks;
        $_filter_callbacks[$tag][] = ['callback' => $callback, 'priority' => $priority, 'accepted_args' => $accepted_args];
    }
}
if (!function_exists('remove_filter')) {
    function remove_filter($tag, $callback, $priority = 10) {
        global $_filter_callbacks;
        if (isset($_filter_callbacks[$tag])) {
            $_filter_callbacks[$tag] = array_filter($_filter_callbacks[$tag], function($f) use ($callback, $priority) {
                return !($f['callback'] === $callback && $f['priority'] === $priority);
            });
        }
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value, ...$args) {
        global $_filter_callbacks;
        if (!isset($_filter_callbacks[$tag])) {
            return $value;
        }
        // Sort by priority
        usort($_filter_callbacks[$tag], fn($a, $b) => $a['priority'] - $b['priority']);
        foreach ($_filter_callbacks[$tag] as $filter) {
            $all_args = array_merge([$value], $args);
            $value = call_user_func_array($filter['callback'], array_slice($all_args, 0, $filter['accepted_args']));
        }
        return $value;
    }
}

// ─── wp_slimstat stub ────────────────────────────────────────────
if (!class_exists('wp_slimstat')) {
    class wp_slimstat {
        public static $settings = [];
        public static $is_programmatic_tracking = false;
    }
}

// ─── Load Consent class ──────────────────────────────────────────
require_once __DIR__ . '/../src/Utils/Consent.php';

use SlimStat\Utils\Consent;

// ─── Reset state helper ──────────────────────────────────────────
function reset_state(): void
{
    global $_filter_callbacks;
    $_filter_callbacks = [];
    wp_slimstat::$is_programmatic_tracking = false;
    wp_slimstat::$settings = [
        'gdpr_enabled' => 'off', // Simplest path - bypass all GDPR checks
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// TEST 1: buildFilterContext() returns correct structure
// ═══════════════════════════════════════════════════════════════════════════

reset_state();
wp_slimstat::$is_programmatic_tracking = false;
$context = Consent::buildFilterContext();
assert_same(false, $context['programmatic'], 'buildFilterContext: programmatic should be false for browser');
assert_same('browser', $context['source'], 'buildFilterContext: source should be "browser" for browser');

wp_slimstat::$is_programmatic_tracking = true;
$context = Consent::buildFilterContext();
assert_same(true, $context['programmatic'], 'buildFilterContext: programmatic should be true for server');
assert_same('server', $context['source'], 'buildFilterContext: source should be "server" for server');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 2: Deny-by-default filter blocks browser tracking
// ═══════════════════════════════════════════════════════════════════════════

reset_state();

// Register a deny-by-default filter (simulating a strict consent plugin)
add_filter('slimstat_can_track', function($default, $context = []) {
    // Strict policy: deny all tracking by default
    return false;
}, 10, 2);

wp_slimstat::$is_programmatic_tracking = false;
$canTrack = Consent::canTrack();
assert_false($canTrack, 'Deny-by-default filter should block browser tracking');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 3: Deny-by-default filter can allow programmatic tracking via context
// ═══════════════════════════════════════════════════════════════════════════

reset_state();

// Register a consent filter that:
// - Denies browser tracking by default
// - Allows programmatic/server-side tracking
add_filter('slimstat_can_track', function($default, $context = []) {
    // If this is a programmatic call (from slimtrack_server()), allow it
    if (!empty($context['programmatic'])) {
        return true;
    }
    // Otherwise deny (strict consent policy for browser hits)
    return false;
}, 10, 2);

// Browser tracking should be denied
wp_slimstat::$is_programmatic_tracking = false;
$canTrack = Consent::canTrack();
assert_false($canTrack, 'Browser tracking should be denied by consent filter');

// Programmatic tracking should be allowed
wp_slimstat::$is_programmatic_tracking = true;
$canTrack = Consent::canTrack();
assert_true($canTrack, 'Programmatic tracking should be allowed via context inspection');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 4: Filter receives context parameter with correct source
// ═══════════════════════════════════════════════════════════════════════════

reset_state();

$capturedContext = null;
add_filter('slimstat_can_track', function($default, $context = []) use (&$capturedContext) {
    $capturedContext = $context;
    return $default;
}, 10, 2);

// Test browser context
wp_slimstat::$is_programmatic_tracking = false;
Consent::canTrack();
assert_same('browser', $capturedContext['source'], 'Context source should be "browser" for browser calls');
assert_same(false, $capturedContext['programmatic'], 'Context programmatic should be false for browser calls');

// Test server context
wp_slimstat::$is_programmatic_tracking = true;
Consent::canTrack();
assert_same('server', $capturedContext['source'], 'Context source should be "server" for server calls');
assert_same(true, $capturedContext['programmatic'], 'Context programmatic should be true for server calls');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 5: Filter with only 1 accepted_arg still works (backward compatibility)
// ═══════════════════════════════════════════════════════════════════════════

reset_state();

// Legacy filter that only accepts $default (no context)
add_filter('slimstat_can_track', function($default) {
    // Legacy filter that doesn't use context - just returns default
    return $default;
}, 10, 1);

wp_slimstat::$is_programmatic_tracking = false;
$canTrack = Consent::canTrack();
assert_true($canTrack, 'Legacy single-arg filter should still work and pass through default');

// ═══════════════════════════════════════════════════════════════════════════
// TEST 6: Context-aware filter can implement sophisticated consent logic
// ═══════════════════════════════════════════════════════════════════════════

reset_state();

// Sophisticated consent filter that:
// - Allows server-side API calls (programmatic)
// - For browser: only allows if some condition is met (simulated by $default)
add_filter('slimstat_can_track', function($default, $context = []) {
    // Server-side calls from cron/CLI/redirect handlers: always allow
    if (!empty($context['programmatic']) && $context['source'] === 'server') {
        return true;
    }
    // Browser calls: respect the default decision (which may be influenced by CMP)
    return $default;
}, 10, 2);

// Browser with default=true should be allowed
wp_slimstat::$is_programmatic_tracking = false;
$canTrack = Consent::canTrack();
assert_true($canTrack, 'Browser tracking with default=true should be allowed');

// Server-side should always be allowed
wp_slimstat::$is_programmatic_tracking = true;
$canTrack = Consent::canTrack();
assert_true($canTrack, 'Server-side tracking should always be allowed by sophisticated filter');

echo "All {$assertions} assertions passed in consent-filter-context-test.php\n";
