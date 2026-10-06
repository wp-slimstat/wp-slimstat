<?php
declare(strict_types=1);
namespace WpSlimstat\Tests\Unit\Migration;

use SlimStat\Migration\Migrations\RepairLegacyColumnWidths;
use SlimStat\Schema\Schema;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

class RepairLegacyColumnWidthsTest extends WpSlimstatTestCase
{
    private function column(string $field, string $type = 'varchar(255)'): array
    {
        return ['Field' => $field, 'Type' => $type, 'Collation' => 'utf8mb4_unicode_ci',
            'Null' => 'YES', 'Default' => null, 'Extra' => '', 'Comment' => ''];
    }

    public function test_manifest_widening_preserves_supported_attributes_and_is_online_only(): void
    {
        $city = $this->column('city');
        $username = $this->column('username');
        $username['Null'] = 'NO';
        $username['Collation'] = 'utf8mb4_bin';
        $sql = Schema::widenLegacyColumnsSql('slim_stats', 'wp_', [$city, $username]);
        $this->assertStringContainsString('`city` VARCHAR(256) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_unicode_ci` NULL DEFAULT NULL', $sql);
        $this->assertStringContainsString('`username` VARCHAR(256) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_bin` NOT NULL', $sql);
        $this->assertStringContainsString('ALGORITHM=INPLACE, LOCK=NONE', $sql);
        $this->assertStringNotContainsString('COPY', $sql);
    }

    public function test_unknown_shapes_are_refused_before_ddl(): void
    {
        foreach (['Type' => 'varchar(254)', 'Default' => 'custom', 'Extra' => 'VIRTUAL GENERATED',
            'Comment' => 'custom comment', 'Collation' => 'unexpected`sql', 'Field' => 'email'] as $field => $value) {
            $column = $this->column('city');
            $column[$field] = $value;
            try {
                Schema::widenLegacyColumnsSql('slim_stats', 'wp_', [$column]);
                $this->fail('Accepted unsupported ' . $field);
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    private function migration(array &$tables, array &$queries, bool $apply = true, bool $refuse = false, bool $changeAttribute = false, string $engine = 'InnoDB'): RepairLegacyColumnWidths
    {
        $db = \Mockery::mock(\wpdb::class);
        $db->prefix = 'wp_';
        $db->last_error = '';
        $db->shouldReceive('suppress_errors')->andReturn(false);
        $db->shouldReceive('get_var')->with('SELECT @@SESSION.lock_wait_timeout')->andReturn('123');
        $db->shouldReceive('prepare')->andReturnUsing(static fn ($sql, $table) => str_replace('%s', "'" . $table . "'", $sql));
        $db->shouldReceive('get_var')->with(\Mockery::pattern('/^SELECT ENGINE/'))->andReturn($engine);
        $db->shouldReceive('get_results')->andReturnUsing(static function ($sql) use (&$tables) {
            preg_match('/`([^`]+)`/', $sql, $match);
            return $tables[$match[1]] ?? [];
        });
        $db->shouldReceive('query')->andReturnUsing(static function ($sql) use (&$tables, &$queries, $apply, $refuse, $changeAttribute) {
            $queries[] = $sql;
            if ($refuse && strpos($sql, 'ALTER TABLE') === 0) { return false; }
            if ($apply && preg_match('/ALTER TABLE `([^`]+)`/', $sql, $match)) {
                foreach ($tables[$match[1]] as &$column) {
                    if ('varchar(255)' === $column['Type']) { $column['Type'] = 'varchar(256)'; }
                    if ($changeAttribute) { $column['Collation'] = 'utf8mb4_bin'; }
                }
            }
            return 0;
        });
        return new RepairLegacyColumnWidths($db);
    }

    public function test_opt_in_repair_verifies_both_tables_and_is_idempotent(): void
    {
        $tables = ['wp_slim_stats' => [$this->column('city'), $this->column('username')],
            'wp_slim_stats_archive' => [$this->column('city'), $this->column('username')]];
        $queries = [];
        $migration = $this->migration($tables, $queries);
        $this->assertTrue($migration->isOptional());
        $this->assertTrue($migration->shouldRun());
        $this->assertSame([], $queries, 'offering the repair must not issue DDL');
        $this->assertTrue($migration->run());
        $this->assertCount(2, array_filter($queries, static fn ($sql) => strpos($sql, 'ALTER TABLE') === 0));
        $this->assertSame('SET SESSION lock_wait_timeout = 123', end($queries));
        $queries = [];
        $this->assertTrue($migration->run());
        $this->assertFalse($migration->shouldRun());
        $this->assertSame([], $queries);
    }

    public function test_truthy_ddl_without_changed_metadata_is_not_completion(): void
    {
        $tables = ['wp_slim_stats' => [$this->column('city'), $this->column('username')],
            'wp_slim_stats_archive' => [$this->column('city'), $this->column('username')]];
        $queries = [];
        $this->assertFalse($this->migration($tables, $queries, false)->run());
        $this->assertSame('SET SESSION lock_wait_timeout = 123', end($queries));
    }

    public function test_unexpected_width_blocks_the_entire_attempt(): void
    {
        $tables = ['wp_slim_stats' => [$this->column('city'), $this->column('username')],
            'wp_slim_stats_archive' => [$this->column('city', 'varchar(254)'), $this->column('username')]];
        $queries = [];
        $migration = $this->migration($tables, $queries);
        $this->assertFalse($migration->shouldRun());
        $this->assertFalse($migration->run());
        $this->assertSame([], $queries);
    }
    public function test_refused_online_ddl_never_retries_with_a_blocking_algorithm(): void
    {
        $tables = ['wp_slim_stats' => [$this->column('city'), $this->column('username')],
            'wp_slim_stats_archive' => [$this->column('city'), $this->column('username')]];
        $queries = [];
        $this->assertFalse($this->migration($tables, $queries, true, true)->run());
        $alters = array_values(array_filter($queries, static fn ($sql) => strpos($sql, 'ALTER TABLE') === 0));
        $this->assertCount(1, $alters);
        $this->assertStringContainsString('ALGORITHM=INPLACE, LOCK=NONE', $alters[0]);
        $this->assertSame('SET SESSION lock_wait_timeout = 123', end($queries));
    }

    public function test_changed_attributes_cannot_be_reported_as_success(): void
    {
        $tables = ['wp_slim_stats' => [$this->column('city'), $this->column('username')],
            'wp_slim_stats_archive' => [$this->column('city'), $this->column('username')]];
        $queries = [];
        $this->assertFalse($this->migration($tables, $queries, true, false, true)->run());
    }

    public function test_retry_only_changes_the_remaining_archive_table(): void
    {
        $tables = ['wp_slim_stats' => [$this->column('city', 'varchar(256)'), $this->column('username', 'varchar(256)')],
            'wp_slim_stats_archive' => [$this->column('city'), $this->column('username')]];
        $queries = [];
        $migration = $this->migration($tables, $queries);
        $this->assertTrue($migration->run());
        $alters = array_values(array_filter($queries, static fn ($sql) => strpos($sql, 'ALTER TABLE') === 0));
        $this->assertCount(1, $alters);
        $this->assertStringContainsString('wp_slim_stats_archive', $alters[0]);
    }

    public function test_missing_or_unreadable_table_cannot_trigger_a_partial_repair(): void
    {
        $tables = ['wp_slim_stats' => [$this->column('city'), $this->column('username')]];
        $queries = [];
        $migration = $this->migration($tables, $queries);
        $this->assertFalse($migration->shouldRun());
        $this->assertFalse($migration->run());
        $this->assertSame([], $queries);
    }

    public function test_non_innodb_tables_are_refused_without_ddl(): void
    {
        $tables = ['wp_slim_stats' => [$this->column('city'), $this->column('username')],
            'wp_slim_stats_archive' => [$this->column('city'), $this->column('username')]];
        $queries = [];
        $migration = $this->migration($tables, $queries, true, false, false, 'MyISAM');
        $this->assertFalse($migration->shouldRun());
        $this->assertFalse($migration->run());
        $this->assertSame([], $queries);
    }

}
