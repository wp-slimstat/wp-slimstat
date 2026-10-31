<?php
/** #363: missing-cache recovery and failed replacement must preserve working data. */
function __($s, $domain = '') { return $s; }
class WP_Error { public function get_error_message() { return 'test extraction failure'; } }
function is_wp_error($v) { return $v instanceof WP_Error; }
function get_option($key, $default = []) { return $GLOBALS['stored']; }
function wp_next_scheduled($hook) { return $GLOBALS['scheduled'][$hook] ?? false; }
function wp_schedule_single_event($time, $hook) { $GLOBALS['scheduled'][$hook] = $time; }
function wp_remote_get($url, $args = []) {
    $GLOBALS['requests'][] = $url;
    if (isset($args['filename'])) { file_put_contents($args['filename'], "PK\x03\x04fixture"); }
    return ['response' => ['code' => 200], 'body' => '2'];
}
function wp_remote_retrieve_response_code($r) { return $r['response']['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function wp_delete_file($path) { if (is_file($path)) { unlink($path); } }
function WP_Filesystem($credentials, $context) { $GLOBALS['wp_filesystem'] = new class { public function move($from, $to) { return !empty($GLOBALS['fail_publish']) && strpos($from, 'browscap-staging/') !== false ? false : rename($from, $to); } public function delete($path, $recursive = false, $type = '') { return BrowscapFixtureFiles::rmdir($path); } }; return true; }
function unzip_file($zip, $directory) {
    if ($GLOBALS['fail_extract']) { return new WP_Error(); }
    mkdir($directory . '/browscap-cache-master', 0700, true);
    file_put_contents($directory . '/browscap-cache-master/version.txt', '2');
    file_put_contents($directory . '/browscap-cache-master/cache.data', 'new-data');
    return true;
}
class wp_slimstat {
    public static $upload_dir;
    public static $settings = ['enable_browscap' => 'on', 'browscap_last_modified' => 0];
    public static function update_option($name, $value) { $GLOBALS['stored'] = $value; }
    public static function create_upload_directory() { mkdir(self::$upload_dir, 0700, true); }
    public static function log($message, $level) { $GLOBALS['logs'][] = $message; }
}
class BrowscapFixtureFiles {
    public static function rmdir($dir) {
        if (!is_dir($dir)) { return true; }
        foreach (scandir($dir) as $name) { if ($name !== '.' && $name !== '..') { $path = $dir . '/' . $name; is_dir($path) ? self::rmdir($path) : unlink($path); } }
        return rmdir($dir);
    }
}
require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/src/Services/Browscap.php';
use SlimStat\Services\Browscap;
$root = sys_get_temp_dir() . '/slimstat-browscap-test-' . bin2hex(random_bytes(8));
wp_slimstat::$upload_dir = $root;
$stored = ['enable_browscap' => 'on', 'browscap_last_modified' => 0, 'use_slimstat_banner' => 'off'];
$requests = $logs = $scheduled = [];
$fail_extract = true;
$checks = 0;
function recovery_check($ok, $message) { global $checks; ++$checks; if (!$ok) { throw new RuntimeException($message); } }
try {
    $browser = ['browser' => 'Default Browser'];
    recovery_check(Browscap::get_browser_from_browscap($browser, $root . '/browscap-cache-master/') === $browser, 'missing data returns unchanged fallback');
    recovery_check(!$logs && !is_dir($root), 'missing data causes no log or adapter directory creation');
    Browscap::init();
    recovery_check(!$requests && isset($scheduled['wp_slimstat_update_browscap']), 'frontend only schedules recovery, never downloads');
    Browscap::update_browscap_database(false);
    recovery_check(count($requests) === 1, 'missing version attempts download');
    recovery_check($stored['use_slimstat_banner'] === 'off', 'stored settings preserved');
    Browscap::update_browscap_database(false);
    recovery_check(count($requests) === 1, 'failed recovery is throttled');
    mkdir($root . '/browscap-cache-master', 0700, true);
    file_put_contents($root . '/browscap-cache-master/version.txt', '1');
    file_put_contents($root . '/browscap-cache-master/cache.data', 'old-data');
    $result = Browscap::update_browscap_database(true);
    recovery_check($result[0] !== 0, 'forced update reports extraction failure');
    recovery_check(strpos($result[1], 'test extraction failure') !== false, 'extraction failure retains the actionable WordPress diagnostic');
    recovery_check(file_get_contents($root . '/browscap-cache-master/cache.data') === 'old-data', 'failed extraction preserves active data');
    $fail_extract = false;
    $fail_publish = true;
    $result = Browscap::update_browscap_database(true);
    recovery_check($result[0] !== 0 && file_get_contents($root . '/browscap-cache-master/cache.data') === 'old-data', 'failed publish restores previous data');
    $fail_publish = false;
    $lock = fopen($root . '/browscap-update.lock', 'c');
    flock($lock, LOCK_EX);
    $count = count($requests);
    $result = Browscap::update_browscap_database(true);
    recovery_check($result[0] !== 0 && count($requests) === $count, 'concurrent update refuses without network or data change');
    flock($lock, LOCK_UN); fclose($lock);
    $result = Browscap::update_browscap_database(true);
    recovery_check($result[0] === 0 && file_get_contents($root . '/browscap-cache-master/cache.data') === 'new-data', 'forced update bypasses throttle and replaces complete cache');
    wp_slimstat::$settings['enable_browscap'] = 'no';
    wp_slimstat::$settings['browscap_last_modified'] = 0;
    $count = count($requests);
    Browscap::update_browscap_database(false);
    recovery_check(count($requests) === $count, 'disabled library never downloads automatically');
    echo "PASS: $checks Browscap recovery assertions\n";
} finally { BrowscapFixtureFiles::rmdir($root); }
