<?php
// Avoid direct access to this piece of code
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

$slimstat_options = get_option('slimstat_options', array());
if (!empty($slimstat_options['addon_custom_db_dbuser']) && !empty($slimstat_options['addon_custom_db_dbpass']) && !empty($slimstat_options['addon_custom_db_dbname']) && !empty($slimstat_options['addon_custom_db_dbhost'])){
	$slimstat_wpdb = new wpdb($slimstat_options['addon_custom_db_dbuser'], $slimstat_options['addon_custom_db_dbpass'], $slimstat_options['addon_custom_db_dbname'], $slimstat_options['addon_custom_db_dbhost']);
}
else {
	$slimstat_wpdb = $GLOBALS['wpdb'];
}

$slimstat_wpdb->query("DROP TABLE IF EXISTS {$GLOBALS['wpdb']->base_prefix}slim_browsers");
$slimstat_wpdb->query("DROP TABLE IF EXISTS {$GLOBALS['wpdb']->base_prefix}slim_screenres");
$slimstat_wpdb->query("DROP TABLE IF EXISTS {$GLOBALS['wpdb']->base_prefix}slim_content_info");

if (function_exists('is_multisite') && is_multisite()) {
	$blogids = $GLOBALS['wpdb']->get_col($GLOBALS['wpdb']->prepare("
		SELECT blog_id
		FROM $wpdb->blogs
		WHERE site_id = %d
			AND deleted = 0
			AND spam = 0", $GLOBALS['wpdb']->siteid));

	foreach ($blogids as $blog_id) {
		switch_to_blog($blog_id);
		slimstat_uninstall($slimstat_wpdb);
	}
	restore_current_blog();
}
else{
	slimstat_uninstall($slimstat_wpdb);
}

function slimstat_uninstall($_wpdb = ''){
	// Goodbye data...
	$_wpdb->query("DROP TABLE IF EXISTS {$GLOBALS['wpdb']->prefix}slim_outbound");
	$_wpdb->query("DROP TABLE IF EXISTS {$GLOBALS['wpdb']->prefix}slim_stats");

	// Goodbye options...
	delete_option('slimstat_options');
	delete_option('slimstat_visit_id');

	$GLOBALS['wpdb']->query("DELETE FROM {$GLOBALS['wpdb']->prefix}usermeta WHERE meta_key LIKE '%wp-slimstat%'");

	// Remove scheduled autopurge events
	wp_clear_scheduled_hook('wp_slimstat_purge');
}