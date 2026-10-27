<?php
/** #361: execute the real dispatcher with a query-producing report callback. */
if (PHP_SAPI !== 'cli') { exit(1); }
require __DIR__ . '/lib/source-scan.php';
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function current_user_can($capability) { return $GLOBALS['allowed']; }
function get_current_screen() { return $GLOBALS['screen']; }
function wp_doing_ajax() { return $GLOBALS['ajax']; }
function check_ajax_referer($action, $key) {
    check($action === 'meta-box-order' && $key === 'security', 'AJAX nonce contract changed');
    if (($_POST[$key] ?? '') !== 'valid') { throw new LogicException('invalid nonce'); }
}
function sanitize_key($value) { return $value; }
function wp_unslash($value) { return $value; }
class wp_slimstat { public static $settings = ['can_view' => '', 'capability_can_view' => 'read', 'rows_to_show' => 20, 'async_load' => 'no']; }
$admin = file_get_contents(__DIR__ . '/../admin/index.php');
check(preg_match('/public const COMPACT_DASHBOARD_REPORTS = (.+);/', $admin, $compact) === 1, 'missing shared compact-widget policy');
eval('class wp_slimstat_admin { public const COMPACT_DASHBOARD_REPORTS = ' . $compact[1] . '; public static $current_screen = "slimview1"; }');
require __DIR__ . '/../admin/view/wp-slimstat-reports.php';
$GLOBALS['current_user'] = (object) ['user_login' => 'admin'];
$GLOBALS['allowed'] = true;
$GLOBALS['ajax'] = false;
$GLOBALS['queries'] = 0;
wp_slimstat_reports::$reports['fixture'] = ['callback' => function ($args) { $GLOBALS['queries']++; echo 'accurate report'; }, 'callback_args' => []];
check(preg_match("/'async_load'\\s*=>\\s*(.+),/", slimstat_function_body($admin, 'wp_slimstat_enqueue_scripts'), $match) === 1, 'missing client async policy');
foreach (['no', 'on'] as $stored) {
    wp_slimstat::$settings['async_load'] = $stored;
    foreach (['dashboard', 'dashboard-network', 'toplevel_page_slimview1', null] as $base) {
        $current_screen = $GLOBALS['screen'] = $base === null ? null : (object) ['id' => $base];
        $GLOBALS['queries'] = 0;
        ob_start(); wp_slimstat_reports::callback_wrapper('', ['id' => 'fixture']); $html = ob_get_clean();
        check($GLOBALS['queries'] === ($base === 'dashboard' ? 0 : 1), 'Dashboard must defer report queries; other contexts must dispatch normally');
        check($html === ($base === 'dashboard' ? '' : 'accurate report'), 'unexpected report output');
        $localized = eval('return ' . $match[1] . ';');
        check($localized === ($base === 'dashboard' ? 'on' : $stored), 'client loading policy does not match server deferral');
        check(wp_slimstat::$settings['async_load'] === $stored, 'saved preference changed');
    }
}
$GLOBALS['screen'] = (object) ['id' => 'dashboard'];
$GLOBALS['ajax'] = true;
$_POST = ['report_id' => 'fixture', 'security' => 'valid'];
ob_start(); wp_slimstat_reports::callback_wrapper([]); $html = ob_get_clean();
check($html === 'accurate report', 'deferred AJAX must dispatch the same report');
foreach (['slim_p9_01', 'slim_p9_02', 'slim_p3_03', 'slim_p3_04'] as $id) {
    wp_slimstat_reports::$reports[$id] = ['callback' => function ($args) { echo !empty($args['is_widget']) ? 'compact' : 'full'; }, 'callback_args' => []];
    $_POST['report_id'] = $id;
    foreach (['dashboard' => 'compact', 'slimview6' => 'full'] as $screen => $expected) {
        wp_slimstat_admin::$current_screen = $screen;
        ob_start(); wp_slimstat_reports::callback_wrapper([]); $html = ob_get_clean();
        check($html === $expected, 'AJAX report presentation changed for ' . $screen);
    }
}
$_POST['security'] = 'invalid';
try { wp_slimstat_reports::callback_wrapper([]); throw new RuntimeException('invalid AJAX nonce accepted'); }
catch (LogicException $error) { check($error->getMessage() === 'invalid nonce', 'wrong nonce rejection'); }
$GLOBALS['allowed'] = false;
$GLOBALS['queries'] = 0;
wp_slimstat_reports::callback_wrapper([]);
check($GLOBALS['queries'] === 0, 'unauthorized report executed');
echo "Dashboard deferral, saved settings, AJAX dispatch and authorization passed.\n";
