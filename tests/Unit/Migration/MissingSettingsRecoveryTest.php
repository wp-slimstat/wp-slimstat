<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Migration;

use SlimStat\Migration\MissingSettingsRecovery;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

class MissingSettingsRecoveryTest extends WpSlimstatTestCase
{
    public function testExistingEmptyTableIsNotAFreshInstall(): void
    {
        $db = \Mockery::mock(\wpdb::class);
        $db->last_error = '';
        $db->shouldReceive('suppress_errors')->with(true)->once()->andReturn(false);
        $db->shouldReceive('suppress_errors')->with(false)->once();
        $db->shouldReceive('prepare')->once()->andReturn('SHOW TABLES');
        $db->shouldReceive('get_col')->with('SHOW TABLES')->once()->andReturn(['wp_slim_stats']);
        $this->assertFalse(MissingSettingsRecovery::isFresh($db, 'wp_'));
    }

    public function testNoAnalyticsTablesIsFresh(): void
    {
        $db = \Mockery::mock(\wpdb::class);
        $db->last_error = '';
        $db->shouldReceive('suppress_errors')->andReturn(false);
        $db->shouldReceive('prepare')->once()->andReturn('SHOW TABLES');
        $db->shouldReceive('get_col')->once()->andReturn([]);
        $this->assertTrue(MissingSettingsRecovery::isFresh($db, 'wp_'));
    }

    public function testUnreadableDatabaseIsNotFresh(): void
    {
        $db = \Mockery::mock(\wpdb::class);
        $db->last_error = 'Permission denied';
        $db->shouldReceive('suppress_errors')->andReturn(false);
        $db->shouldReceive('prepare')->andReturn('SHOW TABLES');
        $db->shouldReceive('get_col')->andReturn(null);
        $this->assertFalse(MissingSettingsRecovery::isFresh($db, 'wp_'));
    }

    public function testRecoveryAddsOnlyMissingLegacyColumnsAndNeverDrops(): void
    {
        $queries = [];
        $db = \Mockery::mock(\wpdb::class);
        $db->last_error = '';
        $db->shouldReceive('suppress_errors')->andReturn(false);
        $db->shouldReceive('prepare')->andReturn('SHOW TABLES');
        $db->shouldReceive('get_col')->andReturn(['wp_2_slim_stats']);
        $columns = ['id', 'username', 'language', 'outbound_resource', 'email'];
        $db->shouldReceive('get_results')->andReturnUsing(static function () use (&$columns) {
            return array_map(static function ($name) { return ['Field' => $name]; }, $columns);
        });
        $db->shouldReceive('query')->andReturnUsing(static function ($sql) use (&$queries, &$columns) {
            $queries[] = $sql;
            preg_match('/ADD COLUMN `?(\w+)/', $sql, $match);
            $columns[] = $match[1];
            return 0;
        });
        $this->assertTrue(MissingSettingsRecovery::repairLegacyColumns($db, 'wp_2_'));
        $this->assertCount(2, $queries);
        $this->assertStringContainsString('fingerprint', $queries[0]);
        $this->assertStringContainsString('tz_offset', $queries[1]);
        $this->assertStringNotContainsString('DROP', implode(' ', $queries));
        $this->assertTrue(MissingSettingsRecovery::repairLegacyColumns($db, 'wp_2_'));
        $this->assertCount(2, $queries, 'second pass must not issue DDL');
    }

    public function testFailedMetadataNeverBecomesRepairSuccess(): void
    {
        $db = \Mockery::mock(\wpdb::class);
        $db->last_error = 'Permission denied';
        $db->shouldReceive('suppress_errors')->andReturn(false);
        $db->shouldReceive('prepare')->andReturn('SHOW TABLES');
        $db->shouldReceive('get_col')->andReturn(null);
        $db->shouldNotReceive('query');
        $this->assertFalse(MissingSettingsRecovery::repairLegacyColumns($db, 'wp_'));
    }
    public function testSuccessfulQueryWithoutColumnReadbackIsNotCompletion(): void
    {
        $db = \Mockery::mock(\wpdb::class);
        $db->last_error = '';
        $db->shouldReceive('suppress_errors')->andReturn(false);
        $db->shouldReceive('prepare')->andReturn('SHOW TABLES');
        $db->shouldReceive('get_col')->andReturn(['wp_slim_stats']);
        $db->shouldReceive('get_results')->andReturn([['Field' => 'id']]);
        $db->shouldReceive('query')->andReturn(0);
        $this->assertFalse(MissingSettingsRecovery::repairLegacyColumns($db, 'wp_'));
    }

    public function testExpiredBudgetIssuesNoDdl(): void
    {
        $db = \Mockery::mock(\wpdb::class);
        $db->last_error = '';
        $db->shouldReceive('suppress_errors')->andReturn(false);
        $db->shouldReceive('prepare')->andReturn('SHOW TABLES');
        $db->shouldReceive('get_col')->andReturn(['wp_slim_stats']);
        $db->shouldReceive('get_results')->andReturn([['Field' => 'id']]);
        $db->shouldNotReceive('query');
        $this->assertFalse(MissingSettingsRecovery::repairLegacyColumns($db, 'wp_', time() - 1));
    }

}
