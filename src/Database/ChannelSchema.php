<?php

namespace SlimStat\Database;

/**
 * Database schema definition for wp_slim_channels table.
 *
 * This class defines the normalized mapping table that links visit records
 * (wp_slim_stats) to their classified traffic channels with UTM attribution data.
 *
 * @package SlimStat\Database
 * @since 5.1.0
 */
class ChannelSchema
{
    /**
     * Get the SQL schema definition for wp_slim_channels table.
     *
     * Uses utf8mb4 character set, InnoDB engine, and indexed foreign keys
     * as per WP-Slimstat Project Constitution Principle VIII.
     *
     * @return string SQL CREATE TABLE statement compatible with dbDelta()
     */
    public static function get_schema(): string
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'slim_channels';
        $charset_collate = $wpdb->get_charset_collate();

        // Generate SQL compatible with dbDelta() requirements:
        // - Two spaces after PRIMARY KEY
        // - Must use KEY (not INDEX)
        // - Each field definition on its own line
        // - No trailing comma after last field
        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            visit_id INT UNSIGNED NOT NULL COMMENT 'Foreign key to wp_slim_stats.id',
            channel VARCHAR(50) NOT NULL COMMENT 'Channel category: Direct|Organic Search|Paid Search|Social|Email|AI|Referral|Other',
            utm_source VARCHAR(255) DEFAULT NULL COMMENT 'UTM source parameter (e.g., google, newsletter)',
            utm_medium VARCHAR(255) DEFAULT NULL COMMENT 'UTM medium parameter (e.g., cpc, email)',
            utm_campaign VARCHAR(255) DEFAULT NULL COMMENT 'UTM campaign parameter (e.g., spring_sale)',
            classified_at INT(10) UNSIGNED NOT NULL COMMENT 'Unix timestamp when classification occurred',
            classification_version TINYINT UNSIGNED DEFAULT 1 COMMENT 'Rule version for future re-classification tracking',
            PRIMARY KEY  (id),
            UNIQUE KEY idx_visit_id (visit_id),
            KEY idx_channel (channel),
            KEY idx_classified_at (classified_at),
            KEY idx_utm_source (utm_source(50)),
            KEY idx_utm_medium (utm_medium(50))
        ) {$charset_collate} ENGINE=InnoDB COMMENT='Traffic channel classification mapping';";

        return $sql;
    }

    /**
     * Get the list of channel categories supported by the classification engine.
     *
     * @return array List of valid channel names
     */
    public static function get_channel_categories(): array
    {
        return [
            'Direct',
            'Organic Search',
            'Paid Search',
            'Social',
            'Email',
            'AI',
            'Referral',
            'Other',
        ];
    }

    /**
     * Validate if a channel name is valid according to the taxonomy.
     *
     * @param string $channel Channel name to validate
     * @return bool True if valid, false otherwise
     */
    public static function is_valid_channel(string $channel): bool
    {
        return in_array($channel, self::get_channel_categories(), true);
    }
}
