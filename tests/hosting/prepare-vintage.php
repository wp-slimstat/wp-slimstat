<?php
/** Make a 5.5-native comparison fixture from the first million synthetic rows. */
if (!defined('WP_CLI') || !WP_CLI || getenv('SLIMSTAT_HOSTING_DISPOSABLE') !== '1' || DB_NAME !== 'tests-v550' || DB_HOST !== 'db' || SLIMSTAT_ANALYTICS_VERSION !== '5.5.0') {
    throw new RuntimeException('Requires the armed, disposable 5.5.0 comparison database.');
}
require_once WP_PLUGIN_DIR . '/wp-slimstat/admin/index.php';
wp_slimstat_admin::init_tables($GLOBALS['wpdb']);
$db = $GLOBALS['wpdb'];
$options = wp_slimstat::init_options();
$options = array_merge($options, ['version' => '5.5.0', 'javascript_mode' => 'on', 'tracking_request_method' => 'rest',
    'is_tracking' => 'on', 'ignore_ips' => '', 'ignore_users' => '', 'gdpr_enabled' => 'off',
    'anonymous_tracking' => 'off', 'auto_purge' => 30, 'auto_purge_delete' => 'on']);
update_option('slimstat_options', $options);
$proof = [];
foreach (['slim_stats', 'slim_events'] as $suffix) {
    $table = $db->prefix . $suffix;
    if ((int) $db->get_var("SELECT COUNT(*) FROM `$table`") !== 0) { throw new RuntimeException('Refuse a nonempty vintage fixture.'); }
    $columns = array_intersect($db->get_col("SHOW COLUMNS FROM `$table`"), $db->get_col("SHOW COLUMNS FROM wordpress.`$table`"));
    $names = '`' . implode('`,`', $columns) . '`';
    if (false === $db->query("INSERT INTO `$table` ($names) SELECT $names FROM wordpress.`$table` WHERE id <= 1000000")) { throw new RuntimeException($db->last_error); }
    $proof[$table] = ['rows' => (int) $db->get_var("SELECT COUNT(*) FROM `$table`"), 'shared_columns' => $columns];
}
update_option('slimstat_visit_id_counter', (int) $db->get_var("SELECT MAX(visit_id) FROM {$db->prefix}slim_stats"), false);
echo json_encode($proof, JSON_PRETTY_PRINT) . "\n";
