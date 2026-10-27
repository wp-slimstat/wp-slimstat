<?php
/** #362: diagnostics must not change answers, SQL, cache hits or database errors. */
define('ABSPATH', '/');
define('ARRAY_A', 'ARRAY_A');
define('MINUTE_IN_SECONDS', 60);
function apply_filters($tag, $value, ...$args) { return $tag === 'slimstat_network_merge_active' ? !empty($GLOBALS['merging']) : ($tag === 'slimstat_get_results_sql' && !empty($GLOBALS['merging']) ? $value . ' /* merged network SQL */' : $value); }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function __($value, $domain = '') { return $value; }
function get_transient($key) { return $GLOBALS['debug_cache'][$key] ?? false; }
function set_transient($key, $value, $ttl = 0) { $GLOBALS['debug_cache'][$key] = $value; }
class wp_slimstat {
    public static $wpdb;
    public static $settings = ['show_sql_debug' => 'off'];
    public static function now() { return 2000000000; }
}
class DebugDb {
    public $prefix = 'wp_';
    public $last_error = '';
    public $error = '';
    public $queries = [];
    public function prepare($sql, ...$args) { return $sql; }
    public function get_results($sql, $format = null) { $this->queries[] = $sql; $this->last_error = $this->error; return [['resource' => '/<script>x</script>', 'counthits' => 7]]; }
    public function get_var($sql) { $this->get_results($sql); return 7; }
    public function get_row($sql) { return (object) $this->get_results($sql)[0]; }
    public function get_col($sql) { $this->get_results($sql); return [7]; }
}
require dirname(__DIR__) . '/src/Utils/NetworkMerge.php';
require dirname(__DIR__) . '/src/Utils/Query.php';
require dirname(__DIR__) . '/admin/view/wp-slimstat-db.php';
$db = wp_slimstat::$wpdb = $GLOBALS['wpdb'] = new DebugDb();
$checks = 0;
function debug_check($ok, $message) { global $checks; ++$checks; if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
foreach (['getAll', 'getVar', 'getRow', 'getCol'] as $method) {
    $answers = $queries = [];
    foreach (['off', 'on'] as $enabled) {
        wp_slimstat::$settings['show_sql_debug'] = $enabled;
        wp_slimstat_db::$debug_message = '';
        $db->queries = [];
        $query = SlimStat\Utils\Query::select('resource')->from('wp_slim_stats')->whereRaw("resource <> '<script>x</script>'")->groupBy('resource')->allowCaching(false);
        $answers[] = $query->$method();
        $queries[] = $db->queries;
        $message = wp_slimstat_db::$debug_message;
        debug_check($enabled === 'on' ? strpos($message, 'GROUP BY') !== false : $message === '', "$method debug toggle");
        debug_check(strpos($message, '<script>') === false, "$method HTML escaped");
    }
    debug_check($answers[0] == $answers[1] && $queries[0] === $queries[1] && count($queries[0]) === 1, "$method answers and execution unchanged");
    $db->error = 'Unknown column <img src=x onerror=alert(1)>';
    wp_slimstat_db::$debug_message = '';
    $query->$method();
    debug_check(strpos(wp_slimstat_db::$debug_message, 'Unknown column &lt;img') !== false, "$method escaped database error");
    debug_check($db->last_error === $db->error, "$method preserves error state");
    $db->error = '';
    $GLOBALS['debug_cache'] = [];
    $query->allowCaching(true);
    $query->$method();
    $count = count($db->queries);
    $db->last_error = 'unrelated stale error';
    wp_slimstat_db::$debug_message = '';
    $query->$method();
    debug_check(count($db->queries) === $count, "$method cache not bypassed for debug");
    debug_check(strpos(wp_slimstat_db::$debug_message, 'GROUP BY') !== false && strpos(wp_slimstat_db::$debug_message, 'unrelated') === false, "$method cache SQL without stale errors");
}
wp_slimstat_db::$debug_message = '';
wp_slimstat_db::get_results("SELECT resource FROM wp_slim_stats WHERE resource = '<script>x</script>'");
debug_check(strpos(wp_slimstat_db::$debug_message, '&lt;script&gt;') !== false, 'legacy wrapper SQL escaped');
$merging = true;
wp_slimstat_db::$debug_message = '';
SlimStat\Utils\Query::select('resource')->from('wp_slim_stats')->getAll(SlimStat\Utils\NetworkMerge::SUM, 'resource', 'resource', '', '', 5);
debug_check(strpos(wp_slimstat_db::$debug_message, 'merged network SQL */ LIMIT 5') !== false, 'network diagnostics show final filtered SQL and outer limit');
echo "PASS: $checks SQL diagnostic assertions\n";
