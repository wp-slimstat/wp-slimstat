<?php
/** Multi-user reset controls execute the production handler with metadata stubs. */
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') { http_response_code(403); exit(1); }
require_once __DIR__ . '/lib/source-scan.php';
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function esc_html__($text, $domain) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function sanitize_text_field($value) { return strip_tags($value); }
function wp_unslash($value) { return stripslashes($value); }
function wp_verify_nonce($nonce, $action) { return $nonce === $action . '-valid'; }
function current_user_can($capability) { return !empty($GLOBALS['caps'][$capability]); }
function is_multisite() { return $GLOBALS['multisite']; }
function wp_get_current_user() { return $GLOBALS['user']; }
function get_user_meta($id) { return $GLOBALS['metadata'][$id]; }
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
