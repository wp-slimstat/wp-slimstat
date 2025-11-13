<?php

namespace SlimStat\Widgets;

/**
 * Top Traffic Channel widget.
 *
 * Displays the channel with the most visits for the selected time period
 * with visit count, percentage, and mini visualization.
 *
 * @package SlimStat\Widgets
 * @since 5.1.0
 */
class TopChannelWidget extends BaseChannelWidget
{
    /**
     * Constructor.
     */
    public function __construct()
    {
        parent::__construct(
            'slim_channel_top',
            __('Top Traffic Channel', 'wp-slimstat')
        );
    }

    /**
     * Fetch top channel data from database (T042).
     *
     * Queries wp_slim_channels for the channel with most visits.
     *
     * @param array $date_range Date range filters ['date_from' => int, 'date_to' => int]
     * @param array $args Additional widget arguments
     * @return array Top channel data with keys:
     *               - 'channel' (string): Channel name
     *               - 'count' (int): Visit count
     *               - 'percentage' (float): Percentage of total visits
     *               - 'total_visits' (int): Total visits in period
     *               - 'distribution' (array): All channels for comparison
     */
    protected function fetch_data(array $date_range, array $args): array
    {
        global $wpdb;

        $table_channels = $wpdb->prefix . 'slim_channels';

        // Query channel distribution
        $sql = $wpdb->prepare(
            "SELECT channel, COUNT(*) as count
             FROM {$table_channels}
             WHERE classified_at >= %d
               AND classified_at <= %d
             GROUP BY channel
             ORDER BY count DESC",
            $date_range['date_from'],
            $date_range['date_to']
        );

        $results = $wpdb->get_results($sql, ARRAY_A);

        if (empty($results)) {
            return [
                'channel' => null,
                'count' => 0,
                'percentage' => 0,
                'total_visits' => 0,
                'distribution' => [],
            ];
        }

        // Calculate totals
        $total_visits = array_sum(array_column($results, 'count'));
        $top_channel = $results[0];
        $top_percentage = $total_visits > 0
            ? round(($top_channel['count'] / $total_visits) * 100, 2)
            : 0;

        return [
            'channel' => $top_channel['channel'],
            'count' => (int) $top_channel['count'],
            'percentage' => $top_percentage,
            'total_visits' => $total_visits,
            'distribution' => $results,
        ];
    }

    /**
     * Render widget content HTML (T043).
     *
     * Displays channel name, visit count, percentage, and mini chart.
     *
     * @param array $data Widget data from fetch_data()
     * @param array $args Widget arguments
     * @return string Widget content HTML
     */
    protected function render_content(array $data, array $args): string
    {
        if (empty($data['channel'])) {
            return '<p class="slimstat-no-data">' . esc_html__('No channel data available for this time period.', 'wp-slimstat') . '</p>';
        }

        // Get channel taxonomy for styling
        $taxonomy = require dirname(__DIR__) . '/Config/channel-taxonomy.php';
        $channel_info = $taxonomy[$data['channel']] ?? [
            'name' => $data['channel'],
            'icon' => 'dashicons-admin-generic',
            'color' => '#7e8c8d',
        ];

        $output = '<div class="top-channel-widget">';

        // Channel name with icon
        $output .= sprintf(
            '<div class="channel-name" style="color: %s;">
                <span class="dashicons %s"></span>
                <h2>%s</h2>
            </div>',
            esc_attr($channel_info['color']),
            esc_attr($channel_info['icon']),
            esc_html($channel_info['name'])
        );

        // Visit count
        $output .= sprintf(
            '<div class="channel-stats">
                <div class="stat-item">
                    <span class="stat-label">%s</span>
                    <span class="stat-value">%s</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">%s</span>
                    <span class="stat-value">%s%%</span>
                </div>
            </div>',
            esc_html__('Visits', 'wp-slimstat'),
            esc_html(number_format_i18n($data['count'])),
            esc_html__('Share', 'wp-slimstat'),
            esc_html(number_format_i18n($data['percentage'], 2))
        );

        // Mini bar chart showing top 3 channels
        $top_3 = array_slice($data['distribution'], 0, 3);
        $output .= '<div class="mini-chart">';
        foreach ($top_3 as $channel) {
            $channel_name = $channel['channel'];
            $channel_count = (int) $channel['count'];
            $channel_percent = $data['total_visits'] > 0
                ? round(($channel_count / $data['total_visits']) * 100, 1)
                : 0;

            $channel_color = $taxonomy[$channel_name]['color'] ?? '#7e8c8d';

            $output .= sprintf(
                '<div class="chart-bar" title="%s: %s (%s%%)">
                    <div class="bar-label">%s</div>
                    <div class="bar-fill" style="width: %s%%; background-color: %s;"></div>
                    <div class="bar-value">%s%%</div>
                </div>',
                esc_attr($channel_name),
                esc_attr(number_format_i18n($channel_count)),
                esc_attr($channel_percent),
                esc_html($channel_name),
                esc_attr($channel_percent),
                esc_attr($channel_color),
                esc_html(number_format_i18n($channel_percent, 1))
            );
        }
        $output .= '</div>';

        $output .= '</div>';

        return $output;
    }
}
