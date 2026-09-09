<?php
namespace SlimStat\Schema {
    class Schema {
        public static $states = [];
        public static function optionalGroup($group) { return [['slim_stats', 'a'], ['slim_stats', 'b']]; }
        public static function resolve($index, $prefix) { return $prefix . $index; }
        public static function createIndexSql($table, $index, $prefix) { return 'CREATE INDEX ' . $prefix . $index; }
        public static function indexState($db, $table, $prefix) { return array_shift(self::$states); }
    }
}
namespace {
    if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') { http_response_code(403); exit(1); }
    function __($text, $domain) { return $text; }
    class wp_slimstat { public static $wpdb; public static $settings; }
    class wp_slimstat_admin {
        public static $message;
        public static function show_message($message, $type = 'updated') { self::$message = [$message, $type]; }
    }
    class MaintenanceDb {
        public $prefix = 'wp_';
        public $results = [];
        public $queries = [];
        public function query($sql) { $this->queries[] = $sql; return array_shift($this->results) ?? 0; }
    }
    function check($ok, $label) { if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); } }
    $source = file_get_contents($argv[1] ?? dirname(__DIR__) . '/admin/config/index.php');
    $start = strpos($source, "            case 'truncate-table':");
    // Use actual newline, retaining every statement before the switch break.
    $end = strpos($source, "\n                break;", $start);
    check($start !== false && $end !== false, 'maintenance case exists');
    $truncate = substr($source, $start + strlen("            case 'truncate-table':"), $end - $start - strlen("            case 'truncate-table':"));
    foreach ([[false], [0, false], [0, 0, 0, 0]] as $results) {
        $GLOBALS['wpdb'] = wp_slimstat::$wpdb = $db = new MaintenanceDb();
        $db->results = $results;
        eval($truncate);
        $failed = in_array(false, $results, true);
        check((wp_slimstat_admin::$message[1] === 'error') === $failed, 'failed delete/optimize never claims success');
        check(count($db->queries) === count($results), 'maintenance stops after first failure and accepts zero affected rows');
    }
    $start = strpos($source, "        if (!empty(\$posted_options['db_indexes'])) {");
    $end = strpos($source, '// Geolocation settings save', $start);
    check($start !== false && $end !== false, 'index toggle block exists');
    $toggle = substr($source, $start, $end - $start);
    $missing = ['present' => [], 'missing' => ['a', 'b'], 'malformed' => []];
    $present = ['present' => ['wp_a', 'wp_b'], 'missing' => [], 'malformed' => []];
    $unknown = ['present' => [], 'missing' => [], 'malformed' => []];
    foreach (['on', 'no'] as $desired) {
        $old = $desired === 'on' ? 'no' : 'on';
        foreach ([false, true] as $success) {
            $GLOBALS['wpdb'] = wp_slimstat::$wpdb = $db = new MaintenanceDb();
            wp_slimstat::$settings = ['db_indexes' => $old];
            $posted_options = ['db_indexes' => $desired];
            $save_messages = [];
            \SlimStat\Schema\Schema::$states = [$desired === 'on' ? $missing : $present, $desired === 'on' ? $missing : $present];
            $db->results = $success ? [0, 0] : [false];
            eval($toggle);
            check(wp_slimstat::$settings['db_indexes'] === ($success ? $desired : $old), 'DDL failure preserves preference for ' . $desired);
        }
        $GLOBALS['wpdb'] = wp_slimstat::$wpdb = $db = new MaintenanceDb();
        wp_slimstat::$settings = ['db_indexes' => $old];
        \SlimStat\Schema\Schema::$states = [$desired === 'on' ? $present : $missing, $desired === 'on' ? $present : $missing];
        eval($toggle);
        check(count($db->queries) === 0 && wp_slimstat::$settings['db_indexes'] === $desired, 'retry accepts already completed DDL');
        wp_slimstat::$settings = ['db_indexes' => $old];
        \SlimStat\Schema\Schema::$states = [$unknown];
        eval($toggle);
        check(wp_slimstat::$settings['db_indexes'] === $old && count($db->queries) === 0, 'unknown index state cannot authorize DDL');
    }
    check(strpos($source, "['enable_maxmind', 'enable_browscap', 'db_indexes']") !== false, 'generic save cannot overwrite the result of index handling');
    echo "PASS: maintenance failures, zero-row success and resumable index toggles\n";
}
