<?php
declare(strict_types=1);

namespace SlimStat\Migration;

use SlimStat\Migration\Admin\MigrationAdmin;
use SlimStat\Migration\Migrations\CreateCountryDtIndex;
use SlimStat\Migration\Migrations\CreateDtBrowserIndex;
use SlimStat\Migration\Migrations\CreateDtOutIndex;
use SlimStat\Migration\Migrations\CreateDtPlatformIndex;
use SlimStat\Migration\Migrations\CreateDtScreenIndex;
use SlimStat\Migration\Migrations\MigrateSlimStatBannerToConsentIntegration;

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
            $manager->register(new MigrateSlimStatBannerToConsentIntegration($wpdb));

            $admin = new MigrationAdmin($manager);
            $admin->hooks();
        }, 70); // Run after SlimStat admin init (priority 60)
    }
}
