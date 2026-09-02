<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use SlimStat\Helpers\GeneralPageData;

/**
 * Pure data-shaping for the General landing page (admin/view/general.php):
 * free-tier row gating, percentage-bar denominators, bounce-rate clamping,
 * and the Direct/Search/Other-referrers row builder. No WordPress functions
 * involved, so this is tested directly without Brain Monkey stubs.
 */
class GeneralPageDataTest extends TestCase
{
    private const ROWS = [
        ['resource' => '/a', 'counthits' => 50],
        ['resource' => '/b', 'counthits' => 30],
        ['resource' => '/c', 'counthits' => 15],
        ['resource' => '/d', 'counthits' => 5],
    ];

    public function testSplitRowsShowsOnlyTheFreeTierCountOnFree(): void
    {
        [$shown, $blurred] = GeneralPageData::splitRows(self::ROWS, false);

        $this->assertCount(2, $shown);
        $this->assertCount(2, $blurred);
        $this->assertSame(['/a', '/b'], array_column($shown, 'resource'));
        $this->assertSame(['/c', '/d'], array_column($blurred, 'resource'));
    }

    public function testSplitRowsShowsEverythingOnPro(): void
    {
        [$shown, $blurred] = GeneralPageData::splitRows(self::ROWS, true);

        $this->assertSame(self::ROWS, $shown);
        $this->assertSame([], $blurred);
    }

    public function testSplitRowsHandlesEmptyInput(): void
    {
        [$shown, $blurred] = GeneralPageData::splitRows([], false);

        $this->assertSame([], $shown);
        $this->assertSame([], $blurred);
    }

    public function testSplitRowsHonorsACustomFreeRowCount(): void
    {
        [$shown, $blurred] = GeneralPageData::splitRows(self::ROWS, false, 1);

        $this->assertCount(1, $shown);
        $this->assertCount(3, $blurred);
    }

    public function testSumCounthitsTotalsTheColumn(): void
    {
        $this->assertSame(100, GeneralPageData::sumCounthits(self::ROWS));
    }

    public function testSumCounthitsHandlesEmptyAndMissingKeys(): void
    {
        $this->assertSame(0, GeneralPageData::sumCounthits([]));
        $this->assertSame(0, GeneralPageData::sumCounthits([['resource' => '/x']]));
    }

    public function testBounceRateIsZeroWithNoVisits(): void
    {
        $this->assertSame(0.0, GeneralPageData::bounceRate(0, 0));
    }

    public function testBounceRateComputesThePercentage(): void
    {
        $this->assertSame(50.0, GeneralPageData::bounceRate(100, 50));
    }

    /**
     * Same clamp wp_slimstat_db::get_visitors_summary() applies: a rate that
     * would round above 99 (a single-page-visit count that, from a data
     * anomaly, exceeds the total visit count) reports as fully bounced
     * rather than an out-of-range percentage.
     */
    public function testBounceRateClampsAboveNinetyNineToOneHundred(): void
    {
        $this->assertSame(100.0, GeneralPageData::bounceRate(10, 15));
    }

    public function testBounceRateExactlyOneHundredStaysOneHundred(): void
    {
        $this->assertSame(100.0, GeneralPageData::bounceRate(10, 10));
    }

    public function testOtherReferrersCountIsThePageviewsRemainder(): void
    {
        $this->assertSame(30, GeneralPageData::otherReferrersCount(100, 40, 30));
    }

    /**
     * Direct/Search/pageviews are three separate live queries against a
     * changing table, so their sum can transiently exceed the pageviews
     * total — the remainder must never render as a negative row.
     */
    public function testOtherReferrersCountFloorsAtZero(): void
    {
        $this->assertSame(0, GeneralPageData::otherReferrersCount(100, 60, 60));
    }

    public function testTrafficRowsDropsZeroCountBuckets(): void
    {
        $rows = GeneralPageData::trafficRows(0, 0, 0, 'Direct', 'Search', 'Other referrers');

        $this->assertSame([], $rows);
    }

    public function testTrafficRowsKeepsOnlyNonZeroBuckets(): void
    {
        $rows = GeneralPageData::trafficRows(10, 0, 0, 'Direct', 'Search', 'Other referrers');

        $this->assertCount(1, $rows);
        $this->assertSame('Direct', $rows[0]['label']);
        $this->assertSame(10, $rows[0]['counthits']);
    }

    public function testTrafficRowsKeepsAllThreeInOrderWhenAllPresent(): void
    {
        $rows = GeneralPageData::trafficRows(10, 5, 3, 'Direct', 'Search', 'Other referrers');

        $this->assertSame(['Direct', 'Search', 'Other referrers'], array_column($rows, 'label'));
        $this->assertSame([10, 5, 3], array_column($rows, 'counthits'));
    }

    /**
     * Mirrors \SlimStat\Modules\Chart::calculatePreviousArgs(): a normal
     * multi-day range shifts back by its own length, both ends normalized to
     * UTC midnight on the start only.
     */
    public function testPreviousPeriodShiftsBackByTheRangeLength(): void
    {
        // 2026-01-08 00:00:00 UTC .. 2026-01-10 00:00:00 UTC (2-day range)
        $start = gmmktime(0, 0, 0, 1, 8, 2026);
        $end   = gmmktime(0, 0, 0, 1, 10, 2026);

        $previous = GeneralPageData::previousPeriod($start, $end);

        $this->assertSame(gmmktime(0, 0, 0, 1, 6, 2026), $previous['start']);
        $this->assertSame(gmmktime(0, 0, 0, 1, 8, 2026), $previous['end']);
    }

    /**
     * A range whose start is mid-day gets normalized to midnight on the
     * previous-period start — the end is shifted but NOT time-normalized,
     * matching calculatePreviousArgs() exactly (setTime(0,0,0) is called
     * only on $dtStart).
     */
    public function testPreviousPeriodNormalizesOnlyTheStartToMidnight(): void
    {
        $start = gmmktime(14, 30, 0, 6, 15, 2026);
        $end   = gmmktime(9, 0, 0, 6, 16, 2026);

        $previous = GeneralPageData::previousPeriod($start, $end);

        $this->assertSame(gmmktime(0, 0, 0, 6, 14, 2026), $previous['start']);
        $this->assertSame(gmmktime(14, 30, 0, 6, 15, 2026), $previous['end']);
    }

    /**
     * The previous period is symmetric with the current one: its length
     * (before the start-of-day normalization can shift it slightly) matches,
     * and it ends exactly where the current period begins.
     */
    public function testPreviousPeriodEndsWhereTheCurrentPeriodBegins(): void
    {
        $start = gmmktime(0, 0, 0, 3, 1, 2026);
        $end   = gmmktime(0, 0, 0, 3, 8, 2026);

        $previous = GeneralPageData::previousPeriod($start, $end);

        $this->assertSame($start, $previous['end']);
    }

    public function testPreviousPeriodAcceptsAnExplicitTimezone(): void
    {
        $tz    = new \DateTimeZone('America/New_York');
        $start = gmmktime(0, 0, 0, 5, 8, 2026);
        $end   = gmmktime(0, 0, 0, 5, 10, 2026);

        $previous = GeneralPageData::previousPeriod($start, $end, $tz);

        // Midnight America/New_York, not midnight UTC — must differ from
        // the UTC-default result for the same inputs.
        $utcPrevious = GeneralPageData::previousPeriod($start, $end);
        $this->assertNotSame($utcPrevious['start'], $previous['start']);
    }

    public function testPercentChangeComputesTheDelta(): void
    {
        $this->assertSame(20.0, GeneralPageData::percentChange(120, 100));
        $this->assertSame(-25.0, GeneralPageData::percentChange(75, 100));
    }

    public function testPercentChangeIsNullWhenPreviousIsZero(): void
    {
        $this->assertNull(GeneralPageData::percentChange(50, 0));
    }

    public function testPercentChangeIsZeroWhenUnchanged(): void
    {
        $this->assertSame(0.0, GeneralPageData::percentChange(50, 50));
    }

    public function testFakeBlurredRowsReturnsTheRequestedCount(): void
    {
        $rows = GeneralPageData::fakeBlurredRows(8, 'resource', 'Page', 0);

        $this->assertCount(8, $rows);
        foreach ($rows as $row) {
            $this->assertArrayHasKey('resource', $row);
            $this->assertArrayHasKey('counthits', $row);
            $this->assertIsInt($row['counthits']);
            $this->assertGreaterThan(0, $row['counthits']);
        }
    }

    public function testFakeBlurredRowsIsDeterministicForTheSameSeed(): void
    {
        $first  = GeneralPageData::fakeBlurredRows(5, 'country', 'Region', 2);
        $second = GeneralPageData::fakeBlurredRows(5, 'country', 'Region', 2);

        $this->assertSame($first, $second);
    }

    public function testFakeBlurredRowsDiffersAcrossSeeds(): void
    {
        $a = GeneralPageData::fakeBlurredRows(5, 'country', 'Region', 0);
        $b = GeneralPageData::fakeBlurredRows(5, 'country', 'Region', 3);

        $this->assertNotSame($a, $b);
    }

    public function testFakeBlurredRowsHandlesZeroCount(): void
    {
        $this->assertSame([], GeneralPageData::fakeBlurredRows(0, 'resource', 'Page'));
    }
}
