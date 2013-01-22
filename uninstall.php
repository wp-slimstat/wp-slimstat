<?php
// Avoid direct access to this piece of code
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

global $wpdb;

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->base_prefix}slim_countries");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->base_prefix}slim_browsers");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->base_prefix}slim_screenres");

function uninstall(){
	global $wpdb;

	// Goodbye data...
	$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}slim_stats");
	$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}slim_outbound");
	$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}slim_content_info");

	// Goodbye options...
	delete_option('slimstat_options');
	delete_option('slimstat_visit_id');

	$wpdb->query("DELETE FROM {$wpdb->prefix}usermeta WHERE meta_key LIKE '%wp-slimstat%'");

	// Remove scheduled autopurge events
	wp_clear_scheduled_hook('wp_slimstat_purge');
}

if (function_exists('is_multisite') && is_multisite()) {
	$blogids = $wpdb->get_col($wpdb->prepare("
		SELECT blog_id
		FROM $wpdb->blogs
		WHERE site_id = %d
			AND deleted = 0
			AND spam = 0", $wpdb->siteid));

	foreach ($blogids as $blog_id) {
		switch_to_blog($blog_id);
		uninstall();
	}
	restore_current_blog();
}
else{
	uninstall();
}
?>