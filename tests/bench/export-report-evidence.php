<?php
// wp eval-file export-report-evidence.php <fresh.sqlite>
function slimstat_evidence_analytics_handle()
{
    if (class_exists('wp_slimstat')
        && property_exists('wp_slimstat', 'wpdb')
        && wp_slimstat::$wpdb instanceof wpdb
    ) {
        return wp_slimstat::$wpdb;
    }

    return apply_filters('slimstat_custom_wpdb', $GLOBALS['wpdb']);
}

if (defined('SLIMSTAT_EVIDENCE_EXPORT_LIBRARY') && SLIMSTAT_EVIDENCE_EXPORT_LIBRARY) {
    return;
}

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('run this wrapper through wp eval-file');
}
$path = (string) ($args[0] ?? '');
if ('' === $path) {
    throw new InvalidArgumentException('a fresh SQLite path is required');
}
$manifest = require __DIR__ . '/export-manifest.php';
$GLOBALS['slimstat_evidence_export_manifest'] = $manifest;
function slimstat_fp2_pinned_columns($suffix)
{
    $manifest = $GLOBALS['slimstat_evidence_export_manifest'];
    if (!isset($manifest[$suffix])) {
        throw new RuntimeException("no pinned export manifest for {$suffix}");
    }
    return $manifest[$suffix]['columns'];
}
function slimstat_fp2_order_by($suffix)
{
    $manifest = $GLOBALS['slimstat_evidence_export_manifest'];
    if (!isset($manifest[$suffix])) {
        throw new RuntimeException("no deterministic export order for {$suffix}");
    }
    return $manifest[$suffix]['order_by'];
}
require_once __DIR__ . '/lib/export-snapshot.php';

$db = slimstat_evidence_analytics_handle();
$mysql = [];
foreach ($manifest as $suffix => $table) {
    $mysql[$suffix] = slimstat_fp2_table_fingerprint(
        $db,
        $db->prefix . $suffix,
        $table['columns'],
        $table['order_by']
    );
}
$export = slimstat_fp2_export_snapshot($db, $path, array_keys($manifest));
echo 'SLIMSTAT-EVIDENCE-EXPORT ' . json_encode([
    'mysql' => $mysql,
    'export' => $export['tables'],
    'order_by' => array_map(static function ($table) { return $table['order_by']; }, $manifest),
], JSON_UNESCAPED_SLASHES) . "\n";
