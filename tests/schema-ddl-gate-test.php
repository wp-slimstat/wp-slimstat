<?php
/**
 * Source-level: schema DDL is gated by capability, request type and a claim lock.
 *
 * PINS FIX (S3, S5). `update_tables_and_options()` is hooked on `admin_init`, and
 * `admin_init` is NOT "an admin page" — `wp-admin/admin-ajax.php` fires it too. The
 * path had no capability check anywhere on it: `wp-slimstat.php` gates only on
 * `is_user_logged_in()`, and `wp_slimstat_admin::init()` gates on nothing. So a
 * SUBSCRIBER opening /wp-admin/profile.php, or any Heartbeat tick, or any autosave,
 * could trigger the whole schema upgrade — `DROP COLUMN plugins`, four full-rebuild
 * `ADD COLUMN`s, up to nine index builds, and a full-table UPDATE.
 *
 * It also had no mutual exclusion, and the version stamp is the LAST statement of the
 * body — so two admins plus a Heartbeat gave three concurrent runs of the same DDL,
 * and a run killed by max_execution_time left no record, so the next request restarted
 * from 4.8.2.
 *
 * Three properties, each of which the other two do not imply:
 *
 *   1. CAPABILITY — only a user who could legitimately upgrade the plugin may trigger
 *      its schema upgrade.
 *   2. REQUEST TYPE — not from admin-ajax, cron or REST. These are background and
 *      third-party surfaces where a multi-minute ALTER has no business, and where the
 *      user behind the request never sees the result.
 *   3. MUTUAL EXCLUSION — an atomic claim, so concurrent requests cannot both run it.
 *      NOT `add_option()`: core decides whether the option exists with a PHP-level
 *      `get_option()` pre-check and then issues `INSERT ... ON DUPLICATE KEY UPDATE`,
 *      which overwrites — so the unique index never rejects anything and two requests
 *      can both believe they hold it. A raw INSERT that lets the index reject the loser
 *      is what makes the claim atomic. `wp_cache_add()` is atomic only against a
 *      persistent object cache, which most wp.org installs lack.
 *
 * The lock must be released on EVERY exit path, including the deliberate early return
 * the notes conversion uses to resume — otherwise a resumable migration locks itself
 * out until the stale-lock timeout, which is the opposite of resumable.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$source      = (string) @file_get_contents($plugin_root . '/admin/index.php');

if ('' === $source) {
    fwrite(STDERR, "FAIL: cannot read admin/index.php\n");
    exit(1);
}

$failures = [];

$wrapper = slimstat_function_body($source, 'update_tables_and_options');
if ('' === $wrapper) {
    fwrite(STDERR, "FAIL: update_tables_and_options() not found — this test guards its entry\n"
        . "  conditions; if it moved, follow it rather than deleting this file.\n");
    exit(1);
}

$guard = slimstat_function_body($source, 'may_run_schema_ddl');
if ('' === $guard) {
    $failures[] = 'may_run_schema_ddl() not found. The entry conditions belong in one named '
        . 'place so the CREATE-TABLE repair paths can adopt them once activation is fixed — '
        . 'inline copies of a security gate drift';
}

// ── 1. Capability ───────────────────────────────────────────────────────────
if ('' !== $guard && !preg_match("/current_user_can\(\s*'manage_options'\s*\)/", $guard)) {
    $failures[] = "the DDL gate does not require 'manage_options'. admin_init fires on "
        . 'admin-ajax.php, so without it a subscriber loading their profile page triggers '
        . 'DROP COLUMN, four table rebuilds and a full-table UPDATE';
}

// ── 2. Request type ─────────────────────────────────────────────────────────
foreach (['wp_doing_ajax', 'wp_doing_cron'] as $probe) {
    if ('' !== $guard && false === strpos($guard, $probe . '(')) {
        $failures[] = "the DDL gate does not exclude {$probe}() requests. Schema DDL on a "
            . 'background surface runs where nobody sees the result and where a multi-minute '
            . 'ALTER blocks work the user did not ask for';
    }
}
if ('' !== $guard && false === strpos($guard, 'REST_REQUEST')) {
    $failures[] = 'the DDL gate does not exclude REST requests. The tracker itself is a REST '
        . 'route, so this is the difference between an upgrade and an outage';
}

// ── 3. Kill switch ──────────────────────────────────────────────────────────
// wp.org has no staged rollout, no canary and no remote kill switch, so a constant is
// the only abort that can exist. This is one of its honour points, not all of them.
if ('' !== $guard && false === strpos($guard, 'SLIMSTAT_DISABLE_MIGRATIONS')) {
    $failures[] = 'the DDL gate does not honour SLIMSTAT_DISABLE_MIGRATIONS. It is the only '
        . 'abort mechanism a wp.org plugin can offer, and this is the most dangerous path it '
        . 'has to cover';
}

// ── 4. Mutual exclusion, claimed atomically ─────────────────────────────────
if (false === strpos($wrapper, 'claim_schema_lock')) {
    $failures[] = 'the upgrade takes no lock. The version stamp is the last statement of the '
        . 'body, so without one, concurrent admin requests all re-enter from the first legacy '
        . 'branch and run the same DDL simultaneously';
}

$claim = slimstat_function_body($source, 'claim_schema_lock');
if ('' === $claim) {
    $failures[] = 'claim_schema_lock() not found — without it the assertions below are vacuous';
} else {
    // Must let the unique index do the rejecting, not a PHP-level pre-check.
    if (false !== strpos($claim, 'add_option(')) {
        $failures[] = 'the lock is claimed with add_option(), which is NOT atomic: core '
            . 'pre-checks with get_option() and then issues INSERT ... ON DUPLICATE KEY '
            . 'UPDATE, so the index never rejects and two requests can both claim it';
    }
    if (false === strpos($claim, 'INSERT INTO')) {
        $failures[] = 'the lock is not claimed with a raw INSERT. Only letting the option_name '
            . 'unique index reject the loser makes this atomic on an install with no '
            . 'persistent object cache — which is most of them';
    }
    // Stale takeover must be conditional, or two requests seeing the same stale claim
    // both proceed — in the scenario where re-entry is most expensive.
    if (!preg_match('/UPDATE.*?WHERE\s+option_name\s*=\s*%s\s+AND\s+option_value\s*=\s*%s/s', $claim)) {
        $failures[] = 'the stale-claim takeover is unconditional. Two requests that both '
            . 'observe the same dead claim would both win; the UPDATE must match the exact '
            . 'value observed';
    }
}

// ── 5. The lock is released on every exit path ──────────────────────────────
// Including the deliberate early return the notes conversion uses to resume. A
// resumable migration that locks itself out is not resumable.
if (false === strpos($wrapper, 'finally')) {
    $failures[] = 'the lock is not released in a finally block. update_tables_and_options() has '
        . 'three exits — success, caught throw, and the early return that lets the notes '
        . 'conversion resume — and a lock released on only some of them strands the rest';
}

// ── 6. The lock option is removable ─────────────────────────────────────────
// Same convention notes-migration-bounded-test.php pins: every option this plugin
// creates has to be removable.
$uninstall = (string) @file_get_contents($plugin_root . '/uninstall.php');
if (false === strpos($uninstall, 'slimstat_schema_upgrade_lock')) {
    $failures[] = 'uninstall.php does not delete slimstat_schema_upgrade_lock';
}

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: schema DDL gate (' . count($failures) . " problem(s))\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

echo "PASS: schema DDL is capability-gated, request-type-gated, kill-switchable and "
    . "single-flight\n";
exit(0);
