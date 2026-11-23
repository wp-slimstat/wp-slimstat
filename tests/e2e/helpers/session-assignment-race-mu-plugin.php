<?php
/** Test-only: force a competing assignment between the signed update's read and write. */
if (!defined('SLIMSTAT_E2E_TESTING') || !SLIMSTAT_E2E_TESTING) { return; }
if (!preg_match('/^(\d+):(\d+)$/', $_SERVER['HTTP_X_SLIMSTAT_E2E_ASSIGNMENT'] ?? '', $match)) { return; }
$target = (int) $match[1];
$winner = (int) $match[2];
$compete = static function ($sql) use ($target, $winner, &$compete) {
    global $wpdb;
    if (preg_match('/^UPDATE\s+(?:IGNORE\s+)?`?' . preg_quote($wpdb->prefix . 'slim_stats', '/') . '`?\s/i', $sql)
        && preg_match('/`?id`?\s*=\s*[\'\"]?' . $target . '\b/', $sql)) {
        remove_filter('query', $compete);
        $wpdb->update($wpdb->prefix . 'slim_stats', ['visit_id' => $winner], ['id' => $target]);
    }
    return $sql;
};
add_filter('query', $compete);
