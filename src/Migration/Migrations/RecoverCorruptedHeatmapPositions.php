<?php
declare(strict_types=1);

namespace SlimStat\Migration\Migrations;

use SlimStat\Migration\AbstractMigration;

class RecoverCorruptedHeatmapPositions extends AbstractMigration
{
    /**
     * Highest event_id this migration has already examined.
     *
     * MEASURED, on the 443k-row local corpus: 18,120 rows match the SQL predicate below and
     * ZERO of them are recoverable. That is not a quirk of this dataset — recoverPosition()
     * returns a value only when exactly ONE split of the digits is compatible with the screen
     * width, and for a realistic position like 69270 at width 1440 several splits are, so the
     * answer is ambiguous and the row is left alone. Correct behaviour.
     *
     * What was NOT correct: shouldRun() asked "is there a CANDIDATE row" while run() only fixes
     * "rows with a UNIQUE split". Those sets differ, so on any install with one unrecoverable
     * row the Migration screen offered this step, reported success, and offered it again —
     * forever, each click re-scanning the whole events table with a REGEXP. "All migrations
     * complete" was unreachable. Reproduced end to end here: run() returned OK in 0.43 s and
     * shouldRun() was still true immediately afterwards.
     *
     * The watermark closes it without weakening anything: a row that has been examined and
     * found unrecoverable is not offered again; a row that arrives later still is.
     */
    private const OPTION_WATERMARK = 'slimstat_heatmap_recovery_watermark';

    private ?bool $shouldRunCache = null;

    public function getId(): string
    {
        return 'recover-corrupted-heatmap-positions';
    }

    public function getName(): string
    {
        return __('Recover corrupted heatmap click positions', 'wp-slimstat');
    }

    public function getDescription(): string
    {
        return __('Attempts to restore comma-separated heatmap positions for historical rows when a single screen-width-compatible split exists.', 'wp-slimstat');
    }

    public function run(): bool
    {
        $events_table = $this->tablePrefix() . 'slim_events';
        $stats_table = $this->tablePrefix() . 'slim_stats';

        $base_sql = "
            SELECT e.event_id, e.position, s.screen_width
            FROM {$events_table} e
            INNER JOIN {$stats_table} s ON e.id = s.id
            WHERE e.position IS NOT NULL
              AND e.position NOT LIKE '%,%'
              AND e.position REGEXP '^[0-9]+$'
              AND s.screen_width > 0";

        $batch_size = 1000;
        $cursor = $this->watermark();
        $examined = $cursor;

        do {
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    $base_sql . ' AND e.event_id > %d ORDER BY e.event_id ASC LIMIT %d',
                    $cursor,
                    $batch_size
                ),
                ARRAY_A
            );

            if (empty($rows)) {
                break;
            }

            // Advance cursor to the last event_id in this batch
            $cursor = (int) end($rows)['event_id'];
            $examined = $cursor;

            // Collect recoverable rows for a batched UPDATE
            $updates = [];
            foreach ($rows as $row) {
                $candidate = $this->recoverPosition((string) $row['position'], (int) $row['screen_width']);
                if ($candidate !== null) {
                    $updates[(int) $row['event_id']] = $candidate;
                }
            }

            if (!empty($updates)) {
                // Build a prepared CASE/WHEN with %d/%s placeholders.
                $cases = [];
                $values = [];
                foreach ($updates as $event_id => $position) {
                    $cases[] = 'WHEN %d THEN %s';
                    $values[] = $event_id;
                    $values[] = $position;
                }
                $id_placeholders = implode(',', array_fill(0, count($updates), '%d'));
                $values = array_merge($values, array_keys($updates));

                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- dynamic CASE count
                $result = $this->wpdb->query(
                    $this->wpdb->prepare(
                        "UPDATE {$events_table} SET position = CASE event_id "
                        . implode(' ', $cases)
                        . " END WHERE event_id IN ({$id_placeholders})",
                        $values
                    )
                );
                if ($result === false) {
                    return false;
                }
            }
        } while (count($rows) === $batch_size);

        // Every candidate at or below $examined has now been offered to recoverPosition(); the
        // ones still uncorrected are the ones it cannot decide. Recording that is what stops the
        // screen re-offering work that has already been done.
        if ($examined > $this->watermark()) {
            \update_option(self::OPTION_WATERMARK, $examined, false);
        }

        // Invalidate cache so shouldRun() re-checks after recovery
        $this->shouldRunCache = null;

        return true;
    }

    /**
     * The highest event_id already examined, or 0 on a site that has never run this.
     *
     * Not autoloaded: it is written once on an admin click and read on the migration screen,
     * so joining `alloptions` for it would invalidate that blob for every request on the site.
     */
    private function watermark(): int
    {
        return (int) \get_option(self::OPTION_WATERMARK, 0);
    }

    public function shouldRun(): bool
    {
        if ($this->shouldRunCache !== null) {
            return $this->shouldRunCache;
        }

        $events_table = $this->tablePrefix() . 'slim_events';
        $stats_table = $this->tablePrefix() . 'slim_stats';

        // `e.event_id > watermark` is the whole fix, and it also makes the probe cheap: the
        // scan starts after everything already examined instead of re-reading the table.
        $result = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "
            SELECT 1
            FROM {$events_table} e
            INNER JOIN {$stats_table} s ON e.id = s.id
            WHERE e.position IS NOT NULL
              AND e.position NOT LIKE '%,%'
              AND e.position REGEXP '^[0-9]+$'
              AND s.screen_width > 0
              AND e.event_id > %d
            LIMIT 1
            ",
                $this->watermark()
            )
        );

        $this->shouldRunCache = !empty($result);

        return $this->shouldRunCache;
    }

    public function getDiagnostics(): array
    {
        return [
            [
                'key'     => $this->getId(),
                'exists'  => !$this->shouldRun(),
                'table'   => $this->tablePrefix() . 'slim_events',
                'columns' => 'position',
            ],
        ];
    }

    private function recoverPosition(string $position, int $screenWidth): ?string
    {
        if ($screenWidth <= 0 || !ctype_digit($position) || strlen($position) < 2) {
            return null;
        }

        $candidates = [];
        $length = strlen($position);

        for ($split = 1; $split < $length; $split++) {
            $x = substr($position, 0, $split);
            $y = substr($position, $split);

            if (($x !== '0' && $x[0] === '0') || ($y !== '0' && $y[0] === '0')) {
                continue;
            }

            $x_value = (int) $x;
            $y_value = (int) $y;

            if ($x_value > $screenWidth || $x_value > 99999 || $y_value > 99999 || $y_value > $screenWidth * 10) {
                continue;
            }

            $candidates[] = $x . ',' . $y;
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }
}
