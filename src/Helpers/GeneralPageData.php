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
     * @return array<int, array{label: string, counthits: int}>
     */
    public static function trafficRows(int $directCount, int $searchCount, int $otherReferrersCount, string $directLabel, string $searchLabel, string $otherLabel): array
    {
        return array_values(array_filter([
            ['label' => $directLabel, 'counthits' => $directCount],
            ['label' => $searchLabel, 'counthits' => $searchCount],
            ['label' => $otherLabel, 'counthits' => $otherReferrersCount],
        ], static function (array $aRow): bool {
            return $aRow['counthits'] > 0;
        }));
    }
}
