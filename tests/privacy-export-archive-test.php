<?php
/** Execute privacy exports over independently paged live/archive fixtures. Native SQL is checked in CI. */
define('ABSPATH', '/');
function __($text, $domain = '') { return $text; }
function get_option($name) { return $name === 'date_format' ? 'Y-m-d' : 'H:i:s'; }
function date_i18n($format, $time) { return gmdate($format, $time); }
class WP_Error {}
function is_wp_error($value) { return $value instanceof WP_Error; }
class wp_slimstat { public static $wpdb; }
class ExportDb {
    public $prefix = 'test_';
    public $last_error = '';
    public $tables = [];
    public $columns = [];
    public $failMetadata = false;
    public $failColumns = false;
    public $failRows = false;
    public $failAll = false;
    public function esc_like($value) { return $value; }
    public function prepare($sql, ...$args) { return vsprintf(str_replace('%s', "'%s'", $sql), $args); }
    public function get_var($sql) {
        $this->last_error = '';
        if ($this->failAll || $this->failMetadata) { $this->last_error = 'metadata failed'; return null; }
        if (preg_match("/SHOW TABLES LIKE '([^']+)'/i", $sql, $m)) { return isset($this->tables[$m[1]]) ? $m[1] : null; }
        if (!preg_match('/SHOW COLUMNS FROM (\w+)/i', $sql, $m)) { throw new RuntimeException('Unexpected metadata query: ' . $sql); }
        if ($this->failColumns) { $this->last_error = 'column metadata failed'; return null; }
        if (!isset($this->tables[$m[1]])) { $this->last_error = 'missing table'; return null; }
        return in_array('email', $this->columns[$m[1]], true) ? 'email' : null;
    }
    public function get_results($sql) {
        $this->last_error = '';
        if ($this->failAll || $this->failRows) { $this->last_error = 'row query failed'; return []; }
        if (!preg_match('/FROM\s+(\w+)/i', $sql, $m)) { throw new RuntimeException('Unexpected row query: ' . $sql); }
        $table = $m[1];
        $joined = preg_match('/INNER JOIN\s+(\w+)/i', $sql, $join);
        $parent = $joined ? $join[1] : $table;
        if (!isset($this->tables[$table], $this->tables[$parent]) || !in_array('email', $this->columns[$parent], true)) {
            $this->last_error = 'missing table or email column'; return [];
        }
        preg_match("/email\s*=\s*'([^']*)'/i", $sql, $email);
        $rows = array_values(array_filter($this->tables[$table], function ($row) use ($parent, $joined, $email) {
            $owner = $joined ? ($this->tables[$parent][$row['id']] ?? []) : $row;
            return ($owner['email'] ?? '') === $email[1];
        }));
        $key = $joined ? 'event_id' : 'id';
        usort($rows, static function ($a, $b) use ($key) { return [$b['dt'], $b[$key]] <=> [$a['dt'], $a[$key]]; });
        preg_match('/LIMIT\s+(\d+)\s+OFFSET\s+(\d+)/i', $sql, $page);
        return array_map(static function ($row) { return (object) $row; }, array_slice($rows, (int) $page[2], (int) $page[1]));
    }
}
require dirname(__DIR__) . '/src/Services/Privacy/DataExporter.php';
require dirname(__DIR__) . '/src/Reports/ReportPrivacy.php';
$check = static function ($ok, $message) { if (!$ok) { throw new RuntimeException($message); } };
$core = $GLOBALS['wpdb'] = new ExportDb(); $core->failAll = true;
$db = wp_slimstat::$wpdb = new ExportDb(); $db->prefix = 'wrong_';
$seed = static function ($live, $archive) use ($db) {
    $db->tables = []; $db->columns = [];
    foreach (['' => $live, '_archive' => $archive] as $suffix => $count) {
        $stats = 'test_slim_stats' . $suffix; $events = 'test_slim_events' . $suffix;
        $db->tables[$stats] = $db->tables[$events] = [];
        $db->columns[$stats] = ['id', 'email', 'dt']; $db->columns[$events] = ['event_id', 'id', 'dt'];
        for ($id = 1; $id <= $count; ++$id) {
            $db->tables[$stats][$id] = ['id' => $id, 'email' => 'owned@example.test', 'resource' => '/owned' . $suffix . '-' . $id, 'dt' => 1700000000];
            $db->tables[$events][$id] = ['event_id' => $id, 'id' => $id, 'notes' => 'owned' . $suffix . '-' . $id, 'dt' => 1700000000];
        }
    }
};
foreach (['live', 'archive', 'both'] as $location) {
    foreach ([0, 1, 500, 501, 1000, 1001] as $count) {
        $live = $location === 'archive' ? 0 : $count; $archive = $location === 'live' ? 0 : $count;
        $seed($live, $archive);
        foreach (['exportPageviews', 'exportEvents'] as $method) {
            $rows = []; $done = false;
            for ($page = 1; $page <= 4; ++$page) {
                $result = \SlimStat\Services\Privacy\DataExporter::$method('owned@example.test', $page);
                $check(!is_wp_error($result), "$location $count $method: export failed");
                $check(count($result['data']) <= 1000, 'Each export page must stay bounded');
                $rows = array_merge($rows, $result['data']); $done = $result['done'];
                if ($done) { break; }
            }
            $check($done && count($rows) === $live + $archive, "$location $count $method: retained rows missing or pagination incomplete");
            $check(count(array_unique(array_column($rows, 'item_id'))) === count($rows), "$location $count $method: live/archive IDs collide");
        }
    }
}
$seed(1, 1);
$db->tables['test_slim_stats'][1]['email'] = 'other@example.test';
$archived = \SlimStat\Services\Privacy\DataExporter::exportEvents('owned@example.test');
$check(count($archived['data']) === 1 && strpos(json_encode($archived), 'owned_archive') !== false, 'Archived events must use their archived parent identity');
$db->tables['test_slim_stats_archive'][1]['resource'] = '/fresh-archive';
$check(strpos(json_encode(\SlimStat\Services\Privacy\DataExporter::exportPageviews('owned@example.test')), '/fresh-archive') !== false, 'Archived exports must remain fresh');
foreach (['test_slim_stats', 'test_slim_stats_archive'] as $legacy) {
    $seed(1, 1); $db->columns[$legacy] = ['id', 'dt'];
    foreach (['exportPageviews', 'exportEvents'] as $method) {
        $result = \SlimStat\Services\Privacy\DataExporter::$method('owned@example.test');
        $check(!is_wp_error($result) && count($result['data']) === 1 && $result['done'], 'Legacy tables without email must not break export');
    }
}
$seed(1, 1); unset($db->tables['test_slim_stats_archive'], $db->tables['test_slim_events_archive']);
$optional = \SlimStat\Services\Privacy\DataExporter::exportPageviews('owned@example.test');
$check(!is_wp_error($optional) && count($optional['data']) === 1, 'Absent optional archives must not break export');
foreach (['failMetadata', 'failColumns', 'failRows'] as $fault) {
    $seed(1, 1); $db->$fault = true;
    foreach (['exportPageviews', 'exportEvents'] as $method) {
        $check(is_wp_error(\SlimStat\Services\Privacy\DataExporter::$method('owned@example.test')), "$fault must refuse an incomplete export");
    }
    $db->$fault = false;
}
echo "PASS: email exports cover live/archive pagination, colliding IDs, fresh objects, legacy schemas and query-error refusal\n";
