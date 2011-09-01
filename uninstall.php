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
	$wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}slim_visits`");

	// Goodbye options...
	delete_option('slimstat_secret');
	delete_option('slimstat_is_tracking');
	delete_option('slimstat_enable_javascript');
	delete_option('slimstat_custom_js_path');
	delete_option('slimstat_browscap_autoupdate');
	delete_option('slimstat_ignore_interval');
	delete_option('slimstat_ignore_bots');
	delete_option('slimstat_track_users');
	delete_option('slimstat_auto_purge');
	delete_option('slimstat_use_separate_menu');
	delete_option('slimstat_convert_ip_addresses');
	delete_option('slimstat_use_european_separators');
	delete_option('slimstat_rows_to_show');
	delete_option('slimstat_ip_lookup_service');
	delete_option('slimstat_refresh_interval');
	delete_option('slimstat_ignore_ip');
	delete_option('slimstat_ignore_resources');
	delete_option('slimstat_ignore_browsers');
	delete_option('slimstat_ignore_referers');
	delete_option('slimstat_ignore_users');
	delete_option('slimstat_can_view');
	delete_option('slimstat_capability_can_view');
	delete_option('slimstat_can_admin');
	delete_option('slimstat_enable_footer_link');

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