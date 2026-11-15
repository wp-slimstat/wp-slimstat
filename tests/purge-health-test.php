<?php
/** Behavioral regression for cleanup health and operator-controlled optimization. */
require_once __DIR__ . '/lib/source-scan.php';
require_once dirname(__DIR__) . '/src/Utils/Query.php';
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; }
function do_action($hook, ...$args) {}
function apply_filters($hook, $value) { return $value; }
function wp_next_scheduled($hook) { return $GLOBALS['purge_followup'] ?? false; }
function wp_schedule_single_event($when, $hook) { $GLOBALS['purge_followup'] = $when; ++$GLOBALS['purge_scheduled']; }

class PurgeHealthDb
{
    public $prefix = 'test_';
    public $last_error = '';
    public $queries = [];
    public $probes = [];
    public $failDelete = false;
    public $batchDone = false;
    public $batchCalls = 0;
    public $lockFailure = '';
    public $oldestFailure = false;
    public function get_row($sql, $format) { return $this->lockFailure === 'read' ? null : ['metadata_wait' => '600', 'row_wait' => '50']; }
    public function prepare($sql, ...$args) { return $sql; }
    public function get_var($sql) {
        $this->queries[] = $sql;
        if (strpos($sql, 'SELECT MIN') === 0) {
            $this->last_error = $this->oldestFailure && strpos($sql, 'slim_events') !== false ? 'oldest probe failed' : '';
            return $this->last_error === '' ? '-40000000' : null;
        }
        [$result, $this->last_error] = array_shift($this->probes) ?? [null, ''];
        return $result;
    }
    public function query($sql) {
        $this->queries[] = $sql;
        $this->last_error = '';
        if (strpos($sql, 'SET SESSION') === 0 && (($this->lockFailure === 'set' && strpos($sql, '= 5,') !== false)
            || ($this->lockFailure === 'restore' && strpos($sql, '= 600,') !== false))) {
            $this->last_error = 'setting failed'; return false;
        }
        return 1;
    }
}

// Health bookkeeping depends on the batch outcome, not its SQL (covered by the
// executable archive-order gate and native MySQL matrix).
class PurgeHealthBatch {
    public static function copyableColumns(...$args) { return $GLOBALS['purge_archive_plan']; }
    public static function purgeBatch($db, ...$args) {
        ++$db->batchCalls;
        if ($db->failDelete) { return ['stats' => 0, 'events' => 0, 'error' => 'write failed']; }
        $count = $db->batchDone ? 0 : 1;
        $db->batchDone = true;
        if ($GLOBALS['purge_budget_stop']) { $GLOBALS['purge_clock'] += 21; }
        return ['stats' => $count, 'events' => $count, 'error' => ''];
    }
}
class_alias(PurgeHealthBatch::class, 'SlimStat\\Utils\\PurgeArchive');

$source = file_get_contents(dirname(__DIR__) . '/wp-slimstat.php');
$methods = '';
foreach (['purge_expired_archives', 'wp_slimstat_purge', 'purge_is_stale', 'bump_data_version'] as $method) {
    $methods .= 'public static function ' . $method . '(){' . str_replace('microtime(true)', '$GLOBALS["purge_clock"]', slimstat_function_body($source, $method)) . '}';
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

foreach (['empty', 'first-empty', 'disabled', 'first-probe-error', 'second-probe-error', 'work-probe-error', 'work', 'write-error', 'opt-in', 'throttled', 'budget', 'lock-read', 'lock-set', 'lock-restore', 'archive-lost', 'archive-key', 'oldest-error'] as $case) {
    $GLOBALS['purge_clock'] = 0; $GLOBALS['purge_budget_stop'] = $case === 'budget';
    $GLOBALS['purge_archive_plan'] = ['copy' => ['id', 'dt'], 'lost' => $case === 'archive-lost' ? ['resource'] : [], 'usable' => $case !== 'archive-key'];
    $GLOBALS['purge_followup'] = false; $GLOBALS['purge_scheduled'] = 0;
    $GLOBALS['options'] = $case === 'first-empty' ? [] : ['slimstat_last_purge_ok' => 1];
    wp_slimstat::$wpdb = $GLOBALS['wpdb'] = new PurgeHealthDb();
    wp_slimstat::$settings = ['auto_purge' => $case === 'disabled' ? 0 : 420, 'auto_purge_delete' => strpos($case, 'archive-') === 0 ? 'on' : 'no'];
    wp_slimstat::$failures = [];
    $work = in_array($case, ['work', 'work-probe-error', 'write-error', 'opt-in', 'throttled', 'budget', 'lock-read', 'lock-set', 'lock-restore', 'archive-lost', 'archive-key', 'oldest-error'], true);
    wp_slimstat::$wpdb->probes = [[$work ? 1 : null, $case === 'first-probe-error' ? 'probe failed' : ''], [null, in_array($case, ['second-probe-error', 'work-probe-error'], true) ? 'probe failed' : '']];
    wp_slimstat::$wpdb->failDelete = $case === 'write-error';
    wp_slimstat::$wpdb->oldestFailure = $case === 'oldest-error';
    if (strpos($case, 'lock-') === 0) { wp_slimstat::$wpdb->lockFailure = substr($case, 5); }
    if ($case === 'budget') { wp_slimstat::$wpdb->probes[] = [1, '']; wp_slimstat::$wpdb->probes[] = [null, '']; }
    if (in_array($case, ['opt-in', 'throttled'], true)) {
        wp_slimstat::$settings['auto_purge_optimize'] = 'on';
        $GLOBALS['options']['slimstat_purge_optimized_at'] = $case === 'throttled' ? wp_slimstat::now() : -30 * DAY_IN_SECONDS;
    }
    wp_slimstat::wp_slimstat_purge();
    $success = !in_array($case, ['disabled', 'first-probe-error', 'second-probe-error', 'work-probe-error', 'write-error', 'budget', 'lock-read', 'lock-set', 'lock-restore', 'archive-lost', 'archive-key', 'oldest-error'], true);
    if ($case === 'oldest-error') {
        verify(wp_slimstat::$wpdb->batchCalls === 0 && count(wp_slimstat::$failures) === 1, 'oldest read error stops every batch and records failure');
        verify(strpos(end(wp_slimstat::$wpdb->queries), '= 600,') !== false, 'oldest read error restores lock limits');
    }
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
    if ($case === 'budget') {
        verify(wp_slimstat::$wpdb->batchCalls === 1, 'budget must stop before another batch');
        verify($GLOBALS['purge_scheduled'] === 1 && $GLOBALS['purge_followup'] <= time() + 120, 'partial purge schedules continuation');
        wp_slimstat::$wpdb->batchDone = false;
        wp_slimstat::$wpdb->probes = [[1, ''], [null, ''], [1, ''], [null, '']];
        wp_slimstat::wp_slimstat_purge();
        verify($GLOBALS['purge_scheduled'] === 1, 'only one near-term continuation may be scheduled');
    }
    if ($case === 'empty') {
        wp_slimstat::wp_slimstat_purge();
        verify(!wp_slimstat::purge_is_stale(), 'repeated empty ticks stay healthy');
    }
    echo "PASS $case\n";
}
