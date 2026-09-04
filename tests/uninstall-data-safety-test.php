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
    $GLOBALS['__updated_options'] = [];
    $GLOBALS['__switched']        = [];
    $GLOBALS['__multisite']       = false;
    $GLOBALS['__network_options'] = [];
    $GLOBALS['__options_by_blog'] = [];
    $GLOBALS['__current_blog']    = 1;
    $GLOBALS['__updated_site_options'] = [];
    $GLOBALS['__fs_deletes']      = 0;
    $GLOBALS['__fs_deleted']      = [];
    $GLOBALS['__cleared_hooks']   = [];

    // The uploads root the harness reports, and the one every containment check is against.
    //
    // Computed ONCE and reported back in the JSON, because the driver and the child do not
    // agree on sys_get_temp_dir(): proc_open() is handed `$_ENV`, which carries no TMPDIR
    // under the default variables_order, so the child falls back to /var/tmp while the driver
    // sees /var/folders/... A driver-side copy of this expression is two computations of one
    // fact, and they were already disagreeing the first time this ran.
    $GLOBALS['__upload_base'] = rtrim(sys_get_temp_dir(), '/\\') . '/wp-slimstat-test-uploads';
    $upload_base              = $GLOBALS['__upload_base'];

    // Cases carrying a relocation filter, written '<opt-in>@<where the filter points>'.
    //
    // apply_filters() is DEFINED ONLY for these. uninstall.php guards on
    // function_exists('apply_filters'), so a harness that always defined it could never
    // exercise the unfiltered path — and the unfiltered path is what cases 1-3 pin.
    $filter_cases = [
        'on@root'      => ['on',     '/'],
        'on@uploads'   => ['on',     $upload_base],
        'on@traversal' => ['on',     $upload_base . '/wp-slimstat/../../..'],
        'on@inside'    => ['on',     $upload_base . '/moved-geo'],
        'absent@root'  => ['absent', '/'],
    ];

    if (isset($filter_cases[$case])) {
        $GLOBALS['__filter_return'] = $filter_cases[$case][1];
        $case                       = $filter_cases[$case][0];

        function apply_filters($hook, $value)
        {
            return 'slimstat_maxmind_path' === $hook ? $GLOBALS['__filter_return'] : $value;
        }
    }

    // The external-database connection Pro writes — through \wp_slimstat::update_option(), so
    // it lands in FREE's option array and Pro's own uninstall could never reach it.
    $credentials = [
        'addon_custom_db_enable' => 'on',
        'addon_custom_db_dbhost' => 'db.internal.example',
        'addon_custom_db_dbname' => 'analytics',
        'addon_custom_db_dbuser' => 'slimstat_ro',
        'addon_custom_db_dbpass' => 'correct-horse-battery-staple',
    ];

    // 'on'     → explicit opt-in
    // 'no'     → what the Maintenance-tab toggle actually writes when switched off
    // 'absent' → a normal install that never saved that tab (the #327 population)
    // '<case>@ms' runs the identical case through the multisite branch.
    if (substr($case, -3) === '@ms') {
        $GLOBALS['__multisite'] = true;
        $case                   = substr($case, 0, -3);
    }

    if ($case === 'keep-with-credentials') {
        $GLOBALS['__options_by_blog'][1] = $credentials + [
            'delete_data_on_uninstall' => 'no',
            'is_tracking'              => 'on',
        ];
        // The SAME credential in the network store — the only place it can be on a
        // network-activated install, because Pro shows those fields at network level only and
        // wp_slimstat::update_option() routes network-admin writes to update_site_option().
        $GLOBALS['__network_options']['slimstat_options'] = $credentials + [
            'is_tracking' => 'on',
        ];
        // And on a SECOND blog, which only the loop's per-blog call can reach — so only in the
        // multisite variant, where that loop runs at all.
        if ($GLOBALS['__multisite']) {
            $GLOBALS['__options_by_blog'][2] = $credentials + [
                'delete_data_on_uninstall' => 'no',
                'is_tracking'              => 'on',
            ];
        }
    } elseif ($case === 'delete-with-external-db-that-bails') {
        // Composed from $credentials rather than retyped, so a fifth tuple member added there
        // reaches this case too — the same drift uninstall.php's own key list exists to stop.
        $unreachable = ['addon_custom_db_dbhost' => 'bails-on-connect.example'] + $credentials;

        $GLOBALS['__options_by_blog'][1] = $unreachable + [
            'delete_data_on_uninstall' => 'on',
            'is_tracking'              => 'on',
        ];
        $GLOBALS['__network_options']['slimstat_options'] = $unreachable + [
            'is_tracking' => 'on',
        ];
    } elseif ($case === 'delete-with-credentials') {
        $GLOBALS['__options_by_blog'][1] = $credentials + [
            'delete_data_on_uninstall' => 'on',
            'is_tracking'              => 'on',
        ];
    } elseif ($case === 'on') {
        $GLOBALS['__options_by_blog'][1] = ['delete_data_on_uninstall' => 'on'];
    } elseif ($case === 'no') {
        $GLOBALS['__options_by_blog'][1] = ['delete_data_on_uninstall' => 'no'];
    } else {
        $GLOBALS['__options_by_blog'][1] = [];
    }

    // The EXTERNAL-database handle. uninstall.php does `new wpdb(user, pass, name, host)` when
    // the opt-in is set and a connection is configured, and until this class existed that
    // branch fataled — so the one path where an external database is purged had never been
    // reached by any test, only reasoned about.
    class wpdb
    {
        public $queries = [];

        public function __construct($user = '', $pass = '', $name = '', $host = '')
        {
            $GLOBALS['__external_dsn']  = compact('user', 'name', 'host');
            $GLOBALS['__external_wpdb'] = $this;

            // CORE'S wpdb CAN END THE REQUEST HERE — and "can" is the honest word, which an
            // earlier version of this comment got wrong by saying it always does.
            // wpdb::bail() reaches wp_die() only when $show_errors is set, and the constructor
            // sets it only under WP_DEBUG && WP_DEBUG_DISPLAY; otherwise it records the error
            // and RETURNS, and uninstall.php runs on. The unconditional death is the
            // wp-content/db-error.php drop-in, which die()s before that check and which
            // managed hosts ship as a matter of course.
            //
            // So this models the minority path. It is the one worth modelling: it is the only
            // ordering under which anything after line 30 of uninstall.php fails to run, and
            // strip-first is free on every other.
            //
            // NOT MODELLED, and named so this fixture is not mistaken for the general case: the
            // commoner branch returns a handle that is not ready, and every later
            // $slimstat_wpdb->query('DROP TABLE …') then returns false while the external
            // tables quietly survive. That is a different defect from this one.
            if ('bails-on-connect.example' === $host) {
                exit(0);
            }
        }
        public function query($sql) { $this->queries[] = $sql; return 0; }
    }

    // A $wpdb double that records every query(); also serves as the custom-db
    // fallback target ($GLOBALS['wpdb']).
    $GLOBALS['wpdb'] = new class {
        public $prefix      = 'wp_';
        public $base_prefix = 'wp_';
        public $options     = 'wp_options';
        public $blogs       = 'wp_blogs';
        public $siteid      = 1;
        public $queries     = [];
        public function query($sql) { $this->queries[] = $sql; return 0; }
        // Both blogs, as a real network returns. Blog 2 is the one that matters: the strip
        // at the top of uninstall.php already covers whichever blog is current, so only a blog
        // it never stood on can tell the loop's per-blog call from a no-op.
        public function get_col($sql) { return [1, 2]; }
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

    // KEYED ON THE CURRENT BLOG. With one flat store the per-blog strip inside the loop was
    // untestable: the call at the top of uninstall.php already covers whichever blog is
    // current, so moving the loop's call behind the opt-in changed nothing the harness could
    // see. That is V8-02, and it survived until this store could tell blogs apart.
    function get_option($name, $default = false)
    {
        if ('slimstat_options' !== $name) {
            return $default;
        }

        $blog = $GLOBALS['__current_blog'] ?? 1;

        return $GLOBALS['__options_by_blog'][$blog] ?? $default;
    }
    function delete_option($name)
    {
        $GLOBALS['__deleted_options'][] = $name;
        return true;
    }
    // The harness had no update_option at all, so an option WRITTEN BACK was invisible to it —
    // which is the whole mechanism of a surgical strip. Recorded, and the store updated, so a
    // later read in the same run sees what the strip left behind.
    function update_option($name, $value, $autoload = null)
    {
        if ('slimstat_options' === $name) {
            $GLOBALS['__options_by_blog'][$GLOBALS['__current_blog'] ?? 1] = $value;
        }
        $GLOBALS['__updated_options'][$name] = $value;
        return true;
    }
    // Recorded into the SAME list as delete_option(). The derived scan below asks whether an
    // option the plugin mints is removed at all, not which store it lived in — and a network
    // option missing from that list is the same defect as a per-blog one missing from it.
    // THE NETWORK-SCOPED STORE. It did not exist until now, and its absence made
    // slimstat_uninstall_network_credentials() return at its function_exists() guard — so the
    // four assertions about the network copy compared an empty array against a list of keys and
    // passed without the strip running at all. Vacuity in the assertions written to close a
    // vacuity, caught by asking whether the stub they depend on was ever defined.
    function get_site_option($name, $default = false)
    {
        return array_key_exists($name, $GLOBALS['__network_options'])
            ? $GLOBALS['__network_options'][$name]
            : $default;
    }
    function update_site_option($name, $value)
    {
        $GLOBALS['__network_options'][$name]      = $value;
        $GLOBALS['__updated_site_options'][$name] = $value;
        return true;
    }
    function delete_site_option($name)
    {
        unset($GLOBALS['__network_options'][$name]);
        $GLOBALS['__deleted_options'][] = $name;
        return true;
    }
    // Varied by case, because until it was every behavioural assertion in this file ran the
    // single-site branch only — and a step wired into one branch and not the other was
    // invisible to all of them. Measured: V8's defect applied to the multisite branch left the
    // suite green at 63 assertions.
    function is_multisite() { return !empty($GLOBALS['__multisite']); }

    // Reached only once is_multisite() is true. slimstat_uninstall_data_dir() consults these to
    // undo the per-site uploads path; on this fixture the base carries no `/sites/N`, so the
    // str_replace is a no-op and the artifact assertions are unchanged.
    function is_main_network() { return true; }
    function is_main_site() { return true; }
    function get_current_blog_id() { return (int) ($GLOBALS['__current_blog'] ?? 1); }
    function switch_to_blog($blog_id)
    {
        $GLOBALS['__current_blog'] = (int) $blog_id;
        $GLOBALS['__switched'][]   = (int) $blog_id;
        return true;
    }
    function restore_current_blog()
    {
        $GLOBALS['__current_blog'] = 1;
        return true;
    }
    function wp_clear_scheduled_hook($hook)
    {
        $GLOBALS['__cleared_hooks'][] = $hook;
    }
    function wp_upload_dir()
    {
        return ['basedir' => $GLOBALS['__upload_base']];
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

    // Emitted from a SHUTDOWN HANDLER, so a case that ends the request mid-uninstall — the
    // unreachable-host one — still reports what the store looked like when it stopped. A plain
    // echo after the require prints nothing there, and the driver calls that "returned no
    // JSON": a failure naming the harness instead of the subject.
    register_shutdown_function('slimstat_uninstall_emit');

    require __DIR__ . '/../uninstall.php';

    // Reached only when uninstall.php ran to the end. The shutdown handler emits either way,
    // so without this flag a case that died halfway now produces a PARTIAL STORE THAT LOOKS
    // LIKE A RESULT — where before the emitter existed it produced no JSON and a named
    // failure. The flag buys that loudness back for the eleven cases that must complete.
    $GLOBALS['__completed'] = true;

    slimstat_uninstall_emit();
    exit(0);
}

function slimstat_uninstall_emit()
{
    static $emitted = false;

    if ($emitted) {
        return;
    }
    $emitted = true;

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
        'uploadBase'   => $GLOBALS['__upload_base'],
        'updated'      => $GLOBALS['__updated_options'],
        'optionsNow'   => $GLOBALS['__options_by_blog'][1] ?? [],
        'blog2Options' => $GLOBALS['__options_by_blog'][2] ?? null,
        'networkOptionsNow' => $GLOBALS['__network_options']['slimstat_options'] ?? [],
        'externalDsn'  => $GLOBALS['__external_dsn'] ?? null,
        'externalSql'  => isset($GLOBALS['__external_wpdb']) ? $GLOBALS['__external_wpdb']->queries : [],
        'completed'    => !empty($GLOBALS['__completed']),
    ]);
}

// ─────────────────────────────── DRIVER MODE ────────────────────────────────

// Required here rather than beside its first assertion below: run_case() now calls
// slimstat_spawn_child() out of it.
require_once __DIR__ . '/lib/source-scan.php';

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
    // The spawn itself is slimstat_spawn_child(); the selector-as-explicit-env reasoning that
    // used to live here moved onto it, with the second caller that arrived in the same commit.
    $child = slimstat_spawn_child(__FILE__, ['SLIMSTAT_UNINSTALL_CASE' => $case]);

    if (null === $child) {
        fail("could not spawn child for case '{$case}'");
    }

    $result = json_decode($child['stdout'], true);
    if (!is_array($result)) {
        fail("child for case '{$case}' returned no JSON.\nSTDOUT: {$child['stdout']}"
            . "\nSTDERR: {$child['stderr']}");
    }

    // Every case but the deliberate bail must have reached the end of uninstall.php. Without
    // this, the shutdown emitter turns "died halfway" into a result the assertions read as
    // real — quietly, and for whichever case broke rather than the one testing a break.
    if ('delete-with-external-db-that-bails' !== $case && empty($result['completed'])) {
        fail("child for case '{$case}' stopped before the end of uninstall.php");
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
    // NOT "nothing deleted" — "no ANALYTICS option deleted". The network activation cursor is
    // scheduler bookkeeping like the cron entries beside it, so it goes on both paths; the
    // options this opt-in protects are the ones that must survive. Written as a difference so a
    // new always-deleted key has to be added here deliberately rather than widening silently.
    assert_same(
        [],
        array_values(array_diff($result['deleted'], [
            'slimstat_network_activation_pending',
            'slimstat_network_activation_attempting',
        ])),
        "no analytics option is deleted when the key is '{$case}'"
    );
    assert_true($result['clearedHooks'] !== [], "cron hooks cleared when the key is '{$case}'");

    // NOTHING IS WRITTEN BACK when there is nothing to strip. The `updated` payload existed and
    // no assertion read it, so the guard that produces this property was unkillable — an
    // unconditional write passed every case in this file.
    assert_same(
        [],
        $result['updated'],
        "no option is written back when there is no credential to remove, key '{$case}'"
    );

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

// Cases 4-8 — `slimstat_maxmind_path` must not be able to aim the recursive delete.
//
// The next statement after that filter is `$wp_filesystem->delete($upload_dir, true, 'd')`,
// so whatever a third-party filter returns is what gets recursively removed. 5.5.0 did not
// apply the filter here at all; 6.0.0 added it so a site that MOVED the directory would not
// keep everything after uninstall, and in doing so routed an arbitrary string into a
// recursive delete. No in-tree consumer returns a broader path — the hazard is that nothing
// stops one, and the blast radius runs from the whole uploads directory to the filesystem root.
//
// The sibling consumer was checked and is NOT affected: MaxmindGeoIPProvider's
// `$wp_filesystem->delete($extractDir, true)` targets `<filtered>/.mmdb_extract_<random>`,
// a directory that call just created, not the filtered path itself.
foreach ([
    'on@root'      => 'the filesystem root',
    'on@uploads'   => 'the whole uploads directory',
    'on@traversal' => 'a path that climbs out of uploads with ..',
    'absent@root'  => 'the filesystem root, on the KEEP-data path (where the delete is scoped '
        . 'to a child, so it would have removed /browscap-cache-master)',
] as $case => $what) {
    $escaped = run_case($case);
    assert_same(
        0,
        $escaped['fsDeletes'],
        "nothing is deleted when slimstat_maxmind_path returns {$what}; got: "
            . implode(', ', $escaped['fsDeleted'])
    );
}

// THE CONTROL, and without it the four above are satisfied by ignoring the filter entirely —
// which would restore the 5.5.0 defect the filter was added to fix. A filter that stays inside
// the uploads directory must still be honoured, at exactly the path it names.
$moved = run_case('on@inside');
assert_same(1, $moved['fsDeletes'], 'a relocation inside uploads is still honoured');
assert_same(
    $moved['uploadBase'] . '/moved-geo',
    $moved['fsDeleted'][0] ?? null,
    'the honoured relocation deletes the directory the filter named'
);

// Cases 9 & 10 — the external-database password must not survive an uninstall, on ANY path.
//
// Pro writes `addon_custom_db_dbpass` through `\wp_slimstat::update_option('slimstat_options')`,
// so the credential lives inside FREE's option array. Free deletes that array only inside
// slimstat_uninstall(), which runs only when `delete_data_on_uninstall` is 'on' — and that
// defaults to 'no'. So before this change the password survived three of the four ways a site
// can remove this software:
//
//   delete Pro only ................................. survives (free's uninstall never runs)
//   delete free, keep data (THE DEFAULT) ............ survives
//   delete both, keep data (THE DEFAULT) ............ survives
//   delete free with delete-data ON ................. removed
//
// A SECRET IS NOT "DATA". The opt-in exists to protect a site's analytics — the numbers someone
// might want back after a reinstall. It was never meant to preserve a database password the
// site can no longer use, and a Pro-side uninstall.php cannot reach this array at all.
$credential_keys = [
    'addon_custom_db_dbhost',
    'addon_custom_db_dbname',
    'addon_custom_db_dbuser',
    'addon_custom_db_dbpass',
];

// BOTH BRANCHES, the same assertions. `@ms` re-runs the identical case with is_multisite()
// true, so the multisite loop is executed rather than reasoned about — the gap that let a
// defect on that branch pass a suite of 63 assertions.
foreach (['keep-with-credentials', 'keep-with-credentials@ms'] as $keep_case) {
    $kept = run_case($keep_case);

    foreach ($credential_keys as $credential_key) {
        assert_true(
            !array_key_exists($credential_key, $kept['optionsNow']),
            "'{$credential_key}' is gone on the KEEP-data path ({$keep_case}) — the default one, "
                . 'and the one where the option array survives'
        );
    }

    // AND FROM THE NETWORK-SCOPED COPY. wp_slimstat::update_option() routes to
    // update_site_option() whenever is_network_admin() is true, and Pro's Custom DB fields are
    // only shown at network level once Pro is network-activated — so on those installs sitemeta
    // is the ONLY place the credential ever was. The first version of this strip read
    // get_option() alone and missed it on every one of the four uninstall paths.
    foreach ($credential_keys as $credential_key) {
        assert_true(
            !array_key_exists($credential_key, $kept['networkOptionsNow']),
            "'{$credential_key}' is gone from the NETWORK settings too ({$keep_case})"
        );
    }

    // THE CONTROL FOR THE FOUR ABOVE. Without it they are satisfied by an empty array — which
    // is exactly what they compared against before the network store existed, and by deleting
    // the network settings wholesale, which the keep-data path must not do.
    assert_true(
        array_key_exists('is_tracking', $kept['networkOptionsNow']),
        "the network settings array itself survives, minus the tuple ({$keep_case})"
    );

    // A BLOG THE EARLY STRIP NEVER STOOD ON. Only the per-blog call inside the loop reaches it,
    // so this is the one assertion that can tell that call from a no-op.
    if (null !== $kept['blog2Options']) {
        foreach ($credential_keys as $credential_key) {
            assert_true(
                !array_key_exists($credential_key, $kept['blog2Options']),
                "'{$credential_key}' is gone from a SECOND blog's settings ({$keep_case}) — only "
                    . 'the per-blog call inside the loop can do that'
            );
        }
        assert_true(
            array_key_exists('is_tracking', $kept['blog2Options']),
            "and that blog's other settings survive ({$keep_case})"
        );
    }
}

// THE CONTROL. Without it "strip the credentials" is satisfied by deleting the whole array,
// which is the one thing the keep-data path must not do: these are the settings a reinstall is
// supposed to find waiting.
assert_true(
    array_key_exists('is_tracking', $kept['optionsNow']),
    'and the settings the opt-in protects are still there — the strip is surgical, not a delete'
);
assert_true(
    !in_array('slimstat_options', $kept['deleted'], true),
    'slimstat_options itself is NOT deleted on the keep-data path'
);
// The non-secret toggle stays: it says whether the feature was on, which is a setting, and
// leaving it makes the strip's boundary explicit rather than incidental.
assert_true(
    array_key_exists('addon_custom_db_enable', $kept['optionsNow']),
    'the on/off toggle is a setting, not a credential, and survives'
);

// The opt-in path removes the whole array, so the credential goes with it — asserted rather
// than assumed, because it is the one path that was already correct and the easiest to break.
$purged = run_case('delete-with-credentials');
// Restated here for readability at this case rather than as independent coverage: case 3
// already asserts it, and delete_option('slimstat_options') runs unconditionally inside
// slimstat_uninstall(), so no change can break one without the other.
assert_true(
    in_array('slimstat_options', $purged['deleted'], true),
    'the opt-in path still deletes the whole option array, credential included'
);

// AND THE PURGE GOES TO THE RIGHT SERVER. This branch — `new wpdb(...)` from the stored
// connection — had never been reached by any test before the wpdb double above existed; it was
// reasoned about and not run. Dropping an external install's tables against the CORE handle
// would silently leave the analytics behind on the server that holds them while reporting
// success, and the strip above must not break the handle, which is built before it runs.
$external_drops = count(array_filter($purged['externalSql'], static function ($sql) {
    return false !== stripos($sql, 'DROP TABLE');
}));
assert_true($external_drops >= 7, "the external database receives the DROPs, got {$external_drops}");
assert_same(0, $purged['drops'], 'and the core database receives none of them');
assert_same(
    'db.internal.example',
    $purged['externalDsn']['host'] ?? null,
    'the handle was opened against the configured host'
);

// THE REQUEST THAT ENDS BEFORE THE UNINSTALL DOES. Core's wpdb constructor reaches wp_die()
// when it cannot connect, so on an install whose external database is configured and gone,
// uninstall.php stops at that line — before the blog loop, before the artifact cleanup, before
// everything. The credential strip runs above it for exactly that reason, and this case is the
// only thing that can tell that ordering from the obvious one.
$bailed = run_case('delete-with-external-db-that-bails');
foreach ($credential_keys as $credential_key) {
    assert_true(
        !array_key_exists($credential_key, $bailed['optionsNow']),
        "'{$credential_key}' is gone even though the request died at the external connection"
    );
    assert_true(
        !array_key_exists($credential_key, $bailed['networkOptionsNow']),
        "'{$credential_key}' is gone from the network settings too, on that same dead request"
    );
}
// THE CONTROL: the request really did stop AT THE CONNECTION.
//
// It used to assert `drops === 0`, and that was vacuous — `drops` counts the CORE handle, and
// the completed twin of this case asserts `drops === 0` too, thirty lines up, because its DROPs
// all go to the external one. The control could not fail for the reason it gave.
//
// externalSql is the first thing downstream of the constructor: zero queries on the handle that
// was just built is the tightest available statement that nothing after it ran.
assert_same([], $bailed['externalSql'], 'not one query reached the handle the request died building');
assert_same(0, $bailed['queries'], 'and none reached the core handle either');
assert_same([], $bailed['deleted'], 'and no option was deleted, so the strip is the only thing that ran');

// The harness defines its own WP_Filesystem(), so it cannot exercise the case where
// WordPress has not loaded the File API. Pin the guard at source level instead — the
// same approach browscap-wp-filesystem-test.php takes for the identical hazard.
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

// EVERY SINGLE-SITE STEP ALSO RUNS IN THE LOOP — derived, not enumerated.
//
// The first version of this counted three hand-written names and required each to appear twice.
// That is a spell-check: it cannot see a FOURTH step added to one branch, and review proved it
// cannot see the defect it was written for either — moving the credential strip inside the
// opt-in on the multisite branch alone leaves the count at two and every behavioural case green.
// The behavioural half now runs both branches (`@ms` above); this is the structural half, and it
// compares the SETS, so a step added to one branch has to be accounted for without anyone
// editing this file.
$multisite_block = (string) strstr(
    (string) strstr($uninstall_src, 'foreach ($blogids as $blog_id) {'),
    'restore_current_blog();',
    true
);
$single_block = (string) strstr(
    (string) strstr($uninstall_src, "} else {\n    slimstat_uninstall_cron();"),
    'slimstat_uninstall_artifacts(',
    true
);

$branch_steps = [];
foreach (['multisite' => $multisite_block, 'single-site' => $single_block] as $branch => $block) {
    assert_true($block !== '', "the {$branch} branch was located in uninstall.php");
    preg_match_all('/\b(slimstat_uninstall\w*)\s*\(/', $block, $found);
    $branch_steps[$branch] = array_values(array_unique($found[1]));
    sort($branch_steps[$branch]);
}

// VACUITY FLOOR, per branch. Four steps in the loop (the opt-in purge, credentials, cron,
// transients) and three on the single-site path, which does not need the per-blog credential
// strip. Floors on BOTH, because a floor only on the larger set turns "the loop lost a step"
// into a message about the extraction being broken — a failure naming the harness instead of
// the subject, which is the complaint this file makes elsewhere.
assert_true(
    count($branch_steps['multisite']) >= 4,
    'the branch extraction still finds the multisite steps, found: '
        . implode(', ', $branch_steps['multisite'])
);
assert_true(
    count($branch_steps['single-site']) >= 3,
    'the branch extraction still finds the single-site steps, found: '
        . implode(', ', $branch_steps['single-site'])
);
// BOTH DIRECTIONS, with the one legitimate asymmetry NAMED — which is what a plain subset
// check gives up. Measured: with only the subset half, a step added to the multisite loop and
// forgotten in the single-site branch left the suite green, and that is the more natural
// editing order of the two. Equality would have caught it, but equality also mandates the
// redundant single-site credential call that was just removed as dead — so the exemption is
// listed instead, and a second one has to be argued for here rather than slipped in.
$multisite_only = ['slimstat_uninstall_credentials'];

assert_same(
    [],
    array_values(array_diff($branch_steps['single-site'], $branch_steps['multisite'])),
    'every uninstall step on the single-site path also runs inside the multisite loop'
);
assert_same(
    $multisite_only,
    array_values(array_diff($branch_steps['multisite'], $branch_steps['single-site'])),
    'and the loop carries exactly one step the single-site path does not: the per-blog '
        . 'credential strip, which a single site does not need because the call at the top of '
        . 'uninstall.php already stands on its only blog'
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

// 15 measured at 6.0.0 — 13, plus the two network-activation options. A LOWER count means the
// walk or the regex broke, not that options were removed; re-measure and lower this
// deliberately if a constant genuinely goes away.
assert_true(
    count($minted_options) >= 15,
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
