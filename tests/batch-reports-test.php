<?php
/** Execute the batch dispatcher with adversarial inputs and stateful report callbacks. */
function batch_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function current_user_can($capability) { return $GLOBALS['batch_allowed'] && in_array($capability, $GLOBALS['batch_caps'] ?? ['read'], true); }
require __DIR__ . '/lib/source-scan.php';
$admin = file_get_contents(dirname(__DIR__) . '/admin/index.php');
eval('class wp_slimstat_admin { public static $current_screen = "slimview2"; public static function can_view_stats() {' . slimstat_function_body($admin, 'can_view_stats') . '} private static function stats_view_capability() {' . slimstat_function_body($admin, 'stats_view_capability') . '} }');
function get_current_screen() { return null; }
function wp_doing_ajax() { return true; }
function check_ajax_referer($action, $key) {
    ++$GLOBALS['batch_nonce_calls'];
    batch_check($action === 'meta-box-order' && $key === 'security', 'nonce contract changed');
    if (($_POST[$key] ?? '') !== 'valid') { throw new LogicException('invalid nonce'); }
}
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)); }
function wp_unslash($value) { return $value; }
function wp_send_json_success($data) { throw new BatchSent($data); }
class BatchSent extends RuntimeException {
    public $data;
    public function __construct($data) { $this->data = $data; parent::__construct('sent'); }
}
class wp_slimstat {
    public static $settings = ['can_view' => '', 'capability_can_view' => 'read', 'rows_to_show' => 20];
    public static $wpdb;
}
class wp_slimstat_db {
    public static $filters_normalized = ['fixture' => 'original'];
    public static $sql_where = ['fixture' => 'original'];
    public static $pageviews = 42;
    public static $debug_message = 'original';
}
require dirname(__DIR__) . '/admin/view/wp-slimstat-reports.php';
batch_check(method_exists('wp_slimstat_reports', 'load_reports'), 'batch dispatcher must exist');
$GLOBALS['current_user'] = (object) ['user_login' => 'viewer'];
wp_slimstat::$wpdb = (object) ['last_error' => 'original'];
$registry = [];
for ($i = 0; $i < 30; ++$i) {
    $id = 'free_' . $i;
    $registry[$id] = ['callback_args' => [], 'callback' => static function () use ($id) {
        batch_check(wp_slimstat_db::$pageviews === 42 && wp_slimstat_db::$filters_normalized === ['fixture' => 'original']
            && wp_slimstat_db::$sql_where === ['fixture' => 'original'] && wp_slimstat_db::$debug_message === 'original', 'report state leaked');
        wp_slimstat_db::$pageviews = 999;
        wp_slimstat_db::$filters_normalized = wp_slimstat_db::$sql_where = [];
        wp_slimstat_db::$debug_message = 'changed';
        echo $id . ':' . ($_REQUEST['granularity'] ?? 'default');
    }];
}
$registry['throws'] = ['callback_args' => [], 'callback' => static function () {
    wp_slimstat_db::$pageviews = 999;
    echo 'partial output';
    throw new RuntimeException('report failed');
}];
$registry['denies_next'] = ['callback_args' => [], 'callback' => static function () { $GLOBALS['batch_allowed'] = false; echo 'allowed'; }];
$registry['slim_p10_01'] = ['callback_args' => [], 'callback' => static function () { echo 'forbidden Ecommerce output'; }];
wp_slimstat_reports::$reports = $registry;
$owned = new ReflectionProperty('wp_slimstat_reports', 'free_reports');
if (PHP_VERSION_ID < 80100) { $owned->setAccessible(true); }
$owned->setValue(null, $registry);
wp_slimstat_reports::$reports['pro_report'] = ['callback_args' => [], 'callback' => static function () { echo 'forbidden Pro output'; }];
wp_slimstat_reports::$reports['free_29']['callback'] = wp_slimstat_reports::$reports['pro_report']['callback'];
function batch_call($ids, $nonce = 'valid', $allowed = true) {
    $GLOBALS['batch_allowed'] = $allowed; $GLOBALS['batch_nonce_calls'] = 0;
    $_POST = ['report_ids' => $ids, 'security' => $nonce, 'report_id' => ['malformed'], 'granularities' => ['free_0' => 'weekly']];
    $_REQUEST = ['granularity' => 'monthly'];
    $post = $_POST; $request = $_REQUEST;
    try { wp_slimstat_reports::load_reports(); }
    catch (BatchSent $sent) {
        batch_check($_POST === $post && $_REQUEST === $request && !wp_slimstat_reports::is_batch_rendering(), 'request or batch state leaked');
        batch_check(wp_slimstat_db::$pageviews === 42, 'final report state leaked');
        return $sent->data;
    }
    throw new RuntimeException('batch did not send a response');
}
foreach (['', 'bad'] as $nonce) {
    try { batch_call(['free_0'], $nonce); throw new RuntimeException('invalid nonce accepted'); }
    catch (LogicException $e) { batch_check($e->getMessage() === 'invalid nonce', 'unexpected nonce failure'); }
}
batch_check([] === batch_call(['free_0'], 'valid', false), 'unauthorized report must be omitted');
$rows = batch_call(['free_0', 'free_0', 'free_1', 'pro_report', 'unknown', 'free_29', 'slim_p10_01', ['nested']]);
batch_check(['free_0' => 'free_0:weekly', 'free_1' => 'free_1:default'] === $rows, 'duplicates, Pro, overrides or unknown IDs were accepted');
batch_check(1 === $GLOBALS['batch_nonce_calls'], 'batch must verify its nonce once');
batch_check(25 === count(batch_call(array_keys($registry))), 'batch must cap at 25 reports');
batch_check([] === batch_call('free_0'), 'scalar report_ids must be refused');
batch_check(['free_1' => 'free_1:default'] === batch_call(['throws', 'free_1']), 'a failed report must discard partial HTML and restore state');
batch_check(['denies_next' => 'allowed'] === batch_call(['denies_next', 'free_1']), 'capability must be checked for each report');
echo "PASS: batch nonce, per-report permission, Free ownership, cap, deduplication, error isolation and request restoration\n";

wp_slimstat::$settings['capability_can_view'] = 'manage_options';
wp_slimstat::$settings['can_view'] = 'previewer';
batch_check([] === batch_call(['free_0']), 'partial login must not grant report access');
wp_slimstat::$settings['can_view'] = 'alice, viewer, bob';
batch_check(count(batch_call(['free_0'])) === 1, 'exact listed login keeps access');
