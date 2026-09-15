<?php
/**
 * SlimStat\Utils\PurgeArchive::sameRow() — the discriminator that decides whether a row
 * already sitting in an archive under a given primary key IS the row about to be archived.
 *
 * The purge archives with INSERT IGNORE and then deletes what it archived. IGNORE cannot
 * tell "already archived" (the interrupted-run replay it exists for) from "a DIFFERENT row
 * owns this key" — it reports both as a silent no-op. So the purge probes for a mismatch
 * first and refuses to delete through one.
 *
 * The discriminator originally compared three columns. Measured on scratch tables: an
 * archive row agreeing with the live row on (id, dt, notes) but carrying a different
 * type / event_description / position did not trip it — INSERT IGNORE dropped the live row,
 * the DELETE removed it, live events went 1 -> 0, the archive kept the stale payload, and
 * nothing was recorded in slimstat_degradations. Silent data loss inside a data-safety fix.
 *
 * These tests pin the property that closes it: EVERY column the archive copies is compared,
 * and the only one skipped is the one already joined on.
 *
 * @package WpSlimstat
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit;

use SlimStat\Utils\PurgeArchive;

class PurgeArchiveCollisionGuardTest extends WpSlimstatTestCase
{
    /** @param string[] $columns */
    private function guard(array $columns, string $key, string $liveAlias): string
    {
        return PurgeArchive::sameRow($columns, $key, $liveAlias);
    }

    /**
     * The whole point. Anything uncompared is a column a colliding row may differ on while
     * still passing the guard, and passing the guard means the live row gets deleted.
     *
     * @dataProvider archiveColumnSets
     * @param string[] $columns
     */
    public function testEveryCopiedColumnIsCompared(array $columns, string $key, string $alias): void
    {
        $sql = $this->guard($columns, $key, $alias);

        foreach ($columns as $column) {
            if ($column === $key) {
                continue;
            }

            $this->assertStringContainsString(
                sprintf('a.%s <=> %s.%s', $column, $alias, $column),
                $sql,
                sprintf(
                    '%s is copied into the archive but never compared. A colliding archive row '
                        . 'may differ on it, pass the guard, and the live row is then deleted after '
                        . 'INSERT IGNORE declined to copy it.',
                    $column
                )
            );
        }
    }

    /**
     * Exactly one comparison per copied column, and none for the join key — no more, no
     * fewer. Catches both a trimmed guard and a duplicated term masking a missing one.
     *
     * @dataProvider archiveColumnSets
     * @param string[] $columns
     */
    public function testComparisonCountEqualsColumnsMinusTheJoinKey(array $columns, string $key, string $alias): void
    {
        $sql = $this->guard($columns, $key, $alias);

        $this->assertSame(count($columns) - 1, substr_count($sql, '<=>'));
        $this->assertStringNotContainsString(sprintf('a.%s <=>', $key), $sql);
    }

    /**
     * `=` is never true for NULL, and city is 100% NULL on the reference dataset while
     * searchterms is 97.9% NULL. A `=` guard would report a mismatch for every ordinary row,
     * fire on every purge, and wedge retention permanently — failing in the direction that
     * looks safe while quietly stopping the feature.
     *
     * @dataProvider archiveColumnSets
     * @param string[] $columns
     */
    public function testComparisonIsNullSafe(array $columns, string $key, string $alias): void
    {
        $sql = $this->guard($columns, $key, $alias);

        $this->assertStringNotContainsString('= ', str_replace('<=>', '', $sql));
        $this->assertStringContainsString('<=>', $sql);
    }

    /** The guard is ANDed into a larger WHERE, so every term must bind. */
    public function testTermsAreConjoined(): void
    {
        $sql = $this->guard(['id', 'a', 'b'], 'id', 'e');

        $this->assertSame('a.a <=> e.a AND a.b <=> e.b', $sql);
    }

    /** A single-column archive has nothing left to compare once the key is skipped. */
    public function testKeyOnlyColumnSetYieldsNoComparison(): void
    {
        $this->assertSame('', $this->guard(['id'], 'id', 'e'));
    }

    /** @return array<string,array{0:string[],1:string,2:string}> */
    public static function archiveColumnSets(): array
    {
        return [
            'events'    => [PurgeArchive::EVENT_COLUMNS, 'event_id', 'e'],
            'pageviews' => [PurgeArchive::STATS_COLUMNS, 'id', 's'],
        ];
    }

    // ── The probe must not answer "clean" when it could not look (C25) ──────

    /**
     * A HALF-CONVERTED install is the case that matters, and it is reachable today:
     * ConvertTablesToUtf8mb4 deliberately continues past a table it could not convert
     * ("three of four is better than none"). If slim_stats converts and
     * slim_stats_archive does not, sameRow() compares 33 columns ACROSS the two tables,
     * and two IMPLICIT columns of different charsets raise ER_CANT_AGGREGATE_2COLLATIONS.
     *
     * $wpdb->get_var() returns null for that — exactly as it does for "no colliding row
     * found". The guard read null as "clean" and execution proceeded to INSERT IGNORE and
     * then DELETE. That is precisely the silent-loss shape this guard was written to
     * prevent, reintroduced by a half-completed migration.
     */
    public function test_a_probe_that_errored_is_not_reported_as_clean(): void
    {
        $wpdb = $this->probeWpdb(null, 'Illegal mix of collations (utf8mb3_general_ci,IMPLICIT) '
            . "and (utf8mb4_unicode_ci,IMPLICIT) for operation '<=>'");

        $this->assertNull(
            PurgeArchive::probeForCollision($wpdb, 'SELECT 1 ...', [123]),
            'null means "could not tell" — the purge must stop rather than delete'
        );
    }

    /** Clean: the probe ran and found no colliding row. */
    public function test_a_probe_that_found_nothing_reports_clean(): void
    {
        $this->assertFalse(
            PurgeArchive::probeForCollision($this->probeWpdb(null, ''), 'SELECT 1 ...', [123])
        );
    }

    /** Collision: a different row already owns the key. */
    public function test_a_probe_that_found_a_row_reports_a_collision(): void
    {
        $this->assertTrue(
            PurgeArchive::probeForCollision($this->probeWpdb('1', ''), 'SELECT 1 ...', [123])
        );
    }

    /**
     * @param mixed  $getVar    What get_var() answers.
     * @param string $lastError wpdb's error text, '' when the query succeeded.
     */
    private function probeWpdb($getVar, string $lastError): \wpdb
    {
        $wpdb             = \Mockery::mock(\wpdb::class);
        $wpdb->last_error = $lastError;
        $wpdb->shouldReceive('suppress_errors')->andReturn(false);
        $wpdb->shouldReceive('prepare')->andReturnUsing(static fn($sql) => $sql);
        $wpdb->shouldReceive('get_var')->andReturn($getVar);

        return $wpdb;
    }
}
