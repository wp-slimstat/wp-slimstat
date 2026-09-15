<?php
/** Multi-user reset controls execute the production handler with metadata stubs. */
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') { http_response_code(403); exit(1); }
require_once __DIR__ . '/lib/source-scan.php';
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function esc_html__($text, $domain) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function sanitize_text_field($value) { return strip_tags($value); }
function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : stripslashes($value); }
function wp_verify_nonce($nonce, $action) { return $nonce === $action . '-valid'; }
function current_user_can($capability) { return !empty($GLOBALS['caps'][$capability]); }
function is_multisite() { return $GLOBALS['multisite']; }
function wp_get_current_user() { return $GLOBALS['user']; }
function get_user_meta($id, $key = '', $single = false) { return $key === '' ? $GLOBALS['metadata'][$id] : ($GLOBALS['metadata'][$id][$key] ?? ''); }
function delete_user_meta($id, $key) { unset($GLOBALS['metadata'][$id][$key]); return true; }
function admin_url($path) { return '/wp-admin/' . $path; }
function network_admin_url($path) { return '/wp-admin/network/' . $path; }
class ResetDenied extends Exception {}
class ResetRedirect extends Exception {}
function wp_die($message) { throw new ResetDenied($message); }
function wp_safe_redirect($url) { throw new ResetRedirect($url); }
class wp_slimstat { public static $settings = ['can_customize' => 'delegated', 'capability_can_customize' => 'manage_options']; }
$GLOBALS['wpdb'] = new class { public function get_blog_prefix() { return 'wp_'; } };
$source = file_get_contents(__DIR__ . '/../admin/index.php');
eval('class ResetProbe { public static function run() {' . slimstat_function_body($source, 'handle_reset_layout') . '} }');
$personal = 'meta-box-order_slimstat_page_slimlayout';
$network = $personal . '-network';
$fixtures = [
    1 => [$personal => ['personal admin'], $network => ['network layout'], 'wp_' . $network => ['legacy network'], 'meta-box-order_dashboard' => ['other widgets']],
    2 => [$personal => ['personal delegated'], 'wp_' . $personal => ['legacy personal'], 'closedpostboxes_slimstat_page_slimview1' => ['collapsed'], $network => ['must stay'], 'meta-box-order_dashboard' => ['other widgets'], 'unrelated' => ['keep']],
    3 => [$personal => ['other user']],
];
function run_reset($scope, $nonce, $allowed) {
    $_POST = ['slimstat_layout_scope' => $scope];
    $_REQUEST = ['_wpnonce' => $nonce];
    $before = $GLOBALS['metadata'];
    try { ResetProbe::run(); throw new RuntimeException('reset did not terminate'); }
    catch (ResetDenied $error) { check(!$allowed, 'valid reset denied'); check($GLOBALS['metadata'] === $before, 'denied reset mutated metadata'); }
    catch (ResetRedirect $redirect) { check($allowed, 'unauthorized reset redirected as success'); }
}
$GLOBALS['multisite'] = true;
$GLOBALS['user'] = (object) ['ID' => 2, 'user_login' => 'delegated'];
$GLOBALS['caps'] = ['read' => true];
$GLOBALS['metadata'] = $fixtures;
run_reset('personal', 'reset_layout-valid', true);
check($GLOBALS['metadata'][1] === $fixtures[1] && $GLOBALS['metadata'][3] === $fixtures[3], 'personal reset touched another user');
check(!isset($GLOBALS['metadata'][2][$personal], $GLOBALS['metadata'][2]['wp_' . $personal]), 'personal layout not reset');
check($GLOBALS['metadata'][2] === [$network => ['must stay'], 'meta-box-order_dashboard' => ['other widgets'], 'unrelated' => ['keep']], 'personal reset removed wrong keys');
$GLOBALS['metadata'] = $fixtures;
run_reset('network', 'reset_layout-valid', false);
run_reset('network', 'reset_layout_network-valid', false);
run_reset('personal', ['reset_layout-valid'], false);
run_reset('unknown', 'reset_layout-valid', false);
run_reset(['network'], 'reset_layout-valid', false);
$GLOBALS['user']->user_login = 'gated'; // substring of delegated must not grant customization.
run_reset('personal', 'reset_layout-valid', false);
$GLOBALS['caps']['manage_network_options'] = true;
run_reset('network', 'reset_layout_network-valid', true);
check($GLOBALS['metadata'][2] === $fixtures[2] && $GLOBALS['metadata'][3] === $fixtures[3], 'network reset touched personal users');
check($GLOBALS['metadata'][1] === [$personal => ['personal admin'], 'meta-box-order_dashboard' => ['other widgets']], 'network reset did not isolate network keys');
$GLOBALS['metadata'] = $fixtures;
$GLOBALS['multisite'] = false;
run_reset('network', 'reset_layout_network-valid', false);
$template = file_get_contents(__DIR__ . '/../admin/view/layout.php');
check(strpos($template, "is_network_admin() ? 'reset_layout_network' : 'reset_layout'") !== false, 'network reset nonce not bound to form scope');
check(strpos($template, 'name="slimstat_layout_scope"') !== false, 'scope absent from reset form');
echo "PASS: delegated personal reset preserves other users/network/dashboard; network reset requires scoped nonce/capability and changes user1 network keys only\n";

class SaveResult extends Exception {}
function check_ajax_referer($action, $field) { if (!isset($_POST[$field]) || !is_string($_POST[$field]) || !wp_verify_nonce($_POST[$field], $action)) { throw new SaveResult('nonce', 403); } }
function wp_send_json_error($message, $status = 400) { throw new SaveResult($message, $status); }
function wp_send_json_success() { throw new SaveResult('success', 200); }
function wp_slash($value) { return is_array($value) ? array_map('wp_slash', $value) : addslashes($value); }
function metadata_exists($type, $id, $key) { return isset($GLOBALS['metadata'][$id][$key]); }
function update_user_meta($id, $key, $value) {
    $value = wp_unslash($value);
    if (!empty($GLOBALS['fail_update']) || ($GLOBALS['metadata'][$id][$key] ?? null) === $value) { return false; }
    $GLOBALS['metadata'][$id][$key] = $value;
    return true;
}
eval('class NetworkSaveProbe { public static $screens_info = ["dashboard" => [], "slimview1" => [], "inactive" => []]; public static function run() {' . slimstat_function_body($source, 'save_network_layout') . '} }');
function save_result($request, $expected) {
    $_POST = $request;
    $before = $GLOBALS['metadata'];
    try { NetworkSaveProbe::run(); check($expected === 0, 'network request fell through to core personal handler'); }
    catch (SaveResult $result) { check($result->getCode() === $expected, 'wrong save verdict: ' . $result->getMessage()); }
    if ($expected !== 200) { check($GLOBALS['metadata'] === $before, 'rejected/core-only save modified network metadata'); }
}
$GLOBALS['multisite'] = true;
$GLOBALS['metadata'] = $fixtures;
$request = ['slimstat_layout_scope' => 'network', '_slimstat_nonce' => 'slimstat_network_layout-valid', 'page' => 'slimstat_page_slimlayout-network', 'order' => ['dashboard' => 'slim_p9_01', 'slimview1' => 'slim_p7_02', 'inactive' => '']];
$GLOBALS['caps'] = ['read' => true];
save_result($request, 403);
$GLOBALS['caps']['manage_network_options'] = true;
$forged = $request;
unset($forged['slimstat_layout_scope'], $forged['_slimstat_nonce']);
save_result($forged, 403); // Cannot bypass by posting the core action directly.
foreach ([['order', ['dashboard' => ['nested']]], ['order', ['unrelated' => 'slim_p1_01']], ['order', []], ['page', 'dashboard'], ['page', []]] as $bad) {
    $malformed = $request;
    $malformed[$bad[0]] = $bad[1];
    save_result($malformed, 400);
}
save_result(['page' => 'slimstat_page_slimlayout', 'order' => ['dashboard' => 'slim_p1_01']], 0);
save_result($request, 200);
check($GLOBALS['metadata'][1]['wp_' . $network] === $request['order'], 'existing legacy network key was not updated');
check($GLOBALS['metadata'][1][$personal] === $fixtures[1][$personal] && $GLOBALS['metadata'][2] === $fixtures[2], 'network save changed personal layouts');
save_result($request, 200); // update_user_meta returns false for an unchanged value.
$GLOBALS['metadata'] = $fixtures;
unset($GLOBALS['metadata'][1]['wp_' . $network]);
save_result($request, 200);
check($GLOBALS['metadata'][1][$network] === $request['order'], 'modern network key was not updated');
$GLOBALS['fail_update'] = true;
$request['order']['dashboard'] = 'slim_p9_02';
save_result($request, 500);
check(strpos($source, "add_action('wp_ajax_meta-box-order', ['wp_slimstat_admin', 'save_network_layout'], 0)") !== false, 'network guard must precede core metabox handler');
$js = file_get_contents(__DIR__ . '/../admin/assets/js/admin.js');
check(substr_count($js, 'slimstat_layout_scope: SlimStatAdminParams.layout_scope') === 2, 'both sort and clone/delete flows must carry scope');
echo "PASS: scoped network saves target existing user1 format; direct-core bypass, malformed/unauthorized requests and failed writes refuse; personal core flow untouched\n";
