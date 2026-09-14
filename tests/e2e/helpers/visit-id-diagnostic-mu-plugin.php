<?php
/** Disposable redacted trace for the C3 first-divergence investigation. */
if (!defined('SLIMSTAT_E2E_TESTING') || !SLIMSTAT_E2E_TESTING) {
    return;
}

$GLOBALS['slimstat_visit_id_queries'] = [];
add_filter('query', static function ($query) {
    if (false !== strpos($query, 'slimstat_visit_id_counter')) {
        $GLOBALS['slimstat_visit_id_queries'][] = preg_replace('/\s+/', ' ', trim($query));
    }
    return $query;
});

add_action('shutdown', static function () {
    if (!class_exists('wp_slimstat')) {
        return;
    }
    $raw = wp_slimstat::$raw_post_array;
    $sid = is_array($raw) && is_string($raw['sid'] ?? null) ? $raw['sid'] : '';
    $cookie = is_string($_COOKIE['slimstat_tracking_code'] ?? null) ? $_COOKIE['slimstat_tracking_code'] : '';
    $stat = wp_slimstat::get_stat();
    $row = [
        'uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
        'sid_hash' => '' === $sid ? null : hash('sha256', $sid),
        'cookie_hash' => '' === $cookie ? null : hash('sha256', $cookie),
        'stat_id' => (int) ($stat['id'] ?? 0),
        'visit_id' => (int) ($stat['visit_id'] ?? 0),
        'vid_hash_hash' => empty($stat['vid_hash']) ? null : hash('sha256', (string) $stat['vid_hash']),
        'queries' => $GLOBALS['slimstat_visit_id_queries'],
        'db_error' => (string) ($GLOBALS['wpdb']->last_error ?? ''),
    ];
    file_put_contents(WP_CONTENT_DIR . '/visit-id-diagnostic.log', wp_json_encode($row) . "\n", FILE_APPEND | LOCK_EX);
});
