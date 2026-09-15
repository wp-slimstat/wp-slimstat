<?php
declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Migration;

use Mockery;
use SlimStat\Migration\Migrations\AddUserAgentDimension;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

class UserAgentBackfillFailureTest extends WpSlimstatTestCase
{
    public function test_failed_dimension_insert_never_stamps_facts(): void
    {
        $this->assertFailedQueryStopsBeforeLaterWrites([false], 1);
    }

    public function test_failed_fact_update_stops_after_previously_completed_tuple(): void
    {
        $this->assertFailedQueryStopsBeforeLaterWrites([1, 1, 1, false], 4);
    }

    private function assertFailedQueryStopsBeforeLaterWrites(array $results, int $expectedQueries): void
    {
        $wpdb = Mockery::mock(\wpdb::class);
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $wpdb->rows_affected = 0;
        $wpdb->shouldReceive('prepare')->andReturnUsing(static fn ($sql) => $sql);
        $wpdb->shouldReceive('get_results')->once()->andReturn(array_map(static function ($browser) {
            return ['browser' => $browser, 'browser_version' => '1', 'browser_type' => 1, 'platform' => 'Linux'];
        }, ['first', 'second', 'third']));
        $queries = [];
        $wpdb->shouldReceive('query')->andReturnUsing(static function ($sql) use ($wpdb, &$queries, &$results) {
            $queries[] = $sql;
            $result = count($results) ? array_shift($results) : 1;
            $wpdb->rows_affected = $result === false ? 0 : 1;
            $wpdb->last_error = $result === false ? 'write refused' : '';
            return $result;
        });
        $wpdb->shouldReceive('get_var')->zeroOrMoreTimes()->andReturn('1');
        $migration = new AddUserAgentDimension($wpdb);
        $backfill = new \ReflectionMethod($migration, 'backfill');

        $this->assertFalse($backfill->invoke($migration), 'failed writes must not report a successful pass');
        $this->assertCount($expectedQueries, $queries, 'stop at the refused write; preserve earlier tuples and retry the remainder');
    }
}
