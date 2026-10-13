<?php
// Activation must store new-install defaults before it creates the tables. Otherwise the next
// request finds "no settings, tables present", takes the missing-settings recovery path and a
// brand-new site gets upgrade defaults: no DB-IP and the 6.0 release notice. Runs the real
// init_environment() body against the real MissingSettingsRecovery::isFresh().
namespace SlimStat\Tracker {
    class VisitIdGenerator { public static function initializeCounter() { return 1; } }
}
namespace {
    require __DIR__ . '/lib/source-scan.php';
    require dirname(__DIR__) . '/src/Migration/MissingSettingsRecovery.php';

    class wpdb {
        public $prefix = 'wp_', $last_error = '', $tables = [];
        public function suppress_errors($on) { return false; }
        public function prepare($sql, ...$args) { return $sql; }
        public function get_col($sql) { return $this->tables; }
        public function query($sql) { return 0; }
    }
    class wp_slimstat {
        const DEGRADATION_OPERATIONAL = 'operational';
        public static $settings = [];
        public static function get_fresh_defaults() { return ['version' => '6.0.0', 'geolocation_provider' => 'dbip', 'notice_latest_news' => 'no']; }
        public static function record_degradation(...$args) {}
        public static function update_option($name, $value) {}
    }
    function apply_filters($hook, $value) { return $value; }
    function get_option($name) { return $GLOBALS['options'][$name] ?? false; }
    function add_option($name, $value) { $GLOBALS['added'][$name] = $value; return true; }
    function update_option(...$args) { return true; }
    function flush_rewrite_rules() {}

    $source = file_get_contents(dirname(__DIR__) . '/admin/index.php');
    eval('class ActivationProbe {
        public static function init_tables($db) { $GLOBALS["created"] = true; return ["failed" => [], "present" => []]; }
        public static function init_environment() {' . slimstat_function_body($source, 'init_environment') . '}
    }');

    $cases = [
        'new site'                        => [false, [], '', true],
        'settings already stored'         => [['version' => '5.5.0'], [], '', false],
        'settings lost, tables present'   => [false, ['wp_slim_stats'], '', false],
        'table probe failed'              => [false, [], 'Lost connection', false],
    ];
    foreach ($cases as $name => [$stored, $tables, $error, $expectSeed]) {
        $GLOBALS['wpdb'] = new wpdb();
        $GLOBALS['wpdb']->tables = $tables;
        $GLOBALS['wpdb']->last_error = $error;
        $GLOBALS['options'] = false === $stored ? [] : ['slimstat_options' => $stored];
        $GLOBALS['added'] = [];
        $GLOBALS['created'] = false;
        if (true !== ActivationProbe::init_environment() || !$GLOBALS['created']) {
            throw new \RuntimeException("$name: activation did not complete");
        }
        $seeded = isset($GLOBALS['added']['slimstat_options']);
        if ($seeded !== $expectSeed) {
            throw new \RuntimeException("$name: new-install defaults " . ($seeded ? 'stored' : 'not stored'));
        }
        if ($seeded && 'dbip' !== $GLOBALS['added']['slimstat_options']['geolocation_provider']) {
            throw new \RuntimeException("$name: stored something other than the new-install defaults");
        }
    }
    echo "PASS: activation stores new-install defaults only on a new site; existing settings, legacy tables and a failed probe keep the recovery path\n";
}
