<?php
// Exercise the real activation and upgrade bodies, including failure recording and lock release.
namespace SlimStat\Tracker {
    class VisitIdGenerator { public static $result = -1, $marker = true; public static function initializeCounter() { return self::$result; } public static function isInitialized() { return self::$marker && self::$result >= 0; } }
}
namespace {
    require __DIR__ . '/lib/source-scan.php';
    define('SLIMSTAT_ANALYTICS_VERSION', '6.0.0');
    class wp_slimstat {
        public static $settings = [], $degraded = [], $saved = [], $persist = true;
        const DEGRADATION_OPERATIONAL = 'operational';
        public static function record_degradation($key, ...$args) { self::$degraded[$key] = $args; }
        public static function clear_degradation($key) { unset(self::$degraded[$key]); }
        public static function update_option($name, $value) { if (self::$persist) { self::$saved[$name] = $value; } }
    }
    function is_network_admin() { return false; }
    function apply_filters($hook, $value) { return $value; }
    function update_option(...$args) { return true; }
    // An upgrade: the settings row exists, so activation's fresh-install seeding stays out of the way.
    function get_option($name, $default = false) { return $name === 'slimstat_options' ? (wp_slimstat::$saved[$name] ?? ['version' => '5.5.0']) : $default; }
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
    // A retry clears only the recovered upgrade record, never unrelated failures.
    wp_slimstat::$settings = ['version' => '5.5.0', 'goals_indexes' => 'on'];
    wp_slimstat::$degraded = ['purge' => ['keep']];
    \SlimStat\Tracker\VisitIdGenerator::$result = -1;
    AdminCounterProbe::update_tables_and_options();
    AdminCounterProbe::update_tables_and_options();
    if (!isset(wp_slimstat::$degraded['schema upgrade'])) { throw new \RuntimeException('Persistent failure lost its warning'); }
    \SlimStat\Tracker\VisitIdGenerator::$result = 5000000;
    AdminCounterProbe::update_tables_and_options();
    if (wp_slimstat::$degraded !== ['purge' => ['keep']]) { throw new \RuntimeException('Recovery did not selectively clear the warning'); }
    foreach (['marker', 'settings'] as $failure) {
        wp_slimstat::$settings = ['version' => '5.5.0', 'goals_indexes' => 'on'];
        wp_slimstat::$saved = [];
        wp_slimstat::$degraded = ['purge' => ['keep']];
        \SlimStat\Tracker\VisitIdGenerator::$marker = $failure !== 'marker';
        wp_slimstat::$persist = $failure !== 'settings';
        if (AdminCounterProbe::update_tables_and_options() || !isset(wp_slimstat::$degraded['schema upgrade'])) {
            throw new \RuntimeException('Failed ' . $failure . ' write reported successful recovery');
        }
        \SlimStat\Tracker\VisitIdGenerator::$marker = wp_slimstat::$persist = true;
        if (!AdminCounterProbe::update_tables_and_options() || wp_slimstat::$degraded !== ['purge' => ['keep']]) {
            throw new \RuntimeException('Retry did not recover after ' . $failure . ' write failure');
        }
    }
    echo "PASS: failed visit counter blocks activation/upgrade completion, records degradation and releases claim; success proceeds\n";
}
