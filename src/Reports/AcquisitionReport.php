<?php

namespace SlimStat\Reports;

use SlimStat\Tracker\Acquisition;
use SlimStat\Utils\NetworkMerge;
use SlimStat\Utils\Query;

/** Uses the existing report filters, query cache, network merge and pagination. */
class AcquisitionReport
{
    private static function fields(string $mode): array
    {
        return 'utm' === $mode ? [
            'utm_campaign' => __('Campaign', 'wp-slimstat'),
            'utm_source' => __('Source', 'wp-slimstat'),
            'utm_medium' => __('Medium', 'wp-slimstat'),
            'utm_content' => __('Content', 'wp-slimstat'),
            'utm_term' => __('Term', 'wp-slimstat'),
            'utm_id' => __('Campaign ID', 'wp-slimstat'),
        ] : [
            'traffic_channel' => __('Channel', 'wp-slimstat'),
            'traffic_source' => __('Source', 'wp-slimstat'),
        ];
    }

    private static function where(string $mode): string
    {
        if ('utm' !== $mode) {
            return '';
        }
        // Bots may carry campaign URLs but must not count as campaign engagement.
        return "COALESCE(traffic_channel, '') NOT IN ('ai_crawler', 'ai_fetcher', 'bot') AND ("
            . implode(' OR ', array_map(static function ($field) {
                return "({$field} IS NOT NULL AND {$field} <> '')";
            }, Acquisition::UTM_FIELDS)) . ')';
    }

    public static function rows(array $args = []): array
    {
        // CSV/email callbacks also run while setup is pending, without the rendered setup state.
        if ('1' !== get_option(Acquisition::readinessKey(), '0')) {
            return [];
        }
        $mode = ($args['mode'] ?? '') === 'utm' ? 'utm' : 'channels';
        $columns = implode(', ', array_keys(self::fields($mode)));
        $group = NetworkMerge::isMerging() ? NetworkMerge::groupKeyFor($columns) : $columns;
        $order = 'counthits DESC, ' . $group;
        $range = \wp_slimstat_db::$filters_normalized['utime'];
        $limit = max(1, (int) \wp_slimstat::$settings['limit_results']);
        // Never split a limited GROUP BY at midnight: partition top-Ns lose contributions.
        // Historical-only ranges use the existing SQL cache; ranges reaching today stay live.
        $today = (int) (floor(\wp_slimstat::now() / DAY_IN_SECONDS) * DAY_IN_SECONDS);
        $query = Query::select($columns . ', COUNT(*) AS counthits')
            ->from($GLOBALS['wpdb']->prefix . 'slim_stats')
            ->whereDate('dt', ['from' => (int) $range['start'], 'to' => (int) $range['end']], $range['end'] >= $today)
            ->whereRaw(\wp_slimstat_db::get_combined_where(self::where($mode), '', false))
            ->groupBy($group)->orderBy($order);
        if (!empty($args['where'])) {
            // Raw report consumers (including Pro author emails) supply an extra SQL scope.
            $query->whereRaw($args['where']);
        }
        if (!NetworkMerge::isMerging()) {
            $query->limit($limit);
        }
        return (array) $query->getAll(NetworkMerge::SUM, $columns, $group, $order, '', $limit);
    }

    public static function render(array $args = []): void
    {
        if ('on' === (\wp_slimstat::$settings['async_load'] ?? '') && !wp_doing_ajax() && empty($args['is_widget'])) {
            return;
        }
        if (!empty($args['is_widget'])) {
            wp_enqueue_style('wp-slimstat-tokens', plugins_url('admin/assets/css/tokens.css', SLIMSTAT_FILE), [], SLIMSTAT_ANALYTICS_VERSION);
            wp_enqueue_style('wp-slimstat-acquisition', plugins_url('admin/assets/css/acquisition.css', SLIMSTAT_FILE), ['wp-slimstat-tokens'], SLIMSTAT_ANALYTICS_VERSION);
            echo '<div class="wrap-slimstat">';
        }
        self::content($args);
        if (!empty($args['is_widget'])) {
            echo '</div>';
        }
        if (wp_doing_ajax()) {
            wp_die();
        }
    }

    private static function content(array $args): void
    {
        echo '<div class="slimstat-acquisition">';
        if (!Acquisition::checkSchema()) {
            echo '<p class="slimstat-acquisition__empty">' . esc_html__('UTM and channel reports need a database update. Existing tracking continues while setup is pending.', 'wp-slimstat') . '</p>';
            if (current_user_can('manage_options')) {
                echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=slimstat_migration')) . '">' . esc_html__('Set up reports', 'wp-slimstat') . '</a>';
            }
            echo '</div>';
            return;
        }
        $mode = ($args['mode'] ?? '') === 'utm' ? 'utm' : 'channels';
        $fields = self::fields($mode);
        $db = \wp_slimstat::$wpdb ?? $GLOBALS['wpdb'];
        $suppressed = $db->suppress_errors(true);
        try {
            $all = self::rows(['mode' => $mode]);
            $failed = '' !== (string) $db->last_error;
            $total = \wp_slimstat_db::count_records('id', self::where($mode));
            $failed = $failed || '' !== (string) $db->last_error;
        } finally {
            $db->suppress_errors($suppressed);
        }
        if ($failed) {
            echo '<p role="status">' . esc_html__('This report could not be loaded. Check the database connection and report setup, then refresh.', 'wp-slimstat') . '</p></div>';
            return;
        }
        $description = 'utm' === $mode
            ? __('Tagged pageviews, excluding detected bots. Select a value to filter your reports.', 'wp-slimstat')
            : __('Pageviews by channel and source. Select a value to filter your reports.', 'wp-slimstat');
        echo '<div class="slimstat-acquisition__intro"><span>' . esc_html($description) . '</span><strong>';
        echo esc_html(sprintf(
            /* translators: %s: localized pageview count. */
            _n('%s pageview', '%s pageviews', $total, 'wp-slimstat'), number_format_i18n($total)
        )) . '</strong></div>';

        if (!$all) {
            echo '<p class="slimstat-acquisition__empty">' . esc_html('utm' === $mode
                ? __('No tagged pageviews in this period. Add utm_source, utm_medium and utm_campaign to your incoming links, or choose another date range.', 'wp-slimstat')
                : __('No pageviews match this period and these filters. Try a wider date range or clear a filter.', 'wp-slimstat')) . '</p>';
        } else {
            $perPage = max(1, (int) \wp_slimstat::$settings['rows_to_show']);
            $start = max(0, (int) (\wp_slimstat_db::$filters_normalized['misc']['start_from'] ?? 0));
            if ($start >= count($all)) {
                $start = (int) (floor((count($all) - 1) / $perPage) * $perPage);
            }
            $rows = array_slice($all, $start, $perPage);
            $network = NetworkMerge::isMerging();
            echo '<div class="slimstat-acquisition__scroll" role="region" tabindex="0" aria-label="' . esc_attr('utm' === $mode ? __('UTM campaign results', 'wp-slimstat') : __('Channel results', 'wp-slimstat')) . '"><table><caption class="screen-reader-text">' . esc_html($description) . '</caption><thead><tr>';
            if ($network) {
                echo '<th scope="col">' . esc_html__('Site', 'wp-slimstat') . '</th>';
            }
            foreach ($fields as $label) {
                echo '<th scope="col">' . esc_html($label) . '</th>';
            }
            echo '<th scope="col" class="slimstat-acquisition__number">' . esc_html__('Pageviews', 'wp-slimstat') . '</th><th scope="col" class="slimstat-acquisition__number">' . esc_html__('Share', 'wp-slimstat') . '</th></tr></thead><tbody>';
            $labels = Acquisition::labels();
            foreach ($rows as $row) {
                echo '<tr>';
                if ($network) {
                    echo '<td>' . esc_html(get_blog_option((int) ($row['blog_id'] ?? 0), 'blogname')) . '</td>';
                }
                foreach ($fields as $field => $label) {
                    $value = $row[$field] ?? null;
                    $text = null === $value || '' === $value ? __('Not set', 'wp-slimstat') : $value;
                    if ('traffic_channel' === $field) {
                        $text = $labels[$value] ?? __('Not attributed', 'wp-slimstat');
                    }
                    echo '<td>';
                    if (is_admin() && null !== $value && '' !== $value) {
                        $url = \wp_slimstat_reports::fs_url($field . ' equals ' . rawurlencode($value) . '&&&start_from equals 0');
                        echo '<a class="slimstat-filter-link" href="' . esc_url($url) . '">' . esc_html($text) . '</a>';
                    } else {
                        echo '<span class="slimstat-acquisition__muted">' . esc_html($text) . '</span>';
                    }
                    echo '</td>';
                }
                $share = $total > 0 ? min(100, 100 * (int) $row['counthits'] / $total) : 0;
                echo '<td class="slimstat-acquisition__number">' . esc_html(number_format_i18n((int) $row['counthits'])) . '</td>';
                echo '<td class="slimstat-acquisition__number"><span class="slimstat-acquisition__share"><meter min="0" max="100" value="' . esc_attr((string) $share) . '" aria-hidden="true"></meter>' . esc_html(number_format_i18n($share, 1) . '%') . '</span></td></tr>';
            }
            echo '</tbody></table></div>';
            // Keep pagination inside this table's AJAX fragment. The legacy .pagination
            // class is extracted by admin.js for older reports with an external footer.
            echo '<nav class="slimstat-acquisition__pagination" aria-label="' . esc_attr__('Report pages', 'wp-slimstat') . '"><span>';
            echo esc_html(sprintf(
                /* translators: 1: first row, 2: last row, 3: displayed result count. */
                __('%1$s–%2$s of %3$s', 'wp-slimstat'), number_format_i18n($start + 1), number_format_i18n($start + count($rows)), number_format_i18n(count($all))
            )) . '</span>';
            if (is_admin() && $start > 0) {
                echo '<a class="button refresh" href="' . esc_url(\wp_slimstat_reports::fs_url('start_from equals ' . max(0, $start - $perPage))) . '">' . esc_html__('Previous', 'wp-slimstat') . '</a>';
            }
            if (is_admin() && $start + $perPage < count($all)) {
                echo '<a class="button refresh" href="' . esc_url(\wp_slimstat_reports::fs_url('start_from equals ' . ($start + $perPage))) . '">' . esc_html__('Next', 'wp-slimstat') . '</a>';
            }
            echo '</nav>';
            if (count($all) >= (int) \wp_slimstat::$settings['limit_results']) {
                echo '<p class="slimstat-acquisition__note">' . esc_html__('The report result limit was reached. Narrow your filters to see more detail. Shares use all matching pageviews, including rows beyond this limit.', 'wp-slimstat') . '</p>';
            }
        }
        echo '<details class="slimstat-acquisition__help"><summary>' . esc_html__('How to read this report', 'wp-slimstat') . '</summary>';
        echo '<p>' . esc_html('utm' === $mode
            ? __('Each row is one combination of campaign tags on a recorded page URL, not a session or conversion. Values keep their original case. Not set means that tag was absent. Use consistent names and tag incoming links, not internal links.', 'wp-slimstat')
            : __('These are pageview channels, not session attribution. Campaign tags take priority over referring sites. Internal navigation is separate. Direct / unknown means no usable source was sent; it can include untagged email, private messages or apps. Not attributed means the pageview predates report setup.', 'wp-slimstat')) . '</p>';
        if ('channels' === $mode) {
            echo '<p>' . esc_html__('AI Assistants identifies referrals or tagged links from known assistants. AI Crawlers and AI User-requested Fetches identify automated requests by their claimed user agent, not verified identity. They appear only when your tracking settings collect them. Google AI Overviews cannot reliably be separated from Organic Search. Unknown tagged media appear as Unassigned.', 'wp-slimstat') . '</p>';
        } else {
            echo '<p><code>?utm_source=newsletter&amp;utm_medium=email&amp;utm_campaign=autumn</code></p>';
        }
        echo '</details></div>';
    }
}
