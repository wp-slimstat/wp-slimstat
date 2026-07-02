<?php
/**
 * Regression: uninstall.php must only delete data when the user explicitly opted
 * in via "Delete Data on Uninstall".
 *
 * The option has no default, so on a normal install the key is absent. The old
 * gate `isset(key) && 'on' != key` was false for an absent key, so it fell
 * through and DROPped every table + deleted every option + removed the uploads
 * dir. This test proves an absent key now RETAINS data, and that 'on' still deletes.
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
    $GLOBALS['__options']         = $case === 'on'
        ? ['delete_data_on_uninstall' => 'on']
        : []; // key deliberately absent

    // A $wpdb double that records every query(); also serves as the custom-db
    // fallback target ($GLOBALS['wpdb']).
    $GLOBALS['wpdb'] = new class {
        public $prefix      = 'wp_';
        public $base_prefix = 'wp_';
        public $options     = 'wp_options';
        public $queries     = [];
        public function query($sql) { $this->queries[] = $sql; return 0; }
        public function esc_like($text) { return $text; }
        public function prepare($sql, ...$args) { return $sql; }
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
    function wp_clear_scheduled_hook($hook) {}
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
        'queries'   => count($GLOBALS['wpdb']->queries),
        'drops'     => $drops,
        'deleted'   => $GLOBALS['__deleted_options'],
        'fsDeletes' => $GLOBALS['__fs_deletes'],
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
    // Set the selector in the parent; the child inherits it via proc_open's
    // default (null) environment.
    putenv("SLIMSTAT_UNINSTALL_CASE={$case}");
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc        = proc_open(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__),
        $descriptors,
        $pipes
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

// Case 1 — opt-out key ABSENT (fresh install): nothing may be deleted.
$absent = run_case('absent');
assert_same(0, $absent['queries'], 'no DB queries at all when the key is absent');
assert_same([], $absent['deleted'], 'no options deleted when the key is absent');
assert_same(0, $absent['fsDeletes'], 'uploads dir not deleted when the key is absent');

// Case 2 — explicit opt-in: deletion proceeds as before.
$on = run_case('on');
assert_true($on['drops'] >= 7, 'DROP TABLE runs when delete_data_on_uninstall is on');
assert_true(in_array('slimstat_options', $on['deleted'], true), 'slimstat_options deleted when opted in');
assert_same(1, $on['fsDeletes'], 'uploads dir deleted when opted in');

fwrite(STDOUT, "OK: {$assertions} assertions passed (uninstall deletes only on explicit opt-in)\n");
exit(0);
