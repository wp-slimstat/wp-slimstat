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
 * Free/pro-tier row gating (item 6/7 of the General-page rework): gated
 * boxes cap at GATED_TOTAL_ROWS visible slots (2 real + up to 8 synthetic)
 * and render a bold "Unlock full report with Pro" overlay CTA — never real
 * data — for the blurred-looking portion, so DOM inspection cannot recover
 * real numbers the CSS merely hides.
 */
class GeneralReports
{
    /** Total visible row slots on a gated (free-tier) box: 2 real + 3 fake. */
    private const GATED_TOTAL_ROWS = 5;

    /** Rows per page for a Pro account's un-gated boxes (client-side pagination — see renderPager()). */
    private const ROWS_PER_PAGE = 5;

    private const PRICING_URL = 'https://wp-slimstat.com/pricing/?utm_source=wp-slimstat&utm_medium=link&utm_campaign=general';

    private const LOCK_ICON = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';

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
     * "Where visitors come from": Direct / Search / Other referrers.
     */
    public static function trafficSources(array $args = []): void
    {
        $direct_count = \wp_slimstat_db::count_records('id', 'resource IS NULL');
        $search_count = \wp_slimstat_db::count_records('id', 'searchterms IS NOT NULL');
        $other_count  = GeneralPageData::otherReferrersCount((int) \wp_slimstat_db::$pageviews, $direct_count, $search_count);

        $rows = GeneralPageData::trafficRows(
            $direct_count,
            $search_count,
            $other_count,
            __('Direct', 'wp-slimstat'),
            __('Search', 'wp-slimstat'),
            __('Other referrers', 'wp-slimstat')
        );

        self::renderGatedBox($rows, 'label', false, 1, __('We will group your visitors by Direct, Search and other referrers once they arrive.', 'wp-slimstat'), __('Source', 'wp-slimstat'));
    }

    /**
     * "Your most visited pages" — same data source as slim_p1_08 "Top Web
     * Pages" (wp_slimstat_db::get_top('resource')).
     */
    public static function topPages(array $args = []): void
    {
        $rows = \wp_slimstat_db::get_top('resource');
        self::renderGatedBox($rows, 'resource', true, 2, __('Your pages will be ranked here by how often they are viewed.', 'wp-slimstat'), __('Page', 'wp-slimstat'));
    }

    /**
     * "Where your visitors are" — same data source as slim_p1_13 "Top
     * Countries" (wp_slimstat_db::get_top('country')).
     */
    public static function topCountries(array $args = []): void
    {
        $rows = \wp_slimstat_db::get_top('country');
        self::renderGatedBox($rows, 'country', false, 3, __('Countries appear here as soon as someone visits.', 'wp-slimstat'), __('Country', 'wp-slimstat'));
    }

    /**
     * "Browsers": real Browser-family data, same query shape as slim_p2_18
     * "Top Browser Families" (wp_slimstat_db::get_top('browser')). Device
     * FORM FACTOR (phone/tablet/desktop) genuinely isn't tracked — this box
     * used to claim "device type is not tracked" and then show nothing at
     * all, which is misleading twice over: browser (and OS, via slim_p2_19's
     * platform expression) ARE tracked and were simply never queried here.
     */
    public static function devicesAndBrowsers(array $args = []): void
    {
        $rows = \wp_slimstat_db::get_top('browser');
        self::renderGatedBox($rows, 'browser', true, 4, __('Browser families will appear here as visitors arrive.', 'wp-slimstat'), __('Browser', 'wp-slimstat'));
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
     * The bottom-of-page Goals & Custom Events Pro-upsell banner. Renders
     * nothing at all on Pro (the report is still registered/looped so its
     * position in the layout is customizable, but there is nothing to
     * upsell once the features are unlocked).
     */
    public static function goalsUpsell(array $args = []): void
    {
        if (\wp_slimstat::pro_is_installed()) {
            return;
        }
        ?>
        <section class="pro-card" data-upgrade tabindex="0" role="button" aria-label="<?php esc_attr_e('Unlock Goals and Custom Events with Pro', 'wp-slimstat'); ?>">
            <div class="blurred" aria-hidden="true">
                <div style="font-size:15px;font-weight:700;margin-bottom:14px"><?php esc_html_e('Goals & Custom Events', 'wp-slimstat'); ?></div>
            </div>
            <div class="veil">
                <span class="lock"><?php echo self::LOCK_ICON; ?></span>
                <span class="cap"><?php esc_html_e('See which actions turn visitors into customers — track goals, funnels, and conversions', 'wp-slimstat'); ?></span>
                <button class="btn-pro" type="button" data-upgrade><?php esc_html_e('Unlock Goals & Funnels with Pro', 'wp-slimstat'); ?></button>
            </div>
        </section>
        <div class="slimstat-general-scrim"></div>
        <?php
        \wp_slimstat_admin::get_template('slimstat-pro-modal');
    }

    /**
     * Renders one "top N" box. Free tier: real rows first, then synthetic
     * rows filling out to GATED_TOTAL_ROWS, with a bold centered "Unlock
     * full report with Pro" overlay covering the synthetic portion. The
     * synthetic rows are GeneralPageData::fakeBlurredRows() output:
     * plausible-looking but never derived from real stats, so inspecting the
     * DOM under the CSS blur recovers no real numbers (unlike the previous
     * rows-blur/aria-hidden markup, which sent real values to the browser).
     *
     * Pro tier: every real row, paginated ROWS_PER_PAGE at a time via
     * renderPager() — a lightweight per-box, client-side pager (see that
     * method's docblock for why this box can't reuse
     * wp_slimstat_reports::report_pagination()).
     *
     * Row markup matches wp_slimstat_reports::raw_results_to_html()'s
     * .slimstat-tooltip-trigger / .slimstat-tooltip-bar-wrap /
     * .slimstat-count-pct / .slimstat-pct output for the VISIBLE rows — the
     * percentage-bar span itself is the same shared
     * wp_slimstat_reports::tooltip_bar() call that method uses (one clamp
     * rule, not two copies of it); the surrounding row markup is
     * necessarily a second implementation because raw_results_to_html()
     * fuses SQL dispatch, pagination and column-specific post-processing
     * into the same method, which General's rows (already fetched, already
     * gated) don't go through.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private static function renderGatedBox(array $rows, string $field, bool $titleAttr, int $fakeSeed, string $emptyBody, string $fakeLabel): void
    {
        if (empty($rows)) {
            echo '<div class="empty"><span class="big">' . esc_html__('No data yet', 'wp-slimstat') . '</span><span>' . esc_html($emptyBody) . '</span></div>';
            return;
        }

        $is_pro = \wp_slimstat::pro_is_installed();
        $total  = GeneralPageData::sumCounthits($rows);

        if ($is_pro) {
            self::renderPager($rows, $field, $titleAttr, $total);
            return;
        }

        // splitRows() caps at 2 free rows regardless of GATED_TOTAL_ROWS —
        // the free-visible count and the total gated-box slot count are
        // deliberately separate knobs.
        [$shown, $blurred] = GeneralPageData::splitRows($rows, false, 2);

        echo '<div class="rows">';
        self::renderRows($shown, $field, $titleAttr, $total);
        echo '</div>';

        $fakeSlots = max(0, self::GATED_TOTAL_ROWS - count($shown));
        $fakeRows  = empty($blurred) ? [] : GeneralPageData::fakeBlurredRows($fakeSlots, $field, $fakeLabel, $fakeSeed);
        $fakeTotal = GeneralPageData::sumCounthits(array_merge($shown, $fakeRows));

        if (!empty($fakeRows)) {
            echo '<div class="slimstat-gated-wrap">';
            echo '<div class="rows rows-blur" aria-hidden="true">';
            self::renderRows($fakeRows, $field, false, $fakeTotal ?: 1);
            echo '</div>';
            echo '<a class="slimstat-gated-cta" href="' . esc_url(self::PRICING_URL) . '" target="_blank" rel="noopener">';
            echo self::LOCK_ICON;
            echo '<span>' . esc_html__('Unlock full report with Pro', 'wp-slimstat') . '</span>';
            echo '</a>';
            echo '</div>';
        }
    }

    /**
     * A Pro-tier box's rows, split into ROWS_PER_PAGE-sized pages and all
     * rendered up front (each page a <div class="slimstat-page">, every page
     * but the first starting hidden), with a next/prev arrow pair at the
     * bottom that general.js toggles which page is visible.
     *
     * NOT wp_slimstat_reports::report_pagination(): that pager is a single
     * PAGE-WIDE filter (start_from, via fs_url()) that full-page-navigates —
     * correct for a report alone on its own screen, but General has 4
     * independent gated boxes on the SAME screen, and one shared start_from
     * would page all 4 together when only one box's "next" was clicked.
     * Since get_top() already returns every row (no SQL LIMIT/OFFSET), the
     * cheaper and correct fix is a pager scoped to this one box: no new
     * query, just which already-rendered page is visible.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private static function renderPager(array $rows, string $field, bool $titleAttr, int $total): void
    {
        $pages = array_chunk($rows, self::ROWS_PER_PAGE);

        if (count($pages) <= 1) {
            echo '<div class="rows">';
            self::renderRows($rows, $field, $titleAttr, $total);
            echo '</div>';
            return;
        }

        // data-status-format carries the translated "Page %1$s of %2$s"
        // template so general.js can re-render the status text on every page
        // change without hardcoding an English string client-side or
        // resorting to a regex over already-rendered text.
        echo '<div class="slimstat-general-pager" data-page="0" data-page-count="' . esc_attr(count($pages)) . '" data-status-format="' . esc_attr__('Page %1$s of %2$s', 'wp-slimstat') . '">';
        foreach ($pages as $i => $a_page) {
            echo '<div class="rows slimstat-page"' . (0 === $i ? '' : ' hidden') . '>';
            self::renderRows($a_page, $field, $titleAttr, $total);
            echo '</div>';
        }
        echo '<div class="slimstat-general-pager-controls">';
        echo '<button type="button" class="slimstat-general-pager-prev" disabled aria-label="' . esc_attr__('Previous page', 'wp-slimstat') . '">&#9650;</button>';
        echo '<span class="slimstat-general-pager-status">' . esc_html(sprintf(
            /* translators: 1: current page number, 2: total page count */
            __('Page %1$s of %2$s', 'wp-slimstat'),
            1,
            count($pages)
        )) . '</span>';
        echo '<button type="button" class="slimstat-general-pager-next" aria-label="' . esc_attr__('Next page', 'wp-slimstat') . '">&#9660;</button>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private static function renderRows(array $rows, string $field, bool $titleAttr, int $total): void
    {
        foreach ($rows as $a_row) {
            $counthits    = (int) ($a_row['counthits'] ?? 0);
            $percent_raw  = $total > 0 ? (100 * $counthits / $total) : 0;
            $percent      = number_format_i18n((float) sprintf('%01.2f', $percent_raw), 2);
            $label        = (string) ($a_row[$field] ?? '');
            $title_attr   = $titleAttr ? ' title="' . esc_attr($label) . '"' : '';

            echo '<p class="slimstat-tooltip-trigger"' . $title_attr . '>';
            echo \wp_slimstat_reports::tooltip_bar($percent_raw);
            echo esc_html($label);
            echo ' <span class="slimstat-count-pct">' . esc_html(number_format_i18n($counthits)) . '<span class="slimstat-pct">(' . esc_html($percent) . '%)</span></span>';
            echo '</p>';
        }
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
