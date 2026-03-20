<?php
/**
 * WP Slimstat — Unit tests for Processor::isUserExcluded()
 *
 * @package    SlimStat
 * @subpackage Tests
 * @license    GPL-2.0-or-later
 * @copyright  2026 WP Slimstat contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

/**
 * Verifies that user exclusion logic works correctly with defensive
 * wp_get_current_user() resolution, covering all three exclusion types:
 * - ignore_wp_users toggle (exclude all logged-in users)
 * - ignore_capabilities (role slug AND capability key blacklist)
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
 *
 * @param array $allcaps Associative array of capability => true/false.
 *                       If empty, defaults are derived from roles.
 */
function make_user(int $id, string $login = '', array $roles = [], array $allcaps = []): object
{
    $user = new stdClass();
    $user->ID = $id;
    $user->roles = $roles;
    $user->allcaps = $allcaps;
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

// Test 13: ignore_capabilities matches a capability key (manage_options), not just role slug
set_settings(['ignore_capabilities' => 'manage_options']);
set_current_user(make_user(1, 'admin', ['administrator'], ['manage_options' => true, 'edit_posts' => true, 'read' => true]));
assert_true(
    \SlimStat\Tracker\Processor::isUserExcluded(),
    'Test 13: User with manage_options capability should be excluded by ignore_capabilities=manage_options'
);

// Test 14: ignore_capabilities does NOT match a capability key the user lacks
set_settings(['ignore_capabilities' => 'manage_options']);
set_current_user(make_user(2, 'editor_user', ['editor'], ['edit_posts' => true, 'read' => true]));
assert_false(
    \SlimStat\Tracker\Processor::isUserExcluded(),
    'Test 14: Editor without manage_options should NOT be excluded by ignore_capabilities=manage_options'
);

// Test 15: ignore_capabilities matches capability key even when role slug does not match
set_settings(['ignore_capabilities' => 'edit_posts']);
set_current_user(make_user(3, 'contributor', ['contributor'], ['edit_posts' => true, 'read' => true]));
assert_true(
    \SlimStat\Tracker\Processor::isUserExcluded(),
    'Test 15: User with edit_posts capability should be excluded even if role slug "contributor" is not blacklisted'
);

// Test 16: Capability with granted=false is NOT matched
set_settings(['ignore_capabilities' => 'manage_options']);
set_current_user(make_user(4, 'limited', ['subscriber'], ['manage_options' => false, 'read' => true]));
assert_false(
    \SlimStat\Tracker\Processor::isUserExcluded(),
    'Test 16: Capability with granted=false should NOT trigger exclusion'
);

// ─── Report ──────────────────────────────────────────────────────

echo "All {$assertions} assertions passed in " . basename(__FILE__) . "\n";
