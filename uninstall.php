<?php
// Avoid direct access to this piece of code
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$slimstat_options = get_option('slimstat_options', array());

if (isset($slimstat_options['delete_data_on_uninstall']) && $slimstat_options['delete_data_on_uninstall'] != 'on') {
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

$slimstat_wpdb->query("DROP TABLE IF EXISTS {$GLOBALS[ 'wpdb' ]->base_prefix}slim_browsers");
$slimstat_wpdb->query("DROP TABLE IF EXISTS {$GLOBALS[ 'wpdb' ]->base_prefix}slim_screenres");
$slimstat_wpdb->query("DROP TABLE IF EXISTS {$GLOBALS[ 'wpdb' ]->base_prefix}slim_content_info");

function slimstat_uninstall($_wpdb = '', $_options = array())
{
    // Bye bye data...
    $_wpdb->query("DROP TABLE IF EXISTS {$GLOBALS[ 'wpdb' ]->prefix}slim_outbound");
    $_wpdb->query("DROP TABLE IF EXISTS {$GLOBALS[ 'wpdb' ]->prefix}slim_events");
    $_wpdb->query("DROP TABLE IF EXISTS {$GLOBALS[ 'wpdb' ]->prefix}slim_stats");
    $_wpdb->query("DROP TABLE IF EXISTS {$GLOBALS[ 'wpdb' ]->prefix}slim_events_archive");
    $_wpdb->query("DROP TABLE IF EXISTS {$GLOBALS[ 'wpdb' ]->prefix}slim_stats_archive");
    $_wpdb->query("DROP TABLE IF EXISTS {$GLOBALS[ 'wpdb' ]->prefix}slim_stats_3");
    $_wpdb->query("DROP TABLE IF EXISTS {$GLOBALS[ 'wpdb' ]->prefix}slim_stats_archive_3");

    // Bye bye options...
    delete_option('slimstat_options');
    delete_option('slimstat_visit_id');
    delete_option('slimstat_filters');
    delete_option('slimstat_tracker_error');

    $GLOBALS['wpdb']->query("DELETE FROM {$GLOBALS[ 'wpdb' ]->prefix}usermeta WHERE meta_key LIKE '%meta-box-order_slimstat%'");
    $GLOBALS['wpdb']->query("DELETE FROM {$GLOBALS[ 'wpdb' ]->prefix}usermeta WHERE meta_key LIKE '%metaboxhidden_slimstat%'");
    $GLOBALS['wpdb']->query("DELETE FROM {$GLOBALS[ 'wpdb' ]->prefix}usermeta WHERE meta_key LIKE '%closedpostboxes_slimstat%'");

    // Remove scheduled autopurge events
    wp_clear_scheduled_hook('wp_slimstat_purge');
    wp_clear_scheduled_hook('wp_slimstat_update_geoip_database');

    // Remove the uploads folder
    if (defined('UPLOADS')) {
        $upload_dir = ABSPATH . UPLOADS . '/wp-slimstat';
    } else {
        $upload_dir_info = wp_upload_dir();
        $upload_dir = $upload_dir_info['basedir'];

        // Handle multisite environment
        if (is_multisite()) {
            if (!(is_main_network() && is_main_site() && defined('MULTISITE'))) {
                $upload_dir = str_replace('/sites/' . get_current_blog_id(), '', $upload_dir);
            }
        }
        $upload_dir .= '/wp-slimstat';
    }

    WP_Filesystem();
    global $wp_filesystem;
    $wp_filesystem->delete($upload_dir, true, 'd');
}