<?php
/** Read-only SHA256 chains of every vintage field, using the existing ENCODING_V1 encoder. */
if (!defined('WP_CLI') || !WP_CLI || 'tests-v550' !== DB_NAME || 'db' !== DB_HOST) {
    throw new RuntimeException('Requires the disposable vintage comparison database.');
}
require_once __DIR__ . '/../bench/lib/fingerprint-v2.php';
$baseline = empty($args[0]) ? null : json_decode(file_get_contents($args[0]), true, 512, JSON_THROW_ON_ERROR);
$result = ['version' => SLIMSTAT_ANALYTICS_VERSION, 'tables' => [], 'preserved' => []];
$db = wp_slimstat::$wpdb;
foreach (['slim_stats' => 'id', 'slim_events' => 'event_id'] as $suffix => $key) {
    $table = $db->prefix . $suffix;
    $schema = $db->get_results("SHOW COLUMNS FROM `$table`", ARRAY_A);
    if (!$schema || $db->last_error) { throw new RuntimeException('Cannot read table schema.'); }
    $columns = $baseline['tables'][$suffix]['columns'] ?? array_map(static function ($row) {
        return [$row['Field'], $row['Type'], 'YES' === $row['Null']];
    }, $schema);
    foreach ($columns as $column) {
        if (!preg_match('/^[a-z_]+$/', $column[0]) || !in_array($column[0], array_column($schema, 'Field'), true)) {
            throw new RuntimeException('A pinned historical field is absent or invalid.');
        }
    }
    $hash = slimstat_fp2_table_fingerprint($db, $table, $columns, '`' . $key . '`');
    $result['tables'][$suffix] = ['columns' => $columns, 'fingerprint' => $hash, 'schema' => $schema];
    if ($baseline) {
        $result['preserved'][$suffix] = $baseline['tables'][$suffix]['fingerprint'] === $hash;
    }
}
$stored = get_option('slimstat_options');
foreach (['auto_purge', 'auto_purge_delete', 'javascript_mode', 'gdpr_enabled', 'anonymous_tracking', 'set_tracker_cookie'] as $key) {
    $result['settings'][$key] = $stored[$key] ?? null;
    if ($baseline) { $result['preserved']['setting_' . $key] = $result['settings'][$key] === $baseline['settings'][$key]; }
}
echo wp_json_encode($result, JSON_PRETTY_PRINT) . "\n";
if (in_array(false, $result['preserved'], true)) { WP_CLI::error('Historical data or saved settings changed.'); }
