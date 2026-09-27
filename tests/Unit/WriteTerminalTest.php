<?php
/**
 * The tracker's write terminals — S6, C30 and C31.
 *
 * One mechanism, three recorded symptoms, all at Storage/Processor/Query::execute():
 *
 *   S6  During the window where the code is v6 and the schema is v5, an INSERT naming a
 *       column the table does not have fails, the retry fails identically, and the
 *       PAGEVIEW IS DROPPED — with the only trace overwritten by the next hit. The window
 *       is unbounded: auto-updates and WP-CLI produce no admin request, so a site whose
 *       owner does not log in for a week serves traffic in it for a week. There was no
 *       column-existence probe anywhere in the tree.
 *
 *       Decision P1, ratified 2026-07-31: insert the INTERSECTION of wanted and present
 *       columns. Dropping a pageview is worse than dropping a field.
 *
 *   C30 `Query::execute()` returns `insert_id ?: $result`, so a `0` — an INSERT IGNORE
 *       swallowing a duplicate, or a row an FK refused — is indistinguishable from success
 *       to any caller testing `false === $id`. It then propagates as `$stat['id'] = 0`,
 *       whose event insert violates the FK and is silently dropped. Under a dual write,
 *       "the new table write failed" and "it was a legitimate no-op" become the same value.
 *
 *   C31 `Storage::updateRow()` is the SECOND write path — every dt_out heartbeat, every
 *       `;;;` outbound append, every `[k:v]` note — and it discarded `execute()`'s result
 *       entirely, so a divergence there was not even representable.
 *
 * The probe is deliberately reactive: it runs only after an insert fails with an
 * unknown-column error, never preemptively. The tracker's budget is denominated in queries
 * and wp_options writes and neither may move, so the happy path must cost exactly what it
 * cost before.
 *
 * @package WpSlimstat
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit;

use Brain\Monkey\Functions;
use SlimStat\Tracker\Storage;
use SlimStat\Tracker\WriteResult;

class WriteTerminalTest extends WpSlimstatTestCase
{
    /** @var string[] Statements issued, in order. */
    private array $statements = [];

    /** @var mixed What the next query() reports. */
    private $queryResult = 1;

    /** @var int What the next insert reports as insert_id. */
    private int $insertId = 0;

    /** @var string[] Columns the fake table claims to have. */
    private array $presentColumns = [];

    /** @var callable|null Per-test override for what query() does; null uses the defaults. */
    private $onQuery = null;

    /** @var mixed */
    private $originalWpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stubCommonWpFunctions();

        $this->statements     = [];
        $this->queryResult    = 1;
        $this->insertId       = 42;
        $this->presentColumns = ['id', 'ip', 'resource', 'dt'];
        $this->onQuery        = null;
        $this->originalWpdb   = $GLOBALS['wpdb'] ?? null;

        // The shared stub in tests/Unit/Tracker/stubs.php already provides wp_slimstat
        // with a recording record_degradation(); its state is static, so it is reset here
        // rather than re-declared. (An earlier draft eval()'d a local class — unnecessary,
        // and it would have shadowed the stub the rest of the Tracker tests rely on.)
        \wp_slimstat::$degradations = [];

        Functions\when('sanitize_url')->alias(static fn($v) => $v);
        Functions\when('esc_sql')->alias(static fn($v) => $v);

        $wpdb             = \Mockery::mock(\wpdb::class);
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';
        $wpdb->insert_id  = 0;

        // Substitute placeholders one at a time rather than vsprintf'ing: Query passes its
        // args as a single array on some paths and variadically on others, and a strict
        // vsprintf throws on the mismatch instead of producing the SQL under test.
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($sql, ...$args) {
            $flat = (1 === count($args) && is_array($args[0])) ? $args[0] : $args;
            foreach ($flat as $arg) {
                $sql = preg_replace('/%[sdfF]/', is_numeric($arg) ? (string) $arg : "'" . $arg . "'", (string) $sql, 1);
            }
            return $sql;
        });
        // One handler, overridable per test via $this->onQuery — rather than re-mocking
        // query() mid-test, which needed Mockery::resetContainer() and would have torn down
        // every other expectation on the way past.
        $wpdb->shouldReceive('query')->andReturnUsing(function ($sql) use ($wpdb) {
            $this->statements[] = $sql;
            if (null !== $this->onQuery) {
                return ($this->onQuery)($sql, $wpdb, count($this->queries()));
            }
            $wpdb->insert_id = (int) $this->insertId;
            return $this->queryResult;
        });
        // The probe suppresses errors around itself — a fail-allowed read must not emit
        // wpdb's HTML error block into an anonymous tracking response.
        $wpdb->shouldReceive('suppress_errors')->andReturn(false);
        // The column probe reads the real table shape.
        $wpdb->shouldReceive('get_col')->andReturnUsing(function () {
            $this->statements[] = 'SHOW COLUMNS';
            return $this->presentColumns;
        });

        $GLOBALS['wpdb'] = $wpdb;
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->originalWpdb;
        parent::tearDown();
    }

    private function queries(): array
    {
        return array_values(array_filter($this->statements, static fn($s) => 'SHOW COLUMNS' !== $s));
    }

    private function probes(): int
    {
        return count(array_filter($this->statements, static fn($s) => 'SHOW COLUMNS' === $s));
    }

    // ── C30: a 0 is not a success ───────────────────────────────────────────

    public function test_a_swallowed_duplicate_is_ignored_not_inserted(): void
    {
        $this->queryResult = 0;   // INSERT IGNORE matched an existing row
        $this->insertId    = 0;

        $result = Storage::insertRow(['ip' => '1.2.3.4'], 'wp_slim_stats');

        $this->assertInstanceOf(WriteResult::class, $result);
        $this->assertSame(WriteResult::IGNORED, $result->state());
        $this->assertFalse($result->isStored(), 'a 0 from INSERT IGNORE is not a stored row');
        $this->assertSame(
            0,
            $result->id(),
            'and it must not hand back an id — 0 propagated as $stat["id"] = 0, whose event '
            . 'insert then violated the FK and was silently dropped'
        );
    }

    public function test_a_real_insert_reports_its_id(): void
    {
        $this->queryResult = 1;
        $this->insertId    = 4242;

        $result = Storage::insertRow(['ip' => '1.2.3.4'], 'wp_slim_stats');

        $this->assertSame(WriteResult::STORED, $result->state());
        $this->assertTrue($result->isStored());
        $this->assertSame(4242, $result->id());
    }

    public function test_a_hard_error_is_failed_and_distinct_from_ignored(): void
    {
        $this->queryResult                = false;
        $GLOBALS['wpdb']->last_error      = 'Deadlock found when trying to get lock';

        $result = Storage::insertRow(['ip' => '1.2.3.4'], 'wp_slim_stats');

        $this->assertSame(WriteResult::FAILED, $result->state());
        $this->assertTrue($result->isFailed());
        $this->assertNotSame(
            WriteResult::IGNORED,
            $result->state(),
            'failed and ignored must never be the same value — that conflation is C30'
        );
    }

    public function test_nothing_to_write_is_its_own_state(): void
    {
        $this->assertSame(WriteResult::IGNORED, Storage::insertRow([], 'wp_slim_stats')->state());
        $this->assertSame(WriteResult::IGNORED, Storage::insertRow(['ip' => '1'], '')->state());
    }

    // ── S6: the v6-code-on-v5-schema window ─────────────────────────────────

    public function test_an_unknown_column_retries_with_the_intersection_and_stores_the_row(): void
    {
        // The v6 tracker writes a column this v5 schema does not have yet.
        $this->presentColumns = ['id', 'ip', 'resource', 'dt'];

        // First attempt fails on the absent column; the retry succeeds.
        $this->onQuery = static function ($sql, $wpdb, $nth) {
            if (1 === $nth) {
                $wpdb->last_error = "Unknown column 'vid_hash' in 'field list'";
                return false;
            }
            $wpdb->last_error = '';
            $wpdb->insert_id  = 77;
            return 1;
        };

        $result = Storage::insertRow(
            // 32 hex chars, as Session actually produces: insertRow() VALIDATES vid_hash
            // before packing it to BINARY(16) and drops anything else — so the 8-char
            // 'deadbeef' this test originally used was discarded before the first INSERT,
            // the intersection had nothing to narrow, and the retry never fired. The test
            // failing on that change was correct behaviour on both sides.
            ['ip' => '1.2.3.4', 'resource' => '/x', 'dt' => 1, 'vid_hash' => str_repeat('deadbeef', 4)],
            'wp_slim_stats'
        );

        $this->assertSame(
            WriteResult::STORED,
            $result->state(),
            'P1: the pageview is stored with the fields that exist. Dropping a pageview is '
            . 'worse than dropping a field, and this window can last a week unattended'
        );
        $this->assertSame(77, $result->id());

        $retry = end($this->statements);
        $this->assertStringNotContainsString('vid_hash', $retry, 'the absent column must be dropped from the retry');
        $this->assertStringContainsString('ip', $retry, 'and the present ones must survive it');
    }

    public function test_the_degradation_is_recorded_once_not_per_hit(): void
    {
        $this->presentColumns = ['id', 'ip'];
        // Every hit hits the same absent column: odd attempts fail, even ones (the retry)
        // succeed. Three hits, so three chances to record the degradation three times.
        $this->onQuery = static function ($sql, $wpdb, $nth) {
            if (0 === $nth % 2) {
                $wpdb->last_error = '';
                $wpdb->insert_id  = 5;
                return 1;
            }
            $wpdb->last_error = "Unknown column 'vid_hash' in 'field list'";
            return false;
        };

        for ($i = 0; $i < 3; $i++) {
            Storage::insertRow(['ip' => '1.2.3.4', 'vid_hash' => 'x'], 'wp_slim_stats');
        }

        $this->assertLessThanOrEqual(
            1,
            count(\wp_slimstat::$degradations),
            'record_degradation() writes an option; per-hit it would be a wp_options write on '
            . 'the anonymous hot path, on exactly the sites already unhealthy'
        );
    }

    public function test_the_happy_path_never_probes(): void
    {
        $this->queryResult = 1;
        $this->insertId    = 9;

        Storage::insertRow(['ip' => '1.2.3.4'], 'wp_slim_stats');

        $this->assertSame(
            0,
            $this->probes(),
            'the probe is reactive by design — the tracker budget is denominated in queries '
            . 'and wp_options writes, and neither may move on the path that always runs'
        );
        $this->assertCount(1, $this->queries(), 'one INSERT, exactly as before');
    }

    public function test_an_unrelated_error_is_not_retried_as_a_schema_problem(): void
    {
        $this->queryResult           = false;
        $GLOBALS['wpdb']->last_error = 'Deadlock found when trying to get lock; try restarting transaction';

        $result = Storage::insertRow(['ip' => '1.2.3.4'], 'wp_slim_stats');

        $this->assertSame(WriteResult::FAILED, $result->state());
        $this->assertSame(0, $this->probes(), 'a deadlock is not a missing column');
        $this->assertCount(1, $this->queries(), 'and it must not be retried');
    }

    // ── C31: the second write path reports what it did ──────────────────────

    public function test_update_row_reports_its_outcome(): void
    {
        $this->queryResult = 1;

        $result = Storage::updateRow(['id' => 7, 'browser' => 'Firefox']);

        $this->assertInstanceOf(
            WriteResult::class,
            $result,
            'updateRow() discarded execute()\'s result entirely, so a divergence on the '
            . 'second write path — every dt_out heartbeat and note append — was not representable'
        );
        $this->assertSame(7, $result->id());
    }

    public function test_update_row_reports_a_failure_instead_of_the_id(): void
    {
        $this->queryResult           = false;
        $GLOBALS['wpdb']->last_error = 'Unknown column';

        $result = Storage::updateRow(['id' => 7, 'browser' => 'Firefox']);

        $this->assertSame(WriteResult::FAILED, $result->state());
    }

    public function test_update_row_with_nothing_to_write_is_skipped_not_failed(): void
    {
        $result = Storage::updateRow(['id' => 7]);

        $this->assertSame(WriteResult::IGNORED, $result->state());
        $this->assertSame(7, $result->id(), 'the row id is still the caller\'s answer');
        $this->assertCount(0, $this->queries(), 'and no invalid `UPDATE ... SET  WHERE` is emitted');
    }
}
