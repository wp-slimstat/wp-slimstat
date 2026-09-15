<?php
// Exercise the real activation and upgrade bodies, including failure recording and lock release.
namespace SlimStat\Tracker {
    class VisitIdGenerator { public static $result = -1; public static function initializeCounter() { return self::$result; } }
}
namespace {
    require __DIR__ . '/lib/source-scan.php';
    define('SLIMSTAT_ANALYTICS_VERSION', '6.0.0');
    class wp_slimstat {
        public static $settings = [], $degraded = [], $saved = [];
        const DEGRADATION_OPERATIONAL = 'operational';
        public static function record_degradation(...$args) { self::$degraded[] = $args; }
        public static function update_option($name, $value) { self::$saved[$name] = $value; }
    }
    function apply_filters($hook, $value) { return $value; }
    function update_option(...$args) { return true; }
    function delete_option($name) { $GLOBALS['released'][] = $name; }
    function flush_rewrite_rules() { $GLOBALS['flushed']++; }
    $GLOBALS['wpdb'] = new class { public $options = 'wp_options'; public function query($sql) { return 0; } };
    $source = file_get_contents(dirname(__DIR__) . '/admin/index.php');
    $methods = '';
    foreach (['init_environment', 'run_schema_upgrade', 'update_tables_and_options'] as $method) {
        $methods .= 'public static function ' . $method . '() {' . slimstat_function_body($source, $method) . '}';
    }
    eval('class AdminCounterProbe {
        const SCHEMA_UPGRADE_TIME_BUDGET = 10, SCHEMA_LOCK_OPTION = "schema-lock";
        public static function may_run_schema_ddl() { return true; }
        public static function claim_schema_lock() { return true; }
        public static function init_tables($db) { return ["failed" => [], "present" => []]; }
        ' . $methods . '
    }');
    foreach ([-1, 5000000] as $result) {
        \SlimStat\Tracker\VisitIdGenerator::$result = $result;
        foreach (['init_environment', 'update_tables_and_options'] as $method) {
            wp_slimstat::$settings = ['version' => '5.5.0', 'goals_indexes' => 'on'];
            wp_slimstat::$degraded = wp_slimstat::$saved = [];
            $GLOBALS['released'] = []; $GLOBALS['flushed'] = 0;
            if (AdminCounterProbe::$method() !== ($result >= 0)) { throw new \RuntimeException('Counter failure was accepted'); }
            if ($result < 0 && (wp_slimstat::$settings['version'] !== '5.5.0' || wp_slimstat::$saved || !wp_slimstat::$degraded || $GLOBALS['flushed'])) {
                throw new \RuntimeException('Failed counter stamped completion or lost degradation');
            }
            if ($method === 'update_tables_and_options' && $GLOBALS['released'] !== ['schema-lock']) { throw new \RuntimeException('Upgrade claim was not released'); }
            if ($result >= 0 && $method === 'update_tables_and_options' && wp_slimstat::$settings['version'] !== '6.0.0') { throw new \RuntimeException('Successful upgrade never completes'); }
        }
    }
    echo "PASS: failed visit counter blocks activation/upgrade completion, records degradation and releases claim; success proceeds\n";
}
