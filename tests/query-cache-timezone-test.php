<?php
// The dt column stores a legacy wall-clock timestamp, with the WP offset already applied.
class wp_slimstat {
    public static $wpdb;
    public static $clock;
    public static function now() { return self::$clock; }
}
function wp_date($format, $timestamp) { return gmdate($format, $timestamp + 9 * 3600); }
require dirname(__DIR__) . '/src/Utils/Query.php';
require dirname(__DIR__) . '/admin/view/wp-slimstat-db.php';
wp_slimstat::$wpdb = (object) [];
$cache = new ReflectionProperty(SlimStat\Utils\Query::class, 'allowCaching');
if (PHP_VERSION_ID < 80100) { $cache->setAccessible(true); }
$raw = new ReflectionMethod('wp_slimstat_db', 'window_is_cacheable');
if (PHP_VERSION_ID < 80100) { $raw->setAccessible(true); }
$enable = new ReflectionMethod('wp_slimstat_db', 'maybe_enable_query_cache');
if (PHP_VERSION_ID < 80100) { $enable->setAccessible(true); }
$utc_midnight = strtotime(gmdate('Y-m-d') . ' 00:00:00 UTC');
$original = date_default_timezone_get();
try {
    foreach (['UTC', 'Asia/Tokyo', 'America/Los_Angeles'] as $server_zone) {
        date_default_timezone_set($server_zone);
        foreach ([$utc_midnight - 6 * 3600, $utc_midnight + 30 * 3600] as $now) {
            wp_slimstat::$clock = $now;
            $midnight = intdiv($now, 86400) * 86400;
            foreach ([$midnight - 1 => true, $midnight => false, $now => false] as $end => $expected) {
                wp_slimstat_db::$filters_normalized = ['utime' => ['end' => $end]];
                if ($raw->invoke(null) !== $expected) { throw new RuntimeException('Raw cache disagrees with site wall-clock day'); }
                $query = new SlimStat\Utils\Query();
                $query->canUseCacheForDateRange($end);
                if ($cache->getValue($query) !== $expected) { throw new RuntimeException('Query cache disagrees with site wall-clock day'); }
                $query->allowCaching(false);
                $enable->invoke(null, $query);
                if ($cache->getValue($query) !== $expected) { throw new RuntimeException('Report cache applies a second site offset'); }
            }
        }
    }
    SlimStat\Utils\Query::setProcessingTimestamp($utc_midnight - 6 * 3600);
    $query = new SlimStat\Utils\Query();
    $query->canUseCacheForDateRange($utc_midnight - 7 * 3600);
    if ($cache->getValue($query)) { throw new RuntimeException('Processing timestamp context lost'); }
} finally {
    date_default_timezone_set($original);
    SlimStat\Utils\Query::setProcessingTimestamp(null);
}
echo "PASS: live/historical cache cutoffs use one site wall-clock offset across server timezones and processing contexts\n";
