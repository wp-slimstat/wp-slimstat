<?php

// Avoid direct access to this piece of code
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Required directly, not autoloaded. This file runs with the plugin unloaded and no Composer
// autoloader — which is exactly why the drop list was hardcoded here in the first place, and why
// Schema.php is deliberately dependency-free. `class_exists` first because WP-CLI's
// `wp plugin uninstall` can reach this with the plugin still in memory.
if (!class_exists('SlimStat\\Schema\\Schema')) {
    require_once __DIR__ . '/src/Schema/Schema.php';
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
        slimstat_uninstall_transients();
        if ($slimstat_delete_data) {
            slimstat_uninstall($slimstat_wpdb);
        }
        restore_current_blog();
    }
} else {
    slimstat_uninstall_cron();
    slimstat_uninstall_transients();
    if ($slimstat_delete_data) {
        slimstat_uninstall($slimstat_wpdb);
    }
}

slimstat_uninstall_artifacts($slimstat_delete_data);

if (!$slimstat_delete_data) {
    return;
}

// Retired pre-5.0 dimension tables. Network-wide even on multisite, hence base_prefix, hence
// outside the per-blog loop above.
foreach (SlimStat\Schema\Schema::legacyTables('base_prefix') as $slimstat_legacy_table) {
    $slimstat_wpdb->query(sprintf('DROP TABLE IF EXISTS %s%s', $GLOBALS['wpdb']->base_prefix, $slimstat_legacy_table));
}

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

/**
 * Remove every transient this plugin creates. RUNS ON BOTH PATHS, like the cron cleanup.
 *
 * Transients are a regenerable cache, not analytics, so keeping them serves nobody: the
 * "keep my data" setting is about a visitor's browsing history, not about a cached dropdown.
 * Until now the keep-data path -- the DEFAULT since 5.5.1, and therefore what almost every
 * uninstall does -- swept NOTHING, and the opt-in path swept four prefixes out of the sixteen
 * families in use. admin/index.php records finding 2,146 accumulated wp_slimstat_query_* rows
 * on the reference install; those survived an uninstall, a reinstall, and every uninstall
 * after that.
 *
 * THREE PREFIX FAMILIES, NOT A LIST OF KEYS. The four-prefix list was already stale twice
 * over. Every transient key this plugin writes begins `slimstat_`, `wp_slimstat_` or
 * `wp-slimstat-`, and enumerating families covers keys added later by construction. The
 * `_timeout_` twin of each is swept with it, or WordPress is left holding an expiry for a
 * value that is gone.
 *
 * Per-blog, so it runs inside the multisite loop: transients live in each site's own options
 * table. No site transients exist anywhere in this plugin (grep for set_site_transient
 * returns nothing outside vendor/).
 *
 * Built with esc_like() + prepare(), NOT the hand-escaped sprintf() the goals sweep uses:
 * `_` is a single-character LIKE wildcard, so an unescaped `slimstat_` also matches
 * `slimstatX`, and getting that right by hand once per pattern is how the list went stale.
 */
function slimstat_uninstall_transients()
{
    $db = $GLOBALS['wpdb'];

    // The three value families are written out as whole literals, because
    // goals-cache-key-stability-test.php greps this file for `_transient_slimstat_` and a name
    // built by concatenation is invisible to it. The `_timeout_` twins are DERIVED, so a fourth
    // family cannot be added without one — which is the precise way the old four-prefix list
    // went stale, and leaving a timeout behind strands an expiry for a value that is gone.
    $families = [
        '_transient_slimstat_',
        '_transient_wp_slimstat_',
        '_transient_wp-slimstat-',
    ];

    $patterns = [];
    foreach ($families as $family) {
        $patterns[] = $db->esc_like($family) . '%';
        $patterns[] = $db->esc_like(str_replace('_transient_', '_transient_timeout_', $family)) . '%';
    }

    $db->query($db->prepare(
        'DELETE FROM ' . $db->options . ' WHERE '
            . implode(' OR ', array_fill(0, count($patterns), 'option_name LIKE %s')),
        $patterns
    ));

    // OPTIONS-TABLE ONLY, and the previous comment here claimed otherwise. Under a persistent
    // object cache set_transient() writes the `transient` cache group and no option row at all,
    // so the DELETE above matches nothing and those entries are left to expire on their TTL.
    // Flushing the `transient` group would take every other plugin's with it.
    //
    // What IS ours to flush are the two groups this plugin writes with wp_cache_set() directly.
    // The call was wp_cache_delete_group(), which is not a WordPress function — core defines
    // wp_cache_flush_group() (6.1+, with a shim for older drop-ins) — so this branch had never
    // executed on any stock install.
    if (function_exists('wp_cache_flush_group')) {
        wp_cache_flush_group('slimstat_filter_options');
        wp_cache_flush_group('slimstat');
    }
}

function slimstat_uninstall($_wpdb = '')
{
    // Bye bye data. The list comes from the manifest, in its declared FK-safe order (children
    // before parents), so a table added in a later release cannot be the one nobody remembers to
    // remove here — which is C16 exactly, and the reason this list needed four hand-edits to add
    // one table.
    // Live tables in the manifest's declared FK-safe order (children before parents), then the
    // per-blog retired ones — `slim_outbound`, which predates 4.0, and the `_3` pair left by a
    // 3.x-era per-blog naming scheme. Both lists come from Schema, so this file holds no table
    // name of its own. It held three after the first pass at C16, in a second hardcoded loop
    // the gate could not see, which is the whole defect reappearing at half size.
    $slimstat_drop = array_merge(
        SlimStat\Schema\Schema::tables(),
        SlimStat\Schema\Schema::legacyTables('prefix')
    );

    foreach ($slimstat_drop as $slimstat_table) {
        $_wpdb->query(sprintf('DROP TABLE IF EXISTS %s%s', $GLOBALS['wpdb']->prefix, $slimstat_table));
    }

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
    // admin/index.php's COLUMN_DRIFT_OPTION. Diagnostics, but still ours to remove.
    delete_option('slimstat_schema_column_drift');
    // VisitIdGenerator::OPTION_NAME. NOT the same key as slimstat_visit_id above — that
    // pair of near-identical names is why this one survived every hand-audit of this list.
    delete_option('slimstat_visit_id_counter');
    // Minted with bare literals rather than constants, so no convention-based scan can
    // see them; they are here because the gate's own residual gap was written down.
    delete_option('wp_slimstat_notifications');
    delete_option('slimstat_purge_optimized_at');
// Migration runner state. Every option this plugin creates has to be removable — and the
// run claim especially, since a stranded one is the single thing a user cannot clear from
// the UI, making reinstall the only remedy that must actually work.
delete_option('slimstat_last_purge_ok');
delete_option('slimstat_migration_run_claim');
delete_option('slimstat_migration_dismissed');
// Minted by RecoverCorruptedHeatmapPositions as its examined-watermark.
delete_option('slimstat_heatmap_recovery_watermark');

    // Goals & Funnels (5.5.0+): admin-configured records + cache-version key.
    delete_option('slimstat_goals');
    delete_option('slimstat_funnels');
    delete_option('slimstat_goals_cache_ver');

    // The goals/funnels/unique-visitor transients used to be swept here, and the
    // filter-options ones below. Both are now covered by slimstat_uninstall_transients(),
    // which runs on BOTH uninstall paths rather than only this one — two sweepers for one job
    // is how the list came to name four prefixes out of sixteen.

    $GLOBALS['wpdb']->query(sprintf("DELETE FROM %susermeta WHERE meta_key LIKE '%%meta-box-order_slimstat%%'", $GLOBALS[ 'wpdb' ]->prefix));
    $GLOBALS['wpdb']->query(sprintf("DELETE FROM %susermeta WHERE meta_key LIKE '%%metaboxhidden_slimstat%%'", $GLOBALS[ 'wpdb' ]->prefix));
    $GLOBALS['wpdb']->query(sprintf("DELETE FROM %susermeta WHERE meta_key LIKE '%%closedpostboxes_slimstat%%'", $GLOBALS[ 'wpdb' ]->prefix));

}
