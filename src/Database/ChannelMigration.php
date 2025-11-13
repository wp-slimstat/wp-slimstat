<?php

namespace SlimStat\Database;

/**
 * Database migration handler for wp_slim_channels table.
 *
 * Handles plugin activation, deactivation, and upgrade migrations with
 * schema versioning support as per Constitution Principle VIII.
 *
 * @package SlimStat\Database
 * @since 5.1.0
 */
class ChannelMigration
{
    /**
     * Current schema version.
     *
     * Increment this when making schema changes to trigger re-migration.
     *
     * @var int
     */
    public const SCHEMA_VERSION = 1;

    /**
     * WordPress option key for storing schema version.
     *
     * @var string
     */
    public const SCHEMA_VERSION_OPTION = 'slimstat_channel_schema_version';

    /**
     * WordPress option key for storing activation timestamp.
     *
     * Used to exclude historical data from classification (FR-002).
     *
     * @var string
     */
    public const ACTIVATION_TIMESTAMP_OPTION = 'slimstat_channel_activation_timestamp';

    /**
     * Check if channel schema is up-to-date (T007.2).
     *
     * Used by automatic migration hook to quickly check if migration is needed.
     * Performance: Single get_option() call, <0.1ms when up-to-date.
     *
     * @return bool True if schema version matches or exceeds current version
     */
    public static function is_up_to_date(): bool
    {
        return self::get_current_version() >= self::SCHEMA_VERSION;
    }

    /**
     * Get currently installed schema version (T007.2).
     *
     * @return int Schema version (0 if never installed)
     */
    public static function get_current_version(): int
    {
        return (int) get_option(self::SCHEMA_VERSION_OPTION, 0);
    }

    /**
     * Run activation migrations.
     *
     * This method is called when the plugin is activated. It creates the
     * wp_slim_channels table and stores the activation timestamp.
     *
     * Schema versioning prevents duplicate execution on repeated activations.
     *
     * @return bool True on success, false on failure
     */
    public static function activate(): bool
    {
        $current_version = self::get_current_version();

        // Skip migration if schema is already up-to-date
        if ($current_version >= self::SCHEMA_VERSION) {
            return true;
        }

        // Create or update table using dbDelta()
        $result = self::create_table();

        if (!$result) {
            return false;
        }

        // Store schema version to prevent duplicate migrations
        update_option(self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION);

        // Store activation timestamp (only on first activation)
        if (!get_option(self::ACTIVATION_TIMESTAMP_OPTION)) {
            update_option(self::ACTIVATION_TIMESTAMP_OPTION, time());
        }

        return true;
    }

    /**
     * Create wp_slim_channels table using dbDelta().
     *
     * Uses WordPress dbDelta() function for safe table creation/upgrade.
     * dbDelta() compares existing table structure and applies only necessary changes.
     *
     * @return bool True on success, false on failure
     */
    private static function create_table(): bool
    {
        global $wpdb;

        // Require WordPress upgrade functions
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Get schema definition from ChannelSchema
        $sql = ChannelSchema::get_schema();

        // Execute dbDelta() and capture result
        $result = dbDelta($sql);

        // Verify table was created successfully
        $table_name = $wpdb->prefix . 'slim_channels';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;

        if (!$table_exists) {
            // Log error for debugging
            error_log(
                sprintf(
                    '[SlimStat Channel Migration] Failed to create table %s. dbDelta result: %s',
                    $table_name,
                    print_r($result, true)
                )
            );

            return false;
        }

        return true;
    }

    /**
     * Get the activation timestamp.
     *
     * Returns the Unix timestamp when the channel feature was first activated.
     * Used to determine the cutoff date for historical data exclusion (FR-002).
     *
     * @return int|null Unix timestamp, or null if not activated yet
     */
    public static function get_activation_timestamp(): ?int
    {
        $timestamp = get_option(self::ACTIVATION_TIMESTAMP_OPTION);

        return $timestamp ? (int) $timestamp : null;
    }

    /**
     * Handle plugin deactivation cleanup.
     *
     * Note: This does NOT drop the table or delete options to preserve data.
     * Use uninstall.php for complete removal.
     *
     * @return void
     */
    public static function deactivate(): void
    {
        // Unschedule cron events (will be implemented in T017-T022)
        $timestamp = wp_next_scheduled('slimstat_classify_channels');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'slimstat_classify_channels');
        }
    }
}
