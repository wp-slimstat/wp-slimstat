<?php
namespace SlimStat\Tracker {
    class VisitIdGenerator { public static $initialized = true, $result = -1; public static function isInitialized() { return self::$initialized; } public static function initializeCounter() { self::$initialized = self::$result >= 0; return self::$result; } }
}
namespace SlimStat\Utils {
    class ProLinks { public static function activation() { return $GLOBALS['installed'] ? '/plugins' : ''; } public static function url(...$args) { return 'https://example.test/pro'; } }
}
namespace {
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') { http_response_code(403); exit(1); }
require __DIR__ . '/lib/source-scan.php';
$source = file_get_contents(dirname(__DIR__) . '/admin/index.php');
$code = '';
foreach (['legacy_custom_db_plugin', 'custom_db_is_external', 'show_custom_db_notice', 'reconcile_custom_db_notices', 'update_tables_and_options'] as $method) {
    $code .= 'public static function ' . $method . '() {' . slimstat_function_body($source, $method) . '}';
}
eval('class CustomDbNoticeProbe { const SCHEMA_LOCK_OPTION = "schema_lock"; public static $notices = []; public static function may_run_schema_ddl() { return true; } public static function claim_schema_lock() { return true; } public static function run_schema_upgrade() { $GLOBALS["repairs"]++; return $GLOBALS["schemaHealthy"]; } public static function show_dismissible_notice(...$args) { self::$notices[] = $args; } ' . $code . '}');
class wp_slimstat { const DEGRADATION_TRACKING = 'tracking'; public static $settings = [], $pro = false, $wpdb, $degradations = []; public static function get_degradations() { return array_map(static function ($severity) { return ['severity' => $severity]; }, self::$degradations); } public static function clear_degradation($key) { unset(self::$degradations[$key]); } public static function record_degradation($key, $message, $severity) { self::$degradations[$key] = $severity; } public static function pro_plugin_active() { return self::$pro; } }
define('DB_NAME', 'wordpress'); define('DB_HOST', 'localhost');
function current_user_can($cap) { return $GLOBALS['allowed'] && !in_array($cap, $GLOBALS['denied_caps'] ?? [], true); }
function get_current_screen() { return (object) ['base' => $GLOBALS['screen'], 'id' => $GLOBALS['screen']]; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function get_site_option($key, $default = false) { return $GLOBALS['network'][$key] ?? $default; }
function wp_doing_ajax() { return false; }
function has_filter($key) { return true; }
function delete_option($key) { unset($GLOBALS['options'][$key]); }
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; }
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
foreach (['manage_options', 'activate_plugins'] as $cap) { $GLOBALS['denied_caps'] = [$cap]; check(notice() === [], 'A guide must require both management and activation permissions'); } $GLOBALS['denied_caps'] = [];
$GLOBALS['screen'] = 'edit-post'; check(notice() === [], 'Notice on unrelated screen'); $GLOBALS['screen'] = 'plugins';
$GLOBALS['options']['active_plugins'] = []; check(notice() === [], 'Inactive addon pitched');
$GLOBALS['network']['active_sitewide_plugins'] = ['wp-slimstat-custom-db/network.php' => 1]; check(notice() !== [], 'Network addon undetected');
$GLOBALS['options']['slimstat_custom_db_changed_since'] = 123;
$n = notice(); check($n[0] === 'custom-db-changed' && strpos($n[2], 'New visits are now saved to your WordPress database') !== false, 'Database change hidden');
$GLOBALS['options']['slimstat_custom_db_changed_since'] = 124; check(notice()[1] !== $n[1], 'New database-change occurrence reused');
$GLOBALS['repairs'] = 0; $GLOBALS['schemaHealthy'] = false;
$GLOBALS['wpdb'] = (object) ['dbh' => true]; wp_slimstat::$wpdb = (object) ['dbh' => null];
wp_slimstat::$settings['is_tracking'] = 'on';
wp_slimstat::$degradations = ['activation' => 'tracking', 'schema upgrade' => 'tracking', 'visit counter initialization' => 'tracking'];
CustomDbNoticeProbe::reconcile_custom_db_notices();
check(isset(wp_slimstat::$degradations['analytics database unavailable']), 'An initialized counter must not hide a disconnected provider');
check($GLOBALS['repairs'] === 0, 'Disconnected provider must not retry schema DDL');
wp_slimstat::$wpdb->dbh = true;
CustomDbNoticeProbe::reconcile_custom_db_notices();
check(wp_slimstat::$degradations === ['activation' => 'tracking', 'schema upgrade' => 'tracking'], 'Connection/counter recovery must retain unrelated schema failures');
check($GLOBALS['repairs'] === 1, 'Connection recovery must verify the failed schema through the existing bounded runner');
$GLOBALS['schemaHealthy'] = true;
CustomDbNoticeProbe::reconcile_custom_db_notices();
check(wp_slimstat::$degradations === [], 'Successful schema reconciliation must clear the recovered outage');
CustomDbNoticeProbe::reconcile_custom_db_notices();
check($GLOBALS['repairs'] === 2, 'A healthy admin request must not repeat schema recovery');
\SlimStat\Tracker\VisitIdGenerator::$initialized = false;
CustomDbNoticeProbe::reconcile_custom_db_notices();
check((wp_slimstat::$degradations['visit counter initialization'] ?? '') === 'tracking', 'Failed counter recovery must report interrupted tracking');
wp_slimstat::$degradations = []; wp_slimstat::$settings['is_tracking'] = 'off';
CustomDbNoticeProbe::reconcile_custom_db_notices();
check(wp_slimstat::$degradations === [], 'Disabled tracking must not report a counter outage');
wp_slimstat::$settings['is_tracking'] = 'on'; \SlimStat\Tracker\VisitIdGenerator::$result = 7;
wp_slimstat::$degradations = ['visit counter initialization' => 'tracking'];
CustomDbNoticeProbe::reconcile_custom_db_notices();
check(wp_slimstat::$degradations === [], 'Successful counter recovery must clear its outage');
$main = file_get_contents(dirname(__DIR__) . '/wp-slimstat.php'); $methods = '';
foreach (['get_degradations', 'reconcile_degradations', 'clear_degradation'] as $method) {
    $methods .= 'public static function ' . $method . '(' . ($method === 'clear_degradation' ? '$step' : '') . ') {' . slimstat_function_body($main, $method) . '}';
}
eval('class DegradationRecoveryProbe { const DEGRADATION_OPTION="recovery_probe", DEGRADATION_TTL=10800, DEGRADATION_TRACKING="tracking"; public static function purge_is_stale() { return false; } ' . $methods . '}');
$GLOBALS['options']['recovery_probe'] = ['activation' => ['time' => time()-172800, 'severity' => 'tracking'], 'expired load failure' => ['time' => time()-172800, 'severity' => 'load']];
check(isset(DegradationRecoveryProbe::get_degradations()['activation']), 'Pending schema recovery must survive the notice TTL');
DegradationRecoveryProbe::reconcile_degradations();
check(array_keys($GLOBALS['options']['recovery_probe']) === ['activation'], 'Pruning must retain pending schema recovery and expire old load failures');
DegradationRecoveryProbe::clear_degradation('activation');
check(DegradationRecoveryProbe::get_degradations() === [], 'Verified recovery must remove the durable failure');
$upgrade = slimstat_function_body($source, 'update_tables_and_options');
check(strpos($upgrade, "clear_degradation('activation')") > strpos($upgrade, 'if ($complete)'), 'Only completed schema reconciliation can clear an activation failure');
echo "PASS: legacy guide variants, Pro presence, capability, screen, network activation, and database-change occurrence\n";
}
