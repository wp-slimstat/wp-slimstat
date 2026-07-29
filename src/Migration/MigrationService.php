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
     * Initializes the migration system, registers migrations, and hooks into WordPress.
     */
    public static function init(): void
    {
        add_action('init', function () {
            // Not redundant with the is_user_logged_in() gate at the call site: that is true
            // for any authenticated visitor on any FRONTEND page too. Without this, every
            // logged-in frontend pageview would build a manager and register nine
            // migrations — the same cost shape that was just removed from the admin bar.
            if (!is_admin()) {
                return;
            }

            global $wpdb;
            $manager = new MigrationManager($wpdb);

            // Register all migrations
            $manager->register(new CreateDtOutIndex($wpdb));
            $manager->register(new CreateCountryDtIndex($wpdb));
            $manager->register(new CreateDtScreenIndex($wpdb));
            $manager->register(new CreateDtBrowserIndex($wpdb));
            $manager->register(new CreateDtPlatformIndex($wpdb));
            $manager->register(new CreateGoalQueriesIndex($wpdb));
            $manager->register(new CreateFunnelQueriesIndex($wpdb));
            $manager->register(new CreateEventsNotesDtIndex($wpdb));
            $manager->register(new RecoverCorruptedHeatmapPositions($wpdb));
            $manager->register(new ConvertTablesToUtf8mb4($wpdb));

            $admin = new MigrationAdmin($manager);
            $admin->hooks();
        }, 70); // Run after SlimStat admin init (priority 60)
    }
}
