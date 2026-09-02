<?php
/**
 * A migration that adds a column must build the manifest indexes over it.
 *
 * `Schema::ensure()` skips an index naming a column that does not exist yet, and reports the
 * skip into `indexes_skipped_missing_column` — a key nothing reads. On an upgrading install
 * ensure() runs BEFORE the migration (it is called from the version-gated upgrade, which then
 * stamps the new version), so the index is skipped on the only pass ensure() gets. The next pass
 * is the next release. Until then an upgraded install lacks an index a fresh install has had
 * since `CREATE TABLE` — C39's fresh/upgraded divergence, arriving through the skip mechanism.
 *
 * The source-level gate (tests/upgrade-index-convergence-test.php) proves the CALL exists. This
 * proves the call DOES something: that the DDL fires when the index is missing, does not fire
 * when it is present, is skipped entirely for a table that declines reconciliation, and records
 * a degradation rather than throwing when the server refuses.
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Migration;

use Brain\Monkey\Functions;
use SlimStat\Migration\Migrations\AddVisitIdentity;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

class ReconcileColumnIndexesTest extends WpSlimstatTestCase
{
    use AddVisitIdentityDouble;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('update_option')->justReturn(true);
    }

    /** @test */
    public function test_a_missing_index_is_created_on_the_reconciling_table_only(): void
    {
        $wpdb = $this->addVisitIdentityDb(true, ['PRIMARY']); // idx_vid_hash_dt absent

        $statements = [];
        $wpdb->shouldReceive('query')->andReturnUsing(static function ($sql) use (&$statements) {
            $statements[] = $sql;

            return 1;
        });

        $this->assertTrue((new AddVisitIdentity($wpdb))->run());

        // Exactly one CREATE INDEX. `slim_stats_archive` declares the same index and reconciles
        // NONE of its indexes by manifest, so a second statement here would mean the helper had
        // started building the archive's whole declared index set on cold storage.
        $this->assertCount(1, $statements, 'expected exactly one index statement');
        $this->assertStringContainsString('idx_vid_hash_dt', $statements[0]);
        $this->assertStringContainsString('wp_slim_stats', $statements[0]);
        $this->assertStringNotContainsString('archive', $statements[0]);
    }

    /** @test */
    public function test_an_index_already_present_issues_no_ddl(): void
    {
        // Idempotence. The migration is re-runnable by construction, and a second pass must not
        // re-issue DDL against an index that is already there.
        $wpdb = $this->addVisitIdentityDb(true, ['PRIMARY', 'idx_vid_hash_dt']);
        $wpdb->shouldReceive('query')->never();

        $this->assertTrue((new AddVisitIdentity($wpdb))->run());
    }

    /** @test */
    public function test_an_unreadable_table_builds_nothing(): void
    {
        // indexState() returns an empty state when SHOW INDEX errors, so a table the connection
        // cannot read must produce no DDL — never a CREATE INDEX against a name it could not
        // confirm. `columnExists()` still reports true here because last_error is set only by the
        // index probe, which is the ordering the real wpdb produces.
        $wpdb = $this->addVisitIdentityDb(true, static function (\wpdb $db) {
            $db->last_error = "Table 'wordpress.wp_slim_stats' doesn't exist";

            return null;
        });
        // Asserted on INDEX statements, not on query() outright. The index probe is what sets
        // last_error here, so the archive's own ALTER runs afterwards and legitimately calls
        // query() -- a blanket never() would be asserting something this test does not mean.
        $statements = [];
        $wpdb->shouldReceive('query')->andReturnUsing(static function ($sql) use (&$statements) {
            $statements[] = $sql;

            return false;
        });

        (new AddVisitIdentity($wpdb))->run();

        $this->assertSame(
            [],
            array_values(array_filter($statements, static fn ($sql) => false !== stripos($sql, 'INDEX'))),
            'a table whose index probe errored must not have CREATE INDEX issued against it'
        );
    }

    /** @test */
    public function test_a_refused_index_records_a_degradation_and_does_not_fail_the_migration(): void
    {
        // The column is already in place by the time this runs, so the migration has succeeded at
        // the thing it exists to do. A missing index is slower, not broken — turning it into a
        // failed migration would re-offer an ALTER that already landed.
        $wpdb = $this->addVisitIdentityDb(true, ['PRIMARY']);
        $wpdb->shouldReceive('query')->andReturn(false);

        $this->assertTrue((new AddVisitIdentity($wpdb))->run());
    }

    /** @test */
    public function test_a_failed_archive_alter_does_not_withhold_the_live_table_index(): void
    {
        // THE REGRESSION. The first shape of this fix gated the index build on `$ok`, which is
        // the AND of both tables' ALTERs. An archive ALTER that fails -- bigger table, MyISAM,
        // a lock timeout -- then leaves `slim_stats` carrying `vid_hash` with no index, while
        // shouldRun() stays true and every retry re-fails on the archive without ever reaching
        // the index. The defect under repair, reached through the repair's own control flow.
        //
        // The double reports the column present on SHOW COLUMNS (so the live ALTER short-circuits
        // to success) and refuses every query -- which is what the archive's ALTER, its bare
        // retry, and the CREATE INDEX all are here. The assertion is that CREATE INDEX was
        // ATTEMPTED at all: run() returns false, and that must not suppress it.
        // The LIVE table reports vid_hash present (its ALTER short-circuits to success); the
        // ARCHIVE reports it absent, so its ALTER is attempted -- and refused, twice, because
        // every query() here returns false. That is the split the defect needs.
        $wpdb = $this->addVisitIdentityDb(
            static fn (string $sql): bool => false === strpos($sql, 'archive'),
            ['PRIMARY']
        );

        $statements = [];
        $wpdb->shouldReceive('query')->andReturnUsing(static function ($sql) use (&$statements) {
            $statements[] = $sql;

            return false; // every statement refused
        });

        $this->assertFalse((new AddVisitIdentity($wpdb))->run(), 'the run still fails overall');

        $index_statements = array_values(array_filter(
            $statements,
            static fn ($sql) => false !== strpos($sql, 'idx_vid_hash_dt')
        ));
        $this->assertCount(
            1,
            $index_statements,
            'the live index build must not wait on the archive ALTER'
        );
    }
}
