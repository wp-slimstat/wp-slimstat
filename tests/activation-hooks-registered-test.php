<?php
/**
 * Source-level: the activation and deactivation hooks are registered unconditionally.
 *
 * PINS FIX (C38). Both registrations sat inside `if (is_admin())`:
 *
 *   if (is_admin()) {
 *       include_once(plugin_dir_path(__FILE__) . 'admin/index.php');
 *       register_activation_hook(__FILE__, ['wp_slimstat_admin', 'init_environment']);
 *       register_deactivation_hook(__FILE__, ['wp_slimstat_admin', 'deactivate']);
 *   }
 *
 * `is_admin()` returns `WP_ADMIN` only when that constant is defined, and it is defined
 * in exactly one place — `wp-admin/admin.php:15`. WP-CLI never loads that file, so
 * `is_admin()` is FALSE for every CLI command. Consequences:
 *
 *   - `wp plugin activate wp-slimstat` registers no activation hook, so it creates NO
 *     tables, no indexes, no visit-id counter and never flushes rewrite rules. Every
 *     pageview until an administrator happens to load wp-admin is lost, and the only
 *     recovery is the tracker's failed-INSERT path, which runs the whole DDL plus
 *     flush_rewrite_rules() from an anonymous frontend request.
 *   - `wp plugin deactivate wp-slimstat` registers no deactivation hook, so the five
 *     cron events in src/cron-hooks.php stay scheduled against a deactivated plugin —
 *     precisely what that file exists to prevent.
 *
 * The symptom was already observed and worked around rather than diagnosed:
 * `tests/docker/run-cell.sh:98` forces table creation with the comment "Activation does
 * NOT fire admin_init/init_tables — force table creation (mirrors CI)". So the entire
 * Docker compat matrix has never exercised init_environment().
 *
 * This asserts the CONSTRUCT — that neither registration is nested inside a block
 * guarded by is_admin() — rather than the presence of the function names, which were
 * present all along.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$file        = $plugin_root . '/wp-slimstat.php';
$source      = (string) @file_get_contents($file);

if ('' === $source) {
    fwrite(STDERR, "FAIL: cannot read wp-slimstat.php\n");
    exit(1);
}

$tokens = token_get_all($source);
$count  = count($tokens);

$registrations = ['register_activation_hook', 'register_deactivation_hook'];
$registration_name_types = slimstat_name_token_types();
// Hoisted beside its sibling: built once, not once per token of every argument span.
$callback_name_types     = $registration_name_types + [T_CONSTANT_ENCAPSED_STRING => true];

// ── Token-index ranges of every block guarded by a condition calling is_admin() ──
// Shared with subsite-table-hook-test.php via source-scan.php, which is where the walk
// went when that file's private copy was found testing containment in the wrong UNITS.
$admin_only_ranges = slimstat_guarded_block_ranges($tokens);

// Vacuity guard. If either token helper stops matching, $admin_only_ranges is empty,
// the containment loop below becomes a no-op and this file reports PASS while asserting
// nothing — the exact shape its siblings guard against with a pinned count. There are
// several is_admin() blocks in this file and the scan is worthless without them.
if ([] === $admin_only_ranges) {
    fwrite(STDERR, "FAIL: found no is_admin() block in wp-slimstat.php. Either the file stopped\n"
        . "  using is_admin() entirely, or the token walk is broken — fix the scan rather than\n"
        . "  trusting this run, because with no ranges the containment check asserts nothing.\n");
    exit(1);
}

// ── Assert each registration exists, and none is inside such a block ─────────
$failures = [];
$found    = [];

for ($i = 0; $i < $count; $i++) {
    if (!is_array($tokens[$i]) || !isset($registration_name_types[$tokens[$i][0]])) {
        continue;
    }

    // Compare the LAST SEGMENT: on 8.x `\register_activation_hook` arrives as one token
    // carrying the leading backslash, so a strict compare against the bare name misses it and
    // the gate reports a registration that is right there as absent.
    $name = slimstat_last_name_segment($tokens[$i][1]);
    if (!in_array($name, $registrations, true)) {
        continue;
    }

    $found[$name]  = true;

    // The callback must not name wp_slimstat_admin: that class lives in admin/index.php,
    // which is not loaded on the CLI path the registration was just moved onto. Walking
    // the call's own argument span rather than regexing the file, because a regex over
    // one spelling misses six others — array() syntax, double quotes, ::class, a string
    // callable, a constant first argument, or a different admin-only class.
    $args_end = slimstat_token_paren_end($tokens, $i, $count);
    if (null !== $args_end) {
        for ($k = $i; $k < $args_end; $k++) {
            if (!is_array($tokens[$k])) {
                continue;
            }
            // T_STRING alone is FAIL-OPEN here, and this is the direction that matters: PHP
            // 8.0 collapses `\wp_slimstat_admin::init_environment` into ONE
            // T_NAME_FULLY_QUALIFIED token, which is not T_STRING, so the loop `continue`d
            // past the exact callback the check exists to refuse and the gate printed PASS.
            // The sibling site in subsite-table-hook-test.php has the same blindness pointed
            // the other way -- it stops FINDING a qualified registration, which fails loudly.
            // A scan that goes quiet on 8.x is worth strictly less than one that goes red.
            if (!isset($callback_name_types[$tokens[$k][0]])) {
                continue;
            }
            if (false !== strpos($tokens[$k][1], 'wp_slimstat_admin')) {
                $failures[] = sprintf(
                    'line %d: %s() names wp_slimstat_admin, which lives in admin/index.php and '
                        . 'is not loaded on non-admin requests. The callback must load the admin '
                        . 'bundle itself',
                    $tokens[$i][2],
                    $name
                );
                break;
            }
        }
    }

    foreach ($admin_only_ranges as [$from, $to]) {
        if ($i > $from && $i < $to) {
            $failures[] = sprintf(
                'line %d: %s() is inside an is_admin() block. is_admin() is false under '
                    . 'WP-CLI, so `wp plugin activate` / `wp plugin deactivate` would not fire it',
                $tokens[$i][2],
                $name
            );
            break;
        }
    }
}

foreach ($registrations as $name) {
    if (!isset($found[$name])) {
        $failures[] = "{$name}() is not called at all in wp-slimstat.php";
    }
}

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: activation hooks (' . count($failures) . " problem(s))\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

printf(
    "PASS: %d lifecycle hook(s) registered unconditionally, with self-loading callbacks\n",
    count($found)
);
exit(0);
