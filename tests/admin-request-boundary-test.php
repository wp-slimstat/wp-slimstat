<?php
require __DIR__ . '/lib/source-scan.php';
function apply_filters($hook, $value) { return $value; }
function current_user_can($capability) { return $GLOBALS['allowed']; }
function is_network_admin() { return false; }
function wp_verify_nonce($nonce, $action) { return $nonce === 'valid'; }
function wp_unslash($value) { return stripslashes($value); }
class wp_slimstat { public static $wpdb; public static $settings = ['capability_can_admin' => 'manage_options']; }
$GLOBALS['wpdb'] = new class {
    public $prefix = 'wp_', $queries = [];
    public $bound = [];
    public function esc_like($value) { return addcslashes($value, '_%\\'); }
    public function prepare($sql, $id) { if (is_array($id)) { $this->bound = $id; return $sql; } return str_replace('%d', (string) $id, $sql); }
    public function query($sql) {
        if (empty($GLOBALS['expect_delete']) || $sql !== 'DELETE FROM wp_slim_stats WHERE id = 1' || $this->queries !== []) {
            throw new RuntimeException('Deletion escaped the authorized, exact-id request');
        }
        $this->queries[] = $sql;
        echo "PASS: deletion rejects malformed and unauthorized requests; valid id remains bound\n";
        return 1;
    }
};
$body = slimstat_function_body(file_get_contents(dirname(__DIR__) . '/admin/index.php'), 'delete_pageview');
eval('class AdminRequest { public static function delete_pageview() {' . $body . '} }');
set_error_handler(static function ($severity, $message) { throw new RuntimeException($message); });
$GLOBALS['allowed'] = true;
foreach ([[], ['pageview_id' => ['1'], 'security' => 'valid'], ['pageview_id' => '1', 'security' => ['valid']], ['pageview_id' => '1', 'security' => 'bad'], ['pageview_id' => '1 OR 1=1', 'security' => 'valid']] as $post) {
    $_POST = $post; AdminRequest::delete_pageview();
}
if ($GLOBALS['wpdb']->queries !== []) { throw new RuntimeException('Malformed request deleted a pageview'); }
$GLOBALS['allowed'] = false;
$_POST = ['pageview_id' => '1', 'security' => 'valid']; AdminRequest::delete_pageview();
if ($GLOBALS['wpdb']->queries !== []) { throw new RuntimeException('Unauthorized request deleted a pageview'); }
function wp_parse_url($url) { return parse_url($url); }
function get_permalink($id) { return 'https://example.test/literal_%/post'; }
class wp_slimstat_db {
    public static $filters_normalized = ['misc' => ['limit_results' => 10]];
    public static function init($filters) {}
    public static function get_combined_where($where, ...$args) { return $where; }
    public static function get_results($sql) { return []; }
}
$column = slimstat_function_body(file_get_contents(dirname(__DIR__) . '/admin/index.php'), 'init_data_for_column');
eval('class ColumnRequest { public static $data_for_column = []; public static function run() {' . $column . '} }');
$GLOBALS['wp_query'] = (object) ['posts' => [(object) ['ID' => 1]]];
wp_slimstat::$wpdb = $GLOBALS['wpdb'];
wp_slimstat::$settings += ['posts_column_day_interval' => 30, 'posts_column_pageviews' => 'on'];
ColumnRequest::run();
if ($GLOBALS['wpdb']->bound !== [1 => '/literal\\_\\%/post%']) { throw new RuntimeException('Post column treats literal URL characters as LIKE wildcards'); }
echo "PASS: post-column resource prefixes bind literal URL metacharacters\n";

// Execute real recursive cleanup against an owned tree containing a link to a canary.
$cleanup = slimstat_function_body(file_get_contents(dirname(__DIR__) . '/admin/index.php'), 'rmdir');
eval('class wp_slimstat_admin { public static function rmdir($path) {' . $cleanup . '} }');
$base = sys_get_temp_dir() . '/slimstat-cleanup-' . bin2hex(random_bytes(8));
mkdir($base, 0700); mkdir($base . '/owned'); mkdir($base . '/outside');
file_put_contents($base . '/outside/canary', 'preserve');
symlink($base . '/outside', $base . '/owned/link');
symlink($base . '/missing', $base . '/owned/dangling');
try {
    if (!wp_slimstat_admin::rmdir($base . '/owned') || is_dir($base . '/owned')) { throw new RuntimeException('Owned cleanup incomplete'); }
    if (!is_file($base . '/outside/canary') || file_get_contents($base . '/outside/canary') !== 'preserve') { throw new RuntimeException('Owned cleanup traversed outside symlink'); }
    echo "PASS: owned recursive cleanup preserves symlink targets and removes dangling links\n";
} finally {
    foreach (['owned/link', 'owned/dangling', 'outside/canary'] as $file) { if (is_file($base . '/' . $file) || is_link($base . '/' . $file)) { unlink($base . '/' . $file); } }
    foreach (['owned', 'outside', ''] as $dir) { if (is_dir($base . '/' . $dir)) { rmdir($base . '/' . $dir); } }
}
$GLOBALS['allowed'] = true;
$GLOBALS['expect_delete'] = true;
AdminRequest::delete_pageview();
throw new RuntimeException('Valid deletion did not terminate its AJAX response');
