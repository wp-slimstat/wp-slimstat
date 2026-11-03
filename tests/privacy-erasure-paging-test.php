<?php
/** Execute the erasers against a shrinking dataset, as WordPress increments the page. */
define('ABSPATH', '/');
function __($text, $domain = '') { return $text; }
function apply_filters($hook, $value) { return $value; }
class wp_slimstat {
    public static $wpdb;
    public static $settings = ['show_sql_debug' => 'no'];
    public static $bumps = 0;
    public static function bump_data_version() { ++self::$bumps; }
}
class WP_Error {}
class EraserDb {
    public $prefix = 'test_';
    public $last_error = '';
    public $failRead = false;
    public $rows = [];
    public function prepare($sql, ...$args) {
        if (isset($args[0]) && is_array($args[0])) { $args = $args[0]; }
        return vsprintf(str_replace('%s', "'%s'", $sql), $args);
    }
    public function get_col($sql) {
        preg_match('/FROM test_(\w+)/', $sql, $table);
        $ids = array_keys($this->rows[$table[1]]);
        if (strpos($sql, 'UNION') !== false) { $ids = array_values(array_unique(array_merge($ids, array_keys($this->rows['slim_events_archive'])))); }
        preg_match('/LIMIT (\d+)(?: OFFSET (\d+))?/', $sql, $m);
        return array_slice($ids, (int) ($m[2] ?? 0), (int) $m[1]);
    }
    public function get_var($sql) {
        $this->last_error = $this->failRead ? 'read failed' : '';
        if ($this->failRead) { return null; }
        if (strpos($sql, 'SHOW TABLES') === 0) { return 'exists'; }
        preg_match('/FROM test_(\w+)/', $sql, $m);
        return (string) count($this->rows[$m[1]]);
    }
    public function query($sql) {
        preg_match('/DELETE FROM test_(\w+)/', $sql, $m);
        $table = $m[1];
        $before = count($this->rows[$table]);
        if (preg_match('/id IN \(([^)]+)\)/', $sql, $ids)) {
            foreach (explode(',', $ids[1]) as $id) { unset($this->rows[$table][(int) $id]); }
        } else {
            $this->rows[$table] = array_slice($this->rows[$table], 500, null, true);
        }
        return $before - count($this->rows[$table]);
    }
}
require dirname(__DIR__) . '/src/Utils/Query.php';
require dirname(__DIR__) . '/src/Services/Privacy/DataEraser.php';
$db = $GLOBALS['wpdb'] = wp_slimstat::$wpdb = new EraserDb();
$failures = [];
foreach (['live', 'archive', 'both'] as $location) {
    foreach ([0, 1, 500, 501, 1000, 1001] as $count) {
        foreach (['slim_stats', 'slim_stats_archive', 'slim_events', 'slim_events_archive'] as $table) {
            $active = 'both' === $location || ('archive' === $location) === (strpos($table, '_archive') !== false);
            $db->rows[$table] = $active && $count ? array_fill_keys(range(1, $count), true) : [];
        }
        foreach (['eraseEvents' => 'slim_events', 'erasePageviews' => 'slim_stats'] as $method => $table) {
            for ($page = 1; $page <= 5; ++$page) {
                $result = \SlimStat\Services\Privacy\DataEraser::$method('erase@example.test', $page);
                if ($result['done']) { break; }
            }
            if ($db->rows[$table] || $db->rows[$table . '_archive'] || $page > 4) {
                $failures[] = "{$location} {$count} {$method}: erasure left rows or did not finish";
            }
        }
    }
}
$db->failRead = true;
foreach (['eraseEvents', 'erasePageviews'] as $method) {
    if (!(\SlimStat\Services\Privacy\DataEraser::$method('erase@example.test', 1) instanceof WP_Error)) {
        $failures[] = $method . ': query failure must stop erasure';
    }
}
if ($failures) { fwrite(STDERR, implode("\n", $failures) . "\n"); exit(1); }
echo "PASS: erasure drains live and archive batches, including 501 and 1001 rows\n";
