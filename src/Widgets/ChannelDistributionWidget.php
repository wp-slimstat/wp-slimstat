<?php

namespace SlimStat\Widgets;

/**
 * Channel Distribution widget.
 *
 * Displays a breakdown of all 8 channel categories with visit counts,
 * percentages, and visual chart representation.
 *
 * @package SlimStat\Widgets
 * @since 5.1.0
 */
class ChannelDistributionWidget extends BaseChannelWidget
{
    /**
     * Constructor.
     */
    public function __construct()
    {
        parent::__construct(
            'slim_channel_distribution',
            __('Channel Distribution', 'wp-slimstat')
        );
    }

    /**
     * Fetch channel distribution data (T045).
     *
     * Queries all 8 channels with visit counts and percentages.
     *
     * @param array $date_range Date range filters
     * @param array $args Additional widget arguments
     * @return array Channel distribution with keys:
     *               - 'channels' (array): Each channel with count and percentage
     *               - 'total_visits' (int): Total visits across all channels
     */
    protected function fetch_data(array $date_range, array $args): array
    {
        global $wpdb;

        $table_channels = $wpdb->prefix . 'slim_channels';

        // Query all channels
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

        // Load channel taxonomy for complete list
        $taxonomy = require dirname(__DIR__) . '/Config/channel-taxonomy.php';
        $total_visits = array_sum(array_column($results, 'count'));

        // Build complete channel list (include 0-count channels)
        $channels = [];
        foreach ($taxonomy as $channel_name => $channel_info) {
            $channel_data = array_filter($results, function ($r) use ($channel_name) {
                return $r['channel'] === $channel_name;
            });

            $count = !empty($channel_data) ? (int) array_values($channel_data)[0]['count'] : 0;
            $percentage = $total_visits > 0 ? round(($count / $total_visits) * 100, 2) : 0;

            $channels[] = [
                'name' => $channel_name,
                'count' => $count,
                'percentage' => $percentage,
                'info' => $channel_info,
            ];
        }

        // Sort by count descending
        usort($channels, function ($a, $b) {
            return $b['count'] - $a['count'];
        });

        return [
            'channels' => $channels,
            'total_visits' => $total_visits,
        ];
    }

    /**
     * Render widget content HTML (T046, T047).
     *
     * Displays 8-category table/chart with SlimStat styling and visualization.
     *
     * @param array $data Widget data from fetch_data()
     * @param array $args Widget arguments
     * @return string Widget content HTML
     */
    protected function render_content(array $data, array $args): string
    {
        if ($data['total_visits'] === 0) {
            return '<p class="slimstat-no-data">' . esc_html__('No channel data available for this time period.', 'wp-slimstat') . '</p>';
        }

        $output = '<div class="channel-distribution-widget">';

        // Table view
        $output .= '<table class="channel-distribution-table">';
        $output .= '<thead>';
        $output .= '<tr>';
        $output .= '<th>' . esc_html__('Channel', 'wp-slimstat') . '</th>';
        $output .= '<th class="text-right">' . esc_html__('Visits', 'wp-slimstat') . '</th>';
        $output .= '<th class="text-right">' . esc_html__('Share', 'wp-slimstat') . '</th>';
        $output .= '<th>' . esc_html__('Distribution', 'wp-slimstat') . '</th>';
        $output .= '</tr>';
        $output .= '</thead>';
        $output .= '<tbody>';

        foreach ($data['channels'] as $channel) {
            $output .= '<tr>';

            // Channel name with icon
            $output .= sprintf(
                '<td class="channel-name">
                    <span class="dashicons %s" style="color: %s;"></span>
                    <span>%s</span>
                </td>',
                esc_attr($channel['info']['icon']),
                esc_attr($channel['info']['color']),
                esc_html($channel['info']['name'])
            );

            // Visit count
            $output .= sprintf(
                '<td class="text-right">%s</td>',
                esc_html(number_format_i18n($channel['count']))
            );

            // Percentage
            $output .= sprintf(
                '<td class="text-right">%s%%</td>',
                esc_html(number_format_i18n($channel['percentage'], 2))
            );

            // Visual bar
            $output .= sprintf(
                '<td class="channel-bar">
                    <div class="bar-container">
                        <div class="bar-fill" style="width: %s%%; background-color: %s;"></div>
                    </div>
                </td>',
                esc_attr($channel['percentage']),
                esc_attr($channel['info']['color'])
            );

            $output .= '</tr>';
        }

        $output .= '</tbody>';
        $output .= '</table>';

        // Donut chart visualization (simple CSS-based)
        $output .= '<div class="channel-donut-chart">';
        $output .= '<svg viewBox="0 0 100 100" width="200" height="200">';

        $cumulative_percent = 0;
        foreach ($data['channels'] as $channel) {
            if ($channel['percentage'] > 0) {
                $start_angle = $cumulative_percent * 3.6; // Convert percentage to degrees
                $end_angle = ($cumulative_percent + $channel['percentage']) * 3.6;

                // Simple arc path (for donut chart segments)
                // Using circle elements for simplicity
                $output .= sprintf(
                    '<circle cx="50" cy="50" r="30" fill="none" stroke="%s" stroke-width="20"
                            stroke-dasharray="%s 188.5" stroke-dashoffset="-%s"
                            transform="rotate(-90 50 50)" />',
                    esc_attr($channel['info']['color']),
                    esc_attr($channel['percentage'] * 1.885), // Circumference = 2πr = 188.5 for r=30
                    esc_attr($cumulative_percent * 1.885)
                );

                $cumulative_percent += $channel['percentage'];
            }
        }

        $output .= '</svg>';
        $output .= '<div class="chart-center-label">';
        $output .= '<span class="total-visits">' . esc_html(number_format_i18n($data['total_visits'])) . '</span>';
        $output .= '<span class="label">' . esc_html__('Total Visits', 'wp-slimstat') . '</span>';
        $output .= '</div>';
        $output .= '</div>';

        $output .= '</div>';

        return $output;
    }
}
