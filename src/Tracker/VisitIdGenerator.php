<?php
/**
 * Atomic Visit ID Generator for SlimStat
 *
 * @package SlimStat
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SlimStat\Tracker;

/**
 * Atomic Visit ID Generator for SlimStat
 *
 * Uses MySQL's LAST_INSERT_ID() support to atomically increment the counter and expose
 * the new value through the same query result, avoiding metadata lookups and collision loops.
 *
 * @since 5.4.1
 */
class VisitIdGenerator
{
    public const OPTION_NAME = 'slimstat_visit_id_counter';

    /**
     * Generate the next visit ID atomically.
     *
     * The counter is seeded before use and incremented with one atomic UPDATE.
     * Native WordPress mysqli connections expose LAST_INSERT_ID without a query.
     *
     * @return int The next unique visit ID
     */
    public static function generateNextVisitId(): int
    {
        $visit_id = self::runAtomicIncrement();

        if ($visit_id <= 0) {
            // A missing counter is seeded before any ID can be issued. INSERT IGNORE
            // preserves a concurrent initializer's counter rather than resetting it.
            self::initializeCounter();
            $visit_id = self::runAtomicIncrement();
        }

        if ($visit_id <= 0) {
            return self::fallbackGenerateVisitId('Unable to atomically increment the visit ID counter.');
        }

        return $visit_id;
    }

    /**
     * Initialize the counter with the current maximum visit_id from the stats table.
     *
     * Called on plugin activation, and as the repair path when an increment fails.
     * INSERT IGNORE leaves an existing row alone, including racing initializers.
     *
     * @return int The initialized counter value
     */
    public static function initializeCounter(): int
    {
        $initial_value = self::getInitialCounterValue();

        global $wpdb;
        $added = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %d, %s)",
            self::OPTION_NAME,
            $initial_value,
            'no'
        ));
        wp_cache_delete(self::OPTION_NAME, 'options');
        wp_cache_delete('notoptions', 'options');

        return 1 === $added ? $initial_value : (int) get_option(self::OPTION_NAME, $initial_value);
    }

    /**
     * Fallback visit ID generation using timestamp and additional entropy.
     *
     * Used only if the atomic counter fails (e.g., database issues).
     *
     * @param string $reason Optional reason for logging why the fallback was used.
     * @return int A fallback visit ID based on current timestamp
     */
    private static function fallbackGenerateVisitId(string $reason = ''): int
    {
        self::logFallbackUsage($reason);

        try {
            $random_entropy = random_int(0, 99999);
        } catch (\Exception $exception) {
            $random_entropy = mt_rand(0, 99999);
        }

        $process_entropy = function_exists('getmypid') ? (int) getmypid() : 0;

        try {
            $nonce_bytes = random_bytes(4);
            $nonce_data  = unpack('Nnonce', $nonce_bytes);
            $nonce_entropy = isset($nonce_data['nonce']) ? (int) $nonce_data['nonce'] : mt_rand(0, 0xFFFF);
        } catch (\Exception $exception) {
            $nonce_entropy = mt_rand(0, 0xFFFF);
        }

        $entropy = sprintf(
            '%.6F|%d|%d|%d',
            microtime(true),
            $random_entropy,
            $process_entropy,
            $nonce_entropy
        );

        $visit_id = abs((int) hexdec(substr(hash('sha256', $entropy), 0, 8)));

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
        return update_option(self::OPTION_NAME, max($value, 0), false);
    }

    /** Increment an existing counter; a missing row cannot issue an unseeded ID. */
    private static function runAtomicIncrement(): int
    {
        global $wpdb;

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID(option_value + 1) WHERE option_name = %s",
            self::OPTION_NAME
        ));
        if (false === $result || 0 === (int) $result) {
            return 0;
        }

        // wpdb only updates its public insert_id for INSERT/REPLACE. The mysqli
        // connection exposes UPDATE's LAST_INSERT_ID without another query.
        $dbh = $wpdb->dbh ?? null;
        if ($dbh instanceof \mysqli) {
            return (int) mysqli_insert_id($dbh);
        }

        // Non-mysqli database drop-ins retain correctness with one extra read.
        return (int) $wpdb->get_var('SELECT LAST_INSERT_ID()');
    }

    /**
     * Calculate the counter base value from the existing stats table.
     *
     * The stored counter represents the most recently issued visit_id.
     *
     * @return int The current counter value
     */
    private static function getInitialCounterValue(): int
    {
        // Use wp_slimstat::$wpdb for slim_stats (may be on external DB).
        // Lines 73/182 stay on global $wpdb — they query wp_options (always main WP DB).
        $stats_db = \wp_slimstat::$wpdb ?? $GLOBALS['wpdb'];

        $table = $stats_db->prefix . 'slim_stats';

        $max_visit_id = $stats_db->get_var("SELECT COALESCE(MAX(visit_id), 0) FROM `{$table}`");
        $initial_value = max((int) $max_visit_id, 0);

        $auto_increment = $stats_db->get_var($stats_db->prepare(
            "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
            $table
        ));

        if (null !== $auto_increment) {
            $next_available_id = max(((int) $auto_increment) - 1, 0);
            if ($next_available_id > $initial_value) {
                $initial_value = $next_available_id;
            }
        }

        return $initial_value;
    }

    /**
     * Emit a log entry when the fallback generator is used.
     *
     * @param string $reason Optional reason for the fallback.
     * @return void
     */
    private static function logFallbackUsage(string $reason): void
    {
        $message = 'Visit ID generator fallback path used.';

        if ('' !== $reason) {
            $message .= ' ' . $reason;
        }

        if (class_exists('\wp_slimstat')) {
            \wp_slimstat::log($message, 'error');
            return;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WP SLIMSTAT] [ERROR]: ' . $message);
        }
    }
}
