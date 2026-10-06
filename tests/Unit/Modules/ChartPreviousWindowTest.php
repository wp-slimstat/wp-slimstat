<?php
/**
 * The previous period must be the same LENGTH as the current one.
 *
 * ── The defect this pins ────────────────────────────────────────────────────────────────────
 *
 * Found by blind adjudication of the sealed OLD<->NEW comparison R20260824-2c8d1a. Two isolated
 * readers, each given one arm and told nothing, independently reported the same reconciliation
 * failure — and reported it in BOTH arms, which is why the byte-diff could not see it: a defect
 * present in both versions makes the two arms agree.
 *
 *     chart_weekly.totals[previous].v1 = 47552
 *     chart_weekly.datasets_prev.v1    = [875, 4963, 4684, 5281, 5612, 5833, 7282, 5824, 4723]
 *                                      = 45077
 *
 * 2,475 hits counted by the total and appearing in no bar. The current period reconciles exactly
 * (54324), and chart_daily reconciles for both periods, so it is one number out of four — and it
 * is the one that sets the headline: the chart claims the current period is up 14.2% when its own
 * bars say up 20.5%.
 *
 * ── The mechanism ───────────────────────────────────────────────────────────────────────────
 *
 * `Chart::calculatePreviousArgs()` shifts both ends back by the range and then snaps only the
 * START to midnight:
 *
 *     $dtStart->modify("-{$rangeSeconds} seconds")->setTime(0, 0, 0);   // snapped
 *     $dtEnd->modify("-{$rangeSeconds} seconds");                       // not snapped
 *
 * So the previous window is LONGER than the current one by the current start's time-of-day. The
 * totals are a SQL aggregate over that longer window, while the bars come from
 * `DataBuckets::addRow()`, which drops anything outside `[0, points)`:
 *
 *     if ($offset >= 0 && $offset < $this->points) { ... }
 *
 * The extra rows are inside the window, so the total counts them; they fall outside the bucket
 * range, so no bar shows them. Nothing reconciles the two, because the totals are passed INTO
 * DataBuckets and returned verbatim.
 *
 * The same asymmetry is why the previous labels came back on a different week grid from the
 * previous values — Sundays against Mondays in the run above.
 *
 * ── What this test asserts, and why that is the root ────────────────────────────────────────
 *
 * Not "the totals match the bars", which is the symptom and could be satisfied by making the
 * total a sum of whatever survived the clamp. The invariant is that "the previous period" means
 * a period of the same length immediately before this one — from which bucket alignment and
 * reconciliation both follow.
 *
 * 7.4-safe: reflection over a pure private method, no database, no WordPress state.
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Modules;

use SlimStat\Modules\Chart;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

class ChartPreviousWindowTest extends WpSlimstatTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // calculatePreviousArgs() opens with a bare `\wp_timezone();` whose return value is
        // discarded — it computes nothing and is the only WordPress call on the path. Stubbed
        // rather than removed here: deleting it is a separate change with its own reasoning.
        \Brain\Monkey\Functions\stubs([
            'wp_timezone' => static fn() => new \DateTimeZone('UTC'),
        ]);
    }

    /** @return array{start:int,end:int} */
    private function previousFor(int $start, int $end): array
    {
        $chart  = (new \ReflectionClass(Chart::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Chart::class, 'calculatePreviousArgs');
        $method->setAccessible(true);

        return $method->invoke($chart, ['start' => $start, 'end' => $end]);
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function windows(): array
    {
        return [
            // The shape the defect was measured on: a 60-day range whose start is NOT midnight,
            // which is what every live chart has, because the end is quantised to the minute and
            // the start is derived from it.
            'sixty days from mid-morning' => ['2026-06-25 09:17:00', '2026-08-24 09:17:00'],
            'thirty days from midnight'   => ['2026-07-25 00:00:00', '2026-08-24 00:00:00'],
            'seven days from late night'  => ['2026-08-17 23:59:00', '2026-08-24 23:59:00'],
            'one day'                     => ['2026-08-23 14:30:00', '2026-08-24 14:30:00'],
        ];
    }

    /**
     * @dataProvider windows
     */
    public function test_the_previous_period_is_the_same_length_as_the_current_one(string $from, string $to): void
    {
        $start = (new \DateTime($from, new \DateTimeZone('UTC')))->getTimestamp();
        $end   = (new \DateTime($to, new \DateTimeZone('UTC')))->getTimestamp();

        $previous = $this->previousFor($start, $end);

        $this->assertSame(
            $end - $start,
            $previous['end'] - $previous['start'],
            'the previous period is a different length from the current one, so its rows do not '
                . 'map onto the same number of buckets — the surplus is counted by the totals '
                . 'aggregate and dropped by DataBuckets::addRow()\'s [0, points) clamp, which is '
                . 'the 2,475 hits chart_weekly reported in a total and showed in no bar'
        );
    }

    /**
     * @dataProvider windows
     */
    public function test_the_previous_period_ends_where_the_current_one_begins(string $from, string $to): void
    {
        // The vacuity control for the case above: two windows of equal length prove nothing if
        // they do not abut. A previous period that floats anywhere would satisfy the length
        // assertion perfectly.
        $start = (new \DateTime($from, new \DateTimeZone('UTC')))->getTimestamp();
        $end   = (new \DateTime($to, new \DateTimeZone('UTC')))->getTimestamp();

        $previous = $this->previousFor($start, $end);

        $this->assertSame(
            $start,
            $previous['end'],
            'the previous period does not end where the current one starts, so the two either '
                . 'overlap (double-counting the seam) or leave a gap (losing it)'
        );
    }
}
