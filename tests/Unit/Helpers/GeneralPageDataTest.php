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
}
