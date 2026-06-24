<?php

/**
 * Fix 1 (#273 follow-up) — the Access Log author cell must render the
 * capability-guarded "edit user profile" pencil.
 *
 * Root cause: get_edit_profile_link() was built *for the Access Log* but only
 * wired into raw_results_to_html() (the standard reports table). The actual
 * Access Log renders via admin/view/right-now.php, which never called it — so
 * no pencil appeared and "clicking the pencil" did nothing.
 *
 * Guards both halves:
 *   (a) get_edit_profile_link() returns a well-formed, capability-gated anchor
 *       (empty string when the current user cannot edit the target).
 *   (b) right-now.php wires the call into the logged-in-user branch of the
 *       author cell, guarded on a resolved WP user object.
 *
 * Run: php tests/access-log-author-edit-link-test.php
 */

declare(strict_types=1);

$failures = [];
function aael_assert(bool $cond, string $label, array &$failures): void
{
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    if (!$cond) {
        $failures[] = $label;
    }
}

// --- WP shims used by get_edit_profile_link() (resolved at call time only) ---
$GLOBALS['__aael_edit_link'] = '';
if (!function_exists('get_edit_user_link')) {
    function get_edit_user_link($user_id = 0)
    {
        // WordPress returns '' when the current user cannot edit $user_id, which
        // is exactly the capability gate the pencil relies on.
        return $GLOBALS['__aael_edit_link'];
    }
}
if (!function_exists('esc_url')) {
    function esc_url($v)
    {
        return $v;
    }
}
if (!function_exists('esc_attr__')) {
    function esc_attr__($t, $d = 'default')
    {
        return $t;
    }
}
if (!function_exists('__')) {
    function __($t, $d = 'default')
    {
        return $t;
    }
}

$plugin_root = dirname(__DIR__);
require_once $plugin_root . '/admin/view/wp-slimstat-reports.php';

// (a) behavioural — present (and correctly formed) when editable.
$GLOBALS['__aael_edit_link'] = 'https://example.test/wp-admin/user-edit.php?user_id=7';
$link = wp_slimstat_reports::get_edit_profile_link(7);
aael_assert(strpos($link, 'user-edit.php?user_id=7') !== false, 'editable user -> anchor links to the profile edit screen', $failures);
aael_assert(strpos($link, 'slimstat-author-profile-link') !== false, 'editable user -> anchor carries the slimstat-author-profile-link class', $failures);
aael_assert(strpos($link, 'slimstat-font-edit') !== false, 'editable user -> anchor renders the pencil glyph', $failures);

// (a) behavioural — empty when the current user cannot edit the target.
$GLOBALS['__aael_edit_link'] = '';
aael_assert(wp_slimstat_reports::get_edit_profile_link(7) === '', 'non-editable user -> no pencil (empty string)', $failures);

// (b) source — the Access Log (right-now.php) wires the call into the author cell.
$rightnow = (string) file_get_contents($plugin_root . '/admin/view/right-now.php');
aael_assert(
    strpos($rightnow, 'wp_slimstat_reports::get_edit_profile_link(') !== false,
    'right-now.php calls get_edit_profile_link() (the Access Log renders the pencil)',
    $failures
);
// Guarded on a resolved WP user object (mirrors the avatar guard) and keyed on
// $user->ID, so guests/unknown logins never get a pencil.
aael_assert(
    (bool) preg_match('/if\s*\(\s*\$user\s*\)\s*\{[^}]*get_edit_profile_link\(\s*\$user->ID\s*\)/s', $rightnow),
    'the Access Log pencil is guarded by if ($user) and uses $user->ID',
    $failures
);

echo "\n";
if ($failures) {
    echo count($failures) . " FAILURE(S)\n";
    exit(1);
}
echo "ALL PASS\n";
exit(0);
