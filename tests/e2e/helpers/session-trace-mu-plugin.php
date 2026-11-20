<?php
/** Capture only the synthetic #417 visitor when an E2E assertion needs diagnosis. */
if (!defined('SLIMSTAT_E2E_TESTING') || !SLIMSTAT_E2E_TESTING
    || !preg_match('/issue417-\d+/', $_SERVER['HTTP_USER_AGENT'] ?? '', $match)) {
    return;
}
$marker = $match[0];
$queries = [];
add_filter('query', static function ($sql) use (&$queries) {
    if (strpos($sql, 'slim_stats') !== false || strpos($sql, 'slimstat_daily_salt') !== false) {
        $queries[] = $sql;
    }
    return $sql;
});
register_shutdown_function(static function () use ($marker, &$queries) {
    file_put_contents(WP_CONTENT_DIR . '/e2e-session-trace.jsonl', json_encode([
        'marker' => $marker,
        'time' => microtime(true),
        'uri' => $_SERVER['REQUEST_URI'],
        'stat' => wp_slimstat::get_stat(),
        'data_js' => wp_slimstat::get_data_js(),
        'settings' => array_intersect_key(wp_slimstat::$settings, array_flip([
            'gdpr_enabled', 'anonymous_tracking', 'javascript_mode', 'set_tracker_cookie', 'session_duration', 'extend_session',
        ])),
        'queries' => $queries,
    ]) . "\n", FILE_APPEND | LOCK_EX);
});
