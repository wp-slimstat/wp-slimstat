<?php
namespace SlimStat\Utils {
    class ProLinks { public static function activation() { return $GLOBALS['installed'] ? '/plugins' : ''; } public static function url(...$args) { return 'https://example.test/pro'; } }
}
namespace {
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') { http_response_code(403); exit(1); }
require __DIR__ . '/lib/source-scan.php';
$source = file_get_contents(dirname(__DIR__) . '/admin/index.php');
$code = '';
foreach (['legacy_custom_db_plugin', 'custom_db_is_external', 'show_custom_db_notice'] as $method) {
    $code .= 'public static function ' . $method . '() {' . slimstat_function_body($source, $method) . '}';
}
eval('class CustomDbNoticeProbe { public static $notices = []; public static function show_dismissible_notice(...$args) { self::$notices[] = $args; } ' . $code . '}');
class wp_slimstat { public static $settings = [], $pro = false; public static function pro_plugin_active() { return self::$pro; } }
define('DB_NAME', 'wordpress'); define('DB_HOST', 'localhost');
function current_user_can($cap) { return $GLOBALS['allowed']; }
function get_current_screen() { return (object) ['base' => $GLOBALS['screen'], 'id' => $GLOBALS['screen']]; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function get_site_option($key, $default = false) { return $GLOBALS['network'][$key] ?? $default; }
function is_multisite() { return true; }
function admin_url($v) { return '/wp-admin/' . $v; }
function __($v, $domain) { return $v; }
function esc_html__($v, $domain) { return htmlspecialchars($v, ENT_QUOTES); }
function check($ok, $msg) { if (!$ok) throw new RuntimeException($msg); }
function notice() { CustomDbNoticeProbe::$notices = []; CustomDbNoticeProbe::show_custom_db_notice(); return CustomDbNoticeProbe::$notices[0] ?? []; }
$GLOBALS['allowed'] = true; $GLOBALS['installed'] = false; $GLOBALS['screen'] = 'dashboard';
$GLOBALS['options'] = ['active_plugins' => ['wp-slimstat-custom-db/custom.php']]; $GLOBALS['network'] = [];
wp_slimstat::$settings = ['addon_custom_db_dbname' => 'external', 'addon_custom_db_dbhost' => 'localhost'];
$n = notice(); check($n && strpos($n[2], 'retired') !== false && $n[3][0][0] === 'Get SlimStat Pro' && $n[3][0][2], 'Configured guide or purchase link missing');
$GLOBALS['installed'] = true; check(notice()[3][0][0] === 'Activate SlimStat Pro', 'Installed Pro not offered');
wp_slimstat::$pro = true; check(notice() === [], 'Active unlicensed Pro still pitched'); wp_slimstat::$pro = false;
wp_slimstat::$settings['addon_custom_db_dbname'] = 'wordpress'; $unused = notice();
check(strpos($unused[2], 'does nothing') !== false && $unused[3][0][0] === 'Open Plugins' && $unused[1] !== $n[1], 'Unused addon pitched or variant reused');
$GLOBALS['allowed'] = false; check(notice() === [], 'Notice leaked to unauthorized user'); $GLOBALS['allowed'] = true;
$GLOBALS['screen'] = 'edit-post'; check(notice() === [], 'Notice on unrelated screen'); $GLOBALS['screen'] = 'plugins';
$GLOBALS['options']['active_plugins'] = []; check(notice() === [], 'Inactive addon pitched');
$GLOBALS['network']['active_sitewide_plugins'] = ['wp-slimstat-custom-db/network.php' => 1]; check(notice() !== [], 'Network addon undetected');
$GLOBALS['options']['slimstat_custom_db_changed_since'] = 123;
$n = notice(); check($n[0] === 'custom-db-changed' && strpos($n[2], 'New visits are now saved to your WordPress database') !== false, 'Database change hidden');
$GLOBALS['options']['slimstat_custom_db_changed_since'] = 124; check(notice()[1] !== $n[1], 'New database-change occurrence reused');
echo "PASS: legacy guide variants, Pro presence, capability, screen, network activation, and database-change occurrence\n";
}
