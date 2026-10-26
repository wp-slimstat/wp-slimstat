<?php
// Exercise the actual AJAX body and shared capability policy; stop before SQL.
require __DIR__ . '/lib/source-scan.php';
class JsonResponse extends Error { public $success; public function __construct($success) { $this->success = $success; } }
function wp_send_json_error($data) { throw new JsonResponse(false); }
function wp_send_json_success($data) { throw new JsonResponse(true); }
function check_ajax_referer(...$args) {}
function __($text, $domain) { return $text; }
function wp_unslash($value) { return stripslashes($value); }
function absint($value) { return abs((int) $value); }
function sanitize_text_field($value) { return strip_tags($value); }
function current_user_can($capability) { return in_array($capability, $GLOBALS['caps'], true); }
class wp_slimstat { public static $settings = ['can_view' => '', 'capability_can_view' => 'manage_options', 'restrict_authors_view' => 'on']; }
class wp_slimstat_db {
    public static $columns_names = ['author' => [], 'country' => []], $filters_normalized = [];
    public static function init() { self::$filters_normalized = ['columns' => ['author' => ['equals', 'alice']]]; }
}
$admin = file_get_contents(dirname(__DIR__) . '/admin/index.php');
eval('class wp_slimstat_admin { public static function can_view_stats() {' . slimstat_function_body($admin, 'can_view_stats') . '} private static function stats_view_capability() {' . slimstat_function_body($admin, 'stats_view_capability') . '} }');
$source = file_get_contents(dirname(__DIR__) . '/src/Modules/Chart.php');
eval('class ChartBoundary {
    const GRANULARITIES = ["yearly", "monthly", "weekly", "daily", "hourly"];
    private $args = [], $data = [], $prevData = [], $chartLabels = [], $translations = [];
    private function init($args) { $this->args = $args; }
    public static function run() {' . slimstat_function_body($source, 'ajaxFetchChartData') . '}
}');
set_error_handler(static function ($severity, $message) { throw new RuntimeException($message); });
$GLOBALS['current_user'] = (object) ['user_login' => 'alice'];
$valid = ['start' => 100, 'end' => 200, 'filters' => ['author' => ['equals', 'bob'], 'country' => ['equals', 'gb']]];
function run_chart($args, $expected, $granularity = 'daily') {
    $_POST = ['args' => is_array($args) ? addslashes(json_encode($args)) : $args, 'granularity' => $granularity];
    try { ChartBoundary::run(); throw new RuntimeException('No JSON response'); }
    catch (JsonResponse $response) { if ($response->success !== $expected) { throw new RuntimeException('Unexpected chart acceptance'); } }
}
$GLOBALS['caps'] = ['read'];
run_chart($valid, false); // A valid nonce does not grant the configured report capability.
wp_slimstat::$settings['can_view'] = 'alice';
run_chart($valid, true);
if (wp_slimstat_db::$filters_normalized['columns'] !== ['author' => ['equals', 'alice'], 'country' => ['equals', 'gb']]) { throw new RuntimeException('Posted filter escaped author scope'); }
$GLOBALS['caps'] = ['read', 'manage_options'];
wp_slimstat::$settings['can_view'] = '';
run_chart($valid, true);
if (wp_slimstat_db::$filters_normalized['columns']['author'] !== ['equals', 'bob']) { throw new RuntimeException('Administrator filter changed'); }
foreach ([null, 'null', '42', '"text"', '{', [], ['start' => [], 'end' => 200], ['start' => 200, 'end' => 100], ['start' => true, 'end' => 200]] as $args) { run_chart($args, false); }
foreach (['chart_data' => 'text', 'filters' => 'text', 'chart_labels' => 'text'] as $key => $value) { run_chart(array_merge($valid, [$key => $value]), false); }
foreach ([['data1' => []], ['data2' => true], ['where' => []]] as $data) { run_chart(array_merge($valid, ['chart_data' => $data]), false); }
foreach ([['equals', []], [['equals'], 'gb'], 'text'] as $filter) { run_chart(array_merge($valid, ['filters' => ['country' => $filter]]), false); }
run_chart($valid, false, []);
echo "PASS: chart capability, whitelist, immutable author scope and malformed request shapes\n";
