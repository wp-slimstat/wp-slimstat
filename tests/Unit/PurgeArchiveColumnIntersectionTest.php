<?php
/**
 * SlimStat\Utils\PurgeArchive::copyableColumns() — what the purge may actually copy, given
 * what the two tables have RIGHT NOW rather than what the manifest declares.
 *
 * The full account of why this exists is on the method's own docblock. The short version:
 * `slim_stats_archive` is declared `reconcile => false`, so it is created once with
 * `CREATE TABLE ... LIKE` and frozen; `ua_id` is in STATS_COLUMNS but the migration that adds
 * it is optional and touches the fact table only; naming a column the archive has never had
 * made the collision probe error, `probeForCollision()` correctly reported "could not tell",
 * and retention stopped on every tick forever while blaming the utf8mb4 conversion.
 *
 * The source-level gate pins that the purge routes through this method. What it cannot see is
 * the behaviour when a table cannot be READ — which is the case that decides whether this fix
 * is safe or is itself a data-loss bug. That is what these tests are for.
 *
 * @package WpSlimstat
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit;

use SlimStat\Utils\PurgeArchive;

class PurgeArchiveColumnIntersectionTest extends WpSlimstatTestCase
{
    /**
     * A wpdb double that answers SHOW COLUMNS from a per-table script.
     *
     * `null` means "the table could not be read" — the case that separates a safe fix from a
     * catastrophic one.
     *
     * `last_error` is CLEARED on every query, because real `wpdb::query()` clears it via
     * `flush()`. A double that only ever sets it cannot distinguish "this probe failed" from
     * "an earlier probe failed and the flag is still standing", so the unreadable-archive test
     * below would pass while reading the live table's leftover error.
     *
     * @param array<string,string[]|null> $tables
     */
    private function db(array $tables): \wpdb
    {
        $wpdb             = \Mockery::mock(\wpdb::class);
        $wpdb->last_error = '';

        $wpdb->shouldReceive('suppress_errors')->andReturn(false);

        $wpdb->shouldReceive('get_results')->andReturnUsing(
            static function ($sql, $output = null) use ($tables, $wpdb) {
                $wpdb->last_error = '';

                if (!preg_match('/SHOW COLUMNS FROM `([^`]+)`/', (string) $sql, $m)) {
                    return [];
                }

                $columns = array_key_exists($m[1], $tables) ? $tables[$m[1]] : null;

                if (null === $columns) {
                    $wpdb->last_error = "Table '{$m[1]}' doesn't exist";

                    return [];
                }

                return array_map(static function ($c) {
                    return ['Field' => $c, 'Type' => 'varchar(255)'];
                }, $columns);
            }
        );

        return $wpdb;
    }

    /** @return string[] */
    private function statsColumnsWithout(string $omit): array
    {
        return array_values(array_diff(PurgeArchive::STATS_COLUMNS, [$omit]));
    }

    /**
     * The shipped case: an upgraded install where neither table has `ua_id`.
     *
     * It must drop out of the copy list, and it must NOT be reported as lost — nothing is lost
     * when there was nothing to copy. Reporting it would put a permanent notice on a large slice
     * of the installed base for a column they never had.
     */
    public function testColumnAbsentFromBothTablesIsDroppedAndNotReportedLost(): void
    {
        $without = $this->statsColumnsWithout('ua_id');

        $plan = PurgeArchive::copyableColumns(
            $this->db([
                'wp_slim_stats'         => $without,
                'wp_slim_stats_archive' => $without,
            ]),
            'wp_',
            'slim_stats'
        );

        self::assertNotContains('ua_id', $plan['copy']);
        self::assertSame($without, $plan['copy']);
        self::assertSame([], $plan['lost'], 'absent from both tables is not fidelity loss');
        self::assertTrue($plan['usable']);
    }

    /**
     * A column the LIVE table has and the archive does not IS real fidelity loss: the row is
     * about to be deleted and the archived copy will not carry that field. Worth reporting — and
     * worth continuing, because refusing would stop retention entirely over one column.
     */
    public function testColumnOnLiveButNotArchiveIsReportedLostAndStillPurges(): void
    {
        $plan = PurgeArchive::copyableColumns(
            $this->db([
                'wp_slim_stats'         => PurgeArchive::STATS_COLUMNS,
                'wp_slim_stats_archive' => $this->statsColumnsWithout('ua_id'),
            ]),
            'wp_',
            'slim_stats'
        );

        self::assertSame(['ua_id'], $plan['lost']);
        self::assertNotContains('ua_id', $plan['copy']);
        self::assertTrue($plan['usable'], 'one lost column must not stop retention');
    }

    /**
     * THE ONE THAT DECIDES WHETHER THIS FIX IS SAFE.
     *
     * `Schema::columnState()` reports an unreadable table as having no columns — it cannot
     * distinguish "no such table" from "cannot read it". Intersecting against that would produce
     * an EMPTY copy list: an INSERT naming no columns, a collision probe comparing nothing, and
     * then a DELETE. That is worse than the bug being fixed.
     *
     * Falling back to the declaration reproduces exactly the pre-fix behaviour, which
     * probeForCollision() then catches as an error and refuses to delete through. The safety is
     * in the composition, not in either half alone.
     */
    public function testUnreadableArchiveFallsBackToTheDeclarationRatherThanToNothing(): void
    {
        $plan = PurgeArchive::copyableColumns(
            $this->db([
                'wp_slim_stats'         => PurgeArchive::STATS_COLUMNS,
                'wp_slim_stats_archive' => null,
            ]),
            'wp_',
            'slim_stats'
        );

        self::assertSame(
            PurgeArchive::STATS_COLUMNS,
            $plan['copy'],
            'an unreadable archive must not empty the copy list'
        );
        self::assertSame([], $plan['lost']);
        self::assertTrue($plan['usable']);
    }

    /**
     * No key, no statement. There is nothing to build an INSERT or a collision probe from, so
     * the caller must refuse rather than proceed — the same refusal probeForCollision() makes
     * for a question it could not ask.
     */
    public function testMissingPrimaryKeyMakesThePlanUnusable(): void
    {
        $plan = PurgeArchive::copyableColumns(
            $this->db([
                'wp_slim_events'         => PurgeArchive::EVENT_COLUMNS,
                'wp_slim_events_archive' => array_values(
                    array_diff(PurgeArchive::EVENT_COLUMNS, ['event_id'])
                ),
            ]),
            'wp_',
            'slim_events'
        );

        self::assertFalse($plan['usable']);
        self::assertSame(['event_id'], $plan['lost']);
    }

    /**
     * Declared order is the contract, not incidental: the INSERT column list and its SELECT list
     * are built from this same array and must agree positionally. The tables are scripted in a
     * different order here precisely so that a plan echoing table order would fail.
     */
    public function testCopyPreservesDeclaredOrderNotTableOrder(): void
    {
        $scrambled = array_reverse(PurgeArchive::EVENT_COLUMNS);

        $plan = PurgeArchive::copyableColumns(
            $this->db([
                'wp_slim_events'         => $scrambled,
                'wp_slim_events_archive' => $scrambled,
            ]),
            'wp_',
            'slim_events'
        );

        self::assertSame(PurgeArchive::EVENT_COLUMNS, $plan['copy']);
    }

    /**
     * The pairing of live table, archive table, column list and key used to be four of six
     * arguments at the call site, which made it possible to hand STATS_COLUMNS to slim_events.
     * It is stated once now, and an unknown table is refused by name rather than silently
     * producing an empty plan that the caller would read as "nothing to copy".
     */
    public function testUnknownTableIsRefusedRatherThanReturningAnEmptyPlan(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PurgeArchive::copyableColumns($this->db([]), 'wp_', 'slim_meta');
    }
}
