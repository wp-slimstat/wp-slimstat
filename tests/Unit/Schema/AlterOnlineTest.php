<?php
declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Schema;

use SlimStat\Schema\Schema;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

class AlterOnlineTest extends WpSlimstatTestCase
{
    private function database(): \wpdb
    {
        $db = \Mockery::mock(\wpdb::class);
        $db->last_error = '';
        $db->shouldReceive('get_var')->with('SELECT @@SESSION.lock_wait_timeout')->andReturn('600');
        $db->shouldReceive('query')->with('SET SESSION lock_wait_timeout = 5')->once()->andReturn(0);
        $db->shouldReceive('query')->with('SET SESSION lock_wait_timeout = 600')->once()->andReturn(0);
        return $db;
    }

    public function test_appended_columns_try_instant_once(): void
    {
        $sql = 'ALTER TABLE wp_slim_stats ADD COLUMN vid_hash BINARY(16) NULL';
        $db = $this->database();
        $db->shouldReceive('query')->once()->with($sql . ', ALGORITHM=INSTANT')->andReturn(0);
        self::assertSame(0, Schema::alterOnline($db, $sql));
    }

    public function test_unsupported_instant_and_online_fall_back_in_order(): void
    {
        $sql = 'ALTER TABLE wp_slim_stats ADD COLUMN vid_hash BINARY(16) NULL';
        $db = $this->database();
        $db->shouldReceive('query')->once()->with($sql . ', ALGORITHM=INSTANT')->ordered()->andReturn(false);
        $db->shouldReceive('query')->once()->with($sql . ', ALGORITHM=INPLACE, LOCK=NONE')->ordered()->andReturn(false);
        $db->shouldReceive('query')->once()->with($sql)->ordered()->andReturn(0);
        self::assertSame(0, Schema::alterOnline($db, $sql));
    }

    public function test_refused_instant_can_succeed_online(): void
    {
        $sql = 'ALTER TABLE wp_slim_stats ADD COLUMN vid_hash BINARY(16) NULL';
        $db = $this->database();
        $db->shouldReceive('query')->once()->with($sql . ', ALGORITHM=INSTANT')->ordered()->andReturn(false);
        $db->shouldReceive('query')->once()->with($sql . ', ALGORITHM=INPLACE, LOCK=NONE')->ordered()->andReturn(0);
        self::assertSame(0, Schema::alterOnline($db, $sql));
    }

    public function test_indexes_and_mixed_alters_skip_instant(): void
    {
        foreach (['ADD INDEX x (dt)', 'ADD COLUMN x INT NULL, ADD INDEX x (x)', 'MODIFY COLUMN x BIGINT'] as $clause) {
            $sql = 'ALTER TABLE wp_slim_stats ' . $clause;
            $db = $this->database();
            $db->shouldReceive('query')->once()->with($sql . ', ALGORITHM=INPLACE, LOCK=NONE')->andReturn(0);
            self::assertSame(0, Schema::alterOnline($db, $sql));
        }
    }

    public function test_all_failures_remain_failure(): void
    {
        $db = $this->database();
        $db->shouldReceive('query')->with(\Mockery::pattern('/^ALTER TABLE /'))->times(3)->andReturn(false);
        self::assertFalse(Schema::alterOnline($db, 'ALTER TABLE wp_slim_stats ADD COLUMN x INT NULL'));
    }
}
