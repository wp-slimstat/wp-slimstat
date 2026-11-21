<?php
declare(strict_types=1);

namespace WpSlimstat\Tests\Unit;

require_once dirname(__DIR__) . '/perf/lib/scan-budget.php';

class ScanBudgetTest extends WpSlimstatTestCase
{
    public function test_only_reviewed_query_and_report_pairs_receive_a_budget(): void
    {
        $sql = 'SELECT COUNT(*) FROM wp_slim_stats WHERE dt >= 1795000000';
        $shape = \slimstat_scan_shape($sql, 'wp_', 'example.test');
        $budget = ['report' => 'report', 'sql' => $shape, 'max_ms' => 500, 'max_reads_per_row' => 2];
        self::assertSame($budget, \slimstat_scan_budget([$budget], $shape, 'report | interval equals -90'));
        self::assertNull(\slimstat_scan_budget([$budget], $shape, 'other report'));
        self::assertNull(\slimstat_scan_budget([$budget], $shape . ' OR 1=1', 'report'));
        self::assertSame($shape, \slimstat_scan_shape(str_replace('1795000000', '1796000000', $sql), 'wp_', 'example.test'));
        self::assertNotSame($shape, \slimstat_scan_shape(str_replace('>=', '<=', $sql), 'wp_', 'example.test'));
    }

    public function test_measured_work_and_time_are_both_required_for_all_three_samples(): void
    {
        $budget = ['max_ms' => 500, 'max_reads_per_row' => 2];
        $good = array_fill(0, 3, ['ms' => 100, 'reads' => 120010]);
        self::assertTrue(\slimstat_scan_within_budget($good, 120009, $budget));
        foreach ([['ms' => 501, 'reads' => 1], ['ms' => 1, 'reads' => 240119], ['ms' => NAN, 'reads' => 1], ['ms' => 1], ['ms' => -1, 'reads' => 1]] as $bad) {
            self::assertFalse(\slimstat_scan_within_budget([$good[0], $bad, $good[2]], 120009, $budget));
        }
        self::assertFalse(\slimstat_scan_within_budget([], 120009, $budget));
        self::assertFalse(\slimstat_scan_within_budget($good, 0, $budget));
    }
}
