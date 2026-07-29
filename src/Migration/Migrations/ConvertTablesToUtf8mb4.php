<?php
declare(strict_types=1);

namespace SlimStat\Migration\Migrations;

use SlimStat\Migration\AbstractMigration;

/**
 * Convert the analytics tables from utf8mb3 to utf8mb4.
 *
 * ── Why ────────────────────────────────────────────────────────────────────────────
 *
 * The tables are created `COLLATE utf8_general_ci` (utf8mb3), while modern WordPress
 * defaults to utf8mb4. Two consequences, both measured on a 443,535-row install:
 *
 *  - **The Pro user join degrades.** `slim_stats.username` (utf8mb3_general_ci) compared
 *    against `wp_users.user_login` (utf8mb4_unicode_ci) cannot drive the join from the
 *    smaller side. Measured on identical data: **120.9 ms** at utf8mb3 versus **11.1 ms**
 *    once both sides match — an 11x difference from collation alone.
 *  - **Four-byte characters are lost.** utf8mb3 cannot store emoji or many CJK extension
 *    characters, so a page title or search term containing one is truncated or rejected.
 *
 * ── The collation must match wp_users, NOT the database default ────────────────────
 *
 * This is the trap, and it is measured. Converting to the database default
 * (`utf8mb4_unicode_520_ci` on the reference install) leaves the charsets matching but the
 * COLLATIONS different, and MySQL refuses the comparison outright:
 *
 *     Illegal mix of collations (utf8mb4_unicode_ci,IMPLICIT)
 *                           and (utf8mb4_unicode_520_ci,IMPLICIT) for operation '='
 *
 * So a naive "convert to utf8mb4" turns a slow join into a fatal error. The target is
 * resolved from `wp_users.user_login` at run time.
 *
 * ── It is a COPY, and that is why it is manual ─────────────────────────────────────
 *
 * `ALGORITHM=INPLACE` is refused for this change — MySQL answers "Cannot change column type
 * INPLACE. Try ALGORITHM=COPY." So it is a full table rebuild that blocks writes for its
 * duration, and on a tracking table blocked writes mean dropped pageviews.
 *
 * Measured on the real 408 MB / 443,535-row table: **12.4 s at ~36k rows/s**, extrapolating
 * to ~42 s at the 1.5M-row tier and ~5 minutes at 10M. Storage grew **+0%** (408 -> 409 MB),
 * because the stored data is ASCII — the common fear that utf8mb4 inflates the table by a
 * third does not apply here.
 *
 * That is why this ships as a migration behind an explicit click rather than an upgrade
 * hook: the site owner chooses when to take the write pause.
 *
 * @since 5.6.0
 */
class ConvertTablesToUtf8mb4 extends AbstractMigration
{
    /** Fallback when wp_users cannot be inspected. */
    private const FALLBACK_COLLATION = 'utf8mb4_unicode_ci';

    /** @var string[] */
    private const TABLES = ['slim_stats', 'slim_events', 'slim_stats_archive', 'slim_events_archive'];

    /** @var bool|null */
    private $shouldRunCache;

    public function getId(): string
    {
        return 'convert-tables-to-utf8mb4';
    }

    public function getName(): string
    {
        return __('Convert analytics tables to utf8mb4', 'wp-slimstat');
    }

    public function getDescription(): string
    {
        return __(
            'Converts the SlimStat tables from utf8 (3-byte) to utf8mb4 so they can store emoji '
                . 'and match the collation WordPress uses for users. This rebuilds each table and '
                . 'pauses tracking writes while it runs — about 12 seconds per 450,000 rows.',
            'wp-slimstat'
        );
    }

    /**
     * The collation to convert to: whatever wp_users.user_login uses.
     *
     * Matching the database default instead is what turns this fix into
     * ER_CANT_AGGREGATE_2COLLATIONS on the Pro user join.
     */
    public function targetCollation(): string
    {
        $collation = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COLLATION_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'user_login'",
            $this->wpdb->users
        ));

        if (!is_string($collation) || 0 !== strpos($collation, 'utf8mb4_')) {
            return self::FALLBACK_COLLATION;
        }

        return $collation;
    }

    /** Tables that still carry at least one non-utf8mb4 text column. */
    private function pendingTables(): array
    {
        $pending = [];

        foreach (self::TABLES as $suffix) {
            $table = $this->wpdb->prefix . $suffix;

            $stale = (int) $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
                    AND CHARACTER_SET_NAME IS NOT NULL AND CHARACTER_SET_NAME <> 'utf8mb4'",
                $table
            ));

            if ($stale > 0) {
                $pending[$table] = $stale;
            }
        }

        return $pending;
    }

    public function shouldRun(): bool
    {
        if (null !== $this->shouldRunCache) {
            return $this->shouldRunCache;
        }

        return $this->shouldRunCache = ([] !== $this->pendingTables());
    }

    public function run(): bool
    {
        $collation = $this->targetCollation();
        $ok        = true;

        // A rebuild of a large table can sit behind an open report query. Fail fast rather
        // than inheriting whatever the host's default lock wait is.
        $this->wpdb->query('SET SESSION lock_wait_timeout = 30');

        foreach (array_keys($this->pendingTables()) as $table) {
            // No ALGORITHM clause: INPLACE is refused for a charset change and naming COPY
            // explicitly buys nothing over the server's own choice.
            $result = $this->wpdb->query(
                "ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE {$collation}"
            );

            if (false === $result) {
                \wp_slimstat::record_degradation(
                    'utf8mb4 conversion',
                    sprintf('%s could not be converted: %s', $table, $this->wpdb->last_error)
                );
                $ok = false;
                // Keep going: converting three of four tables is better than none, and the
                // migration is idempotent so a retry picks up whatever is left.
            }
        }

        $this->shouldRunCache = null;

        return $ok;
    }

    public function getDiagnostics(): array
    {
        $pending    = $this->pendingTables();
        $collation  = $this->targetCollation();
        $diagnostics = [];

        foreach (self::TABLES as $suffix) {
            $table = $this->wpdb->prefix . $suffix;

            $diagnostics[] = [
                'key'     => $table,
                'exists'  => !isset($pending[$table]),
                'table'   => $table,
                'columns' => isset($pending[$table])
                    ? sprintf(
                        /* translators: 1: number of columns, 2: target collation */
                        _n('%1$d column to convert to %2$s', '%1$d columns to convert to %2$s', $pending[$table], 'wp-slimstat'),
                        $pending[$table],
                        $collation
                    )
                    : $collation,
            ];
        }

        return $diagnostics;
    }
}
