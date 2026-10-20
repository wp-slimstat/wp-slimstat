<?php

namespace SlimStat\Reports;

use SlimStat\Tracker\Acquisition;
use SlimStat\Utils\NetworkMerge;
use SlimStat\Utils\Query;

/** Uses the existing report filters, query cache, network merge and pagination. */
class AcquisitionReport
{
    /** Readable summary groups in Free; all breakdowns stay protected. */
    private const FREE_GROUPS = 2;

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
        if (\wp_slimstat::pro_is_installed()) { return self::queryRows($args); }
        // Every raw consumer, including shortcodes and exports, shares the Free boundary.
        $args['summary'] = true;
        return array_map(static function ($row) { unset($row['values']); return $row; }, array_slice(
            self::mergeSummaries(self::queryRows($args), ($args['mode'] ?? '') === 'utm' ? 'utm_campaign' : 'traffic_channel'), 0, self::FREE_GROUPS
        ));
    }

    private static function summaryKey(array $row, string $groupField): string
    {
        $value = $row[$groupField] ?? null;
        return serialize([(int) ($row['blog_id'] ?? 0), 'traffic_channel' === $groupField && !isset(Acquisition::labels()[$value ?? '']) ? 'unassigned' : (string) $value]);
    }

    private static function mergeSummaries(array $all, string $groupField): array
    {
        // Values that read the same are one group: no channel yet and the unassigned channel, or a NULL and an empty tag.
        $merged = [];
        foreach ($all as $row) {
            $key = self::summaryKey($row, $groupField);
            if (isset($merged[$key])) {
                $merged[$key]['counthits'] = (int) $merged[$key]['counthits'] + (int) $row['counthits'];
                $merged[$key]['values'][] = $row[$groupField] ?? null;
            } else {
                $merged[$key] = $row + ['values' => [$row[$groupField] ?? null]];
            }
        }
        $all = array_values($merged);
        array_multisort(array_map('intval', array_column($all, 'counthits')), SORT_DESC, array_keys($all), $all);
        return $all;
    }

    private static function queryRows(array $args = []): array
    {
        // CSV/email callbacks also run while setup is pending, without the rendered setup state.
        if ('1' !== get_option(Acquisition::readinessKey(), '0')) {
            return [];
        }
        $mode = ($args['mode'] ?? '') === 'utm' ? 'utm' : 'channels';
        $fields = self::fields($mode);
        $columns = implode(', ', array_keys(!empty($args['summary']) ? array_slice($fields, 0, 1, true) : $fields));
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

    private static function value(string $field, $value, ?string $action = null): void
    {
        // Stored tags are literal text: encode existing entities too, unlike esc_html().
        $text = null === $value || '' === $value ? __('Unassigned', 'wp-slimstat') : $value;
        if ('traffic_channel' === $field) {
            $text = Acquisition::labels()[$value ?? ''] ?? __('Unassigned', 'wp-slimstat');
        }
        if (is_admin() && null !== $value && '' !== $value) {
            $url = \wp_slimstat_reports::fs_url($field . ' equals ' . rawurlencode($value) . '&&&start_from equals 0');
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- htmlspecialchars encodes quotes and existing entities to preserve literal stored tags.
            echo '<a class="slimstat-filter-link" href="' . esc_url($url) . '">' . htmlspecialchars($action ?? $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
        } else {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- htmlspecialchars encodes quotes and existing entities to preserve literal stored tags.
            echo '<span class="slimstat-acquisition__muted">' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
        }
    }

    private static function share(int $count, int $total): void
    {
        $share = $total > 0 ? min(100, 100 * $count / $total) : 0;
        echo '<span class="slimstat-acquisition__share"><meter min="0" max="100" value="' . esc_attr((string) $share) . '" aria-hidden="true"></meter><span>' . esc_html(number_format_i18n($share, 1) . '%') . '</span></span>';
    }

    private static function breakdown(array $rows, array $fields, int $total): void
    {
        // The parent summary already names the campaign/channel; do not repeat it.
        $columns = array_slice($fields, 1, 2, true);
        echo '<div class="slimstat-acquisition__scroll" role="region" tabindex="0" aria-label="' . esc_attr__('Source breakdown', 'wp-slimstat') . '"><table><thead><tr>';
        foreach ($columns as $label) {
            echo '<th scope="col">' . esc_html($label) . '</th>';
        }
        echo '<th scope="col" class="slimstat-acquisition__number">' . esc_html__('Pageviews', 'wp-slimstat') . '</th><th scope="col" class="slimstat-acquisition__number">' . esc_html__('Share', 'wp-slimstat') . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($columns as $field => $label) {
                echo '<td>';
                self::value($field, $row[$field] ?? null);
                if ('utm_source' === $field) {
                    $tags = array_filter(array_slice($fields, 3, null, true), static function ($key) use ($row) {
                        return isset($row[$key]) && '' !== $row[$key];
                    }, ARRAY_FILTER_USE_KEY);
                    if ($tags) {
                        echo '<details class="slimstat-acquisition__tags"><summary>' . esc_html__('More tags', 'wp-slimstat') . '</summary><dl>';
                        foreach ($tags as $key => $tagLabel) {
                            echo '<dt>' . esc_html($tagLabel) . '</dt><dd>';
                            self::value($key, $row[$key]);
                            echo '</dd>';
                        }
                        echo '</dl></details>';
                    }
                }
                echo '</td>';
            }
            echo '<td class="slimstat-acquisition__number">' . esc_html(number_format_i18n((int) $row['counthits'])) . '</td><td class="slimstat-acquisition__number">';
            self::share((int) $row['counthits'], $total);
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /** Illustrative rows never include protected labels or values. */
    private static function locked(string $mode, bool $more): void
    {
        echo '<div class="slimstat-acquisition__locked">';
        if ($more) {
            echo '<div class="slimstat-locked-rows" aria-hidden="true"><span></span><span></span></div>';
            echo '<p class="slimstat-preview-caption">' . esc_html__('Illustrative preview of additional results.', 'wp-slimstat') . '</p>';
        }
        \wp_slimstat_admin::get_template('pro-adds', [
            'text' => __('Unlock all results and source breakdowns with Pro.', 'wp-slimstat'),
            'action' => __('Upgrade to see more', 'wp-slimstat'),
            'campaign' => 'traffic-sources', 'content' => 'utm' === $mode ? 'campaigns' : 'channels',
        ]);
        echo '</div>';
    }

    public static function render(array $args = []): void
    {
        if ('on' === (\wp_slimstat::$settings['async_load'] ?? '') && !wp_doing_ajax() && empty($args['is_widget'])) {
            return;
        }
        if (!empty($args['is_widget'])) {
            wp_enqueue_style('wp-slimstat-tokens', plugins_url('admin/assets/css/tokens.css', SLIMSTAT_FILE), [], SLIMSTAT_ANALYTICS_VERSION);
            wp_enqueue_style('wp-slimstat-acquisition', plugins_url('admin/assets/css/acquisition.css', SLIMSTAT_FILE), ['wp-slimstat-tokens'], SLIMSTAT_ANALYTICS_VERSION);
            wp_enqueue_script('slimstat-report-dialogs', plugins_url('admin/assets/js/report-dialogs.js', SLIMSTAT_FILE), [], SLIMSTAT_ANALYTICS_VERSION, true);
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
        $ready = is_admin() ? Acquisition::checkSchema() : '1' === get_option(Acquisition::readinessKey(), '0');
        if (!$ready) {
            echo '<p class="slimstat-acquisition__empty">' . esc_html__('UTM and channel reports need a database update. Existing tracking continues while setup is pending.', 'wp-slimstat') . '</p>';
            if (current_user_can('manage_options')) {
                echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=slimstat_migration')) . '">' . esc_html__('Set up reports', 'wp-slimstat') . '</a>';
            }
            echo '</div>';
            return;
        }
        $mode = ($args['mode'] ?? '') === 'utm' ? 'utm' : 'channels';
        $fields = self::fields($mode);
        $groupField = array_key_first($fields);
        // Navigation needs the complete scope; fs_url() contains only a filter delta.
        $commerceFilters = null;
        if (is_admin() && \wp_slimstat::pro_is_installed() && !NetworkMerge::isMerging() && \SlimStat\Ecommerce\Integration::available() && \SlimStat\Ecommerce\Report::canView()) {
            $commerceFilters = [];
            foreach (\wp_slimstat_db::$filters_normalized['columns'] ?? [] as $key => $filter) {
                $commerceFilters[$key] = $filter[0] . ' ' . $filter[1];
            }
            foreach (\wp_slimstat_db::$filters_normalized['date'] ?? [] as $key => $value) {
                $commerceFilters[$key] = 'equals ' . $value;
            }
        }
        $perPage = max(1, (int) \wp_slimstat::$settings['rows_to_show']);
        $start = max(0, (int) (\wp_slimstat_db::$filters_normalized['misc']['start_from'] ?? 0));
        $db = \wp_slimstat::$wpdb ?? $GLOBALS['wpdb'];
        $suppressed = $db->suppress_errors(true);
        try {
            $all = self::queryRows(['mode' => $mode, 'summary' => true]);
            $failed = '' !== (string) $db->last_error;
            $capped = count($all) >= (int) \wp_slimstat::$settings['limit_results'];
            $all = self::mergeSummaries($all, $groupField);
            $locked = !\wp_slimstat::pro_is_installed() && count($all) > self::FREE_GROUPS;
            if ($locked) {
                $start = 0;
            } elseif ($all && $start >= count($all)) {
                $start = (int) (floor((count($all) - 1) / $perPage) * $perPage);
            }
            $rows = array_slice($all, $start, $locked ? self::FREE_GROUPS : $perPage);
            // One bounded breakdown query for the visible groups, never one query per row.
            $scope = [];
            foreach (array_merge([], ...array_column($rows, 'values')) as $value) {
                $scope[] = null === $value ? $groupField . ' IS NULL' : $db->prepare($groupField . ' = %s', $value);
            }
            $details = $rows && \wp_slimstat::pro_is_installed() ? self::queryRows(['mode' => $mode, 'where' => '(' . implode(' OR ', array_unique($scope)) . ')']) : [];
            $failed = $failed || '' !== (string) $db->last_error;
            $total = \wp_slimstat_db::count_records('id', self::where($mode));
            $failed = $failed || '' !== (string) $db->last_error;
        } finally {
            $db->suppress_errors($suppressed);
        }
        if ($failed) {
            echo '<p role="status">' . esc_html__('This report could not be loaded. Check the database connection and report setup, then refresh.', 'wp-slimstat') . '</p></div>';
            return;
        }
        $helpId = wp_unique_id('ss-acquisition-help-' . $mode . '-');
        $helpLabel = 'utm' === $mode ? __('What are campaigns? How do they work?', 'wp-slimstat') : __('What are channels?', 'wp-slimstat');
        $groupCount = count($all);
        $countLabel = 'utm' === $mode
            ? sprintf(/* translators: %s: campaign count. */ _n('%s campaign', '%s campaigns', $groupCount, 'wp-slimstat'), number_format_i18n($groupCount))
            : sprintf(/* translators: %s: channel count. */ _n('%s channel', '%s channels', $groupCount, 'wp-slimstat'), number_format_i18n($groupCount));
        if ($capped) { $countLabel = sprintf(/* translators: %s: counted groups, such as 20 campaigns. */ __('At least %s', 'wp-slimstat'), $countLabel); }
        echo '<div class="slimstat-acquisition__intro"><button type="button" class="button-link" data-report-dialog="' . esc_attr($helpId) . '">' . esc_html($helpLabel) . '</button><strong>';
        echo esc_html($countLabel . ' - ' . sprintf(/* translators: %s: pageview count. */ _n('%s pageview', '%s pageviews', $total, 'wp-slimstat'), number_format_i18n($total))) . '</strong></div>';
        if (null !== $commerceFilters) {
            $url = add_query_arg(['page' => 'slimview7', 'fs' => urlencode_deep($commerceFilters)], admin_url('admin.php')) . '#ss-ec-panel-' . ('utm' === $mode ? 'campaign' : 'channel');
            echo '<a class="noslimstat" href="' . esc_url($url) . '">' . esc_html__('View sales in Ecommerce', 'wp-slimstat') . '</a>';
        }

        if (!$all) {
            if ('utm' === $mode && is_admin()) {
                echo '<p><strong>' . esc_html__('Need a campaign link?', 'wp-slimstat') . '</strong></p><a class="button button-primary slimstat-utm-builder-link" href="' . esc_url(admin_url('admin.php?page=slimview5#slimstat-utm-builder')) . '">' . esc_html__('Build your first campaign link', 'wp-slimstat') . '</a>';
            }
            echo '<p class="slimstat-acquisition__empty">' . esc_html('utm' === $mode
                ? __('No tagged pageviews in this period. Add utm_source, utm_medium and utm_campaign to your incoming links, or choose another date range.', 'wp-slimstat')
                : (empty(\wp_slimstat_db::$filters_normalized['columns'])
                    ? __('No pageviews in this period. Try a wider date range.', 'wp-slimstat')
                    : __('No pageviews match this period and these filters. Try a wider date range or clear a filter.', 'wp-slimstat'))) . '</p>';
        } else {
            $network = NetworkMerge::isMerging();
            $labelOf = static function ($value) use ($groupField): string {
                return 'traffic_channel' === $groupField ? (Acquisition::labels()[$value ?? ''] ?? __('Unassigned', 'wp-slimstat')) : ('' !== ($value ?? '') ? $value : __('Unassigned', 'wp-slimstat'));
            };
            $byGroup = [];
            foreach ($details as $detail) {
                $byGroup[self::summaryKey($detail, $groupField)][] = $detail;
            }
            echo '<div class="slimstat-acquisition__groups"><div class="slimstat-acquisition__columns" aria-hidden="true"><span>' . esc_html($fields[$groupField]) . '</span><span>' . esc_html__('Pageviews', 'wp-slimstat') . '</span><span>' . esc_html__('Share', 'wp-slimstat') . '</span></div>';
            foreach ($rows as $row) {
                // A merged group has no one value to filter by.
                $value = 1 === count($row['values']) ? $row[$groupField] : null;
                $label = $labelOf($value);
                $groupRows = $byGroup[self::summaryKey($row, $groupField)] ?? [];
                $count = (int) $row['counthits'];
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- htmlspecialchars encodes quotes and existing entities to preserve literal stored tags.
                echo '<details class="slimstat-acquisition__group"><summary><span class="slimstat-acquisition__identity"><span class="slimstat-acquisition__label">' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
                $sources = array_unique(array_filter(array_column($groupRows, 'utm' === $mode ? 'utm_source' : 'traffic_source'), static function ($source) {
                    return null !== $source && '' !== $source;
                }));
                if ($sources) {
                    // A preview only: never infer the total number of sources from capped rows.
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- htmlspecialchars encodes quotes and existing entities to preserve literal stored tags.
                    echo '<span class="slimstat-acquisition__preview">' . htmlspecialchars(implode(' · ', array_slice($sources, 0, 3)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
                }
                if ($network) {
                    echo '<small>' . esc_html(get_blog_option((int) ($row['blog_id'] ?? 0), 'blogname')) . '</small>';
                }
                echo '</span><strong class="slimstat-acquisition__number">' . esc_html(number_format_i18n($count)) . '<span class="screen-reader-text"> ' . esc_html__('Pageviews', 'wp-slimstat') . '</span></strong>';
                self::share($count, $total);
                echo '</summary><div class="slimstat-acquisition__breakdown">';
                if (is_admin() && null !== $value && '' !== $value) {
                    echo '<div class="slimstat-acquisition__filter">';
                    self::value($groupField, $value, 'utm' === $mode ? __('Filter by this campaign', 'wp-slimstat') : __('Filter by this channel', 'wp-slimstat'));
                    if (null !== $commerceFilters) {
                        $url = add_query_arg(['page' => 'slimview7', 'fs' => urlencode_deep(array_merge($commerceFilters, [$groupField => 'equals ' . $value]))], admin_url('admin.php')) . '#ss-ec-panel-' . ('utm' === $mode ? 'campaign' : 'channel');
                        echo '<a class="noslimstat" href="' . esc_url($url) . '">' . esc_html__('View sales in Ecommerce', 'wp-slimstat') . '</a>';
                    }
                    echo '</div>';
                    if (null !== $commerceFilters) {
                        echo '<p class="slimstat-acquisition__note">' . esc_html__('Linked orders uses these dates and filters to find orders associated with matching tracked visits. Orders without a visit link are excluded; pageview share is not a purchase rate.', 'wp-slimstat') . '</p>';
                    }
                }
                if ($groupRows) {
                    self::breakdown($groupRows, $fields, $total);
                } elseif (!\wp_slimstat::pro_is_installed()) {
                    echo '<div class="slimstat-locked-rows" aria-hidden="true"><span></span><span></span></div><p>' . esc_html__('Source breakdowns are available with Pro. Illustrative preview.', 'wp-slimstat') . '</p>';
                }
                $shown = array_sum(array_column($groupRows, 'counthits'));
                if (\wp_slimstat::pro_is_installed() && $shown < $count) {
                    echo '<p class="slimstat-acquisition__note">' . esc_html(sprintf(
                        /* translators: 1: shown pageviews, 2: complete group pageviews. */
                        __('Showing %1$s of %2$s pageviews in this breakdown. The result limit was reached; filter this group or narrow your filters for more detail.', 'wp-slimstat'), number_format_i18n($shown), number_format_i18n($count)
                    )) . '</p>';
                }
                echo '</div></details>';
            }
            if (!\wp_slimstat::pro_is_installed() && is_admin()) {
                self::locked($mode, count($all) > self::FREE_GROUPS);
            }
            echo '</div>';
            // Without Pro there is nothing to page to: the locked row stands in for the rest.
            if (!$locked) {
                // Keep pagination inside this table's AJAX fragment. The legacy .pagination
                // class is extracted by admin.js for older reports with an external footer.
                echo '<nav class="slimstat-acquisition__pagination" aria-label="' . esc_attr__('Report pages', 'wp-slimstat') . '"><span>';
                echo esc_html(sprintf(
                    /* translators: 1: first displayed row, 2: last displayed row, 3: total row count. */
                    __('%1$s–%2$s of %3$s', 'wp-slimstat'), number_format_i18n($start + 1), number_format_i18n($start + count($rows)), number_format_i18n(count($all))
                )) . '</span>';
                if (is_admin() && $start > 0) {
                    echo '<a class="button refresh" href="' . esc_url(\wp_slimstat_reports::fs_url('start_from equals ' . max(0, $start - $perPage))) . '">' . esc_html__('Previous', 'wp-slimstat') . '</a>';
                }
                if (is_admin() && $start + $perPage < count($all)) {
                    echo '<a class="button refresh" href="' . esc_url(\wp_slimstat_reports::fs_url('start_from equals ' . ($start + $perPage))) . '">' . esc_html__('Next', 'wp-slimstat') . '</a>';
                }
                echo '</nav>';
            }
            if ($capped) {
                echo '<p class="slimstat-acquisition__note">' . esc_html__('The report result limit was reached. Narrow your filters to see more detail. Shares use all matching pageviews, including rows beyond this limit.', 'wp-slimstat') . '</p>';
            }
        }
        // Opened from the intro's help link.
        echo '<dialog class="ss-report-dialog" id="' . esc_attr($helpId) . '" aria-labelledby="' . esc_attr($helpId) . '-title"><div class="ss-report-dialog__heading"><h2 id="' . esc_attr($helpId) . '-title">' . esc_html($helpLabel) . '</h2><button type="button" class="button" data-dialog-close>' . esc_html__('Close', 'wp-slimstat') . '</button></div>';
        echo '<p>' . esc_html('utm' === $mode
            ? __('Campaign totals combine all sources and tags. Expand a campaign for each combination of tags on a recorded page URL, not a session or conversion. Values keep their original case. More tags reveals content, term and campaign ID when present. Unassigned means that tag was absent. Use consistent names and tag incoming links, not internal links.', 'wp-slimstat')
            : __('Channel totals combine all sources. Expand a channel for its source breakdown. These are pageview channels, not session attribution. Campaign tags take priority over referring sites. Internal navigation is separate. Direct / unknown means no usable source was sent; it can include untagged email, private messages or apps. Unassigned means the pageview predates report setup.', 'wp-slimstat')) . '</p>';
        if ('channels' === $mode) {
            echo '<p>' . esc_html__('AI Assistants identifies referrals or tagged links from known assistants. AI Crawlers and AI User-requested Fetches identify automated requests by their claimed user agent, not verified identity. They appear only when your tracking settings collect them. Google AI Overviews cannot reliably be separated from Organic Search. Unknown tagged media appear as Unassigned.', 'wp-slimstat') . '</p>';
        } else {
            echo '<p><code>?utm_source=newsletter&amp;utm_medium=email&amp;utm_campaign=autumn</code></p>';
        }
        echo '</dialog></div>';
    }
}
