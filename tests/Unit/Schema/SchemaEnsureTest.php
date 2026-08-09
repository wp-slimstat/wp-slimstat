<?php
/**
 * Behavioural cover for the reconciler. The source-level gate proves there is exactly one
 * declaration of the schema; this proves that declaration reaches the database correctly.
 *
 * Four properties, each of which was a live defect in one of the nine creators this replaced:
 *
 *   1. A HEALTHY install issues no DDL and no writes. The old code ran fourteen single-index
 *      `SHOW INDEX … WHERE Key_name = 'x'` probes across six call sites and then stamped five
 *      options 'yes' unconditionally, on every activation and every upgrade pass.
 *   2. A MISSING index is created, and only that one. C39's four schema states all arise from
 *      creators that could add but never reconcile.
 *   3. An UNREADABLE table produces neither a build nor a stamp. `SHOW INDEX` against a table
 *      this connection cannot see is an ERROR, and get_col() answers [] for it exactly as it
 *      does for a table with no keys — the conflation that made all eight index migrations
 *      claim they had to run on an install with no tables at all (C41).
 *   4. A DISABLED optional group is not rebuilt. Settings -> Maintenance -> "Database Indexes"
 *      DROPs four indexes; a reconciler that put them straight back would turn a working
 *      toggle into a no-op that silently reverses itself.
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Schema;

use SlimStat\Schema\Schema;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

class SchemaEnsureTest extends WpSlimstatTestCase
{
    /** Statements the double was asked to run. */
    private $queries = [];

    /** Read-only probes (SHOW TABLES / SHOW INDEX) the double was asked. */
    private $probes = [];

    /**
     * A $wpdb double that answers SHOW TABLES / SHOW INDEX from a fixture and records the rest.
     *
     * @param string[] $tables  Full table names that exist.
     * @param array<string,string[]> $indexes Table name => key names present.
     * @param string $error     Non-empty to simulate a probe that could not reach the table.
     */
    private function db(array $tables, array $indexes, string $error = '')
    {
        $this->queries = [];
        $this->probes  = [];
        $queries       = &$this->queries;
        $probes        = &$this->probes;

        $wpdb             = \Mockery::mock(\wpdb::class);
        $wpdb->last_error = $error;

        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($sql, ...$args) {
            foreach ($args as $arg) {
                $sql = preg_replace('/%s/', "'" . $arg . "'", $sql, 1);
            }

            return $sql;
        });
        $wpdb->shouldReceive('suppress_errors')->andReturn(false);

        $wpdb->shouldReceive('get_col')->andReturnUsing(
            static function ($sql, $column = 0) use ($tables, $indexes, &$probes) {
                $probes[] = $sql;

                if (preg_match("/SHOW TABLES LIKE '(.+)'/", $sql, $m)) {
                    // Emulate LIKE, because ensure() now asks one patterned question for all
                    // four tables and a separate exact one to verify a CREATE. A double that
                    // only understood exact names would answer "no tables exist" to the bulk
                    // probe and the reconciler would look correct while doing nothing.
                    $like = '/^' . str_replace(
                        ['\\\\_', '%'],
                        ['_', '.*'],
                        preg_quote($m[1], '/')
                    ) . '$/';

                    return array_values(array_filter(
                        $tables,
                        static function ($table) use ($like) {
                            return (bool) preg_match($like, $table);
                        }
                    ));
                }

                if (preg_match('/SHOW INDEX FROM `(.+)`/', $sql, $m)) {
                    return $indexes[$m[1]] ?? [];
                }

                return [];
            }
        );

        $wpdb->shouldReceive('query')->andReturnUsing(static function ($sql) use (&$queries) {
            $queries[] = $sql;

            return 1;
        });

        return $wpdb;
    }

    /** Every table present, every manifest index present. */
    private function healthy(): array
    {
        $tables  = [];
        $indexes = [];

        foreach (Schema::tables() as $suffix) {
            $table            = 'wp_' . $suffix;
            $tables[]         = $table;
            $indexes[$table]  = [];

            foreach (array_keys(Schema::indexes($suffix)) as $name) {
                $indexes[$table][] = Schema::resolve($name, 'wp_');
            }
        }

        return [$tables, $indexes];
    }

    public function testHealthyInstallIssuesNoDdl(): void
    {
        [$tables, $indexes] = $this->healthy();

        $report = Schema::ensure($this->db($tables, $indexes), 'wp_', static fn() => 'utf8mb4_unicode_ci');

        $this->assertSame([], $this->queries, 'a fully reconciled install must issue no DDL');
        $this->assertSame([], $report['tables']);
        $this->assertSame([], $report['indexes']);
        $this->assertSame([], $report['failed']);
        // 13 on slim_stats + 2 on slim_events + 1 on slim_events_archive + 2 on
        // slim_user_agents. slim_stats_archive inherits slim_stats' set via `like` but is not
        // reconciled, so it contributes none.
        //
        // Written out, not derived. Deriving it from the manifest would make it true by
        // construction and it would stop catching anything — so a new table is REQUIRED to
        // update this number deliberately, which is the point.
        $this->assertCount(18, $report['present']);

        // One patterned SHOW TABLES for ALL tables, then one SHOW INDEX per RECONCILED table:
        // 1 + 4 (slim_events, slim_events_archive, slim_stats, slim_user_agents). The archive of
        // slim_stats carries reconcile => false, so it costs nothing here.
        //
        // The old path issued fourteen single-index probes across six call sites, plus a
        // separate information_schema lookup for the collation that was always discarded. This
        // budget is the reason Layer 1 can add a dimension for ONE extra probe rather than one
        // per index.
        $this->assertCount(
            5,
            $this->probes,
            "a healthy install must cost one table probe plus one index probe per reconciled "
                . 'table: ' . implode(' | ', $this->probes)
        );
    }

    public function testCollationIsNotResolvedWhenNothingNeedsCreating(): void
    {
        [$tables, $indexes] = $this->healthy();
        $resolved           = 0;

        Schema::ensure(
            $this->db($tables, $indexes),
            'wp_',
            static function () use (&$resolved) {
                $resolved++;

                return 'utf8mb4_unicode_ci';
            }
        );

        // Resolving costs an information_schema.COLUMNS query — the heaviest statement on this
        // path — and it is consumed only by CREATE TABLE. Passed by value it was paid on every
        // healthy install, on each version-gated upgrade pass, each new blog, each repair.
        $this->assertSame(0, $resolved);
    }

    public function testMissingIndexIsCreatedAndOnlyThatOne(): void
    {
        [$tables, $indexes] = $this->healthy();

        $indexes['wp_slim_stats'] = array_values(array_diff(
            $indexes['wp_slim_stats'],
            ['idx_dt_platform']
        ));

        $report = Schema::ensure($this->db($tables, $indexes), 'wp_', static fn() => 'utf8mb4_unicode_ci');

        $this->assertSame(
            ['CREATE INDEX idx_dt_platform ON wp_slim_stats (dt, platform)'],
            $this->queries
        );
        $this->assertSame(['idx_dt_platform'], $report['indexes']);
        $this->assertContains('idx_dt_platform', $report['present']);
    }

    public function testMissingTableIsCreatedAtTheTargetEngineAndCollation(): void
    {
        $report = Schema::ensure($this->db([], []), 'wp_', static fn() => 'utf8mb4_unicode_520_ci');

        // Nothing exists and nothing can be created against the double, so every table is
        // reported failed rather than silently assumed present — the verify-after-create that
        // `CREATE TABLE IF NOT EXISTS` cannot give you, since it answers 0 both for "already
        // there" and for a statement the server refused.
        $this->assertSame([], $report['tables']);
        // Five: slim_events, slim_events_archive, slim_stats, slim_stats_archive and the
        // slim_user_agents dimension. Deliberately updated when Layer 1 landed.
        $this->assertCount(5, $report['failed']);

        $create = implode("\n", $this->queries);
        $this->assertStringContainsString('ENGINE=InnoDB', $create);
        $this->assertStringContainsString('COLLATE utf8mb4_unicode_520_ci', $create);
        $this->assertStringNotContainsString('utf8_general_ci', $create);
    }

    public function testUnreadableTableProducesNeitherBuildNorStamp(): void
    {
        [$tables] = $this->healthy();

        // Tables exist; the index probe errors. Answering "nothing is indexed" here would issue
        // thirteen CREATE INDEX statements against a table this connection cannot read.
        $report = Schema::ensure(
            $this->db($tables, [], 'Table \'x.wp_slim_stats\' doesn\'t exist'),
            'wp_',
            static fn() => 'utf8mb4_unicode_ci'
        );

        $this->assertSame([], $this->queries, 'an unreadable table must not be written to');
        $this->assertSame([], $report['present'], 'and must not be reported as reconciled');
    }

    public function testDisabledOptionalGroupIsNotRebuilt(): void
    {
        [$tables, $indexes] = $this->healthy();

        $group = [];
        foreach (Schema::optionalGroup('db_indexes') as [$suffix, $index]) {
            $this->assertSame('slim_stats', $suffix);
            $group[] = Schema::resolve($index, 'wp_');
        }

        $indexes['wp_slim_stats'] = array_values(array_diff($indexes['wp_slim_stats'], $group));

        $report = Schema::ensure(
            $this->db($tables, $indexes),
            'wp_',
            static fn() => 'utf8mb4_unicode_ci',
            ['db_indexes']
        );

        $this->assertSame(
            [],
            $this->queries,
            'the Database Indexes toggle DROPs these four; rebuilding them makes the toggle a '
                . 'no-op that reverses itself on the next admin_init'
        );

        foreach ($group as $index) {
            $this->assertNotContains($index, $report['present']);
        }
    }

    public function testCreationOrderSatisfiesEveryDeclaredDependency(): void
    {
        $created = [];

        foreach (Schema::creationOrder() as $suffix) {
            $sql = Schema::createTableSql($suffix, 'wp_', 'utf8mb4_unicode_ci');

            if (preg_match('/LIKE `wp_(\w+)`/', $sql, $m)) {
                $this->assertContains($m[1], $created, "{$suffix} is created LIKE {$m[1]}, which does not exist yet");
            }

            if (preg_match('/REFERENCES wp_(\w+)\(/', $sql, $m)) {
                $this->assertContains($m[1], $created, "{$suffix} has an FK to {$m[1]}, which does not exist yet");
            }

            $created[] = $suffix;
        }

        // The drop order is the FK-safe one and is NOT the reverse of the above: creating is
        // additionally constrained by LIKE. Pin that they differ, so a future "tidy-up" that
        // makes one array_reverse() of the other fails here instead of on a fresh install.
        $this->assertNotSame(
            array_reverse(Schema::tables()),
            Schema::creationOrder(),
            'creation order is constrained by LIKE as well as by the FK, so it cannot be the '
                . 'reverse of the drop order'
        );
    }
}
