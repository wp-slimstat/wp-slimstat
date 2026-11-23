<?php
/** Reconcile this k6 run's successful pageviews, individually, against stored rows. */
if (!defined('WP_CLI') || !WP_CLI) { exit(1); }
$directory = dirname(__DIR__) . '/results';
$log = (string) file_get_contents($directory . '/returning-visitor-load.log');
$summary = json_decode((string) file_get_contents($directory . '/returning-visitor-load.json'), true);
if (!preg_match('/SLIMSTAT_RUN ([0-9]+)/', $log, $run)
    || !preg_match_all('/SLIMSTAT_ACCEPTED ([^"\s]+)/', $log, $accepted)
    || !isset($summary['metrics']['cookie_set_rate']['passes'], $summary['metrics']['cookie_set_rate']['fails'])) {
    throw new RuntimeException('Missing or incomplete k6 delivery evidence');
}
$expected = array_count_values($accepted[1]);
$measured = $summary['metrics']['cookie_set_rate']['passes'] + $summary['metrics']['cookie_set_rate']['fails'];
$db = wp_slimstat::$wpdb;
$rows = $db->get_results($db->prepare(
    'SELECT resource, COUNT(*) AS copies FROM ' . $GLOBALS['wpdb']->prefix . 'slim_stats WHERE resource LIKE %s GROUP BY resource',
    '%' . $db->esc_like('k6_marker=k6-' . $run[1] . '-') . '%'
), ARRAY_A);
if ($db->last_error) { throw new RuntimeException($db->last_error); }
$stored = [];
foreach ($rows as $row) { $stored[$row['resource']] = (int) $row['copies']; }
ksort($expected);
ksort($stored);
$result = ['run' => $run[1], 'measured' => $measured, 'accepted' => array_sum($expected), 'stored' => array_sum($stored), 'pass' => $expected === $stored && $measured === array_sum($expected)];
file_put_contents($directory . '/delivery.json', wp_json_encode($result, JSON_PRETTY_PRINT));
if (!$result['pass']) {
    file_put_contents($directory . '/delivery-mismatch.json', wp_json_encode(['expected' => $expected, 'stored' => $stored]));
    throw new RuntimeException('Accepted/stored pageviews differ: ' . wp_json_encode($result));
}
echo 'PASS: exact pageview delivery ' . wp_json_encode($result) . "\n";
