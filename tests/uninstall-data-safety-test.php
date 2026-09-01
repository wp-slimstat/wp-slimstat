<?php
/**
 * Regression: uninstall.php must only delete collected analytics when the user
 * explicitly opted in via "Delete Data on Uninstall".
 *
 * The option has no default, so on a normal install the key is absent. The old
 * gate `isset(key) && 'on' != key` was false for an absent key, so it fell
 * through and DROPped every table + deleted every option + removed the uploads
 * dir. This test proves an absent key now RETAINS data, and that 'on' still deletes.
 *
 * It also pins the second half of the contract: cron entries and the REGENERABLE
 * browscap cache are cleaned up on EVERY uninstall, opt-in or not, because they are
 * scheduler bookkeeping and a rebuildable cache rather than the analytics the opt-in
 * protects — leaving them orphans wp-cron entries and strands disk space forever.
 *
 * The GeoIP database is deliberately NOT in that set: on hosts without ext-phar the
 * plugin cannot download it and instructs users to upload the .mmdb by hand, so
 * removing it on the keep-my-data path would destroy a file the plugin cannot
 * replace. It goes only on the explicit opt-in path.
 *
 * uninstall.php declares a top-level function, so it can't be required twice in
 * one process. The driver spawns one child PHP process per case.
 *
 * 7.4-safe: plain PHP, no PHPUnit, no vendor autoload.
 */

declare(strict_types=1);

$case = getenv('SLIMSTAT_UNINSTALL_CASE');

if ($case !== false && $case !== '') {
    // ─────────────────────────── HARNESS MODE (child process) ───────────────
    define('WP_UNINSTALL_PLUGIN', true);

    $GLOBALS['__deleted_options'] = [];
    $GLOBALS['__fs_deletes']      = 0;
    $GLOBALS['__fs_deleted']      = [];
    $GLOBALS['__cleared_hooks']   = [];

    // 'on'     → explicit opt-in
    // 'no'     → what the Maintenance-tab toggle actually writes when switched off
    // 'absent' → a normal install that never saved that tab (the #327 population)
    if ($case === 'on') {
        $GLOBALS['__options'] = ['delete_data_on_uninstall' => 'on'];
    } elseif ($case === 'no') {
        $GLOBALS['__options'] = ['delete_data_on_uninstall' => 'no'];
    } else {
        $GLOBALS['__options'] = [];
    }

    // A $wpdb double that records every query(); also serves as the custom-db
    // fallback target ($GLOBALS['wpdb']).
    $GLOBALS['wpdb'] = new class {
        public $prefix      = 'wp_';
        public $base_prefix = 'wp_';
        public $options     = 'wp_options';
        public $queries     = [];
        public function query($sql) { $this->queries[] = $sql; return 0; }
        public function esc_like($text)
        {
            // FAITHFUL, not an identity stub. With `return $text;` no gate could tell whether
            // uninstall.php escaped its LIKE patterns at all — and `_` is a single-character
            // wildcard, so an unescaped `_transient_slimstat_` also matches `XtransientYslimstatZ`.
            // Mirrors wpdb::esc_like().
            return addcslashes($text, '_%\\');
        }
        public function prepare($sql, ...$args)
        {
            // SUBSTITUTES, rather than returning the template. A double that hands back
            // `... LIKE %s` records SQL in which no real prefix ever appears, so any assertion
            // about WHICH keys are swept passes vacuously against a placeholder.
            // Mirrors wpdb: a lone array argument IS the argument list.
            if (1 === count($args) && is_array($args[0])) {
                $args = $args[0];
            }
            foreach ($args as $arg) {
                // str_replace with a count of 1, NOT preg_replace: a replacement string
                // containing a backslash is reinterpreted by preg_replace, and every pattern
                // here now carries them.
                $pos = strpos($sql, '%s');
                if (false !== $pos) {
                    $sql = substr_replace($sql, "'" . $arg . "'", $pos, 2);
                }
            }
            return $sql;
        }
    };

    function get_option($name, $default = false)
    {
        return $name === 'slimstat_options' ? $GLOBALS['__options'] : $default;
    }
    function delete_option($name)
    {
        $GLOBALS['__deleted_options'][] = $name;
        return true;
    }
    function is_multisite() { return false; }
    function wp_clear_scheduled_hook($hook)
    {
        $GLOBALS['__cleared_hooks'][] = $hook;
    }
    function wp_upload_dir()
    {
        return ['basedir' => sys_get_temp_dir() . '/wp-slimstat-test-uploads'];
    }
    function WP_Filesystem()
    {
        $GLOBALS['wp_filesystem'] = new class {
            public function delete($file, $recursive = false, $type = false)
            {
                $GLOBALS['__fs_deletes']++;
                $GLOBALS['__fs_deleted'][] = $file;
                return true;
            }
        };
        return true;
    }

    require __DIR__ . '/../uninstall.php';

    $drops = 0;
    foreach ($GLOBALS['wpdb']->queries as $q) {
        if (stripos($q, 'DROP TABLE') !== false) {
            $drops++;
        }
    }
    echo json_encode([
        'queries'      => count($GLOBALS['wpdb']->queries),
        // The SQL itself, not just a count. "How many queries" cannot distinguish a transient
        // sweep from a DROP TABLE, and the assertion this replaced required zero of either.
        'sql'          => array_values($GLOBALS['wpdb']->queries),
        'drops'        => $drops,
        'deleted'      => $GLOBALS['__deleted_options'],
        'fsDeletes'    => $GLOBALS['__fs_deletes'],
        'fsDeleted'    => $GLOBALS['__fs_deleted'],
        'clearedHooks' => $GLOBALS['__cleared_hooks'],
    ]);
    exit(0);
}

// ─────────────────────────────── DRIVER MODE ────────────────────────────────
$assertions = 0;

function fail($message)
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}
function assert_same($expected, $actual, $message)
{
    global $assertions;
    $assertions++;
    if ($expected !== $actual) {
        fail($message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
    }
}
function assert_true($cond, $message)
{
    global $assertions;
    $assertions++;
    if ($cond !== true) {
        fail($message);
    }
}

function run_case($case)
{
    // Pass the selector explicitly rather than relying on putenv() leaking into
    // proc_open's default (null) environment — that inheritance is not guaranteed
    // across platforms/SAPIs, and a child that silently ran the wrong case would
    // make every assertion below vacuous.
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc        = proc_open(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__),
        $descriptors,
        $pipes,
        null,
        ['SLIMSTAT_UNINSTALL_CASE' => $case] + $_ENV
    );
    if (!is_resource($proc)) {
        fail("could not spawn child for case '{$case}'");
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $result = json_decode($out, true);
    if (!is_array($result)) {
        fail("child for case '{$case}' returned no JSON.\nSTDOUT: {$out}\nSTDERR: {$err}");
    }
    return $result;
}

// The cron hooks themselves are pinned statically by cron-hook-cleanup-test.php;
// here we only prove the clearing path is REACHED on each of the three routes.

// Cases 1 & 2 — no opt-in. `absent` is the #327 population (never saved the
// Maintenance tab); `no` is what the toggle actually writes when switched off.
// Neither may touch collected analytics, but both must still clean up artifacts.
foreach (['absent', 'no'] as $case) {
    $result = run_case($case);

    // RE-ANCHORED, and the previous form was asserting the defect. It required ZERO queries on
    // the keep-data path — which is the DEFAULT since 5.5.1, so it is what almost every
    // uninstall does — and zero queries is precisely why sixteen families of transient
    // survived every uninstall and reinstall. admin/index.php records 2,146 accumulated
    // wp_slimstat_query_* rows on the reference install.
    //
    // What must be true is not "no queries" but "no query that touches analytics". Exactly one
    // DELETE now runs, and it must be against the options table and name only transients.
    assert_same(1, $result['queries'], "exactly one cleanup query when the key is '{$case}'");
    assert_true(
        1 === preg_match('/^DELETE FROM \S*options WHERE/i', trim((string) $result['sql'][0])),
        "the keep-data query is a DELETE against the options table, got: " . ($result['sql'][0] ?? '(none)')
    );
    assert_true(
        0 === preg_match('/slim_stats|slim_events|slim_user_agents|slim_meta/i', (string) $result['sql'][0]),
        'the keep-data query names no analytics table'
    );
    // Counts the ESCAPED form. Counting `_transient_` would pass against an unescaped
    // pattern too, which is the thing esc_like() is there to prevent.
    assert_true(
        substr_count((string) $result['sql'][0], '\\_transient\\_') >= 6,
        'the keep-data query sweeps all three transient families and their timeout twins, '
            . 'with LIKE wildcards escaped: ' . ($result['sql'][0] ?? '(none)')
    );
    assert_same([], $result['deleted'], "no options deleted when the key is '{$case}'");
    assert_true($result['clearedHooks'] !== [], "cron hooks cleared when the key is '{$case}'");

    // Exactly one filesystem delete, and it must be the browscap cache — NOT the
    // parent directory, which also holds a hand-uploaded GeoIP database.
    assert_same(1, $result['fsDeletes'], "one artifact removed when the key is '{$case}'");
    assert_true(
        substr((string) $result['fsDeleted'][0], -22) === '/browscap-cache-master',
        "only the browscap cache is removed when the key is '{$case}', got: " . $result['fsDeleted'][0]
    );
}

// Case 3 — explicit opt-in: deletion proceeds as before, whole directory included.
$on = run_case('on');
assert_true($on['drops'] >= 7, 'DROP TABLE runs when delete_data_on_uninstall is on');
assert_true(in_array('slimstat_options', $on['deleted'], true), 'slimstat_options deleted when opted in');
assert_true(in_array('slimstat_degradations', $on['deleted'], true), 'slimstat_degradations deleted when opted in');
assert_true(in_array('slimstat_daily_salt', $on['deleted'], true), 'the IP-hash salt is deleted when opted in');
assert_same(1, $on['fsDeletes'], 'uploads dir deleted when opted in');
assert_true(
    substr((string) $on['fsDeleted'][0], -12) === '/wp-slimstat',
    'the whole uploads dir is removed when opted in, got: ' . $on['fsDeleted'][0]
);
assert_true($on['clearedHooks'] !== [], 'cron hooks cleared when opted in');

// The harness defines its own WP_Filesystem(), so it cannot exercise the case where
// WordPress has not loaded the File API. Pin the guard at source level instead — the
// same approach browscap-wp-filesystem-test.php takes for the identical hazard.
require_once __DIR__ . '/lib/source-scan.php';
// Comments blanked, strings kept: the option names matched below live INSIDE string
// literals, so strip_comments_and_strings() would erase the subject. Blanking comments is
// what stops a commented-out delete_option() line from satisfying these checks.
$uninstall_src = slimstat_blank_comments(
    (string) file_get_contents(dirname(__DIR__) . '/uninstall.php')
);
assert_true(
    strpos($uninstall_src, "require_once ABSPATH . 'wp-admin/includes/file.php'") !== false,
    'uninstall.php loads the WP File API before calling WP_Filesystem() (WP-CLI does not preload it)'
);
assert_true(
    strpos($uninstall_src, 'if (!WP_Filesystem())') !== false,
    'uninstall.php bails out when WP_Filesystem() cannot initialise, instead of calling delete() on null'
);

// Derived, not enumerated: every option this plugin mints as a `*OPTION*` constant must
// actually be deleted when the user opts in. Written because 6.0.0 added
// `slimstat_heatmap_recovery_watermark` with no line in uninstall.php — and widening the
// scan past src/Migration then found three more the hand-maintained list had never had:
// `slimstat_schema_column_drift`, `slimstat_visit_id_counter` (a different key from the
// `slimstat_visit_id` that WAS listed), and the two bare-literal options below.
//
// The subject is $on['deleted'] — what the child process ACTUALLY deleted when it ran
// uninstall.php with the opt-in set — not the file's source text. A source scan cannot
// tell whether a delete_option() line is reachable, and would be satisfied by one sitting
// after an early return.
$plugin_root    = dirname(__DIR__);
$minted_options = [];
foreach (slimstat_own_php_files(
    [$plugin_root . '/src', $plugin_root . '/admin', $plugin_root . '/wp-slimstat.php'],
    $plugin_root . '/src/Dependencies'
) as $path) {
    $body = slimstat_blank_comments((string) file_get_contents($path));
    // Both spellings are in use: OPTION_NAME (prefix) and SALT_OPTION (suffix).
    if (preg_match_all("/const\s+[A-Z0-9_]*OPTION[A-Z0-9_]*\s*=\s*'([a-z0-9_]+)'/", $body, $m)) {
        foreach ($m[1] as $option_name) {
            $minted_options[$option_name] = slimstat_rel_path($plugin_root, $path);
        }
    }
}

// 13 measured at 6.0.0. A LOWER count means the walk or the regex broke, not that options
// were removed — re-measure and lower this deliberately if a constant genuinely goes away.
assert_true(
    count($minted_options) >= 13,
    'the option-constant scan still finds its subject (else every check below is vacuous), found: '
        . count($minted_options)
);
foreach ($minted_options as $option_name => $declared_in) {
    assert_true(
        in_array($option_name, $on['deleted'], true),
        "opting in deletes '{$option_name}', minted by {$declared_in} — an option the plugin creates has to be removable"
    );
}

// Known residual, written down rather than left to be rediscovered: an option minted from
// a bare literal has no constant for the scan above to find. Two exist, and both are
// pinned by name here so that deleting their delete_option() lines still reds something.
// The real fix is a manifest beside the table one in Schema.php (the C16 shape, see the
// table list above); it is deliberately deferred past the 6.0.0 beta.
foreach (['wp_slimstat_notifications', 'slimstat_purge_optimized_at'] as $literal_option) {
    assert_true(
        in_array($literal_option, $on['deleted'], true),
        "opting in deletes '{$literal_option}' (minted from a bare literal, so no scan finds it)"
    );
}

fwrite(STDOUT, "OK: {$assertions} assertions passed (analytics deleted only on explicit opt-in; cron + browscap cache always cleaned, GeoIP DB preserved)\n");
exit(0);
