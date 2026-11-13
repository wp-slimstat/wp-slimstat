<?php
/** Execute the real invalidation helper and spam callback, including no-op/error paths. */
require __DIR__ . '/lib/source-scan.php';
define('ABSPATH', '/');
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; $GLOBALS['autoload'][$key] = $autoload; return true; }
function do_action($hook, ...$args) {}
function apply_filters($hook, $value) { return $value; }
function get_userdata($id) { return false; }
$source = file_get_contents(dirname(__DIR__) . '/wp-slimstat.php');
$body = slimstat_function_body($source, 'bump_data_version');
eval('class wp_slimstat { public static function bump_data_version() {' . $body . '} }');
require dirname(__DIR__) . '/admin/index.php';
class VersionDb {
    public $prefix = 'wp_';
    public $affected = 0;
    public function prepare($sql, ...$args) { return $sql; }
    public function query($sql) { return $this->affected; }
}
$GLOBALS['wpdb'] = $db = new VersionDb();
foreach ([0, false, 2] as $affected) {
    $GLOBALS['options'] = [];
    $db->affected = $affected;
    wp_slimstat_admin::remove_spam('spam', 'approved', (object) ['comment_author_IP' => '192.0.2.1']);
    if (isset($GLOBALS['options']['slimstat_data_ver']) !== ($affected > 0)) {
        throw new RuntimeException('Only a successful deletion must bump the data version');
    }
}
$before = $GLOBALS['options']['slimstat_data_ver'];
usleep(1000);
wp_slimstat::bump_data_version();
if ($before === $GLOBALS['options']['slimstat_data_ver'] || true !== $GLOBALS['autoload']['slimstat_data_ver']) {
    throw new RuntimeException('Each bump changes the autoloaded version');
}
echo "PASS: data version changes only after deleted rows, and autoloads for report requests\n";
