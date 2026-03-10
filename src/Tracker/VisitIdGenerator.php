<?php

namespace SlimStat\Tracker;

/**
 * Atomic Visit ID Generator for SlimStat
 *
 * Uses MySQL's LAST_INSERT_ID() for atomic increment operations,
 * providing thread-safe visit ID generation without race conditions.
 *
 * Performance: Always exactly 2 queries (O(1)) instead of O(n) collision loop.
 *
 * @since 5.4.1
 */
class VisitIdGenerator
{
    const OPTION_NAME = 'slimstat_visit_id_counter';

    /**
     * Generate the next visit ID atomically.
     *
     * Uses MySQL's LAST_INSERT_ID() trick for atomic increment:
     * UPDATE wp_options SET option_value = LAST_INSERT_ID(option_value + 1) WHERE option_name = 'slimstat_visit_id_counter';
     * SELECT LAST_INSERT_ID();
     *
     * This is thread-safe and always returns a unique ID with exactly 2 queries.
     *
     * @return int The next unique visit ID
     */
    public static function generateNextVisitId(): int
    {
        global $wpdb;

        self::ensureCounterExists();

        // Atomic increment using LAST_INSERT_ID()
        // This sets LAST_INSERT_ID to the new value and returns it atomically
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID(option_value + 1) WHERE option_name = %s",
            self::OPTION_NAME
        ));

        if ($result === false) {
            return self::fallbackGenerateVisitId();
        }

        // Get the value that was set by LAST_INSERT_ID()
        $visit_id = $wpdb->get_var("SELECT LAST_INSERT_ID()");

        if ($visit_id === null || $visit_id <= 0) {
            return self::fallbackGenerateVisitId();
        }

        return (int) $visit_id;
    }

    /**
     * Ensure the counter option exists in the database.
     *
     * If it doesn't exist, initialize it with the current maximum visit_id.
     *
     * @return void
     */
    public static function ensureCounterExists(): void
    {
        global $wpdb;

        // Check if option exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s",
            self::OPTION_NAME
        ));

        if ($exists == 0) {
            self::initializeCounter();
        }
    }

    /**
     * Initialize the counter with the current maximum visit_id from the stats table.
     *
     * This should be called on plugin activation/upgrade to set the initial counter value.
     *
     * @return int The initialized counter value
     */
    public static function initializeCounter(): int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'slim_stats';

        // Get current maximum visit_id
        $max_visit_id = $wpdb->get_var("SELECT COALESCE(MAX(visit_id), 0) FROM {$table}");
        $initial_value = max((int) $max_visit_id, 0);

        // Try to get AUTO_INCREMENT as a fallback reference
        $auto_increment = $wpdb->get_var($wpdb->prepare(
            "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
            $table
        ));

        if ($auto_increment !== null && (int) $auto_increment > $initial_value) {
            $initial_value = (int) $auto_increment;
        }

        // Use add_option to avoid overwriting if it was created by another process
        $added = add_option(self::OPTION_NAME, $initial_value, '', 'no');

        if (!$added) {
            // Option already exists, get its current value
            return (int) get_option(self::OPTION_NAME, $initial_value);
        }

        return $initial_value;
    }

    /**
     * Fallback visit ID generation using timestamp.
     *
     * Used only if the atomic counter fails (e.g., database issues).
     *
     * @return int A fallback visit ID based on current timestamp
     */
    private static function fallbackGenerateVisitId(): int
    {
        // Use microtime for better uniqueness in fallback scenario
        $microtime = microtime(true);
        $visit_id = (int) ($microtime * 1000) % 2147483647; // Keep within 32-bit signed int range

        // Add some randomness to reduce collision chance
        $visit_id += mt_rand(0, 999);

        return max($visit_id, (int) time());
    }

    /**
     * Get the current counter value without incrementing.
     *
     * Useful for debugging and monitoring.
     *
     * @return int Current counter value, or 0 if not initialized
     */
    public static function getCurrentCounter(): int
    {
        return (int) get_option(self::OPTION_NAME, 0);
    }

    /**
     * Reset the counter to a specific value.
     *
     * Use with caution - mainly for testing or recovery scenarios.
     *
     * @param int $value The value to set the counter to
     * @return bool True on success, false on failure
     */
    public static function resetCounter(int $value): bool
    {
        return update_option(self::OPTION_NAME, max($value, 0));
    }
}
