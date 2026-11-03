<?php
/**
 * The midnight split runs only when the two halves can be added back together.
 *
 * COUNT(DISTINCT x) is not additive: a visitor seen before and after midnight is one visitor,
 * and the halves count them once each. AVG is not additive: the mean of two means is not the
 * mean. The merge kept the FIRST half's value for both, so a range ending today reported
 * whichever half came back first. Such a query now runs once over the full range. (C-2)
 *
 * A refused split is a full range that reaches today, so it is not cached — the same rule as
 * the live half, and what the HAVING refusal (D5) always said it did.
 */
define('ABSPATH', '/');
define('ARRAY_A', 'ARRAY_A');
define('MINUTE_IN_SECONDS', 60);
function apply_filters($tag, $value) { return $value; }
function get_transient($key) { return $GLOBALS['split_cache'][$key] ?? false; }
function set_transient($key, $value, $ttl = 0) { $GLOBALS['split_cache'][$key] = $value; return true; }
function delete_transient($key) { unset($GLOBALS['split_cache'][$key]); return true; }
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
class SplitDb {
    public $prefix = 'wp_';
    public $dbhost = 'db';
    public $dbname = 'tests-fixture';
    public $last_error = '';
    public $queries = [];
    public function remove_placeholder_escape($sql) { return $sql; }
    public function prepare($sql, ...$args) {
        if (1 === count($args) && is_array($args[0])) { $args = $args[0]; }
        return vsprintf(str_replace(['%s', '%d'], ["'%s'", '%d'], $sql), $args);
    }
    public function get_results($sql, $format) { $this->queries[] = $sql; return [['resource' => '/a', 'counthits' => '1']]; }
}
class wp_slimstat {
    public static $wpdb;
    public static $settings = ['show_sql_debug' => 'no'];
    public static function now() { return 2000000000; }
}
require dirname(__DIR__) . '/src/Utils/NetworkMerge.php';
require dirname(__DIR__) . '/src/Utils/Query.php';
use SlimStat\Utils\Query;

$db = new SplitDb();
wp_slimstat::$wpdb = $GLOBALS['wpdb'] = $db;
$failures = [];
function split_check($name, $condition) {
    global $failures;
    if (!$condition) { $failures[] = $name; }
}
$now   = wp_slimstat::now();
$today = intdiv($now, 86400) * 86400;
$from  = $today - 3 * 86400;
function run_report($fields, $having = false) {
    global $db, $from, $now;
    $GLOBALS['split_cache'] = [];
    $db->queries            = [];
    $q = Query::select($fields)->from('wp_slim_stats')->where('dt', 'BETWEEN', [$from, $now]);
    if (false !== strpos($fields, 'counthits') || false !== strpos($fields, 'avg_')) {
        $q->groupBy('resource');
    }
    if ($having) {
        $q->havingRaw('COUNT(visit_id) = 1');
    }
    $q->allowCaching(true)->getAll();
}
$fullRange = "BETWEEN '{$from}' AND '{$now}'";

foreach ([
    'COUNT(DISTINCT)' => 'resource, COUNT(DISTINCT ip) AS counthits',
    'AVG'             => 'resource, AVG(server_latency) AS avg_latency',
    'SELECT DISTINCT' => 'DISTINCT resource',
] as $label => $fields) {
    run_report($fields);
    split_check("{$label} must not split: expected one statement, got " . count($db->queries), 1 === count($db->queries));
    split_check("{$label} must run over the full range", false !== strpos((string) end($db->queries), $fullRange));
    split_check("{$label}: a refused split reaches today and must not be cached", [] === $GLOBALS['split_cache']);
}

// HAVING was already refused; its full-range answer must not be cached either (D5's stated intent).
run_report('resource, COUNT(*) AS counthits', true);
split_check('HAVING must not split', 1 === count($db->queries));
split_check('HAVING: a refused split reaches today and must not be cached', [] === $GLOBALS['split_cache']);

// Control: an additive aggregate still splits, and its historical half is still cached.
run_report('resource, COUNT(*) AS counthits');
split_check('COUNT(*) must still split into two statements', 2 === count($db->queries));
split_check('COUNT(*) must still cache its historical half', 1 === count($GLOBALS['split_cache']));

// Control: a range entirely in the past is not a split at all, and is cached as before.
$GLOBALS['split_cache'] = [];
$db->queries            = [];
Query::select('resource, COUNT(DISTINCT ip) AS counthits')->from('wp_slim_stats')
    ->where('dt', 'BETWEEN', [$from, $today - 1])->groupBy('resource')->allowCaching(true)->getAll();
split_check('a historical COUNT(DISTINCT) range is still cached', 1 === count($GLOBALS['split_cache']));

if ([] !== $failures) {
    foreach ($failures as $f) { fwrite(STDERR, "FAIL: {$f}\n"); }
    exit(1);
}
echo "PASS: COUNT(DISTINCT), AVG and SELECT DISTINCT run once over the full range; refused splits are not cached\n";
