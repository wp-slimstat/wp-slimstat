<?php

namespace SlimStat\Filters;

/**
 * Channel filter integration with SlimStat's universal filter system.
 *
 * Adds "channel" as a filterable dimension across all SlimStat reports with
 * full operator support (equals, contains, is_empty, etc.) and filter persistence.
 *
 * @package SlimStat\Filters
 * @since 5.1.0
 */
class ChannelFilterRegistrar
{
    /**
     * Initialize filter registration.
     *
     * Hooks into SlimStat's filter system to add channel dimension.
     *
     * @return void
     */
    public static function init(): void
    {
        // Add channel to filterable columns
        add_filter('slimstat_db_columns', [self::class, 'add_channel_column'], 10, 1);

        // Add JOIN logic when channel filter is active
        add_filter('slimstat_db_pre_where', [self::class, 'add_channel_join'], 10, 2);
    }

    /**
     * Add 'channel' to SlimStat's filterable columns (T067).
     *
     * Registers channel dimension with universal filter system.
     *
     * @param array $columns Existing columns array
     * @return array Modified columns with channel added
     */
    public static function add_channel_column(array $columns): array
    {
        // Add channel column with type 'varchar' for string filtering
        $columns['channel'] = [
            'name' => __('Channel', 'wp-slimstat'),
            'type' => 'varchar',
            'description' => __('Traffic channel category (Direct, Organic Search, Paid Search, Social, Email, AI, Referral, Other)', 'wp-slimstat'),
        ];

        return $columns;
    }

    /**
     * Add SQL JOIN for wp_slim_channels when channel filter is active (T068).
     *
     * Modifies query to include channel data via LEFT JOIN.
     *
     * @param string $where_clause Current WHERE clause
     * @param array $filters Active filters
     * @return string Modified WHERE clause (JOIN is added via different hook)
     */
    public static function add_channel_join(string $where_clause, array $filters): string
    {
        // Check if channel filter is active
        $has_channel_filter = false;
        foreach ($filters as $filter) {
            if (isset($filter['column']) && 'channel' === $filter['column']) {
                $has_channel_filter = true;
                break;
            }
        }

        if (!$has_channel_filter) {
            return $where_clause;
        }

        // Add JOIN via global $wpdb
        global $wpdb;
        $table_stats = $wpdb->prefix . 'slim_stats';
        $table_channels = $wpdb->prefix . 'slim_channels';

        // SlimStat uses a specific pattern for JOINs - we need to hook earlier
        // For now, add a filter that SlimStat can use
        add_filter('slimstat_db_from', function ($from_clause) use ($table_stats, $table_channels) {
            // Add LEFT JOIN if not already present
            if (strpos($from_clause, $table_channels) === false) {
                $from_clause .= " LEFT JOIN {$table_channels} ON {$table_stats}.id = {$table_channels}.visit_id";
            }
            return $from_clause;
        });

        return $where_clause;
    }

    /**
     * Get available channel values for filter dropdown.
     *
     * Returns list of 8 channel categories for filter UI.
     *
     * @return array Channel names
     */
    public static function get_channel_values(): array
    {
        $taxonomy = require dirname(__DIR__) . '/Config/channel-taxonomy.php';

        return array_keys($taxonomy);
    }

    /**
     * Format channel filter for display.
     *
     * Converts internal channel value to display-friendly format.
     *
     * @param string $channel_value Channel internal value
     * @return string Formatted channel name
     */
    public static function format_channel_display(string $channel_value): string
    {
        $taxonomy = require dirname(__DIR__) . '/Config/channel-taxonomy.php';

        if (isset($taxonomy[$channel_value])) {
            return $taxonomy[$channel_value]['name'];
        }

        return $channel_value;
    }
}
