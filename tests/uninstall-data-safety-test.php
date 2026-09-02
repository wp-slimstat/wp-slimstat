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
    // Recorded into the SAME list as delete_option(). The derived scan below asks whether an
    // option the plugin mints is removed at all, not which store it lived in — and a network
    // option missing from that list is the same defect as a per-blog one missing from it.
    function delete_site_option($name)
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
        'uploadBase'   => $GLOBALS['__upload_base'],
    ]);
    exit(0);
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
