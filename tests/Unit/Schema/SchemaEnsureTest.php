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
     * @param array<string,array<string,string>> $columns Table name => column => SHOW COLUMNS Type.
     *                                          Absent means "exactly the manifest", which is what
     *                                          every pre-F4 case assumed without saying so.
     */
    private function db(array $tables, array $indexes, string $error = '', array $columns = [])
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

        // SHOW COLUMNS, for columnState(). Defaults to the manifest itself so the existing cases
        // — written before columns were probed at all — keep describing a healthy install rather
        // than suddenly reporting every column missing.
        $wpdb->shouldReceive('get_results')->andReturnUsing(
            static function ($sql, $output = null) use ($tables, $columns, &$probes) {
                $probes[] = $sql;

                if (!preg_match('/SHOW COLUMNS FROM `(.+)`/', $sql, $m)) {
                    return [];
                }

                $table = $m[1];
                if (!in_array($table, $tables, true)) {
                    return [];
                }

                if (isset($columns[$table])) {
                    $rows = [];
                    foreach ($columns[$table] as $field => $type) {
                        $rows[] = ['Field' => $field, 'Type' => $type];
                    }

                    return $rows;
                }

                $suffix = preg_replace('/^wp_/', '', $table);
                $rows   = [];
                foreach (Schema::columns($suffix) as $field => $definition) {
                    // Lower-cased and stripped to what SHOW COLUMNS actually returns, so the
                    // double cannot make a comparison pass that the server would fail.
                    $rows[] = ['Field' => $field, 'Type' => strtolower(explode(' ', $definition)[0])];
                }

                return $rows;
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
        // 14 on slim_stats (idx_vid_hash_dt joined for D68) + 2 on slim_events + 1 on
        // slim_events_archive + 2 on slim_user_agents. slim_stats_archive inherits
        // slim_stats' set via `like` but is not reconciled, so it contributes none.
        //
        // Written out, not derived. Deriving it from the manifest would make it true by
        // construction and it would stop catching anything — so a new table is REQUIRED to
        // update this number deliberately, which is the point.
        $this->assertCount(19, $report['present']);

        // One patterned SHOW TABLES for ALL tables, then per RECONCILED table one SHOW INDEX and
        // one SHOW COLUMNS: 1 + 4 + 4 (slim_events, slim_events_archive, slim_stats,
        // slim_user_agents). The archive of slim_stats carries reconcile => false, so it costs
        // nothing here.
        //
        // RAISED FROM 5 TO 9 DELIBERATELY, which is what this assertion is for. F4 added a column
        // read model and it costs one metadata read per reconciled table. Two things make that
        // affordable where the fourteen probes it replaced were not:
        //
        //   - ensure() is NOT on a per-request path. Its two callers are init_environment()
        //     (activation and the tracker's repair path) and run_schema_upgrade() (version-gated).
        //     The fourteen probes this budget was written against ran on every wp-admin request
        //     whose slimstat_*_indexed option was still unstamped.
        //   - SHOW COLUMNS reads metadata, not rows.
        //
        // The old path also made a separate information_schema lookup for the collation that was
        // always discarded; that is still gone. If this number rises again, ask those same two
        // questions before changing it.
        $this->assertCount(
            9,
            $this->probes,
            'a healthy install must cost one table probe plus one index probe and one column '
                . 'probe per reconciled table: ' . implode(' | ', $this->probes)
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

    /**
     * The ADD and the CREATE must render one column identically.
     *
     * This is the single fragment where a fresh install and an upgraded one could disagree about
     * the same column, and the disagreement would be invisible to the source gate: the manifest
     * would still hold exactly one declaration, and the two renderings of it would differ. That
     * is C39 in one line, which is why columnSql() exists rather than two sprintf() calls.
     */
    // ── F4: the column read model ────────────────────────────────────────────

    public function testEnsureSkipsAnIndexWhoseColumnsAreMissing(): void
    {
        // The upgrade gap D68 opens: `idx_vid_hash_dt` is in the manifest the moment the
        // release lands, but `vid_hash` reaches an upgraded install only when the
        // AddVisitIdentity migration runs. In between, ensure() runs on every version-gated
        // admin_init — and a CREATE INDEX naming a missing column fails on EVERY pass,
        // stamping failures for a state that is expected and self-healing. The index must be
        // SKIPPED and the skip reported, then built normally on the pass after the column
        // exists (the healthy() cases cover that half).
        $columns = [];
        foreach (Schema::columns('slim_stats') as $field => $definition) {
            if ('vid_hash' === $field) {
                continue;
            }
            $columns[$field] = strtolower(explode(' ', $definition)[0]);
        }

        [$tables, $indexes] = $this->healthy();
        // The index is absent from the table too — that is the whole situation.
        $indexes['wp_slim_stats'] = array_values(array_diff(
            $indexes['wp_slim_stats'],
            ['idx_vid_hash_dt']
        ));

        $report = Schema::ensure(
            $this->db($tables, $indexes, '', ['wp_slim_stats' => $columns]),
            'wp_',
            static fn() => 'utf8mb4_unicode_ci'
        );

        foreach ($this->queries as $sql) {
            $this->assertStringNotContainsString(
                'idx_vid_hash_dt',
                $sql,
                'ensure() must not try to build an index on a column the table does not have'
            );
        }

        $this->assertSame(
            ['wp_slim_stats.idx_vid_hash_dt' => 'vid_hash'],
            $report['indexes_skipped_missing_column'],
            'the skip must be REPORTED — silent is how a permanently missing index looks healthy'
        );
        $this->assertNotContains(
            'idx_vid_hash_dt',
            $report['failed'],
            'an expected, self-healing state is not a failure'
        );
    }

    public function testColumnStateReportsAHealthyTableAsFullyPresent(): void
    {
        [$tables, $indexes] = $this->healthy();

        $state = Schema::columnState($this->db($tables, $indexes), 'slim_stats', 'wp_');

        $this->assertSame([], $state['missing']);
        $this->assertSame([], $state['undeclared']);
        $this->assertSame([], $state['narrow']);
        $this->assertSame(array_keys(Schema::columns('slim_stats')), $state['present']);
    }

    public function testColumnStateNamesAMissingColumn(): void
    {
        // The ua_id shape (PITFALLS 30): declared in the manifest, absent from an install that
        // has not run the optional migration. Reportable now; reportable by nothing before.
        $columns = [];
        foreach (Schema::columns('slim_stats') as $field => $definition) {
            if ('ua_id' === $field) {
                continue;
            }
            $columns[$field] = strtolower(explode(' ', $definition)[0]);
        }

        [$tables, $indexes] = $this->healthy();
        $db = $this->db($tables, $indexes, '', ['wp_slim_stats' => $columns]);

        $this->assertSame(['ua_id'], Schema::columnState($db, 'slim_stats', 'wp_')['missing']);
    }

    public function testColumnStateNamesANarrowColumnButNotAWideOne(): void
    {
        // THE MEASURED CASE. The 4.8.2 upgrade block declared `email VARCHAR(255)` while the
        // manifest declares 256, so every install upgraded from below 4.8.2 is one character
        // short — permanently, because its version stamp means the repaired block never runs
        // again. Narrower truncates; wider is somebody else's ALTER and not a hazard.
        $columns = [];
        foreach (Schema::columns('slim_stats') as $field => $definition) {
            $columns[$field] = strtolower(explode(' ', $definition)[0]);
        }
        $columns['email']    = 'varchar(255)';
        $columns['username'] = 'varchar(512)';

        [$tables, $indexes] = $this->healthy();
        $state = Schema::columnState($this->db($tables, $indexes, '', ['wp_slim_stats' => $columns]), 'slim_stats', 'wp_');

        $this->assertArrayHasKey('email', $state['narrow']);
        $this->assertSame('255, declared 256', $state['narrow']['email']);
        $this->assertArrayNotHasKey('username', $state['narrow'], 'a WIDER column is not drift worth reporting');
    }

    public function testColumnStateIgnoresIntegerWidthsAcrossServerVersions(): void
    {
        // MySQL 8.0.19 REMOVED the display width from integer types. A column declared
        // `INT(10) UNSIGNED` reports as `int unsigned` on 8.x and `int(10) unsigned` on ADR-2's
        // 5.6 floor — so comparing them would flag every integer column on every 8.x install.
        // A normaliser that is right on one server and wrong on the other is a second parser
        // disagreeing with the first, which is this programme's most repeated defect.
        $columns = [];
        foreach (Schema::columns('slim_stats') as $field => $definition) {
            $columns[$field] = strtolower(explode(' ', $definition)[0]);
        }
        // NARROWER display widths, deliberately. `int unsigned` (8.0.19+, no width at all) is
        // skipped by any implementation and so proves nothing — the first version of this test
        // used it and a mutation that compared EVERY parenthesised number SURVIVED. A smaller
        // width is what a naive comparison reports as truncation and what a correct one ignores.
        $columns['dt']           = 'int(8) unsigned';
        $columns['content_id']   = 'bigint(12) unsigned';
        $columns['screen_width'] = 'smallint(4) unsigned';

        [$tables, $indexes] = $this->healthy();
        $state = Schema::columnState($this->db($tables, $indexes, '', ['wp_slim_stats' => $columns]), 'slim_stats', 'wp_');

        $this->assertSame([], $state['narrow'], 'an integer type must never be reported as drift');
        $this->assertSame([], $state['missing']);
    }

    public function testColumnStateReportsNothingWhenTheTableCannotBeRead(): void
    {
        // Same contract as indexState(): a probe that could not look is not an answer in either
        // direction. Reporting `missing` here would make a fresh external-DB install announce
        // its entire schema as absent.
        $state = Schema::columnState($this->db([], []), 'slim_stats', 'wp_');

        $this->assertSame([], $state['missing']);
        $this->assertSame([], $state['present']);
    }

    public function testColumnStateNamesAnUndeclaredColumn(): void
    {
        // `plugins` on an install that never ran the 4.8.4.1 drop. Not a hazard, but the
        // manifest cannot claim to describe a table it has not looked at.
        $columns = ['plugins' => 'varchar(255)'];
        foreach (Schema::columns('slim_stats') as $field => $definition) {
            $columns[$field] = strtolower(explode(' ', $definition)[0]);
        }

        [$tables, $indexes] = $this->healthy();
        $state = Schema::columnState($this->db($tables, $indexes, '', ['wp_slim_stats' => $columns]), 'slim_stats', 'wp_');

        $this->assertSame(['plugins'], $state['undeclared']);
    }

    public function testEnsureReportsColumnDriftWithoutRepairingIt(): void
    {
        // F4's whole design in one assertion: the drift is REPORTED and no ALTER is issued.
        // ensure() runs on admin_init, and rebuilding a 443k-row fact table there is the hazard
        // S7 removed.
        $columns = [];
        foreach (Schema::columns('slim_stats') as $field => $definition) {
            if ('ua_id' === $field) {
                continue;
            }
            $columns[$field] = strtolower(explode(' ', $definition)[0]);
        }
        $columns['email'] = 'varchar(255)';

        [$tables, $indexes] = $this->healthy();
        $db     = $this->db($tables, $indexes, '', ['wp_slim_stats' => $columns]);
        $report = Schema::ensure($db, 'wp_', static fn() => 'utf8mb4_unicode_ci');

        $this->assertContains('slim_stats.ua_id', $report['columns_missing']);
        $this->assertArrayHasKey('slim_stats.email', $report['columns_narrow']);

        foreach ($this->queries as $sql) {
            $this->assertStringNotContainsStringIgnoringCase(
                'ALTER TABLE',
                $sql,
                'ensure() must REPORT column drift, never repair it — this runs on admin_init'
            );
        }
    }

    public function testAddedColumnMatchesTheCreatedOne(): void
    {
        $created = Schema::createTableSql('slim_stats', 'wp_', 'utf8mb4_unicode_ci');

        foreach (['email', 'fingerprint', 'tz_offset', 'ua_id', 'vid_hash'] as $column) {
            $added = Schema::addColumnSql('slim_stats', $column, 'wp_');

            $this->assertStringStartsWith("ALTER TABLE `wp_slim_stats` ADD COLUMN {$column} ", $added);

            $fragment = substr($added, strlen('ALTER TABLE `wp_slim_stats` ADD COLUMN '));

            $this->assertStringContainsString(
                $fragment,
                $created,
                "the ALTER renders {$column} differently from the CREATE, so an upgraded install "
                    . 'and a fresh one end up with differently shaped tables'
            );
        }
    }

    public function testAfterIsOptionalAndNotDerivedFromDeclarationOrder(): void
    {
        // `tz_offset` is declared AFTER `ua_id` in the manifest and must be added AFTER
        // `outbound_resource` on a 4.8.4.1 upgrade, where `ua_id` does not exist yet. Deriving
        // position from the manifest would emit `AFTER ua_id` and fail on every such install.
        $this->assertSame(
            'ALTER TABLE `wp_slim_stats` ADD COLUMN tz_offset SMALLINT DEFAULT 0 AFTER outbound_resource',
            Schema::addColumnSql('slim_stats', 'tz_offset', 'wp_', 'outbound_resource')
        );

        $this->assertStringNotContainsString(
            'AFTER',
            Schema::addColumnSql('slim_stats', 'tz_offset', 'wp_'),
            'position is a caller\'s argument, not a manifest property'
        );
    }

    public function testTheArchiveFollowsSlimStatsThroughLike(): void
    {
        $this->assertSame(
            str_replace('wp_slim_stats', 'wp_slim_stats_archive', Schema::addColumnSql('slim_stats', 'email', 'wp_')),
            Schema::addColumnSql('slim_stats_archive', 'email', 'wp_'),
            'the archive is declared `like` slim_stats, so one column has one definition across '
                . 'both — the pair that shipped as VARCHAR(256) and VARCHAR(255) in adjacent lines'
        );
    }

    public function testAddingAnUndeclaredColumnIsRefused(): void
    {
        // PITFALLS 30, made unrepresentable. A migration that adds a column the manifest does not
        // know about means a fresh install is born without it and pays a fact-table ALTER to
        // catch up, while an upgraded one already has it.
        $this->expectException(\InvalidArgumentException::class);
        Schema::addColumnSql('slim_stats', 'no_such_column', 'wp_');
    }

    public function testDroppingADeclaredColumnIsRefused(): void
    {
        // The inverse, and just as silent: the upgrade path would remove a column every fresh
        // install is created with.
        $this->expectException(\InvalidArgumentException::class);
        Schema::dropColumnSql('slim_stats', 'fingerprint', 'wp_');
    }

    public function testDroppingARetiredColumnIsAllowed(): void
    {
        $this->assertSame(
            'ALTER TABLE `wp_slim_stats` DROP COLUMN plugins',
            Schema::dropColumnSql('slim_stats', 'plugins', 'wp_'),
            '`plugins` was retired in 4.8.4.1 and the manifest correctly does not declare it'
        );
    }
}
