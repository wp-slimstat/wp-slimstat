<?php
// Count only SQL issued after WordPress boot, around one visit-ID allocation.
if (!defined('ABSPATH')) { exit(2); }

if (!empty($args[0])) {
    $stats = new wpdb(DB_USER, DB_PASSWORD, (string) $args[0], DB_HOST);
    $stats->blogid = $GLOBALS['wpdb']->blogid;
    $stats->siteid = $GLOBALS['wpdb']->siteid;
    $stats->set_prefix($GLOBALS['wpdb']->base_prefix);
    wp_slimstat::$wpdb = $stats;
}

$queries = [];
add_filter('query', static function ($sql) use (&$queries) {
    $queries[] = preg_replace('/\s+/', ' ', trim($sql));
    return $sql;
});
$id = SlimStat\Tracker\VisitIdGenerator::generateNextVisitId();
$max = array_values(array_filter($queries, static function ($sql) {
    return false !== stripos($sql, 'MAX(visit_id)');
}));
echo json_encode([
    'visit_id' => $id,
    'query_count' => count($queries),
    'max_scan_count' => count($max),
    'queries' => $queries,
], JSON_PRETTY_PRINT) . "\n";
exit($id > 0 ? 0 : 1);
