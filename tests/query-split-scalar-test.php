<?php
/** Reuse immutable pageview counts, never mutable filtered totals or rounded sums. */
define('ABSPATH', '/');
function apply_filters($hook, $value) { return $value; }
function get_option($key, $default = false) { return $GLOBALS['scalar_version'] ?? $default; }
function get_transient($key) { return $GLOBALS['scalar_cache'][$key] ?? false; }
function set_transient($key, $value, $ttl = 0) { $GLOBALS['scalar_cache'][$key] = $value; $GLOBALS['scalar_ttls'][] = $ttl; return true; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
class wp_slimstat {
    public static $wpdb;
    public static $settings = ['show_sql_debug' => 'no'];
    public static $now = 2000023200;
    public static function now() { return self::$now; }
}
class ScalarDb {
    public $prefix = 'wp_';
    public $last_error = '';
    public $queries = [];
    public $history = '10';
    public $live = '3';
    public $full = '13';
    public $fail = '';
    public function prepare($sql, ...$args) {
        if (isset($args[0]) && is_array($args[0])) { $args = $args[0]; }
        return vsprintf(str_replace('%s', "'%s'", $sql), $args);
    }
    public function get_var($sql) {
        $this->queries[] = $sql;
        $today = intdiv(wp_slimstat::now(), 86400) * 86400;
        $kind = strpos($sql, "AND '" . ($today - 1) . "'") !== false ? 'history'
            : (strpos($sql, "BETWEEN '{$today}'") !== false ? 'live' : 'full');
        $this->last_error = $kind === $this->fail ? 'connection failed' : '';
        return $this->last_error ? null : $this->$kind;
    }
}
require dirname(__DIR__) . '/src/Utils/NetworkMerge.php';
require dirname(__DIR__) . '/src/Utils/Query.php';
$db = wp_slimstat::$wpdb = $GLOBALS['wpdb'] = new ScalarDb();
function scalar_query($fields = 'COUNT(id) AS counthits') {
    return \SlimStat\Utils\Query::select($fields)->from('wp_slim_stats')
        ->whereDate('dt', ['from' => wp_slimstat::now() - 3 * 86400, 'to' => wp_slimstat::now()]);
}
function scalar_reset($fail = '') {
    global $db;
    $GLOBALS['scalar_cache'] = $GLOBALS['scalar_ttls'] = $db->queries = [];
    $db->history = '10'; $db->live = '3'; $db->full = '13'; $db->fail = $fail;
}
function scalar_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
scalar_reset();
scalar_check('13' === (string) scalar_query()->getVar() && count($db->queries) === 2, 'additive count must split into history and live');
$db->queries = []; $db->live = '4'; $db->full = '14';
scalar_check('14' === (string) scalar_query()->getVar() && count($db->queries) === 1, 'warm total must reuse history and read live');
scalar_check(count($GLOBALS['scalar_cache']) === 1, 'only history may be cached');
$GLOBALS['scalar_version'] = 'after-erasure'; $db->history = '8'; $db->full = '12';
scalar_check('12' === (string) scalar_query()->getVar(), 'erasure must invalidate the cached denominator');
foreach (['history', 'live'] as $fail) {
    scalar_reset($fail);
    scalar_check('13' === (string) scalar_query()->getVar() && count($db->queries) === 3, 'a failed half must fall back to the full total');
    scalar_check(count($GLOBALS['scalar_cache']) === ('history' === $fail ? 0 : 1), 'failed scalar query must not be cached');
}
foreach (['COUNT(DISTINCT ip)', 'AVG(dt_out)', 'MIN(id)', 'MAX(id)', 'SUM(dt_out)', 'COUNT(email)', 'COUNT(*) + 1', 'COUNT(*), SUM(id)'] as $fields) {
    scalar_reset(); scalar_query($fields)->getVar();
    scalar_check(count($db->queries) === 1 && !$GLOBALS['scalar_cache'], 'unsupported scalar must stay uncached and unsplit: ' . $fields);
}
foreach (['raw', 'group', 'having', 'join', 'limit', 'disabled', 'network'] as $shape) {
    scalar_reset(); $q = scalar_query();
    if ('raw' === $shape) { $q->whereRaw('dt_out > 0'); }
    if ('group' === $shape) { $q->groupBy('resource'); }
    if ('having' === $shape) { $q->havingRaw('COUNT(*) > 1'); }
    if ('join' === $shape) { $q->join('wp_other', 'wp_other.id = wp_slim_stats.id'); }
    if ('limit' === $shape) { $q->limit(1, 1); }
    if ('disabled' === $shape) { $q->allowCaching(false); }
    $q->getVar('network' === $shape ? 'SUM(counthits) AS counthits' : '');
    scalar_check(count($db->queries) === 1 && !$GLOBALS['scalar_cache'], 'unsafe or explicitly uncached shape must not split: ' . $shape);
}
scalar_reset(); $db->history = (string) PHP_INT_MAX; $db->live = '5'; $db->full = '9223372036854775812';
scalar_check($db->full === scalar_query()->getVar(), 'overflow must preserve the native SQL decimal string');
scalar_reset(); $db->history = $db->live = '4611686018427387904'; $db->full = '9223372036854775808';
scalar_check($db->full === scalar_query()->getVar(), 'rounding at PHP_INT_MAX must not permit overflowing addition');
scalar_reset(); $db->history = $db->live = $db->full = '0';
scalar_check('0' === (string) scalar_query()->getVar(), 'empty ranges must return zero');
$noon = wp_slimstat::$now;
wp_slimstat::$now = intdiv($noon, 86400) * 86400 + 600;
scalar_reset(); scalar_query()->getVar();
scalar_check(count($db->queries) === 1 && !$GLOBALS['scalar_cache'], 'the first 30 minutes after midnight must use a fresh total');
scalar_reset();
\SlimStat\Utils\Query::select('COUNT(id)')->from('wp_slim_stats')
    ->whereDate('dt', ['from' => wp_slimstat::now() - 3 * 86400, 'to' => intdiv(wp_slimstat::now(), 86400) * 86400 - 1])->getVar();
scalar_check([1200] === $GLOBALS['scalar_ttls'], 'historical cache entries in the grace window must expire at midnight plus 30 minutes');
echo "PASS: scalar history reuse, live freshness, erasure, failures, unsafe shapes, precision and midnight grace\n";
