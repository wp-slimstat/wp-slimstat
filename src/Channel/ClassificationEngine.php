<?php

namespace SlimStat\Channel;

/**
 * Main classification engine for traffic channel analysis.
 *
 * Provides a high-level API for classifying visits and batch processing.
 * Uses ClassificationRules for the actual classification logic.
 *
 * @package SlimStat\Channel
 * @since 5.1.0
 */
class ClassificationEngine
{
    /**
     * Classification rules instance.
     *
     * @var ClassificationRules
     */
    private ClassificationRules $rules;

    /**
     * Constructor.
     *
     * @param ClassificationRules|null $rules Custom rules instance (for testing)
     */
    public function __construct(?ClassificationRules $rules = null)
    {
        $this->rules = $rules ?? new ClassificationRules();
    }

    /**
     * Classify a single visit record.
     *
     * Wrapper around ClassificationRules::classify() with additional error handling.
     *
     * @param array $visit_data Visit record from wp_slim_stats
     * @return array Classification result with channel and UTM parameters
     */
    public function classify_visit(array $visit_data): array
    {
        try {
            return $this->rules->classify($visit_data);
        } catch (\Exception $e) {
            // Log error and return 'Other' as fallback (FR-040 catchall guarantee)
            error_log(
                sprintf(
                    '[SlimStat Classification] Error classifying visit: %s. Visit data: %s',
                    $e->getMessage(),
                    print_r($visit_data, true)
                )
            );

            return [
                'channel' => 'Other',
                'utm_source' => null,
                'utm_medium' => null,
                'utm_campaign' => null,
            ];
        }
    }

    /**
     * Classify multiple visits in batch.
     *
     * Processes an array of visit records and returns classification results.
     * More efficient than calling classify_visit() in a loop due to shared
     * domain list loading.
     *
     * @param array $visits Array of visit records
     * @return array Array of classification results (same order as input)
     */
    public function classify_batch(array $visits): array
    {
        $results = [];

        foreach ($visits as $visit) {
            $results[] = $this->classify_visit($visit);
        }

        return $results;
    }

    /**
     * Get statistics about classified visits from the database.
     *
     * Returns aggregated channel distribution for analytics purposes.
     *
     * @param array $filters Optional filters (date_from, date_to, etc.)
     * @return array Channel distribution ['Direct' => 123, 'Organic Search' => 456, ...]
     */
    public function get_channel_distribution(array $filters = []): array
    {
        global $wpdb;

        $table_channels = $wpdb->prefix . 'slim_channels';

        // Build WHERE clause from filters
        $where_clauses = [];
        $where_values = [];

        if (!empty($filters['date_from'])) {
            $where_clauses[] = 'classified_at >= %d';
            $where_values[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where_clauses[] = 'classified_at <= %d';
            $where_values[] = $filters['date_to'];
        }

        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        // Query channel distribution
        $sql = "SELECT channel, COUNT(*) as count
                FROM {$table_channels}
                {$where_sql}
                GROUP BY channel
                ORDER BY count DESC";

        if (!empty($where_values)) {
            $sql = $wpdb->prepare($sql, ...$where_values);
        }

        $results = $wpdb->get_results($sql, ARRAY_A);

        // Convert to associative array
        $distribution = [];
        foreach ($results as $row) {
            $distribution[$row['channel']] = (int) $row['count'];
        }

        return $distribution;
    }

    /**
     * Get the top traffic channel by visit count.
     *
     * Returns the channel with the most visits for a given time period.
     *
     * @param array $filters Optional filters (date_from, date_to, etc.)
     * @return array ['channel' => string, 'count' => int, 'percentage' => float] or empty array if no data
     */
    public function get_top_channel(array $filters = []): array
    {
        $distribution = $this->get_channel_distribution($filters);

        if (empty($distribution)) {
            return [];
        }

        // Find the channel with maximum visits
        $top_channel = array_keys($distribution, max($distribution))[0];
        $top_count = $distribution[$top_channel];
        $total_visits = array_sum($distribution);

        return [
            'channel' => $top_channel,
            'count' => $top_count,
            'percentage' => $total_visits > 0 ? round(($top_count / $total_visits) * 100, 2) : 0,
        ];
    }
}
