<?php
/**
 * The migration subsystem must run against the connection the tables are ON (C29),
 * and must ask WordPress core its questions on the connection core is on.
 *
 * MigrationService::init() built every migration from `global $wpdb`, while the
 * analytics tables live on wp_slimstat::$wpdb — a different server entirely when
 * `slimstat_custom_wpdb` is filtered. Two live failures came out of that:
 *
 *   - AbstractIndexMigration::shouldRun() probes `SHOW INDEX` on the main database,
 *     where the table does not exist. That is an ERROR, and $wpdb->get_var() returns
 *     null for an error exactly as it does for "no such index" — so empty(null) is
 *     true, the migration answers "yes, I must run", forever, and every click fails.
 *   - ConvertTablesToUtf8mb4::pendingTables() counts information_schema.COLUMNS
 *     WHERE TABLE_SCHEMA = DATABASE() on the main database, finds zero stale columns,
 *     and reports shouldRun() === false. getDiagnostics() then renders the target
 *     collation beside all four tables: the UI affirmatively states the conversion is
 *     complete on an install where nothing was converted.
 *
 * THE TRAP, and why the one-line fix is wrong: targetCollation() reads
 * wp_users.user_login, also via TABLE_SCHEMA = DATABASE(). Today that works, because
 * DATABASE() is the main database and wp_users is there. Injecting the analytics
 * handle everywhere would fix the two failures above and BREAK this one — wp_users is
 * not on the analytics server, so it would silently fall back to
 * FALLBACK_COLLATION (utf8mb4_unicode_ci) and convert the tables to a collation that
 * need not match the site's. That is ER_CANT_AGGREGATE_2COLLATIONS on the Pro user
 * join, which is fatal rather than slow, and is the precise failure ADR-5 exists to
 * prevent. So the fix is two handles, not one relabelled handle.
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Migration;

use SlimStat\Migration\MigrationService;
use SlimStat\Migration\Migrations\ConvertTablesToUtf8mb4;
use SlimStat\Migration\Migrations\CreateDtOutIndex;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

class ExternalDatabaseMigrationTest extends WpSlimstatTestCase
{
    /** @var mixed The global handle as it stood before this case ran. */
    private $originalGlobalWpdb;

    protected function setUp(): void
    {
        parent::setUp();
        // getDiagnostics() renders translated strings.
        \Brain\Monkey\Functions\when('__')->returnArg(1);
        \Brain\Monkey\Functions\when('_n')->alias(
            static fn($single, $plural, $n) => 1 === $n ? $single : $plural
        );
        $this->originalGlobalWpdb   = $GLOBALS['wpdb'] ?? null;
        \wp_slimstat::$degradations = [];
        \wp_slimstat::$wpdb         = null;
    }

    /**
     * Both handles are process-global statics, so a case that sets one and walks away
     * hands the next test a Mockery object standing in for the database. Measured:
     * leaving wp_slimstat::$wpdb populated made three cases in QueryGetVarCacheTest
     * fail — a suite that passed when either file ran alone.
     */
    protected function tearDown(): void
    {
        \wp_slimstat::$wpdb         = null;
        \wp_slimstat::$degradations = [];

        if (null === $this->originalGlobalWpdb) {
            unset($GLOBALS['wpdb']);
        } else {
            $GLOBALS['wpdb'] = $this->originalGlobalWpdb;
        }

        parent::tearDown();
    }

    /** Degradation steps the code under test reported during this case. */
    private function recordedDegradations(): array
    {
        return array_keys(\wp_slimstat::$degradations);
    }

    /**
     * A wpdb whose get_var() answers from a callback keyed on the SQL, so each test
     * describes a database rather than a sequence of return values.
     */
    private function fakeWpdb(string $prefix, callable $answer, string $lastError = ''): \wpdb
    {
        $wpdb = \Mockery::mock(\wpdb::class);
        $wpdb->prefix = $prefix;
        $wpdb->users  = $prefix . 'users';
        // wpdb sets this on every query and clears it at the start of the next one.
        // It is how "the query errored" is told apart from "the query found nothing",
        // which get_var() reports identically as null.
        $wpdb->last_error = $lastError;

        // Return the SQL with placeholders intact; the tests match on substrings, so
        // they stay readable and do not depend on wpdb's quoting.
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($sql) {
            return $sql;
        });
        $wpdb->shouldReceive('suppress_errors')->andReturn(false);
        $wpdb->shouldReceive('get_var')->andReturnUsing($answer);
        $wpdb->shouldReceive('get_row')->andReturnUsing($answer);

        return $wpdb;
    }

    // ── The probe contract ───────────────────────────────────────────────────

    /**
     * The external-DB shape: the analytics table is not on this connection at all.
     *
     * A probe that cannot READ the table must answer neither "clean" nor "dirty".
     * Answering "dirty" is what produces the permanent, unfixable migration notice.
     */
    public function test_index_probe_that_cannot_read_the_table_does_not_claim_it_must_run(): void
    {
        // SHOW INDEX against a table this connection cannot see: null, plus an error.
        $wpdb = $this->fakeWpdb('wp_', static fn($sql) => null, "Table 'main.wp_slim_stats' doesn't exist");

        $migration = new CreateDtOutIndex($wpdb);

        $this->assertFalse(
            $migration->shouldRun(),
            'An unreadable table must not be reported as needing a migration — that notice can never be cleared'
        );
        $this->assertNotEmpty(
            $this->recordedDegradations(),
            'A probe that could not reach its table must leave a trace, or the failure is invisible'
        );
    }

    /** Positive control: the probe still works when the table IS readable. */
    public function test_index_probe_reports_a_missing_index_on_a_readable_table(): void
    {
        $wpdb = $this->fakeWpdb('wp_', static fn($sql) => null);   // table present, index absent

        $this->assertTrue((new CreateDtOutIndex($wpdb))->shouldRun());
        $this->assertSame([], $this->recordedDegradations(), 'A readable table is not a degradation');
    }

    /** Positive control: an existing index is not re-created. */
    public function test_index_probe_reports_an_existing_index_as_done(): void
    {
        $this->assertFalse((new CreateDtOutIndex($this->fakeWpdb('wp_', static fn($sql) => 'idx_dt_out')))->shouldRun());
    }

    // ── The two-handle contract ──────────────────────────────────────────────

    /**
     * wp_users is a WordPress core table. It is on the CORE connection, never on the
     * analytics one, so the collation probe has to be asked there — otherwise the
     * conversion silently picks FALLBACK_COLLATION and arms ADR-5's fatal.
     */
    public function test_target_collation_is_read_from_the_core_connection(): void
    {
        $analytics = $this->fakeWpdb('wp_', static function ($sql) {
            if (false !== strpos($sql, 'user_login')) {
                throw new \RuntimeException('wp_users must not be asked on the analytics connection');
            }
            return null;
        });

        $core = $this->fakeWpdb('wp_', static function ($sql) {
            if (false !== strpos($sql, 'user_login')) {
                return 'utf8mb4_general_ci';
            }
            return null;
        });

        $migration = new ConvertTablesToUtf8mb4($analytics, $core);

        $this->assertSame(
            'utf8mb4_general_ci',
            $migration->targetCollation(),
            'The collation must come from the site\'s real wp_users, not the hardcoded fallback'
        );
    }

    /**
     * And the analytics questions stay on the analytics connection — the same fix
     * must not simply move every query to the core handle.
     */
    public function test_pending_tables_are_counted_on_the_analytics_connection(): void
    {
        $analytics_asked = false;

        $analytics = $this->fakeWpdb('wp_', static function ($sql) use (&$analytics_asked) {
            if (false !== strpos($sql, 'CHARACTER_SET_NAME')) {
                $analytics_asked = true;
                // 30 columns present, 3 of them still utf8mb3. `total` is what tells a
                // converted table apart from one that is not on this connection.
                return ['total' => '30', 'stale' => '3'];
            }
            return null;
        });

        $core = $this->fakeWpdb('wp_', static function ($sql) {
            if (false !== strpos($sql, 'CHARACTER_SET_NAME')) {
                throw new \RuntimeException('the stale-column count must not be asked on the core connection');
            }
            return 'utf8mb4_general_ci';
        });

        $this->assertTrue((new ConvertTablesToUtf8mb4($analytics, $core))->shouldRun());
        $this->assertTrue($analytics_asked, 'information_schema for slim_* must be read on the analytics connection');
    }

    /**
     * Table NAMES come from the core prefix even though the tables live on the
     * analytics connection.
     *
     * admin/index.php builds every schema statement as `$GLOBALS['wpdb']->prefix .
     * 'slim_stats'` and runs it on the analytics handle — so that is what created the
     * tables, and the migrations have to agree. Reading the prefix off the analytics
     * handle would look right and be wrong: `wpdb::$prefix` is '' until someone calls
     * set_prefix(), which the custom-database path does not always reach.
     */
    public function test_table_names_use_the_core_prefix_not_the_analytics_one(): void
    {
        $seen = [];

        $analytics = $this->fakeWpdb('', static function ($sql) use (&$seen) {
            $seen[] = $sql;
            return null;
        });
        $core = $this->fakeWpdb('wp_', static fn($sql) => null);

        (new CreateDtOutIndex($analytics, $core))->shouldRun();

        $this->assertNotSame([], $seen, 'the probe did not run at all');
        $this->assertStringContainsString(
            'wp_slim_stats',
            $seen[0],
            'the migration named the table with the analytics prefix, which is empty here — '
                . 'it must use the prefix the tables were created with'
        );
    }

    /**
     * The second live failure C29 names: the screen said the conversion was complete
     * on installs where the tables were not there at all.
     *
     * information_schema answers COUNT(*) = 0 both for a fully-converted table and for
     * one that does not exist, so counting only the stale columns cannot tell them
     * apart — and getDiagnostics() rendered the target collation beside every table
     * either way. The total-column count is the discriminator.
     */
    public function test_diagnostics_do_not_report_a_missing_table_as_converted(): void
    {
        $analytics = $this->fakeWpdb('wp_', static function ($sql) {
            if (false !== strpos($sql, 'CHARACTER_SET_NAME')) {
                return ['total' => '0', 'stale' => null];   // no such table on this connection
            }
            return null;
        });
        $core = $this->fakeWpdb('wp_', static fn($sql) => 'utf8mb4_general_ci');

        $migration = new ConvertTablesToUtf8mb4($analytics, $core);

        $this->assertFalse(
            $migration->shouldRun(),
            'There is nothing to convert on a connection that has no such table'
        );

        foreach ($migration->getDiagnostics() as $row) {
            $this->assertFalse(
                $row['exists'],
                sprintf('%s is absent, so the screen must not report it as converted', $row['table'])
            );
            $this->assertStringNotContainsString(
                'utf8mb4_',
                (string) $row['columns'],
                'a collation beside an absent table is the exact claim that was false'
            );
        }
    }

    // ── The wiring ───────────────────────────────────────────────────────────

    public function test_the_service_builds_migrations_on_the_analytics_connection(): void
    {
        $analytics             = $this->fakeWpdb('ext_', static fn($sql) => null);
        \wp_slimstat::$wpdb    = $analytics;
        $GLOBALS['wpdb']       = $this->fakeWpdb('wp_', static fn($sql) => null);

        $this->assertSame(
            $analytics,
            MigrationService::analyticsConnection(),
            'Migrations run DDL against the tables, and the tables can be on another server'
        );
        $this->assertSame(
            $GLOBALS['wpdb'],
            MigrationService::coreConnection(),
            'wp_users and the options table are always local'
        );
    }
}
