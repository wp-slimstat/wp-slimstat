<?php
require __DIR__ . '/lib/source-scan.php';
function apply_filters($hook, $value) { return $value; }
function current_user_can($capability) { return $GLOBALS['allowed']; }
function is_network_admin() { return false; }
function wp_verify_nonce($nonce, $action) { return $nonce === 'valid'; }
function wp_unslash($value) { return stripslashes($value); }
class wp_slimstat { public static $settings = ['capability_can_admin' => 'manage_options']; }
$GLOBALS['wpdb'] = new class {
    public $prefix = 'wp_', $queries = [];
    public function prepare($sql, $id) { return str_replace('%d', (string) $id, $sql); }
    public function query($sql) { $this->queries[] = $sql; return 1; }
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
$GLOBALS['allowed'] = true;
AdminRequest::delete_pageview();
if ($GLOBALS['wpdb']->queries !== ['DELETE FROM wp_slim_stats WHERE id = 1']) { throw new RuntimeException('Valid deletion did not target exactly one id'); }
echo "PASS: deletion rejects malformed and unauthorized requests; valid id remains bound\n";
