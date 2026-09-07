<?php

namespace SlimStat\Modules;

// don't load directly.
if (! defined('ABSPATH')) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit;
}

use SlimStat\Helpers\GeneralPageData;

/**
 * Render callbacks for the General (slimgeneral) landing page's report
 * entries, registered in wp_slimstat_reports::$reports under the slim_p10_*
 * id namespace (p1-p9 and p2_22 were already in use — p5, p8 and p10 were
 * not, chosen after grepping every existing report id). Each public method
 * here is one report's 'callback', called via
 * wp_slimstat_reports::callback_wrapper() exactly like every other report on
 * every other screen — general.php no longer renders any of this directly,
 * it only loops wp_slimstat_reports::$user_reports['slimgeneral'].
 *
 * The four table boxes do NOT render themselves. They are ordinary
 * wp_slimstat_reports::raw_results_to_html() reports, registered exactly
 * like slim_p1_08 / slim_p1_13 / slim_p2_18 — that renderer owns the row
 * markup, the percentage bars, the per-column label formatting
 * (get_resource_title(), browser icons, country flags) and the pagination
 * arrows. An earlier revision of this class re-implemented that row markup
 * by hand; it had none of the per-column label formatting, which is why
 * those tables rendered rows with a number and no name.
 *
 * Free-tier row gating (items 6/7 of the rework) is applied to the DATA,
 * not the markup: gateRows() below truncates to FREE_ROWS real rows and
 * appends synthetic ones, so what reaches the browser under the CSS blur
 * was never real data and DOM inspection cannot recover it.
 */
class GeneralReports
{
    /** Total row slots a gated (free-tier) table shows: FREE_ROWS real + synthetic filler. */
    private const GATED_TOTAL_ROWS = 5;

    /** Real rows a free-tier table reveals; everything past this is synthetic. */
    private const FREE_ROWS = 2;

    private const PRICING_URL = 'https://wp-slimstat.com/pricing/?utm_source=wp-slimstat&utm_medium=link&utm_campaign=general';

    private const LOCK_ICON = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';

    /** The General table reports that carry the free-tier gate. */
    private const GATED_REPORT_IDS = ['slim_p10_03', 'slim_p10_04', 'slim_p10_05', 'slim_p10_06'];

    /**
     * The General reports that get the free-tier "upgrade to Pro" footer.
     *
     * Goals only, deliberately not Funnels: show_funnels_compact() already
     * renders its own full upgrade overlay when max_funnels is 0 (the free
     * default), so adding this footer there stacked a second, identical
     * "Upgrade to Pro" button under the first.
     */
    private const PRO_CTA_REPORT_IDS = ['slim_p10_08'];

    /**
     * The free tier's gating chrome: the per-row marker class the blur keys
     * off, and the "Unlock full report with Pro" overlay.
     *
     * Both go through public filters — slimstat_report_row_classes and
     * slimstat_report_header_after_title, the latter being the one Goals &
     * Funnels already uses for its own header chrome (see
     * wp_slimstat_admin::register_goals_funnels_header_hooks()) — so the
     * shared renderer keeps rendering these four reports exactly as it
     * renders every other top-N report: no fork, no General-only branch
     * inside raw_results_to_html().
     *
     * Deliberately NOT hooked on slimstat_reports_info to stamp a class onto
     * the postbox: wp_slimstat_reports::init() memoizes $reports and is
     * called from wp_slimstat_admin::init() BEFORE the line that registers
     * these hooks, so a registry filter added here never runs and the gate
     * fails open — silently showing every free user their synthetic rows
     * unblurred. Both callbacks below decide per render instead, so they
     * cannot be outrun by initialisation order.
     */
    public static function register_hooks(): void
    {
        add_filter('slimstat_report_header_after_title', [self::class, 'injectUnlockCta'], 10, 2);
        add_filter('slimstat_report_row_classes', [self::class, 'markSyntheticRows'], 10, 3);
        add_filter('slimstat_report_pagination_html', [self::class, 'suppressGatedPagination'], 10, 2);
        add_filter('slimstat_report_after_body', [self::class, 'injectProCta'], 10, 2);
    }

    /**
     * Drops the pager from a gated table. The free tier renders a fixed,
     * truncated row set, so both the arrows and the "Showing 1 - 5 of 5"
     * count would describe pages it cannot reach — and the count would leak
     * how much real data is being withheld.
     *
     * @param string               $html
     * @param array<string, mixed> $args The report's callback args.
     */
    public static function suppressGatedPagination($html = '', $args = [])
    {
        return self::isGatedRender($args) ? '' : $html;
    }

    /**
     * True when this render is one of the General tables AND the free tier is
     * active — i.e. when the gate applies.
     *
     * Keyed on the report id (carried in callback_args by _check_args()), the
     * same discriminator injectUnlockCta() uses, so the blur, the pager
     * suppression and the overlay that sits on top of them all agree on which
     * reports are gated. An earlier revision matched the report's `raw`
     * callable against this class instead, which identifies a DATA SOURCE, not
     * a screen: any future report reusing topColumnRaw() would silently have
     * inherited the blur.
     *
     * Decided per render, never from a class stamped onto the registry
     * earlier: wp_slimstat_reports::init() memoizes $reports and runs before
     * this class's hooks are registered, so a registry filter never fires and
     * the gate fails OPEN — which is exactly how synthetic rows once reached
     * the browser unblurred.
     *
     * @param array<string, mixed> $args The report's callback args.
     */
    private static function isGatedRender($args): bool
    {
        $reportId = is_array($args) ? ($args['report_id'] ?? '') : '';

        return in_array($reportId, self::GATED_REPORT_IDS, true)
            && !\wp_slimstat::pro_is_installed();
    }

    /**
     * Tags a gated table's synthetic rows with `slimstat-row-synthetic`,
     * which the CSS blurs.
     *
     * By class rather than by CSS position: the renderer emits its debug
     * message and its "Showing x - y of z" pagination as <p> siblings of the
     * rows and, on the non-AJAX path, wraps the rows in an extra <div>, so
     * every positional selector — counting from the front OR the end — either
     * shifts or stops matching depending on the render path and the debug
     * setting.
     *
     * @param string               $classes
     * @param array<string, mixed> $args  The report's own callback args.
     * @param int                  $index Row index within the rendered page.
     */
    public static function markSyntheticRows($classes = '', $args = [], $index = 0)
    {
        if (!self::isGatedRender($args) || $index < self::FREE_ROWS) {
            return $classes;
        }

        return trim($classes . ' slimstat-row-synthetic');
    }

    /**
     * The "Unlock full report with Pro" overlay for a gated table, injected
     * right after the postbox <h3> (the filter's insertion point) and
     * positioned over the blurred rows by CSS.
     */
    public static function injectUnlockCta($html = '', $reportId = '')
    {
        if (!in_array($reportId, self::GATED_REPORT_IDS, true) || \wp_slimstat::pro_is_installed()) {
            return $html;
        }

        // Centre of the synthetic tail, as a percentage of the row block:
        // rows FREE_ROWS..GATED_TOTAL_ROWS are the blurred ones, so their
        // midpoint is (FREE_ROWS + GATED_TOTAL_ROWS) / 2 / GATED_TOTAL_ROWS.
        // Computed from the constants rather than hard-coded in the CSS, so
        // changing either one moves the button with it instead of silently
        // leaving it over rows the reader is meant to be able to read.
        $tailMidpoint = round(((self::FREE_ROWS + self::GATED_TOTAL_ROWS) / 2 / self::GATED_TOTAL_ROWS) * 100);

        return $html . '<a class="slimstat-gated-cta" style="--ss-gated-cta-top:' . esc_attr($tailMidpoint) . '%" href="' . esc_url(self::PRICING_URL) . '" target="_blank" rel="noopener">'
            . self::LOCK_ICON
            . '<span>' . esc_html__('Unlock full report with Pro', 'wp-slimstat') . '</span>'
            . '</a>';
    }

    /**
     * A per-tile tooltip trigger, via the SAME markup
     * wp_slimstat_reports::tooltip_trigger_open() emits for a report's own
     * per-box tooltip — needed here because statsRow() renders FOUR tiles
     * under one report entry (item 2's "one combined entry" choice), so
     * report_header()'s single tooltip-per-report cannot cover all four;
     * each tile gets its own trigger via this shared helper instead of a
     * second hand-copied SVG string.
     */
    private static function tooltipTrigger(string $text): string
    {
        return \wp_slimstat_reports::tooltip_trigger_open() . esc_html($text) . '</span></span>';
    }

    /**
     * The four big-number stat tiles (Unique Visitors, Pageviews, Bounce
     * Rate, Avg. Visit Duration), each with a period-over-period comparison
     * badge computed against the immediately preceding, equal-length range
     * (GeneralPageData::previousPeriod() — the same algorithm the pageviews
     * chart's dashed "previous period" line uses).
     */
    public static function statsRow(array $args = []): void
    {
        $current  = \wp_slimstat_db::$filters_normalized['utime'];
        $previous = GeneralPageData::previousPeriod((int) $current['start'], (int) $current['end'], \wp_timezone());

        $now = self::currentStats();
        $was = self::statsForRange((int) $previous['start'], (int) $previous['end']);

        $tiles = [
            [
                'label'   => __('Unique Visitors', 'wp-slimstat'),
                'sub'     => __('separate people, counted once each', 'wp-slimstat'),
                'value'   => number_format_i18n($now['unique_visitors']),
                'delta'   => GeneralPageData::percentChange($now['unique_visitors'], $was['unique_visitors']),
                'tooltip' => __('The number of distinct visitors in the selected date range, each counted once no matter how many pages they viewed.', 'wp-slimstat'),
            ],
            [
                'label'   => __('Pageviews', 'wp-slimstat'),
                'sub'     => __('pages they looked at', 'wp-slimstat'),
                'value'   => number_format_i18n($now['pageviews']),
                'delta'   => GeneralPageData::percentChange($now['pageviews'], $was['pageviews']),
                'tooltip' => __('The total number of pages viewed in the selected date range, including repeat views by the same visitor.', 'wp-slimstat'),
            ],
            [
                'label'   => __('Bounce Rate', 'wp-slimstat'),
                'sub'     => __('left after one page', 'wp-slimstat'),
                'value'   => number_format_i18n($now['bounce_rate'], 0) . '%',
                // Lower is better for bounce rate, but the badge reports the
                // raw direction of change (matching every other tile) rather
                // than inverting its color — inversion is a copy/visual
                // decision left to a follow-up, not silently baked in here.
                'delta'   => GeneralPageData::percentChange($now['bounce_rate'], $was['bounce_rate']),
                'tooltip' => __('The share of human visits that viewed only one page before leaving.', 'wp-slimstat'),
            ],
            [
                'label'   => __('Avg. Visit Duration', 'wp-slimstat'),
                'sub'     => __('time spent per visit', 'wp-slimstat'),
                'value'   => $now['avg_duration_label'],
                'delta'   => GeneralPageData::percentChange($now['avg_duration_seconds'], $was['avg_duration_seconds']),
                'tooltip' => __('The average amount of time human visitors spend on your site per visit.', 'wp-slimstat'),
            ],
        ];

        echo '<div class="stats">';
        foreach ($tiles as $a_tile) {
            echo '<div class="stat">';
            echo '<span class="lbl">' . esc_html($a_tile['label']) . self::tooltipTrigger($a_tile['tooltip']) . '</span>';
            echo '<span class="val">' . esc_html($a_tile['value']) . '</span>';
            echo '<span class="sub">' . esc_html($a_tile['sub']) . '</span>';
            echo self::deltaBadge($a_tile['delta']);
            echo '</div>';
        }
        echo '</div>';
    }

    /**
     * The pageviews-vs-unique-visitors chart, via the exact same
     * \SlimStat\Modules\Chart::showChart() call every other chart report on
     * every other screen uses — wrapped by the caller (report_header() /
     * report_footer()) in the standard .postbox/.inside structure, which is
     * what slimstat-chart.js's fetchChartData() requires to find its
     * granularity-refresh target on granularity change.
     */
    public static function pageviewsChart(array $args = []): void
    {
        $chart = new Chart();
        $chart->showChart([
            'id'         => 'slim_general_pageviews',
            'chart_data' => [
                'data1' => 'COUNT( ip )',
                'data2' => 'COUNT( DISTINCT ip )',
            ],
            'chart_labels' => [
                __('Pageviews', 'wp-slimstat'),
                (('on' == (\wp_slimstat::$settings['hash_ip'] ?? 'off')) ? __('Unique Visitors', 'wp-slimstat') : __('Unique IPs', 'wp-slimstat')),
            ],
        ]);
    }

    /**
     * Data source for the "Where visitors come from" table: Direct / Search /
     * Other referrers.
     *
     * This is a `raw` callable in the wp_slimstat_reports::$reports sense —
     * raw_results_to_html() calls it to GET the rows and does all the
     * rendering itself (bars, percentages, labels, pagination), exactly as it
     * does for slim_p1_08 and friends. The other three General tables need no
     * callable at all: they are plain get_top() columns, so their registry
     * entries point `raw` straight at wp_slimstat_db::get_top() like every
     * other top-N report in the plugin.
     *
     * Row shape matches get_top()'s: the label lives under the key named by
     * the report's `columns` arg ('referer_type' here), alongside 'counthits'.
     *
     * @return array<int, array{referer_type: string, counthits: int}>
     */
    public static function trafficSourcesRaw($args = [])
    {
        $direct_count = \wp_slimstat_db::count_records('id', 'resource IS NULL');
        $search_count = \wp_slimstat_db::count_records('id', 'searchterms IS NOT NULL');

        // Count pageviews here rather than reading wp_slimstat_db::$pageviews:
        // that static is only populated by wp_slimstat_db::init() when the
        // request's `page` is absent or contains 'slimview' (see its "data
        // used by multiple reports" block). This screen is 'slimgeneral', and
        // the async box requests post that same value, so the static is 0 for
        // every render of this table — which made otherReferrersCount() return
        // max(0, 0 - direct - search) = 0 and trafficRows() drop the "Other
        // referrers" row entirely, silently, on every page load.
        $pageviews = (int) \wp_slimstat_db::$pageviews;
        if (0 === $pageviews) {
            $pageviews = (int) \wp_slimstat_db::count_records();
        }

        $other_count = GeneralPageData::otherReferrersCount($pageviews, $direct_count, $search_count);

        return self::gateRows(
            GeneralPageData::trafficRows(
                $direct_count,
                $search_count,
                $other_count,
                __('Direct', 'wp-slimstat'),
                __('Search', 'wp-slimstat'),
                __('Other referrers', 'wp-slimstat')
            ),
            'referer_type',
            __('Source', 'wp-slimstat'),
            1
        );
    }

    /**
     * `raw` callable for the three General tables whose data is a plain
     * get_top() column — it forwards to get_top() unchanged and then applies
     * the same free-tier gate as trafficSourcesRaw().
     *
     * raw_results_to_html() invokes a `raw` callable with the report's own
     * $_args, and get_top() already accepts that array form (its $_column
     * parameter doubles as an args array — see wp_slimstat_db::get_top()), so
     * forwarding is enough; the gate is the only thing added.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function topColumnRaw($args = [])
    {
        $rows = \wp_slimstat_db::get_top($args);

        // ?? not ?: — none of these reports declare 'as_column', and ?:
        // evaluates its left operand, so it warned on every render.
        $column = is_array($args)
            ? (!empty($args['as_column']) ? $args['as_column'] : ($args['columns'] ?? ''))
            : $args;

        return self::gateRows(is_array($rows) ? $rows : [], (string) $column, __('Item', 'wp-slimstat'), 2);
    }

    /**
     * The free-tier gate, applied to ROWS rather than to rendered markup.
     *
     * Pro sees everything. Free sees FREE_ROWS real rows followed by
     * synthetic filler out to GATED_TOTAL_ROWS: the CSS blurs the tail, but
     * because the tail was never real, opening devtools and removing the blur
     * reveals only the synthetic values (the live-analytics.php precedent).
     *
     * Returning FEWER rows than exist is also what keeps the Free table from
     * offering pagination into data the tier cannot see — raw_results_to_html()
     * derives its pagination from the row count this returns.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function gateRows(array $rows, string $field, string $fakeLabel, int $fakeSeed): array
    {
        if (count($rows) <= self::FREE_ROWS) {
            return $rows;
        }

        // splitRows() already encodes "Pro sees everything, Free sees the
        // first N" — reused here rather than re-sliced inline, so the tier
        // rule has one implementation and one set of tests.
        [$shown, $hidden] = GeneralPageData::splitRows($rows, \wp_slimstat::pro_is_installed(), self::FREE_ROWS);

        if (empty($hidden)) {
            return $shown;
        }

        return array_merge(
            $shown,
            GeneralPageData::fakeBlurredRows(self::GATED_TOTAL_ROWS - count($shown), $field, $fakeLabel, $fakeSeed)
        );
    }

    /**
     * Campaign tracking (utm_source / utm_campaign) is not collected by this
     * version of SlimStat — unlike the old "Devices & browsers" box, this
     * empty state is accurate, so it stays a plain empty state rather than a
     * gated box (there is no real data of any tier to gate).
     */
    public static function campaigns(array $args = []): void
    {
        echo '<div class="empty">';
        echo '<span class="big">' . esc_html__('No data yet', 'wp-slimstat') . '</span>';
        echo '<span>' . esc_html__('Campaign tracking is not collected in this version of SlimStat.', 'wp-slimstat') . '</span>';
        echo '</div>';
    }

    /**
     * The free tier's "you can create more of these with Pro" footer under
     * the Goals section.
     *
     * Appended after the report's own output rather than replacing it: that
     * box now renders the REAL goals (via the same compact renderer the
     * dashboard widget uses), so this is a footer under real data, not a
     * banner standing in for it.
     *
     * @param string $html
     * @param string $reportId
     */
    public static function injectProCta($html = '', $reportId = '')
    {
        if (!in_array($reportId, self::PRO_CTA_REPORT_IDS, true) || \wp_slimstat::pro_is_installed()) {
            return $html;
        }

        return $html
            . '<div class="slimstat-general-pro-cta">'
            . '<span class="slimstat-general-pro-cta__copy">'
            . esc_html__('Track unlimited goals and see which actions turn visitors into customers.', 'wp-slimstat')
            . '</span>'
            . '<a class="slimstat-general-pro-cta__button" href="' . esc_url(self::PRICING_URL) . '" target="_blank" rel="noopener">'
            . self::LOCK_ICON
            . '<span>' . esc_html__('Upgrade to Pro', 'wp-slimstat') . '</span>'
            . '</a>'
            . '</div>';
    }

    /**
     * One percent-change badge: an up/down arrow + signed percentage, or
     * "New" when there is no previous-period baseline to compare against
     * (GeneralPageData::percentChange() returns null for a zero previous
     * value rather than an undefined/infinite percentage).
     */
    private static function deltaBadge(?float $delta): string
    {
        if (null === $delta) {
            return '<span class="delta delta-new">' . esc_html__('New', 'wp-slimstat') . '</span>';
        }

        $rounded = round($delta);
        if (0.0 === $rounded) {
            return '<span class="delta delta-flat">' . esc_html__('No change', 'wp-slimstat') . '</span>';
        }

        $direction = $rounded > 0 ? 'up' : 'down';
        $arrow     = $rounded > 0 ? '&#9650;' : '&#9660;';
        $text      = sprintf('%s%s%%', $rounded > 0 ? '+' : '', number_format_i18n($rounded, 0));

        return sprintf(
            '<span class="delta delta-%s"><span class="delta-arrow" aria-hidden="true">%s</span>%s</span>',
            esc_attr($direction),
            $arrow,
            esc_html($text)
        );
    }

    /**
     * The four stat values for whatever range is CURRENTLY active in
     * wp_slimstat_db::$filters_normalized — i.e. exactly what general.php
     * used to compute inline before this class existed. A thin wrapper over
     * statsForRange() using the already-active start/end: statsForRange()'s
     * save-swap-query-restore of $filters_normalized['utime'] is a no-op
     * when the range passed in is the one already active, so there is no
     * second query implementation to keep in sync with this one (simplify
     * review, 2026-09-02 — this and statsForRange() were two independent
     * ~20-line copies of the same four queries until this pass).
     *
     * @return array{unique_visitors: int, pageviews: int, bounce_rate: float, avg_duration_label: string, avg_duration_seconds: int}
     */
    private static function currentStats(): array
    {
        $current = \wp_slimstat_db::$filters_normalized['utime'];

        return self::statsForRange((int) $current['start'], (int) $current['end']);
    }

    /**
     * The four stat values (plus the display-formatted duration label) for
     * an ARBITRARY [start, end] range. Temporarily swaps the active date
     * filter, queries, then restores it — wp_slimstat_db's query methods all
     * read the active filter as ambient state (no range-as-parameter
     * overload exists for any of them), so this swap-query-restore is the
     * same technique Chart.php's own AJAX handler uses
     * (ajaxFetchChartData() overwrites $filters_normalized['utime']
     * directly) rather than a new pattern invented here. Serves both
     * currentStats() (range = the already-active filter, so the swap
     * restores the same values it overwrote) and the previous-period
     * comparison (an arbitrary earlier range).
     *
     * @return array{unique_visitors: int, pageviews: int, bounce_rate: float, avg_duration_label: string, avg_duration_seconds: int}
     */
    private static function statsForRange(int $start, int $end): array
    {
        $original          = \wp_slimstat_db::$filters_normalized['utime'];
        $originalPageviews = \wp_slimstat_db::$pageviews;

        \wp_slimstat_db::$filters_normalized['utime']['start'] = $start;
        \wp_slimstat_db::$filters_normalized['utime']['end']   = $end;
        \wp_slimstat_db::$filters_normalized['utime']['range'] = $end - $start;
        \wp_slimstat_db::$pageviews = \wp_slimstat_db::count_records();

        $pageviews           = (int) \wp_slimstat_db::$pageviews;
        $unique_visitors     = \wp_slimstat_db::count_records('ip', 'visit_id > 0 AND browser_type <> 1');
        $single_page_visits  = \wp_slimstat_db::count_records_having('visit_id', 'browser_type <> 1', 'COUNT(id) = 1');
        $duration_rows       = \wp_slimstat_db::get_visits_duration();
        $avg_duration_row    = end($duration_rows);
        $avg_duration_label  = ($avg_duration_row['metric'] ?? '') === __('Average Visit Duration', 'wp-slimstat')
            ? $avg_duration_row['value']
            : '0:00';
        $total_human_visits  = GeneralPageData::sumCounthits($duration_rows);
        $bounce_rate         = GeneralPageData::bounceRate($total_human_visits, $single_page_visits);

        \wp_slimstat_db::$filters_normalized['utime'] = $original;
        \wp_slimstat_db::$pageviews                   = $originalPageviews;

        return [
            'unique_visitors'      => $unique_visitors,
            'pageviews'            => $pageviews,
            'bounce_rate'          => $bounce_rate,
            'avg_duration_label'   => $avg_duration_label,
            'avg_duration_seconds' => self::durationLabelToSeconds((string) $avg_duration_label),
        ];
    }

    /**
     * get_visits_duration()'s trailing row formats duration as "M:SS" (see
     * general.php's original inline comment) — parsed back to seconds only
     * for the percent-change comparison, never displayed.
     */
    private static function durationLabelToSeconds(string $label): int
    {
        if (!preg_match('/^(\d+):(\d{2})$/', trim($label), $m)) {
            return 0;
        }

        return ((int) $m[1] * 60) + (int) $m[2];
    }
}
