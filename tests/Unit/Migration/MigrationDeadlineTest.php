<?php
/**
 * An unbounded migration must record progress AS IT GOES, not only when it finishes.
 *
 * `tests/migration-deadline-test.php` pins the structural half — that an unbounded migration
 * declares and reads a PASS_SECONDS budget. It cannot show that the budget works, and a constant
 * nothing consults is exactly the shape this codebase keeps finding. This file drives the loops.
 *
 * THE DEFECT. `RecoverCorruptedHeatmapPositions` wrote its watermark once, after the walk. An
 * interrupted pass therefore recorded ZERO progress: the UPDATEs it had already applied survived,
 * but the SCAN did not, so the next attempt re-read the same rows from the same cursor. Since
 * almost every candidate is permanently unrecoverable — measured on the reference corpus: 18,120
 * matched the predicate and 0 were recoverable — that is a walk which finds the same nothing every
 * time. On an events table too large to finish in one request it never converged, and because the
 * migration is REQUIRED, "all migrations complete" was unreachable.
 *
 * A `return false` on a failed batched UPDATE discarded the pass the same way, so a single
 * transient database error cost every batch that had already succeeded — not only a timeout.
 *
 * WHAT IS NOT TESTED HERE, and why. The deadline BREAK itself needs the clock moved, and
 * PASS_SECONDS is a private const with no seam to inject one. Asserting it by sleeping ten seconds
 * would make the suite pay ten seconds to observe a `>=`. The structural gate pins that the budget
 * is declared and read; what this file proves is the property that makes the budget useful —
 * stopping early is only safe if progress survives.
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Migration;

use Brain\Monkey\Functions;
use Mockery;
use SlimStat\Migration\Migrations\RecoverCorruptedHeatmapPositions;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

class MigrationDeadlineTest extends WpSlimstatTestCase
{
    /** The loop continues while a batch comes back full; 1000 is the migration's batch size. */
    private const BATCH = 1000;

    /**
     * `$GLOBALS['slimstat_test_options']` is a PROCESS-global store shared by every test in the
     * run. Seeding it without clearing leaves this file's last value — 5000 — visible to whatever
     * class runs next. Both sibling tests that use the store already clear it
     * (OptionalMigrationTest resets the array, DailySaltTest unsets its key); not doing so here
     * would be the third copy of one idiom drifting, which is what
     * WpSlimstatTestCase::stubTransientCacheMiss() was hoisted to stop.
     */
    protected function tearDown(): void
    {
        unset($GLOBALS['slimstat_test_options']['slimstat_heatmap_recovery_watermark']);

        parent::tearDown();
    }

    /**
     * Rows whose `position` recovers to exactly one candidate, so a batched UPDATE is issued.
     *
     * '12' with a screen width of 1000 splits only as 1,2 — every other split leaves a component
     * above the width or leading-zero padded. Verified against recoverPosition()'s rule.
     *
     * @return array<int,array<string,mixed>>
     */
    private function rows(int $from, int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'event_id'     => $from + $i,
                'position'     => '12',
                'screen_width' => 1000,
            ];
        }

        return $rows;
    }

    /**
     * @param array<int,array<int,array<string,mixed>>> $batches successive get_results answers
     * @param array<int,mixed>                          $queryResults successive query() answers
     */
    private function db(array $batches, array $queryResults = []): \wpdb
    {
        $wpdb             = Mockery::mock(\wpdb::class);
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';

        $wpdb->shouldReceive('prepare')->andReturnUsing(static fn ($sql) => $sql);
        $wpdb->shouldReceive('get_results')->andReturnUsing(
            static function () use (&$batches) {
                return array_shift($batches) ?? [];
            }
        );
        $wpdb->shouldReceive('query')->andReturnUsing(
            static function () use (&$queryResults) {
                return array_key_exists(0, $queryResults) ? array_shift($queryResults) : 1;
            }
        );

        return $wpdb;
    }


    /**
     * Capture watermark writes, keeping get_option() consistent with them.
     *
     * `get_option` is pre-defined by tests/Unit/Tracker/stubs.php over a global store, so Brain
     * Monkey cannot reroute it (Patchwork refuses a function defined before it loaded). Writing
     * through to that store is not a workaround but the more faithful double: production reads
     * back what it just wrote, which is exactly what makes persistWatermark() idempotent.
     *
     * @param  array<int,int> $written filled with each value recorded, in order
     */
    private function captureWatermark(array &$written, int $initial = 0): void
    {
        $GLOBALS['slimstat_test_options']['slimstat_heatmap_recovery_watermark'] = $initial;

        Functions\expect('update_option')->zeroOrMoreTimes()->andReturnUsing(
            static function ($key, $value) use (&$written) {
                if ('slimstat_heatmap_recovery_watermark' === $key) {
                    $written[] = (int) $value;
                    $GLOBALS['slimstat_test_options'][$key] = (int) $value;
                }

                return true;
            }
        );
    }

    /** @test */
    public function test_the_watermark_advances_after_every_batch_not_only_at_the_end(): void
    {
        // Two full batches then a short one. Written only at the end — as it was — exactly ONE
        // watermark write would land, and an interruption before it would lose the whole walk.
        $written = [];
        $this->captureWatermark($written, 0);

        $migration = new RecoverCorruptedHeatmapPositions($this->db([
            $this->rows(1, self::BATCH),
            $this->rows(1 + self::BATCH, self::BATCH),
            $this->rows(1 + 2 * self::BATCH, 10),
        ]));

        $this->assertTrue($migration->run());

        $this->assertGreaterThan(
            1,
            count($written),
            'the watermark must be recorded per batch — one write means an interrupted pass loses the scan'
        );
        $this->assertSame(
            [self::BATCH, 2 * self::BATCH, 2 * self::BATCH + 10],
            $written,
            'each write should record the last event_id of the batch that just completed'
        );
    }

    /** @test */
    public function test_a_failed_update_keeps_the_progress_already_made(): void
    {
        // Batch 1 succeeds, batch 2's UPDATE fails. The watermark must hold batch 1's end: the
        // failing batch's rows were offered but NOT corrected, so advancing past them would
        // retire work that never happened — and discarding batch 1 would repeat the defect.
        $written = [];
        $this->captureWatermark($written, 0);

        $migration = new RecoverCorruptedHeatmapPositions($this->db(
            [
                $this->rows(1, self::BATCH),
                $this->rows(1 + self::BATCH, self::BATCH),
            ],
            [1, false] // first batched UPDATE succeeds, second is refused
        ));

        $this->assertFalse($migration->run(), 'a refused UPDATE still fails the pass');
        $this->assertSame(
            [self::BATCH],
            $written,
            'progress up to the last GOOD batch is kept, and the failing batch is not counted'
        );
    }

    /** @test */
    public function test_a_watermark_is_never_walked_backwards(): void
    {
        // persistWatermark() compares against the STORED value, so a pass that starts behind a
        // concurrent one cannot undo it. Here the walk reaches 1000 while the option already
        // reads 5000, so nothing is written.
        $written = [];
        $this->captureWatermark($written, 5000);

        $migration = new RecoverCorruptedHeatmapPositions($this->db([
            $this->rows(1, 10),
        ]));

        $this->assertTrue($migration->run());
        $this->assertSame([], $written, 'a lower watermark must not overwrite a higher stored one');
    }
}
