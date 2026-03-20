<?php
/**
 * Unit tests for Processor::isUserExcluded().
 *
 * Verifies that user exclusion logic works correctly with defensive
 * wp_get_current_user() resolution, covering all three exclusion types:
 * - ignore_wp_users toggle (exclude all logged-in users)
 * - ignore_capabilities (role/capability blacklist)
 * - ignore_users (username blacklist)
 *
 * @see https://github.com/wp-slimstat/wp-slimstat/issues/246
 * @since 5.4.5
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

// ─── WordPress function stubs ────────────────────────────────────

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

// Simulate the WP_User object that wp_get_current_user() returns
$_test_current_user = null;

if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user()
    {
        global $_test_current_user;
        return $_test_current_user;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) { return is_string($str) ? trim(strip_tags($str)) : ''; }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return is_string($value) ? stripslashes($value) : $value; }
}

// ─── wp_slimstat stub ────────────────────────────────────────────

class wp_slimstat
{
    public static $settings = [];

    public static function string_to_array($_option = '')
    {
        if (empty($_option) || !is_string($_option)) {
            return [];
        }
        return array_filter(array_map('trim', explode(',', $_option)));
    }
}

// ─── Load the classes under test ─────────────────────────────────

require_once __DIR__ . '/../src/Tracker/Utils.php';
require_once __DIR__ . '/../src/Tracker/Processor.php';

// ─── Test helpers ────────────────────────────────────────────────

/**
 * Create a mock WP_User object with given properties.
 */
function make_user(int $id, string $login = '', array $roles = []): object
{
    $user = new stdClass();
    $user->ID = $id;
    $user->roles = $roles;
    $user->data = new stdClass();
    $user->data->ID = $id;
    $user->data->user_login = $login;
    $user->data->user_email = $login ? "{$login}@example.com" : '';
    return $user;
}

function set_current_user(?object $user): void
{
    global $_test_current_user;
    $_test_current_user = $user;
}

function set_settings(array $overrides): void
{
    wp_slimstat::$settings = array_merge([
        'ignore_wp_users'    => 'off',
        'ignore_users'       => '',
        'ignore_capabilities' => '',
    ], $overrides);
}

// ─── Tests ───────────────────────────────────────────────────────

// Test 1: ignore_wp_users=on excludes logged-in user
set_settings(['ignore_wp_users' => 'on']);
set_current_user(make_user(1, 'admin', ['administrator']));
assert_true(
    \SlimStat\Tracker\Processor::isUserExcluded(),
    'Test 1: ignore_wp_users=on should exclude logged-in admin'
);

// Test 2: ignore_wp_users=off does NOT exclude logged-in user
set_settings(['ignore_wp_users' => 'off']);
set_current_user(make_user(1, 'admin', ['administrator']));
assert_false(
    \SlimStat\Tracker\Processor::isUserExcluded(),
    'Test 2: ignore_wp_users=off should NOT exclude logged-in admin'
);

// Test 3: Anonymous user (ID=0) is NOT excluded even with ignore_wp_users=on
set_settings(['ignore_wp_users' => 'on']);
set_current_user(make_user(0));
assert_false(
    \SlimStat\Tracker\Processor::isUserExcluded(),
    'Test 3: Anonymous user (ID=0) should NOT be excluded'
);

// Test 4: Null user (wp_get_current_user not resolved) is NOT excluded
set_settings(['ignore_wp_users' => 'on']);
set_current_user(null);
assert_false(
    \SlimStat\Tracker\Processor::isUserExcluded(),
    'Test 4: Null user should NOT be excluded'
);

// Test 5: ignore_users blacklist excludes matching username
set_settings(['ignore_wp_users' => 'off', 'ignore_users' => 'parhumm']);
set_current_user(make_user(1, 'parhumm', ['administrator']));
assert_true(
    \SlimStat\Tracker\Processor::isUserExcluded(),
    'Test 5: Username in ignore_users blacklist should be excluded'
);

// Test 6: ignore_users blacklist does NOT exclude non-matching username
set_settings(['ignore_wp_users' => 'off', 'ignore_users' => 'parhumm']);
set_current_user(make_user(2, 'editor_user', ['editor']));
assert_false(
    \SlimStat\Tracker\Processor::isUserExcluded(),
    'Test 6: Username NOT in ignore_users should NOT be excluded'
);

// Test 7: ignore_capabilities excludes matching role
set_settings(['ignore_capabilities' => 'editor']);
set_current_user(make_user(2, 'editor_user', ['editor']));
assert_true(
    \SlimStat\Tracker\Processor::isUserExcluded(),
    'Test 7: User with editor role should be excluded by ignore_capabilities=editor'
);

// Test 8: ignore_capabilities does NOT exclude non-matching role
set_settings(['ignore_capabilities' => 'editor']);
set_current_user(make_user(1, 'admin', ['administrator']));
assert_false(
    \SlimStat\Tracker\Processor::isUserExcluded(),
    'Test 8: Admin should NOT be excluded by ignore_capabilities=editor'
);

// Test 9: Multiple comma-separated usernames in blacklist
set_settings(['ignore_wp_users' => 'off', 'ignore_users' => 'alice, bob, charlie']);
set_current_user(make_user(3, 'bob', ['subscriber']));
assert_true(
    \SlimStat\Tracker\Processor::isUserExcluded(),
    'Test 9: bob should be excluded from comma-separated blacklist'
);

// Test 10: Multiple comma-separated capabilities
set_settings(['ignore_capabilities' => 'editor, subscriber']);
set_current_user(make_user(4, 'sub_user', ['subscriber']));
assert_true(
    \SlimStat\Tracker\Processor::isUserExcluded(),
    'Test 10: Subscriber should be excluded from comma-separated capabilities'
);

// Test 11: User with multiple roles, one matches blacklist
set_settings(['ignore_capabilities' => 'shop_manager']);
set_current_user(make_user(5, 'multiuser', ['editor', 'shop_manager']));
assert_true(
    \SlimStat\Tracker\Processor::isUserExcluded(),
    'Test 11: User with shop_manager role (among others) should be excluded'
);

// Test 12: Empty ignore_users does NOT exclude anyone
set_settings(['ignore_wp_users' => 'off', 'ignore_users' => '']);
set_current_user(make_user(1, 'admin', ['administrator']));
assert_false(
    \SlimStat\Tracker\Processor::isUserExcluded(),
    'Test 12: Empty ignore_users should NOT exclude anyone'
);

// ─── Report ──────────────────────────────────────────────────────

echo "All {$assertions} assertions passed in " . basename(__FILE__) . "\n";
