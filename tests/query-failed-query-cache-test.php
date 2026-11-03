<?php
/**
 * A failed statement is never a cached answer, and a split never returns half an answer.
 *
 * wpdb reports a failed statement as `last_error` plus an EMPTY result (null / []) — the
 * same shape as "no rows". Before this gate the cache writers looked only at the result, so
 * one lost connection during a report render became "0 visits" for the next hour, and a
 * failed historical half of the midnight split was merged with today's half into a
 * today-only answer that looked complete. (C-1)
 */
define('ABSPATH', '/');
define('ARRAY_A', 'ARRAY_A');
define('MINUTE_IN_SECONDS', 60);
function apply_filters($tag, $value) { return $value; }
function get_transient($key) { return $GLOBALS['failed_cache'][$key] ?? false; }
function set_transient($key, $value, $ttl = 0) { $GLOBALS['failed_cache'][$key] = $value; return true; }
function delete_transient($key) { unset($GLOBALS['failed_cache'][$key]); return true; }
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
class FailedQueryDb {
    public $prefix = 'wp_';
    public $dbhost = 'db';
    public $dbname = 'tests-fixture';
    public $last_error = '';
    public $queries = [];
    /** Statements containing this substring fail the way wpdb fails: last_error set, empty result. */
    public $failWhen = '';
    public function remove_placeholder_escape($sql) { return $sql; }
    public function prepare($sql, ...$args) {
        if (1 === count($args) && is_array($args[0])) { $args = $args[0]; }
        return vsprintf(str_replace('%s', "'%s'", $sql), $args);
    }
    private function run($sql) {
        $this->queries[]  = $sql;
        $this->last_error = ('' !== $this->failWhen && false !== strpos($sql, $this->failWhen)) ? 'MySQL server has gone away' : '';
        return '' === $this->last_error;
    }
    public function get_var($sql) { return $this->run($sql) ? '42' : null; }
    public function get_row($sql) { return $this->run($sql) ? ['counthits' => '42'] : null; }
    public function get_col($sql) { return $this->run($sql) ? ['42'] : []; }
    public function get_results($sql, $format) {
        if (!$this->run($sql)) { return []; }
        preg_match("/dt BETWEEN '(\d+)' AND '(\d+)'/", $sql, $m);
        return [['resource' => $m[1] . '-' . $m[2], 'counthits' => '1']];
    }
}
class wp_slimstat {
    public static $wpdb;
    public static $settings = ['show_sql_debug' => 'no'];
    public static function now() { return 2000000000; }
}
require dirname(__DIR__) . '/src/Utils/NetworkMerge.php';
require dirname(__DIR__) . '/src/Utils/Query.php';
require dirname(__DIR__) . '/admin/view/wp-slimstat-db.php';
use SlimStat\Utils\Query;

$db = new FailedQueryDb();
wp_slimstat::$wpdb = $GLOBALS['wpdb'] = $db;
$failures = [];
function failed_check($name, $condition) {
    global $failures;
    if (!$condition) { $failures[] = $name; }
}
$now   = wp_slimstat::now();
$today = intdiv($now, 86400) * 86400;
$past  = [$today - 3 * 86400, $today - 86400];      // entirely historical: cacheable, no split
$span  = [$today - 3 * 86400, $now];                // crosses midnight: split into cached + live
function report($from, $to) {
    return Query::select('resource, COUNT(*) AS counthits')->from('wp_slim_stats')
        ->where('dt', 'BETWEEN', [$from, $to])->groupBy('resource')->orderBy('counthits DESC')->limit(5)
        ->allowCaching(true);
}
function reset_world($failWhen = '') {
    global $db;
    $GLOBALS['failed_cache'] = [];
    $db->queries             = [];
    $db->failWhen            = $failWhen;
}

// ── 1. Each executor: a failed statement is not written, and the next call asks the database again
foreach (['getVar', 'getRow', 'getCol', 'getAll'] as $method) {
    reset_world('wp_slim_stats');
    report($past[0], $past[1])->$method();
    failed_check("{$method}: a failed statement must not be cached", [] === $GLOBALS['failed_cache']);
    report($past[0], $past[1])->$method();
    failed_check("{$method}: the call after a failure must query again, not serve a cached failure", 2 === count($db->queries));

    // Positive control: the same shape succeeds, is cached, and the second call does not hit the database.
    reset_world();
    report($past[0], $past[1])->$method();
    report($past[0], $past[1])->$method();
    failed_check("{$method}: a clean historical answer is still cached", 1 === count($GLOBALS['failed_cache']) && 1 === count($db->queries));
}

// ── 2. The split: both halves clean → merged answer, historical half cached, today's half not
reset_world();
$rows = report($span[0], $span[1])->getAll();
$keys = array_column($rows, 'resource');
sort($keys);
failed_check('split: clean halves merge into one answer', [$span[0] . '-' . ($today - 1), $today . '-' . $now] === $keys);
failed_check('split: only the historical half is cached', 1 === count($GLOBALS['failed_cache']) && 2 === count($db->queries));

// ── 3. A failed historical half: not cached, and the answer comes from ONE full-range statement
reset_world("AND '" . ($today - 1) . "'");
$rows = report($span[0], $span[1])->getAll();
failed_check('split: a failed historical half must not be cached as "no rows"', [] === $GLOBALS['failed_cache']);
failed_check('split: a failed historical half must not yield a today-only answer', [$span[0] . '-' . $now] === array_column($rows, 'resource'));
failed_check('split: the fallback is one full-range statement', 3 === count($db->queries) && false !== strpos(end($db->queries), "BETWEEN '{$span[0]}' AND '{$now}'"));

// ── 4. A failed live half: same fallback, and the historical half's cache entry survives (it was correct)
reset_world("BETWEEN '{$today}'");
$rows = report($span[0], $span[1])->getAll();
failed_check('split: a failed live half must not yield a history-only answer', [$span[0] . '-' . $now] === array_column($rows, 'resource'));
failed_check('split: a failed live half still leaves the clean historical half cached', 1 === count($GLOBALS['failed_cache']));

// ── 5. A stale error on the shared handle is not a failure of THIS split: cache hit + clean live half → merged
reset_world();
report($span[0], $span[1])->getAll();          // warms the historical half
$db->queries    = [];
$db->last_error = 'left over from an unrelated statement';
$rows = report($span[0], $span[1])->getAll();
failed_check('split: a cached historical half plus a clean live half is a merge, not a fallback', 1 === count($db->queries) && 2 === count($rows));

// ── 6. The legacy string-SQL path (every Pro report) obeys the same rule
wp_slimstat_db::$filters_normalized = ['utime' => ['start' => $past[0], 'end' => $past[1]]];
$legacySql = "SELECT resource FROM wp_slim_stats WHERE dt BETWEEN '{$past[0]}' AND '{$past[1]}'";
reset_world('wp_slim_stats');
wp_slimstat_db::get_results($legacySql);
failed_check('legacy get_results: a failed statement must not be cached', [] === $GLOBALS['failed_cache']);
reset_world();
wp_slimstat_db::get_results($legacySql);
failed_check('legacy get_results: a clean historical answer is still cached', 1 === count($GLOBALS['failed_cache']));

if ([] !== $failures) {
    foreach ($failures as $f) { fwrite(STDERR, "FAIL: {$f}\n"); }
    exit(1);
}
echo "PASS: failed statements are never cached; a failed split half falls back to one full-range statement\n";
