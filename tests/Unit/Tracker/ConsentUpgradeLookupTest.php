<?php
/**
 * S1 (release review 2026-10-13): the consent-upgrade lookup OR'd `vid_hash = UNHEX(…)` into
 * its identity clause unconditionally. Before AddVisitIdentity adds the column, MySQL rejects
 * the whole statement (1054), the ip/visit_id match that used to claim the anonymous row is
 * lost, and the upgrade inserts a duplicate visit.
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Tracker;

use SlimStat\Tracker\Processor;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

class ConsentUpgradeLookupTest extends WpSlimstatTestCase
{
    /** @var mixed */
    private $originalWpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalWpdb = $GLOBALS['wpdb'] ?? null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->originalWpdb;
        parent::tearDown();
    }

    /** A fresh double per case: Storage memoises columns per connection object. */
    private function match(array $columns, string $vidHash): ?string
    {
        $wpdb = \Mockery::mock(\wpdb::class);
        $wpdb->shouldReceive('suppress_errors')->andReturn(false);
        $wpdb->shouldReceive('get_col')->with('SHOW COLUMNS FROM `wp_slim_stats`')->andReturn($columns);
        $wpdb->shouldReceive('prepare')->andReturnUsing(static fn($sql, $arg) => str_replace('%s', "'" . $arg . "'", $sql));
        $GLOBALS['wpdb'] = $wpdb;

        $ref = new \ReflectionMethod(Processor::class, 'vidHashMatch');
        $ref->setAccessible(true);
        return $ref->invoke(null, 'wp_slim_stats', $vidHash);
    }

    public function test_pre_migration_schema_leaves_the_vid_hash_disjunct_out(): void
    {
        self::assertNull($this->match(['id', 'ip', 'visit_id', 'dt'], str_repeat('a', 32)));
    }

    public function test_migrated_schema_matches_on_the_identity(): void
    {
        self::assertSame("vid_hash = UNHEX('" . str_repeat('a', 32) . "')", $this->match(['id', 'ip', 'visit_id', 'dt', 'vid_hash'], str_repeat('a', 32)));
    }

    public function test_an_unreadable_table_is_treated_as_pre_migration(): void
    {
        self::assertNull($this->match([], str_repeat('a', 32)));
    }

    public function test_no_identity_means_no_disjunct(): void
    {
        self::assertNull($this->match(['vid_hash'], ''));
    }
}
