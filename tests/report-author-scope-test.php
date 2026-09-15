<?php
require __DIR__ . '/lib/source-scan.php';
const DAY_IN_SECONDS = 86400;
const MINUTE_IN_SECONDS = 60;
const ARRAY_A = 'ARRAY_A';
function apply_filters($hook, $value) { return $value; }
function wp_generate_uuid4() { return 'rotated-generation'; }
function current_user_can($capability) { return $GLOBALS['admin']; }
function get_current_blog_id() { return 1; }
function current_time($format) { return 1760000041; }
function home_url() { return 'https://example.test'; }
function wp_parse_url($url, $component) { return parse_url($url, $component); }
function get_transient($key) { return $GLOBALS['cache'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['cache'][$key] = $value; }
$GLOBALS['wpdb'] = new class {
    public $prefix = 'wp_', $dbhost = 'localhost', $dbname = 'local', $sql = [];
    public function esc_like($value) { return addcslashes($value, '_%\\'); }
    public function prepare($sql, ...$values) {
        foreach ($values as $value) { $sql = preg_replace_callback('/%[sd]/', static function ($match) use ($value) { return $match[0] === '%d' ? (string) (int) $value : "'" . str_replace("'", "''", $value) . "'"; }, $sql, 1); }
        return $sql;
    }
    public function get_results($sql, $format) { $this->sql[] = $sql; return []; }
    public function get_var($sql) { $this->sql[] = $sql; return 1; }
    public function get_row($sql) { $this->sql[] = $sql; return (object) []; }
};
$main = file_get_contents(dirname(__DIR__) . '/wp-slimstat.php');
eval('class wp_slimstat { public static $wpdb, $settings = ["restrict_authors_view" => "on"]; public static function now() { return 1760000041; } public static function pro_is_installed() { return true; } public static function report_scope(): array {' . slimstat_function_body($main, 'report_scope') . '} }');
wp_slimstat::$wpdb = $GLOBALS['wpdb'];
$admin_source = file_get_contents(dirname(__DIR__) . '/admin/index.php');
$class = 'class ScopedAdmin {';
foreach (['adminbar_today_stats', 'online_count', 'query_online_count', 'build_filter_options_cache_key'] as $method) {
    $args = $method === 'build_filter_options_cache_key' ? '$dimension, $time_start, $time_end, $search, $limit' : '';
    $class .= ' public static function ' . $method . '(' . $args . ') {' . slimstat_function_body($admin_source, $method) . '}';
}
eval($class . '}');
$live = file_get_contents(dirname(__DIR__) . '/src/Reports/Types/Analytics/LiveAnalyticsReport.php');
eval('class ScopedLive { public function count($window_seconds): int {' . slimstat_function_body($live, 'get_sessions_count_within_window') . '} public function chart() {' . slimstat_function_body($live, 'get_users_chart_data') . '} public static function clear_cache() {' . slimstat_function_body($live, 'clear_cache') . '} private function is_tracking_enabled() { return true; } private function generate_chart_labels() { return []; } private function find_peak_index($data) { return null; } }');
$GLOBALS['admin'] = false;
$GLOBALS['cache'] = [];
$keys = [];
foreach (['alice', 'bob'] as $author) {
    $GLOBALS['current_user'] = (object) ['user_login' => $author];
    $GLOBALS['wpdb']->sql = [];
    ScopedAdmin::adminbar_today_stats(); ScopedAdmin::online_count(); (new ScopedLive())->count(1800); (new ScopedLive())->chart();
    if (count($GLOBALS['wpdb']->sql) !== 5) { throw new RuntimeException('Cross-author cache reused or expected SQL missing'); }
    foreach ($GLOBALS['wpdb']->sql as $sql) {
        if (strpos($sql, "WHERE (author = '$author')") === false) { throw new RuntimeException('Report SQL omitted enforced author: ' . $sql); }
    }
    $keys[] = ScopedAdmin::build_filter_options_cache_key('country', 100, 200, '', 20);
}
if ($keys[0] === $keys[1]) { throw new RuntimeException('Filter suggestions share an author cache'); }
$GLOBALS['wpdb']->sql = [];
foreach (['alice', 'bob'] as $author) {
    $GLOBALS['current_user']->user_login = $author;
    (new ScopedLive())->chart();
}
if ($GLOBALS['wpdb']->sql !== []) { throw new RuntimeException('Repeated scoped chart did not use cache'); }
ScopedLive::clear_cache();
foreach (['alice', 'bob'] as $author) {
    $GLOBALS['current_user']->user_login = $author;
    (new ScopedLive())->chart();
}
if (count($GLOBALS['wpdb']->sql) !== 2) { throw new RuntimeException('Public chart invalidator missed another author'); }
$before = wp_slimstat::report_scope()['cache'];
$GLOBALS['wpdb']->dbname = 'external';
if (wp_slimstat::report_scope()['cache'] === $before) { throw new RuntimeException('Same-host external schema shares cache'); }
$GLOBALS['admin'] = true;
if (wp_slimstat::report_scope()['where'] !== '1=1') { throw new RuntimeException('Administrator scope restricted'); }
$GLOBALS['admin'] = false;
wp_slimstat::$settings['restrict_authors_view'] = 'off';
if (wp_slimstat::report_scope()['where'] !== '1=1') { throw new RuntimeException('Disabled restriction still applied'); }
echo "PASS: adminbar/live SQL and cache identities isolate authors and external database schemas\n";
