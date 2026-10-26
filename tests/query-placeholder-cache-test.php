<?php
/** WordPress's per-request SQL escape marker must not make cache identities random. */
define('ABSPATH', '/');
define('ARRAY_A', 'ARRAY_A');
define('MINUTE_IN_SECONDS', 60);
function apply_filters($tag, $value) { return $value; }
function get_transient($key) { return $GLOBALS['placeholder_cache'][$key] ?? false; }
function set_transient($key, $value, $ttl = 0) { $GLOBALS['placeholder_cache'][$key] = $value; return true; }
class PlaceholderCacheDb {
    public $prefix = 'wp_';
    public $dbhost = 'db';
    public $dbname = 'tests-fixture';
    public $marker = '{request-one}';
    public $queries = [];
    public function remove_placeholder_escape($sql) { return str_replace($this->marker, '%', $sql); }
    public function get_results($sql, $format) { $this->queries[] = $sql; return [['id' => '7', 'resource' => '/100%']]; }
}
class wp_slimstat {
    public static $wpdb;
    public static $settings = ['show_sql_debug' => 'no'];
    public static function now() { return 2000000000; }
}
require dirname(__DIR__) . '/src/Utils/Query.php';
require dirname(__DIR__) . '/admin/view/wp-slimstat-db.php';
$db = new PlaceholderCacheDb();
wp_slimstat::$wpdb = $GLOBALS['wpdb'] = $db;
$method = new ReflectionMethod(SlimStat\Utils\Query::class, 'getCacheKeyForQuery');
if (PHP_VERSION_ID < 80100) { $method->setAccessible(true); }
$failures = [];
function placeholder_check($name, $condition) {
    global $failures;
    if (!$condition) { $failures[] = $name; }
}
$keys = $arg_keys = [];
$canonical = "SELECT resource FROM wp_slim_stats WHERE resource LIKE '%page%'";
foreach (['{request-one}', '{request-two}'] as $marker) {
    $db->marker = $marker;
    $sql = str_replace('%', $marker, $canonical);
    $keys[] = $method->invoke(new SlimStat\Utils\Query(), $sql, ['%page%', 0, null]);
    $arg_keys[] = $method->invoke(new SlimStat\Utils\Query(), $sql, [$marker . 'page' . $marker]);
}
placeholder_check('Prepared SQL keys survive a different request marker', $keys[0] === $keys[1]);
placeholder_check('Escaped bound arguments survive a different request marker', $arg_keys[0] === $arg_keys[1]);
$normal = $method->invoke(new SlimStat\Utils\Query(), $canonical, ['%page%', 0, null]);
placeholder_check('Canonical and placeholder-escaped SQL share identity', $normal === $keys[0]);
placeholder_check('Different predicates retain different keys', $normal !== $method->invoke(new SlimStat\Utils\Query(), str_replace('page', 'other', $canonical), ['%page%', 0, null]));
placeholder_check('Different bound values retain different keys', $normal !== $method->invoke(new SlimStat\Utils\Query(), $canonical, ['%other%', 0, null]));
placeholder_check('Bound value types remain distinct', $normal !== $method->invoke(new SlimStat\Utils\Query(), $canonical, ['%page%', '0', null]));
$db->dbname = 'tests-other';
placeholder_check('Analytics database scopes remain distinct', $normal !== $method->invoke(new SlimStat\Utils\Query(), $canonical, ['%page%', 0, null]));
$db->dbname = 'tests-fixture';
wp_slimstat_db::$filters_normalized = ['utime' => ['start' => 1, 'end' => 1000000]];
$GLOBALS['placeholder_cache'] = [];
$answers = [];
foreach (['{request-one}', '{request-two}'] as $marker) {
    $db->marker = $marker;
    $sql = str_replace('%', $marker, $canonical);
    $answers[] = wp_slimstat_db::get_results($sql);
}
placeholder_check('Raw historical SQL is executed only once across requests', count($db->queries) === 1);
placeholder_check('The executed SQL is preserved byte-for-byte', $db->queries[0] === str_replace('%', '{request-one}', $canonical));
placeholder_check('A raw cache hit preserves the exact answer', $answers[0] === $answers[1] && $answers[0] === [['id' => '7', 'resource' => '/100%']]);
$db->queries = [];
$GLOBALS['placeholder_cache'] = [];
wp_slimstat_db::$filters_normalized['utime']['end'] = wp_slimstat::now();
foreach (['{request-one}', '{request-two}'] as $marker) {
    $db->marker = $marker;
    wp_slimstat_db::get_results(str_replace('%', $marker, $canonical));
}
placeholder_check('Live raw reads still bypass cache writes', count($db->queries) === 2 && $GLOBALS['placeholder_cache'] === []);
if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
echo "PASS: eleven placeholder-cache identity, scope, SQL-preservation and live-read checks\n";
