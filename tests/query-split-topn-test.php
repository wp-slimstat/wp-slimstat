<?php
/** Native full-range fallback must preserve every group contribution and exact ranking. */
define('ABSPATH', '/');
define('ARRAY_A', 'ARRAY_A');
function apply_filters($hook, $value) { return $value; }
function get_option($key, $default = false) { return $default; }
function get_transient($key) { return $GLOBALS['top_cache'][$key] ?? false; }
function set_transient($key, $value, $ttl = 0) { $GLOBALS['top_cache'][$key] = $value; return true; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
class wp_slimstat {
    public static $wpdb;
    public static $settings = ['show_sql_debug' => 'no'];
    public static function now() { return 2000000000; }
}
class TopDb {
    public $prefix = 'wp_';
    public $last_error = '';
    public $history = [];
    public $live = [];
    public $queries = [];
    public function prepare($sql, ...$args) {
        if (isset($args[0]) && is_array($args[0])) { $args = $args[0]; }
        return vsprintf(str_replace('%s', "'%s'", $sql), $args);
    }
    public function get_results($sql, $format) {
        $this->queries[] = $sql;
        preg_match("/dt BETWEEN '(\d+)' AND '(\d+)'/", $sql, $range);
        $today = intdiv(wp_slimstat::now(), 86400) * 86400;
        $counts = [];
        foreach ([(int) $range[1] < $today ? $this->history : [], (int) $range[2] >= $today ? $this->live : []] as $half) {
            foreach ($half as $key => $count) { $counts[$key] = ($counts[$key] ?? 0) + $count; }
        }
        // Candidate lookup predicates contain only this fixture's quoted group values.
        if (preg_match_all("/resource = '([^']*)'/", $sql, $matches)) {
            $allowed = array_fill_keys($matches[1], true);
            if (strpos($sql, 'resource IS NULL') !== false) { $allowed['NULL'] = true; }
            $counts = array_intersect_key($counts, $allowed);
        } elseif (strpos($sql, 'resource IS NULL') !== false) {
            $counts = array_intersect_key($counts, ['NULL' => true]);
        }
        $rows = [];
        foreach ($counts as $key => $count) { $rows[] = ['resource' => 'NULL' === $key ? null : $key, 'counthits' => (string) $count]; }
        usort($rows, static function ($a, $b) {
            return ((int) $b['counthits'] <=> (int) $a['counthits']) ?: (($a['resource'] ?? '') <=> ($b['resource'] ?? ''));
        });
        if (preg_match('/LIMIT (\d+)(?: OFFSET (\d+))?/', $sql, $m)) { $rows = array_slice($rows, (int) ($m[2] ?? 0), (int) $m[1]); }
        return $rows;
    }
}
require dirname(__DIR__) . '/src/Utils/NetworkMerge.php';
require dirname(__DIR__) . '/src/Utils/Query.php';
$db = wp_slimstat::$wpdb = $GLOBALS['wpdb'] = new TopDb();
function top_compare($history, $live, $limit, $offset = 0) {
    global $db;
    $GLOBALS['top_cache'] = [];
    $db->history = $history;
    $db->live = $live;
    $db->queries = [];
    $q = \SlimStat\Utils\Query::select('resource, COUNT(*) AS counthits')->from('wp_slim_stats')
        ->where('dt', 'BETWEEN', [wp_slimstat::now() - 3 * 86400, wp_slimstat::now()])
        ->groupBy('resource')->orderBy('counthits DESC, resource ASC')->limit($limit, $offset)->allowCaching(true);
    $actual = $q->getAll();
    $queries = $db->queries;
    // The independent oracle sums the complete input groups before ranking or slicing.
    $counts = $history;
    foreach ($live as $key => $value) { $counts[$key] = ($counts[$key] ?? 0) + $value; }
    $keys = array_keys($counts);
    usort($keys, static function ($a, $b) use ($counts) { return ($counts[$b] <=> $counts[$a]) ?: (('NULL' === $a ? '' : $a) <=> ('NULL' === $b ? '' : $b)); });
    $expected = [];
    foreach (array_slice($keys, $offset, $limit) as $key) { $expected[] = ['resource' => 'NULL' === $key ? null : $key, 'counthits' => $counts[$key]]; }
    if ($actual != $expected) { throw new RuntimeException('Split top-N differs from the complete oracle: ' . json_encode([$history, $live, $actual, $expected])); }
    return $queries;
}
top_compare(['X' => 10, 'Y' => 9, 'Z' => 8], ['Z' => 5, 'W' => 4], 2);
top_compare(['A' => 10, 'B' => 9, 'Z' => 8], ['C' => 10, 'D' => 9, 'Z' => 8], 2);
$certified = top_compare(['A' => 100, 'B' => 90, 'C' => 1], ['C' => 100, 'B' => 1], 2);
if (count($certified) !== 1 || $GLOBALS['top_cache']) { throw new RuntimeException('Grouped live reports require one uncached full-range statement'); }
mt_srand(6001);
for ($case = 0; $case < 1000; ++$case) {
    $history = $live = [];
    foreach (['A', 'B', 'C', 'D', 'E', 'NULL'] as $key) {
        $h = mt_rand(0, 15); $l = mt_rand(0, 15);
        if ($h) { $history[$key] = $h; }
        if ($l) { $live[$key] = $l; }
    }
    top_compare($history, $live, mt_rand(1, 6), mt_rand(0, 3));
}
echo "PASS: split top-N equals the complete oracle in 1000 seeded cases and three regressions, including NULLs, ties and offsets\n";
