<?php
/** Background GeoIP scheduling and download eligibility, without network requests. */
namespace SlimStat\Services {
    function time() { return 1700000000; }
}
namespace SlimStat\Services\Geolocation {
    class GeolocationService {
        public function __construct($provider, $options) {}
        public function getProvider() { return $this; }
        public function getDbPath() { return $GLOBALS['db_path']; }
        public function updateDatabase() { ++$GLOBALS['downloads']; return $GLOBALS['download_ok']; }
    }
}
namespace {
    require __DIR__ . '/lib/source-scan.php';
    require dirname(__DIR__) . '/src/Services/GeoService.php';
    const MINUTE_IN_SECONDS = 60;
    const WEEK_IN_SECONDS = 604800;
    class wp_slimstat {
        public static $provider = 'dbip';
        public static function resolve_geolocation_provider() { return self::$provider; }
        public static function get_schedule_interval($schedule) { return WEEK_IN_SECONDS; }
        public static function log($message, $level) { throw new \RuntimeException($message); }
    }
    function wp_next_scheduled($hook) { return empty($GLOBALS['events']) ? false : min(array_column($GLOBALS['events'], 'time')); }
    function _get_cron_array() {
        $cron = [];
        foreach ($GLOBALS['events'] as $event) {
            $cron[$event['time']]['wp_slimstat_update_geoip_database'][] = ['schedule' => $event['schedule'], 'args' => []];
        }
        return $cron;
    }
    function wp_schedule_event($time, $schedule, $hook) {
        if ($GLOBALS['weekly_failure']) { return false; }
        $GLOBALS['events'][] = compact('time', 'schedule');
        return true;
    }
    function wp_schedule_single_event($time, $hook) {
        if ($GLOBALS['schedule_failure']) { return false; }
        $GLOBALS['events'][] = ['time' => $time, 'schedule' => false];
        return true;
    }
    function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
    function update_option($key, $value) { $GLOBALS['options'][$key] = $value; }
    function check($ok, $message) { if (!$ok) { throw new \RuntimeException($message); } ++$GLOBALS['assertions']; }
    function reset_geo() {
        wp_slimstat::$provider = 'dbip';
        $GLOBALS['events'] = [];
        $GLOBALS['options'] = [];
        $GLOBALS['schedule_failure'] = false;
        $GLOBALS['weekly_failure'] = false;
        $GLOBALS['db_path'] = __DIR__ . '/missing-geoip-test.mmdb';
        $GLOBALS['downloads'] = 0;
        $GLOBALS['download_ok'] = true;
    }
    $GLOBALS['assertions'] = 0;
    reset_geo();
    check(\SlimStat\Services\GeoService::scheduleDatabaseUpdate(), 'initial update is scheduled');
    check(count($GLOBALS['events']) === 2, 'initial update preserves a weekly event');
    check(wp_next_scheduled('') === 1700000060, 'initial update is due in one minute');
    check(\SlimStat\Services\GeoService::scheduleDatabaseUpdate(), 'existing imminent update is accepted');
    check(count($GLOBALS['events']) === 2, 'repeated scheduling is idempotent');
    foreach ([1700000000 - 60, 1700000000 + 600, 1700000000 + 660] as $next) {
        $GLOBALS['events'] = [['time' => $next, 'schedule' => 'weekly']];
        check(\SlimStat\Services\GeoService::scheduleDatabaseUpdate() && count($GLOBALS['events']) === 1, 'due or imminent recurring event is reused');
    }
    $GLOBALS['events'] = [['time' => 1700000000 + WEEK_IN_SECONDS, 'schedule' => 'weekly']];
    check(\SlimStat\Services\GeoService::scheduleDatabaseUpdate() && count($GLOBALS['events']) === 2, 'weekly event does not block settings update');
    reset_geo();
    $GLOBALS['schedule_failure'] = true;
    check(!\SlimStat\Services\GeoService::scheduleDatabaseUpdate(), 'scheduling failure is reported');
    reset_geo();
    $GLOBALS['weekly_failure'] = true;
    check(!\SlimStat\Services\GeoService::scheduleDatabaseUpdate(), 'weekly failure is reported even when the prompt event succeeds');
    check(count($GLOBALS['events']) === 1 && false === $GLOBALS['events'][0]['schedule'], 'weekly failure preserves the accepted prompt event');
    $prompt = $GLOBALS['events'][0];
    $GLOBALS['weekly_failure'] = false;
    check(\SlimStat\Services\GeoService::scheduleDatabaseUpdate(), 'retry repairs the weekly schedule while the prompt event is pending');
    check(count($GLOBALS['events']) === 2 && $GLOBALS['events'][0] === $prompt, 'retry preserves the original prompt event without duplicating it');
    foreach (['dbip', 'maxmind', 'cloudflare', false] as $provider) {
        reset_geo();
        wp_slimstat::$provider = $provider;
        \SlimStat\Services\GeoService::scheduleMissingDatabaseUpdate();
        check(count($GLOBALS['events']) === (in_array($provider, ['dbip', 'maxmind'], true) ? 2 : 0), 'only database providers bootstrap missing files');
    }
    reset_geo();
    $GLOBALS['db_path'] = __FILE__;
    \SlimStat\Services\GeoService::scheduleMissingDatabaseUpdate();
    check($GLOBALS['events'] === [], 'existing readable file is not bootstrapped again');

    $source = file_get_contents(dirname(__DIR__) . '/wp-slimstat.php');
    eval('class GeoUpdateProbe extends wp_slimstat { public static function wp_slimstat_update_geoip_database() {' . slimstat_function_body($source, 'wp_slimstat_update_geoip_database') . '} }');
    foreach (['dbip', 'maxmind', 'cloudflare', false] as $provider) {
        foreach ([false, true] as $fileExists) {
            reset_geo();
            wp_slimstat::$provider = $provider;
            $GLOBALS['db_path'] = $fileExists ? __FILE__ : $GLOBALS['db_path'];
            $GLOBALS['options']['slimstat_last_geoip_dl'] = time();
            GeoUpdateProbe::wp_slimstat_update_geoip_database();
            check($GLOBALS['downloads'] === (!$fileExists && in_array($provider, ['dbip', 'maxmind'], true) ? 1 : 0), 'missing file overrides recent timestamp only for local providers');
        }
    }
    foreach ([false, true] as $success) {
        reset_geo();
        $GLOBALS['db_path'] = __FILE__;
        $GLOBALS['download_ok'] = $success;
        $GLOBALS['options']['slimstat_last_geoip_dl'] = 1;
        GeoUpdateProbe::wp_slimstat_update_geoip_database();
        check($GLOBALS['downloads'] === 1, 'stale database is refreshed');
        check(($GLOBALS['options']['slimstat_last_geoip_dl'] > 1) === $success, 'only successful download advances timestamp');
    }
    echo 'PASS: GeoIP background updates (' . $GLOBALS['assertions'] . " assertions)\n";
}
