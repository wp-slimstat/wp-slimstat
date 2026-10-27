<?php
/** Behavioral regression for cleanup health and operator-controlled optimization. */
require_once __DIR__ . '/lib/source-scan.php';
require_once dirname(__DIR__) . '/src/Utils/Query.php';
define('DAY_IN_SECONDS', 86400);
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function apply_filters($hook, $value) { return $value; }

class PurgeHealthDb
{
    public $prefix = 'test_';
    public $last_error = '';
    public $queries = [];
    public $probes = [];
    public $failDelete = false;
    public function prepare($sql, ...$args) { return $sql; }
    public function get_var($sql) {
        $this->queries[] = $sql;
        [$result, $this->last_error] = array_shift($this->probes) ?? [null, ''];
        return $result;
    }
    public function query($sql) {
        $this->queries[] = $sql;
        $this->last_error = $this->failDelete ? 'write failed' : '';
        return $this->failDelete ? false : 1;
    }
}

$source = file_get_contents(dirname(__DIR__) . '/wp-slimstat.php');
$methods = '';
foreach (['wp_slimstat_purge', 'purge_is_stale'] as $method) {
    $methods .= 'public static function ' . $method . '(){' . slimstat_function_body($source, $method) . '}';
}
eval('class wp_slimstat {
    public static $wpdb;
    public static $settings;
    public static $failures = [];
    const LAST_PURGE_OK_OPTION = "slimstat_last_purge_ok";
    const PURGE_INTERVAL = 43200;
    const DEGRADATION_OPERATIONAL = "operational";
    public static function now(){ return 1000000; }
    public static function update_option($key, $value, $autoload = false){ $GLOBALS["options"][$key] = $value; }
    public static function record_degradation(...$args){ self::$failures[] = $args; }
' . $methods . '}');

function verify($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}

foreach (['empty', 'first-empty', 'disabled', 'first-probe-error', 'second-probe-error', 'work-probe-error', 'work', 'write-error', 'opt-in', 'throttled'] as $case) {
    $GLOBALS['options'] = $case === 'first-empty' ? [] : ['slimstat_last_purge_ok' => 1];
    wp_slimstat::$wpdb = $GLOBALS['wpdb'] = new PurgeHealthDb();
    wp_slimstat::$settings = ['auto_purge' => $case === 'disabled' ? 0 : 420, 'auto_purge_delete' => 'no'];
    wp_slimstat::$failures = [];
    $work = in_array($case, ['work', 'work-probe-error', 'write-error', 'opt-in', 'throttled'], true);
    wp_slimstat::$wpdb->probes = [[$work ? 1 : null, $case === 'first-probe-error' ? 'probe failed' : ''], [null, in_array($case, ['second-probe-error', 'work-probe-error'], true) ? 'probe failed' : '']];
    wp_slimstat::$wpdb->failDelete = $case === 'write-error';
    if (in_array($case, ['opt-in', 'throttled'], true)) {
        wp_slimstat::$settings['auto_purge_optimize'] = 'on';
        $GLOBALS['options']['slimstat_purge_optimized_at'] = $case === 'throttled' ? wp_slimstat::now() : -30 * DAY_IN_SECONDS;
    }
    wp_slimstat::wp_slimstat_purge();
    $success = !in_array($case, ['disabled', 'first-probe-error', 'second-probe-error', 'work-probe-error', 'write-error'], true);
    if ('work-probe-error' === $case) {
        verify(count(wp_slimstat::$wpdb->queries) === 2, 'second-table read error stops before any purge write, even with old pageviews');
    }
    verify((get_option(wp_slimstat::LAST_PURGE_OK_OPTION) === wp_slimstat::now()) === $success, "$case: success marker");
    $optimizations = array_values(array_filter(wp_slimstat::$wpdb->queries, static function ($sql) { return strpos($sql, 'OPTIMIZE TABLE') === 0; }));
    verify(count($optimizations) === ($case === 'opt-in' ? 2 : 0), "$case: optimization opt-in/throttle");
    verify(strpos(implode(' ', $optimizations), '_archive') === false, "$case: archives never optimized");
    if ($success) {
        verify(!wp_slimstat::purge_is_stale(), "$case: healthy after success");
    }
    if ($case === 'empty') {
        wp_slimstat::wp_slimstat_purge();
        verify(!wp_slimstat::purge_is_stale(), 'repeated empty ticks stay healthy');
    }
    echo "PASS $case\n";
}
