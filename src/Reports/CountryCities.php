<?php
/** @license GPL-2.0-or-later */

namespace SlimStat\Reports;

use SlimStat\Utils\NetworkMerge;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Country → City drilldown, opened from the Audience Location map, its country list and
 * Top Countries. Counts pageviews with the same filters and date range as those reports,
 * through the same get_top()/count_records() pair, so a city never outnumbers its country.
 *
 * Free receives one real city; the rest of the list never leaves the server.
 */
class CountryCities
{
    public static function ajax(): void
    {
        check_ajax_referer('meta-box-order', 'security');
        if (!\wp_slimstat_admin::can_view_stats()) {
            wp_send_json_error(['message' => __('Sorry, you are not allowed to view these statistics.', 'wp-slimstat')], 403);
        }
        $country = isset($_POST['country']) && is_string($_POST['country']) ? sanitize_key(wp_unslash($_POST['country'])) : '';
        if (!preg_match('/^[a-z0-9]{2}$/', $country)) {
            wp_send_json_error(['message' => __('Choose a country to see its cities.', 'wp-slimstat')], 400);
        }
        $start = isset($_POST['start']) && is_string($_POST['start']) ? absint(wp_unslash($_POST['start'])) : 0;

        // The page's filters and date range, parsed as for every report request (see manage_filters()).
        \SlimStat\Reports\Bootstrap::get_instance()->init();
        include_once plugin_dir_path(SLIMSTAT_FILE) . 'admin/view/wp-slimstat-reports.php';
        \wp_slimstat_reports::init();
        $name = \wp_slimstat_i18n::get_string('c-' . $country);

        $db = \wp_slimstat::$wpdb ?? $GLOBALS['wpdb'];
        $suppressed = $db->suppress_errors(true);
        try {
            $html = self::content($country, $name, $start);
            $failed = '' !== (string) $db->last_error;
        } finally {
            $db->suppress_errors($suppressed);
        }
        if ($failed) {
            wp_send_json_error(['message' => __('City statistics could not be loaded. Check the database connection, then try again.', 'wp-slimstat')], 500);
        }

        wp_send_json_success([
            /* translators: %s: country name. */
            'title' => sprintf(__('Top Cities in %s', 'wp-slimstat'), $name),
            'flag'  => self::flagUrl($country),
            'html'  => $html,
        ]);
    }

    /**
     * Applies the Free boundary and the shares to ranked rows, before anything is rendered.
     *
     * @param array<int, array<string, mixed>> $rows  get_top() rows, ranked by pageviews.
     * @param int                              $total Country pageviews: the share denominator.
     * @param int                              $known Country pageviews that carry a city.
     * @return array{rows: list<array{city: string, count: int, share: float, blog_id: int}>, locked: bool, next: int, unknown: int}
     */
    public static function shape(array $rows, int $total, int $known, bool $pro, int $start, int $perPage): array
    {
        $start   = $pro ? min(max(0, $start), max(0, count($rows) - 1)) : 0;
        $visible = array_slice($rows, $start, $pro ? max(1, $perPage) : 1);
        $shaped  = [];
        foreach ($visible as $row) {
            $count    = (int) ($row['counthits'] ?? 0);
            $shaped[] = [
                'city'    => (string) ($row['city'] ?? ''),
                'count'   => $count,
                // Rounded once, here: the bar and the printed number are the same figure (#334).
                'share'   => $total > 0 ? round(min(100, 100 * $count / $total), 1) : 0.0,
                'blog_id' => (int) ($row['blog_id'] ?? 0),
            ];
        }
        $end = $start + count($visible);
        return [
            'rows'    => $shaped,
            'locked'  => !$pro && count($rows) > 1,
            'next'    => $pro && count($rows) > $end ? $end : 0,
            'unknown' => max(0, $total - $known),
        ];
    }

    private static function content(string $country, string $name, int $start): string
    {
        ob_start();
        $provider = \wp_slimstat::resolve_geolocation_provider();
        if ('city' !== \wp_slimstat::get_geolocation_precision() || false === $provider) {
            self::cityTrackingOff($provider);
            return (string) ob_get_clean();
        }

        $pro     = \wp_slimstat::pro_is_installed();
        $where   = (\wp_slimstat::$wpdb ?? $GLOBALS['wpdb'])->prepare('country = %s', $country);
        $hasCity = $where . " AND city IS NOT NULL AND city <> ''";
        // Top Cities' own query, scoped to one country. Free is cut to one row in shape(), not by a
        // smaller LIMIT: a range reaching today is queried in two halves, each with the LIMIT, and
        // two rows per half can miss the real top city.
        $rows    = (array) \wp_slimstat_db::get_top(['columns' => 'city', 'where' => $hasCity]);
        $capped  = count($rows) >= max(1, (int) \wp_slimstat_db::$filters_normalized['misc']['limit_results']);
        $total   = (int) \wp_slimstat_db::count_records('id', $where);
        $perPage = (int) \wp_slimstat::$settings['rows_to_show'];

        if ($start > 0 && $pro) {
            // "Show more" renders only the next rows and button; the no-city note is already shown.
            $data = self::shape($rows, $total, $total, true, $start, $perPage);
            self::rows($data['rows']);
            self::more($data['next'], $capped);
            return (string) ob_get_clean();
        }

        // A list shorter than the limit is complete, so its sum is the known total.
        $known = $capped ? (int) \wp_slimstat_db::count_records('id', $hasCity) : (int) array_sum(array_column($rows, 'counthits'));
        $data  = self::shape($rows, $total, $known, $pro, $start, $perPage);

        if (0 === $total) {
            /* translators: %s: country name. */
            \wp_slimstat_reports::empty_state('', sprintf(__('No pageviews from %s in this period.', 'wp-slimstat'), $name));
        } elseif (!$data['rows']) {
            echo '<div class="slimstat-empty"><p class="nodata">' . esc_html(sprintf(
                /* translators: %s: country name. */
                __('No city data for %s in this period.', 'wp-slimstat'),
                $name
            )) . '</p><span class="slimstat-empty-hint">' . esc_html__('Cities are recorded only while Geolocation precision is set to City and the provider can place the visitor.', 'wp-slimstat') . '</span></div>';
        } else {
            echo '<div class="ss-cc__scroll" role="region" tabindex="0" aria-label="' . esc_attr__('City breakdown', 'wp-slimstat') . '"><table class="ss-cc__table"><thead><tr>';
            echo '<th scope="col">' . esc_html__('City', 'wp-slimstat') . '</th><th scope="col" class="ss-cc__number">' . esc_html__('Pageviews', 'wp-slimstat') . '</th><th scope="col" class="ss-cc__number">' . esc_html__('Share', 'wp-slimstat') . '</th>';
            echo '</tr></thead><tbody>';
            self::rows($data['rows']);
            echo '</tbody></table></div>';
            if ($data['locked']) {
                self::locked();
            } elseif ($pro) {
                self::more($data['next'], $capped);
            }
        }

        if ($data['unknown'] > 0) {
            echo '<p class="ss-cc__note">' . esc_html(sprintf(
                /* translators: 1: pageview count, 2: country name. */
                _n('%1$s pageview from %2$s has no city, because it was recorded before City precision was on or the provider could not place it. Shares are of all pageviews from %2$s.', '%1$s pageviews from %2$s have no city, because they were recorded before City precision was on or the provider could not place them. Shares are of all pageviews from %2$s.', $data['unknown'], 'wp-slimstat'),
                number_format_i18n($data['unknown']),
                $name
            )) . '</p>';
        }
        echo '<p class="ss-cc__filter"><a class="slimstat-filter-link" href="' . esc_url(\wp_slimstat_reports::fs_url('country equals ' . $country)) . '">' . esc_html(sprintf(
            /* translators: %s: country name. */
            __('Filter all reports by %s', 'wp-slimstat'),
            $name
        )) . '</a></p>';
        return (string) ob_get_clean();
    }

    /** @param list<array{city: string, count: int, share: float, blog_id: int}> $rows */
    private static function rows(array $rows): void
    {
        $network = NetworkMerge::isMerging();
        foreach ($rows as $row) {
            $share = number_format_i18n($row['share'], 1);
            // esc_html(), as in Top Cities, so a name reads the same in both reports.
            echo '<tr><th scope="row"><span class="ss-cc__city">' . esc_html($row['city']) . '</span>';
            if ($network) {
                echo '<small>' . esc_html(get_blog_option($row['blog_id'], 'blogname')) . '</small>';
            }
            echo '<span class="ss-cc__bar" aria-hidden="true"><span style="width:' . esc_attr((string) $row['share']) . '%"></span></span></th>';
            echo '<td class="ss-cc__number">' . esc_html(number_format_i18n($row['count'])) . '</td>';
            echo '<td class="ss-cc__number">' . esc_html($share . '%') . '</td></tr>';
        }
    }

    private static function more(int $next, bool $capped): void
    {
        if ($next > 0) {
            echo '<div class="ss-cc__more"><button type="button" class="button" data-ss-cc-more="' . esc_attr((string) $next) . '">' . esc_html__('Show more cities', 'wp-slimstat') . '</button></div>';
        } elseif ($capped) {
            echo '<div class="ss-cc__more"><p class="ss-cc__note">' . esc_html__('The report result limit was reached. Narrow your filters to see more cities. Shares use all matching pageviews.', 'wp-slimstat') . '</p></div>';
        }
    }

    /** Illustrative rows only: no city names or numbers beyond the first are ever sent. */
    private static function locked(): void
    {
        echo '<div class="ss-cc__locked"><div class="slimstat-locked-rows" aria-hidden="true"><span></span><span></span><span></span><span></span></div>';
        echo '<p class="slimstat-preview-caption">' . esc_html__('Illustrative preview of additional results.', 'wp-slimstat') . '</p>';
        echo '<h3>' . esc_html__('Unlock City-Level Insights', 'wp-slimstat') . '</h3>';
        \wp_slimstat_admin::get_template('pro-adds', [
            'text'     => __('Discover where your visitors come from, explore your top-performing cities, and understand your audience at a deeper level.', 'wp-slimstat'),
            'action'   => __('Unlock All Cities with Pro', 'wp-slimstat'),
            'campaign' => 'country-cities',
            'content'  => 'modal',
        ]);
        echo '</div>';
    }

    /** The Settings field that turns city tracking on, or null when cities are already recorded. */
    public static function setupUrl(): ?string
    {
        $provider = \wp_slimstat::resolve_geolocation_provider();
        if ('city' === \wp_slimstat::get_geolocation_precision() && false !== $provider) {
            return null;
        }
        return admin_url('admin.php?page=slimconfig&tab=2') . (false === $provider ? '#wp-slimstat-third-party-libraries' : '#geolocation_country-label');
    }

    /** @param string|false $provider */
    private static function cityTrackingOff($provider): void
    {
        echo '<div class="ss-cc__setup"><h3>' . esc_html__('Enable City-Level Tracking', 'wp-slimstat') . '</h3>';
        echo '<p>' . esc_html__('City-level statistics require City geolocation precision. Enable this setting to start collecting more detailed visitor location data.', 'wp-slimstat') . '</p>';
        echo '<p>' . esc_html__('Cities are recorded from the moment City precision is on. Pageviews recorded before then keep their country only, so earlier dates may show no cities.', 'wp-slimstat') . '</p>';
        if (false === $provider) {
            $hint = __('Geolocation is turned off. Choose a geolocation provider in the same settings tab first.', 'wp-slimstat');
        } elseif ('cloudflare' === $provider) {
            $hint = __('With the Cloudflare provider, cities also require the “Add visitor location headers” Managed Transform in your Cloudflare dashboard.', 'wp-slimstat');
        } else {
            $hint = __('DB-IP and MaxMind download a larger City database after you save the setting.', 'wp-slimstat');
        }
        echo '<p class="ss-cc__note">' . esc_html($hint) . '</p>';
        if (current_user_can(\wp_slimstat::$settings['capability_can_admin'])) {
            echo '<a class="button button-primary noslimstat" href="' . esc_url((string) self::setupUrl()) . '">' . esc_html__('Enable City Tracking', 'wp-slimstat') . '</a>';
        } else {
            echo '<p>' . esc_html__('Ask a SlimStat administrator to set Geolocation precision to City in Settings.', 'wp-slimstat') . '</p>';
        }
        echo '</div>';
    }

    private static function flagUrl(string $country): string
    {
        $flag = 'admin/assets/images/flags/' . $country . '.svg';
        return plugins_url(is_readable(plugin_dir_path(SLIMSTAT_FILE) . $flag) ? $flag : 'admin/assets/images/unk.png', SLIMSTAT_FILE);
    }
}
