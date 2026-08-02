<?php
declare(strict_types=1);

namespace SlimStat\Migration;

use SlimStat\Migration\Admin\MigrationAdmin;
use SlimStat\Migration\Migrations\ConvertTablesToUtf8mb4;
use SlimStat\Migration\Migrations\CreateCountryDtIndex;
use SlimStat\Migration\Migrations\CreateDtBrowserIndex;
use SlimStat\Migration\Migrations\CreateDtOutIndex;
use SlimStat\Migration\Migrations\CreateDtPlatformIndex;
use SlimStat\Migration\Migrations\CreateDtScreenIndex;
use SlimStat\Migration\Migrations\CreateEventsNotesDtIndex;
use SlimStat\Migration\Migrations\CreateFunnelQueriesIndex;
use SlimStat\Migration\Migrations\CreateGoalQueriesIndex;
use SlimStat\Migration\Migrations\RecoverCorruptedHeatmapPositions;

/**
 * Service class to initialize and manage the migration system.
 */
class MigrationService
{
    /**
     * The connection the slim_* tables are on.
     *
     * Not `global $wpdb`. `slimstat_custom_wpdb` can put the analytics tables on a
     * different server entirely, and this subsystem exists to run DDL against those
     * tables — so probing the main database told it, wrongly and permanently, that
     * eight indexes were missing and that the utf8mb4 conversion was already done.
     *
     * The instanceof is not an ordering guard — wp_slimstat::init() assigns its handle
     * 155 lines before it calls this class, and this only runs later still, on `init`
     * priority 70. It defends against a third-party `slimstat_custom_wpdb` filter
     * returning something that is not a wpdb, which would otherwise be a TypeError
     * against the return type rather than a degraded report.
     */
    public static function analyticsConnection(): \wpdb
    {
        return \wp_slimstat::$wpdb instanceof \wpdb ? \wp_slimstat::$wpdb : self::coreConnection();
    }

    /**
     * The connection WordPress core is on — wp_users, wp_options.
     *
     * Always local, whatever the analytics tables are doing.
     */
    public static function coreConnection(): \wpdb
    {
        return $GLOBALS['wpdb'];
    }

    /**
     * Is the migration kill switch thrown?
     *
     * `define('SLIMSTAT_DISABLE_MIGRATIONS', true)` in wp-config.php. wp.org has no staged
     * rollout, no canary, no telemetry and no remote kill switch, so this is the ONLY abort
     * mechanism that exists once a build is out.
     *
     * DELIBERATELY NOT FILTERABLE. A switch a plugin can turn back on is not a kill switch,
     * and the situation in which this is thrown is one where something is already going
     * wrong on that site.
     *
     * One implementation because there are four call sites. Four hand-rolled
     * `defined() && CONSTANT` checks are four chances to spell it differently, and the
     * failure mode is silent — the misspelled one simply never fires.
     */
    public static function migrationsDisabled(): bool
    {
        return defined('SLIMSTAT_DISABLE_MIGRATIONS') && SLIMSTAT_DISABLE_MIGRATIONS;
    }

    /**
     * Initializes the migration system, registers migrations, and hooks into WordPress.
     */
    public static function init(): void
    {
        // Nothing is built at all: no manager, no registered migrations, no probe. The
        // legacy DDL path in admin/index.php has honoured this constant for some time, and
        // this framework did not — so an owner who set it had every reason to believe
        // migrations were off while the runner proceeded. A switch that is documented and
        // silently partial is worse than one that does not exist, because it is trusted.
        if (self::migrationsDisabled()) {
            return;
        }

        add_action('init', function () {
            // Not redundant with the is_user_logged_in() gate at the call site: that is true
            // for any authenticated visitor on any FRONTEND page too. Without this, every
            // logged-in frontend pageview would build a manager and register nine
            // migrations — the same cost shape that was just removed from the admin bar.
            if (!is_admin()) {
                return;
            }

            // Named for what they are. The whole defect was that the handle called
            // "$wpdb" was the wrong one.
            $analytics = self::analyticsConnection();
            $core      = self::coreConnection();

            // MigrationManager declares no constructor — it works through the options
            // and transients APIs, which are always core-side. Passing a handle here
            // advertised a scoping it does not have.
            $manager = new MigrationManager();

            // Register all migrations
            $manager->register(new CreateDtOutIndex($analytics, $core));
            $manager->register(new CreateCountryDtIndex($analytics, $core));
            $manager->register(new CreateDtScreenIndex($analytics, $core));
            $manager->register(new CreateDtBrowserIndex($analytics, $core));
            $manager->register(new CreateDtPlatformIndex($analytics, $core));
            $manager->register(new CreateGoalQueriesIndex($analytics, $core));
            $manager->register(new CreateFunnelQueriesIndex($analytics, $core));
            $manager->register(new CreateEventsNotesDtIndex($analytics, $core));
            $manager->register(new RecoverCorruptedHeatmapPositions($analytics, $core));
            $manager->register(new ConvertTablesToUtf8mb4($analytics, $core));

            $admin = new MigrationAdmin($manager);
            $admin->hooks();
        }, 70); // Run after SlimStat admin init (priority 60)
    }
}
