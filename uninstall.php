<?php

// Avoid direct access to this piece of code
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$slimstat_options = get_option('slimstat_options', []);

// Delete COLLECTED ANALYTICS only when the user explicitly opted in (Settings →
// Maintenance → "Delete Data on Uninstall"); an absent/any-non-'on' value keeps
// all data. Scheduler entries and regenerable caches go either way — see
// slimstat_uninstall_cron() and slimstat_uninstall_artifacts().
$slimstat_delete_data = ('on' === ($slimstat_options['delete_data_on_uninstall'] ?? 'no'));

if ($slimstat_delete_data && !empty($slimstat_options['addon_custom_db_dbuser']) && !empty($slimstat_options['addon_custom_db_dbpass']) && !empty($slimstat_options['addon_custom_db_dbname']) && !empty($slimstat_options['addon_custom_db_dbhost'])) {
    $slimstat_wpdb = new wpdb($slimstat_options['addon_custom_db_dbuser'], $slimstat_options['addon_custom_db_dbpass'], $slimstat_options['addon_custom_db_dbname'], $slimstat_options['addon_custom_db_dbhost']);
} else {
    $slimstat_wpdb = $GLOBALS['wpdb'];
}

// Cron entries are per-site (each blog has its own `cron` option), so they clear
// inside the blog loop. Data deletion is likewise per-site. The uploads directory
// is NOT: it resolves to one shared path for the whole network, so it is handled
// once, after the loop.
if (function_exists('is_multisite') && is_multisite()) {
    $blogids = $GLOBALS['wpdb']->get_col($GLOBALS['wpdb']->prepare("
		SELECT blog_id
		FROM {$GLOBALS[ 'wpdb' ]->blogs}
		WHERE site_id = %d
			AND deleted = 0
			AND spam = 0", $GLOBALS['wpdb']->siteid));

    foreach ($blogids as $blog_id) {
        switch_to_blog($blog_id);
        slimstat_uninstall_cron();
        if ($slimstat_delete_data) {
            slimstat_uninstall($slimstat_wpdb);
        }
        restore_current_blog();
    }
} else {
    slimstat_uninstall_cron();
    if ($slimstat_delete_data) {
        slimstat_uninstall($slimstat_wpdb);
    }
}

slimstat_uninstall_artifacts($slimstat_delete_data);

if (!$slimstat_delete_data) {
    return;
}

$slimstat_wpdb->query(sprintf('DROP TABLE IF EXISTS %sslim_browsers', $GLOBALS[ 'wpdb' ]->base_prefix));
$slimstat_wpdb->query(sprintf('DROP TABLE IF EXISTS %sslim_screenres', $GLOBALS[ 'wpdb' ]->base_prefix));
$slimstat_wpdb->query(sprintf('DROP TABLE IF EXISTS %sslim_content_info', $GLOBALS[ 'wpdb' ]->base_prefix));

/**
 * Clear every cron hook the plugin schedules, for the current site.
 *
 * Always runs: an orphaned schedule keeps firing against a plugin that is gone,
 * and it is not the user's analytics, so the opt-in does not apply. The hook list
 * is shared with wp_slimstat_admin::deactivate() so the two cannot drift.
 */
function slimstat_uninstall_cron()
{
    foreach (require __DIR__ . '/src/cron-hooks.php' as $hook) {
        wp_clear_scheduled_hook($hook);
    }
}

/**
 * Clean up uploads/wp-slimstat.
 *
 * The directory is a mixed bag, so the seam is drawn at the artifact, not the
 * folder. The browscap cache is always regenerable, so it always goes — that is
 * what stops a declined uninstall from stranding disk space forever.
 *
 * The GeoIP database only goes on the opt-in path. On hosts without ext-phar the
 * plugin cannot download it and tells users to upload the .mmdb by hand
 * (admin/config/index.php), so deleting it on the keep-my-data path would destroy
 * a file the plugin cannot replace — and break this release's own promise that
 * reinstalling picks up where you left off.
 *
 * @param bool $delete_data Whether the user opted into full data deletion.
 */
function slimstat_uninstall_artifacts($delete_data = false)
{
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

    // Honour the relocation filter, or a site that moved the directory keeps
    // everything after uninstall.
    if (function_exists('apply_filters')) {
        $upload_dir = apply_filters('slimstat_maxmind_path', $upload_dir);
    }

    // WP_Filesystem() lives in wp-admin/includes/file.php, which is NOT loaded on
    // every uninstall path (WP-CLI's `wp plugin uninstall` does not bootstrap the
    // admin includes). Calling it unguarded would fatal — and this block now runs on
    // EVERY uninstall, not just the opt-in one, so the blast radius is every user.
    // Mirrors the guard in src/Services/Browscap.php.
    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    if (!WP_Filesystem()) {
        return; // No usable filesystem transport (e.g. FTP credentials needed).
    }

    global $wp_filesystem;
    if (!$wp_filesystem) {
        return;
    }

    if ($delete_data) {
        $wp_filesystem->delete($upload_dir, true, 'd');
        return;
    }

    $wp_filesystem->delete($upload_dir . '/browscap-cache-master', true, 'd');
}

function slimstat_uninstall($_wpdb = '')
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

    // Diagnostics and health state.
    delete_option('slimstat_tracker_error');
    delete_option('slimstat_tracker_error_detail');
    delete_option('slimstat_tracker_warning');
    delete_option('slimstat_geoip_error');
    delete_option('slimstat_degradations');
    delete_option('slimstat_last_geoip_dl');

    // The daily IP-hash salt is privacy-relevant: it must not outlive an uninstall
    // that promises to erase all plugin settings.
    delete_option('slimstat_daily_salt');
    delete_option('slimstat_daily_salt_date');

    // Migration / index bookkeeping.
    delete_option('slimstat_migration_status');
    delete_option('slimstat_permalink_structure_updated');
    delete_option('slimstat_goals_funnels_since');
    delete_option('slimstat_dt_out_indexed');
    delete_option('slimstat_country_dt_indexed');
    delete_option('slimstat_dt_screen_indexed');
    delete_option('slimstat_dt_browser_indexed');
    delete_option('slimstat_dt_visit_indexed');
    delete_option('slimstat_dt_platform_indexed');
    delete_option('slimstat_notes_migration_cursor');
    delete_option('slimstat_schema_upgrade_lock');
    delete_option('slimstat_schema_repair_claim');
// Migration runner state. Every option this plugin creates has to be removable — and the
// run claim especially, since a stranded one is the single thing a user cannot clear from
// the UI, making reinstall the only remedy that must actually work.
delete_option('slimstat_migration_run_claim');
delete_option('slimstat_migration_dismissed');

    // Goals & Funnels (5.5.0+): admin-configured records + cache-version key.
    delete_option('slimstat_goals');
    delete_option('slimstat_funnels');
    delete_option('slimstat_goals_cache_ver');

    // Goals & Funnels (5.5.0+): per-goal/per-funnel transients plus the
    // unique-visitor denominator transients (slimstat_uv_*), accumulated between
    // cache-version bumps. Safe to DELETE with LIKE here because uninstall runs
    // once and isn't racing other requests.
    $GLOBALS['wpdb']->query(sprintf(
        "DELETE FROM %soptions WHERE option_name LIKE '\\_transient\\_slimstat\\_goal\\_%%' OR option_name LIKE '\\_transient\\_timeout\\_slimstat\\_goal\\_%%' OR option_name LIKE '\\_transient\\_slimstat\\_funnel\\_%%' OR option_name LIKE '\\_transient\\_timeout\\_slimstat\\_funnel\\_%%' OR option_name LIKE '\\_transient\\_slimstat\\_uv\\_%%' OR option_name LIKE '\\_transient\\_timeout\\_slimstat\\_uv\\_%%'",
        $GLOBALS['wpdb']->prefix
    ));

    $GLOBALS['wpdb']->query(sprintf("DELETE FROM %susermeta WHERE meta_key LIKE '%%meta-box-order_slimstat%%'", $GLOBALS[ 'wpdb' ]->prefix));
    $GLOBALS['wpdb']->query(sprintf("DELETE FROM %susermeta WHERE meta_key LIKE '%%metaboxhidden_slimstat%%'", $GLOBALS[ 'wpdb' ]->prefix));
    $GLOBALS['wpdb']->query(sprintf("DELETE FROM %susermeta WHERE meta_key LIKE '%%closedpostboxes_slimstat%%'", $GLOBALS[ 'wpdb' ]->prefix));

    // Sweep filter-options transients left behind by the dropdown cache.
    $transient_like = $GLOBALS['wpdb']->esc_like('_transient_slimstat_fopts_') . '%';
    $timeout_like   = $GLOBALS['wpdb']->esc_like('_transient_timeout_slimstat_fopts_') . '%';
    $GLOBALS['wpdb']->query($GLOBALS['wpdb']->prepare(
        "DELETE FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $transient_like,
        $timeout_like
    ));
    if (function_exists('wp_cache_delete_group')) {
        wp_cache_delete_group('slimstat_filter_options');
    }
}
