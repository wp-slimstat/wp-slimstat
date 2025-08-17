<?php

// Avoid direct access to this piece of code
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$slimstat_options = get_option('slimstat_options', []);

if (isset($slimstat_options['delete_data_on_uninstall']) && 'on' != $slimstat_options['delete_data_on_uninstall']) {
    // Do not delete db data and settings
    return;
}

if (!empty($slimstat_options['addon_custom_db_dbuser']) && !empty($slimstat_options['addon_custom_db_dbpass']) && !empty($slimstat_options['addon_custom_db_dbname']) && !empty($slimstat_options['addon_custom_db_dbhost'])) {
    $slimstat_wpdb = new wpdb($slimstat_options['addon_custom_db_dbuser'], $slimstat_options['addon_custom_db_dbpass'], $slimstat_options['addon_custom_db_dbname'], $slimstat_options['addon_custom_db_dbhost']);
} else {
    $slimstat_wpdb = $GLOBALS['wpdb'];
}

if (function_exists('is_multisite') && is_multisite()) {
    $blogids = $GLOBALS['wpdb']->get_col($GLOBALS['wpdb']->prepare("
		SELECT blog_id
		FROM {$GLOBALS[ 'wpdb' ]->blogs}
		WHERE site_id = %d
			AND deleted = 0
			AND spam = 0", $GLOBALS['wpdb']->siteid));

    foreach ($blogids as $blog_id) {
        switch_to_blog($blog_id);
        slimstat_uninstall($slimstat_wpdb, $slimstat_options);
        restore_current_blog();
    }
} else {
    slimstat_uninstall($slimstat_wpdb, $slimstat_options);
}

$slimstat_wpdb->query(sprintf('DROP TABLE IF EXISTS %sslim_browsers', $GLOBALS[ 'wpdb' ]->base_prefix));
$slimstat_wpdb->query(sprintf('DROP TABLE IF EXISTS %sslim_screenres', $GLOBALS[ 'wpdb' ]->base_prefix));
$slimstat_wpdb->query(sprintf('DROP TABLE IF EXISTS %sslim_content_info', $GLOBALS[ 'wpdb' ]->base_prefix));

function slimstat_uninstall($_wpdb = '', $_options = [])
{
    // Bye bye data...
    $_wpdb->query(sprintf('DROP TABLE IF EXISTS %sslim_outbound', $GLOBALS[ 'wpdb' ]->prefix));
    $_wpdb->query(sprintf('DROP TABLE IF EXISTS %sslim_events', $GLOBALS[ 'wpdb' ]->prefix));
    $_wpdb->query(sprintf('DROP TABLE IF EXISTS %sslim_stats', $GLOBALS[ 'wpdb' ]->prefix));
    $_wpdb->query(sprintf('DROP TABLE IF EXISTS %sslim_events_archive', $GLOBALS[ 'wpdb' ]->prefix));
    $_wpdb->query(sprintf('DROP TABLE IF EXISTS %sslim_stats_archive', $GLOBALS[ 'wpdb' ]->prefix));
    $_wpdb->query(sprintf('DROP TABLE IF EXISTS %sslim_stats_3', $GLOBALS[ 'wpdb' ]->prefix));
    $_wpdb->query(sprintf('DROP TABLE IF EXISTS %sslim_stats_archive_3', $GLOBALS[ 'wpdb' ]->prefix));

    // Bye bye options...
    delete_option('slimstat_options');
    delete_option('slimstat_visit_id');
    delete_option('slimstat_filters');
    delete_option('slimstat_tracker_error');

    $GLOBALS['wpdb']->query(sprintf("DELETE FROM %susermeta WHERE meta_key LIKE '%%meta-box-order_slimstat%%'", $GLOBALS[ 'wpdb' ]->prefix));
    $GLOBALS['wpdb']->query(sprintf("DELETE FROM %susermeta WHERE meta_key LIKE '%%metaboxhidden_slimstat%%'", $GLOBALS[ 'wpdb' ]->prefix));
    $GLOBALS['wpdb']->query(sprintf("DELETE FROM %susermeta WHERE meta_key LIKE '%%closedpostboxes_slimstat%%'", $GLOBALS[ 'wpdb' ]->prefix));

    // Remove scheduled autopurge events
    wp_clear_scheduled_hook('wp_slimstat_purge');
    wp_clear_scheduled_hook('wp_slimstat_update_geoip_database');

    // Remove the uploads folder
    if (defined('UPLOADS')) {
        $upload_dir = ABSPATH . UPLOADS . '/wp-slimstat';
    } else {
        $upload_dir_info = wp_upload_dir();
        $upload_dir      = $upload_dir_info['basedir'];

        // Handle multisite environment
        if (is_multisite() && !(is_main_network() && is_main_site() && defined('MULTISITE'))) {
            $upload_dir = str_replace('/sites/' . get_current_blog_id(), '', $upload_dir);
        }

        $upload_dir .= '/wp-slimstat';
    }

    WP_Filesystem();
    global $wp_filesystem;
    $wp_filesystem->delete($upload_dir, true, 'd');
}
