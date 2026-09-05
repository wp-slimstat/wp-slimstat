<?php

namespace SlimStat\Helpers;

// don't load directly.
if (! defined('ABSPATH')) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit;
}

/**
 * Pure data-shaping for the General (slimgeneral) landing page: splitting
 * get_top()-shaped rows into shown/blurred for free-tier gating, summing
 * counthits for percentage bars, clamping bounce rate, and building the
 * Direct/Search/Other-referrers rows. No wp_slimstat_db calls here — the
 * view does the querying, this class only shapes the results — so it can be
 * unit tested without a WordPress bootstrap.
 */
class GeneralPageData
{
    /**
     * Splits a get_top()-shaped row list into [shown, blurred] for the
     * free-tier row-gating pattern (top N real rows, rest blurred behind an
     * upsell footer). Pro accounts get every row and nothing blurred.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    public static function splitRows(array $rows, bool $isPro, int $freeRowsShown = 2): array
    {
        if ($isPro) {
            return [$rows, []];
        }

        return [
            array_slice($rows, 0, $freeRowsShown),
            array_slice($rows, $freeRowsShown),
        ];
    }

    /**
     * Sums the 'counthits' field across a get_top()-shaped row list, used as
     * the percentage-bar denominator.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public static function sumCounthits(array $rows): int
    {
        $total = 0;
        foreach ($rows as $aRow) {
            $total += (int) ($aRow['counthits'] ?? 0);
        }

        return $total;
    }

    /**
     * Bounce rate as a percentage, clamped to 100 for the same data-anomaly
     * reason wp_slimstat_db::get_visitors_summary() clamps it (a rate that
     * rounds above 99 is treated as fully bounced).
     */
    public static function bounceRate(int $totalHumanVisits, int $singlePageVisits): float
    {
        $bounceRate = ($totalHumanVisits > 0) ? (100 * $singlePageVisits / $totalHumanVisits) : 0;
        if (intval($bounceRate) > 99) {
            $bounceRate = 100;
        }

        return (float) $bounceRate;
    }

    /**
     * "Other referrers" = pageviews not already counted as Direct or Search.
     * Floored at 0: on real data the three counts already agree by
     * construction, but resource IS NULL / searchterms IS NOT NULL / total
     * pageviews are separate queries against a live, changing table, so a
     * negative rounding artifact must never render as a negative row.
     */
    public static function otherReferrersCount(int $pageviews, int $directCount, int $searchCount): int
    {
        return max(0, $pageviews - $directCount - $searchCount);
    }

    /**
     * Builds the "Where visitors come from" rows (Direct / Search / Other
     * referrers), dropping any bucket with a zero count so the box's own
     * empty-state can trigger when there is nothing real to show.
     *
     * The label key is 'referer_type', not a generic 'label': these rows are
     * consumed by wp_slimstat_reports::raw_results_to_html(), which reads each
     * row's label from the key named by the report's `columns` arg — so the
     * key and that arg have to agree (see slim_p10_03's registration).
     *
     * @return array<int, array{referer_type: string, counthits: int}>
     */
    public static function trafficRows(int $directCount, int $searchCount, int $otherReferrersCount, string $directLabel, string $searchLabel, string $otherLabel): array
    {
        return array_values(array_filter([
            ['referer_type' => $directLabel, 'counthits' => $directCount],
            ['referer_type' => $searchLabel, 'counthits' => $searchCount],
            ['referer_type' => $otherLabel, 'counthits' => $otherReferrersCount],
        ], static function (array $aRow): bool {
            return $aRow['counthits'] > 0;
        }));
    }

    /**
     * The previous-period range for a [start, end] window: same length,
     * immediately preceding, with its start normalized to local midnight.
     *
     * Mirrors \SlimStat\Modules\Chart::calculatePreviousArgs() EXACTLY (same
     * seconds-diff, same setTime(0,0,0) on start only) so the General page's
     * period-over-period badges describe the same "previous period" the
     * pageviews chart's dashed line does. Chart.php's version calls
     * \wp_timezone(), which needs WordPress loaded; this one takes the
     * timezone as a parameter (defaulting to UTC) so it stays unit-testable
     * with no WP bootstrap, same as the rest of this class. The view passes
     * wp_timezone() in production.
     *
     * @return array{start: int, end: int}
     */
    public static function previousPeriod(int $start, int $end, ?\DateTimeZone $timezone = null): array
    {
        $timezone ??= new \DateTimeZone('UTC');
        $rangeSeconds = $end - $start;

        $dtStart = (new \DateTime('now', $timezone))->setTimestamp($start);
        $dtEnd   = (new \DateTime('now', $timezone))->setTimestamp($end);

        $dtStart->modify(sprintf('-%s seconds', $rangeSeconds))->setTime(0, 0, 0);
        $dtEnd->modify(sprintf('-%s seconds', $rangeSeconds));

        return [
            'start' => $dtStart->getTimestamp(),
            'end'   => $dtEnd->getTimestamp(),
        ];
    }

    /**
     * Percent change from a previous-period value to the current one, for
     * the stat-box comparison badges. Null (not 0 or INF) when the previous
     * value is zero — "up from nothing" has no meaningful percentage, and
     * the view renders that as "New" rather than a misleading number.
     */
    public static function percentChange(float $current, float $previous): ?float
    {
        if (0.0 === $previous) {
            return null;
        }

        return (($current - $previous) / $previous) * 100;
    }

    /**
     * Synthetic row data for the free-tier "blurred" rows beyond the free
     * cap, so the page never sends real stats to the browser for content
     * that CSS merely hides (a `filter: blur()` on real numbers is
     * inspectable and un-blurrable via devtools — the numbers must not be
     * real in the first place). Deterministic (no random()) so a given box
     * renders the same fake rows on every load rather than flickering on
     * refresh, and descending so the bars still look like a real ranked
     * list. $seed offsets the synthetic curve per box so two boxes on the
     * same page don't render identical-looking fake rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fakeBlurredRows(int $count, string $field, string $labelPrefix, int $seed = 0): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            // A smoothly decaying, slightly wavy curve — looks like a real
            // ranked "top N" tail without being derived from real data.
            $value = max(1, (int) round(40 / ($i + 2 + $seed % 5) + 3 * sin($i + $seed)));
            $rows[] = [
                $field      => $labelPrefix . ' ' . ($i + 1),
                'counthits' => $value,
            ];
        }

        return $rows;
    }
}
