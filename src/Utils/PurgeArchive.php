<?php

namespace SlimStat\Utils;

// don't load directly.
if (! defined('ABSPATH')) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit;
}

/**
 * What the retention purge copies into the archive tables, and how it proves a row already
 * sitting there is the same row rather than a different one reusing its primary key.
 *
 * The purge archives with INSERT IGNORE and then deletes what it archived. IGNORE cannot
 * tell "already archived" — the interrupted-run replay it exists for — from "a DIFFERENT
 * row owns this primary key", because it reports both as a silent no-op. So the purge
 * probes for a mismatch first and refuses to delete through one.
 *
 * These column lists are the single source for the INSERT column list, its SELECT list and
 * that probe alike, so the guard cannot drift from the statement it protects. The drift is
 * not hypothetical: the guard was originally hand-written against three columns while the
 * INSERT copied thirty-three.
 *
 * @since 5.6.0
 */
class PurgeArchive
{
    /**
     * Columns copied into {prefix}slim_events_archive, keyed on event_id.
     *
     * @var string[]
     */
    public const EVENT_COLUMNS = ['event_id', 'type', 'event_description', 'notes', 'position', 'id', 'dt'];

    /**
     * Columns copied into {prefix}slim_stats_archive, keyed on id.
     *
     * @var string[]
     */
    public const STATS_COLUMNS = [
        'id', 'ip', 'other_ip', 'username', 'email', 'country', 'location', 'city', 'referer',
        'resource', 'searchterms', 'notes', 'visit_id', 'server_latency', 'page_performance',
        'browser', 'browser_version', 'browser_type', 'platform', 'language', 'fingerprint',
        'user_agent', 'resolution', 'screen_width', 'screen_height', 'content_type', 'category',
        'author', 'content_id', 'tz_offset', 'outbound_resource', 'dt_out', 'dt',
        // ADR-9 Layer 1's dimension key (F10/G3). Added here in the same change that declared
        // it on slim_stats, because the two lists diverging IS the defect this class guards:
        // the purge deletes the live row and the archive keeps a copy missing the column.
        // Caught by tests/purge-archive-order-test.php rather than by review — which is the
        // schema-diff gate C25 and C36 earned doing exactly what it was built for.
        'ua_id',
        // The cookieless identity (D68/P2), same rule as ua_id: added in the same change
        // that declared it on slim_stats. Retention applies to the identity exactly as it
        // applies to the row that carries it.
        'vid_hash',
    ];

    /**
     * Live table => the archive it is copied into, the columns copied, and the key they join on.
     *
     * The pairing lived at the call sites as four of six arguments, which made it possible to
     * hand STATS_COLUMNS to slim_events. It is stated once, here, beside the constants whose
     * own docblocks already say which archive they belong to.
     *
     * @var array<string,array{archive:string, columns:string[], key:string}>
     */
    private const ARCHIVE_PAIRS = [
        'slim_events' => [
            'archive' => 'slim_events_archive',
            'columns' => self::EVENT_COLUMNS,
            'key'     => 'event_id',
        ],
        'slim_stats' => [
            'archive' => 'slim_stats_archive',
            'columns' => self::STATS_COLUMNS,
            'key'     => 'id',
        ],
    ];

    /**
     * The declared columns that BOTH tables actually have, and what copying them would lose.
     *
     * THE ARCHIVE IS NOT RECONCILED, AND THAT IS DELIBERATE. `slim_stats_archive` is declared
     * `'like' => 'slim_stats', 'reconcile' => false` (Schema.php), and `Schema::ensure()` skips
     * non-reconciling tables entirely — so the archive is created once, with `CREATE TABLE ...
     * LIKE`, and then frozen at whatever `slim_stats` looked like on the day it was first made.
     * A fresh 6.0.0 install is born correct. An upgraded one is not.
     *
     * WHAT THAT COST. `ua_id` was added to STATS_COLUMNS in the same change that declared it on
     * `slim_stats`, which is the right rule. But the migration that adds the column adds it to
     * `slim_stats` only, and it is OPTIONAL, so `runAll()` skips it — meaning on an upgraded
     * install that took only the required migrations, NEITHER table has the column. The events
     * half of the purge then succeeded (EVENT_COLUMNS has no `ua_id`), the pageview probe raised
     * "Unknown column 'ua_id'", `probeForCollision()` correctly returned null, and the purge
     * refused to delete — every tick, forever. Events aged out. Pageviews never did. And the
     * degradation the admin saw blamed the utf8mb4 conversion, which had nothing to do with it.
     *
     * So the statement is built from what is on the tables at run time, not from what the
     * manifest wishes were there.
     *
     * FAIL-SAFE, NOT FAIL-EMPTY. `Schema::columnState()` reports an unreadable table as having no
     * columns at all — it cannot distinguish "no such table" from "cannot read it" — so
     * intersecting with that would empty the INSERT list and silently copy nothing. Falling back
     * to `$declared` instead reproduces exactly the old behaviour, which `probeForCollision()`
     * then catches as an error and refuses to delete through. The composition is what makes this
     * safe; neither half is safe alone.
     *
     * @param \wpdb  $db
     * @param string $prefix
     * @param string $liveSuffix One of ARCHIVE_PAIRS' keys.
     *
     * @return array{copy:string[], lost:string[], usable:bool}
     *         copy   columns present on both tables, in DECLARED order — the INSERT list and its
     *                SELECT list are built from this same array and must agree positionally
     *         lost   columns the LIVE table has and the archive does not — real fidelity loss.
     *                Absent from BOTH is not loss: there is nothing to copy, which is the
     *                ordinary upgraded-install case and must stay silent.
     *         usable false when the key itself is missing, so there is no statement to build
     *
     * @throws \InvalidArgumentException on a suffix that is not an archived table.
     */
    public static function copyableColumns($db, $prefix, $liveSuffix)
    {
        if (!isset(self::ARCHIVE_PAIRS[$liveSuffix])) {
            throw new \InvalidArgumentException(sprintf(
                "'%s' is not an archived table — this class knows: %s",
                $liveSuffix,
                implode(', ', array_keys(self::ARCHIVE_PAIRS))
            ));
        }

        $pair     = self::ARCHIVE_PAIRS[$liveSuffix];
        $declared = $pair['columns'];

        $live    = self::presentColumns($db, $liveSuffix, $prefix, $declared);
        $archive = self::presentColumns($db, $pair['archive'], $prefix, $declared);

        // array_intersect keeps the FIRST array's order, so declared order survives both steps
        // even though the tables report their own.
        $onLive = array_intersect($declared, $live);
        $copy   = array_values(array_intersect($onLive, $archive));

        return [
            'copy'   => $copy,
            'lost'   => array_values(array_diff($onLive, $archive)),
            'usable' => in_array($pair['key'], $copy, true),
        ];
    }

    /**
     * Declared columns one table actually has, falling back to the declaration when unreadable.
     *
     * @param string[] $declared
     *
     * @return string[]
     */
    private static function presentColumns($db, $suffix, $prefix, array $declared)
    {
        $state = \SlimStat\Schema\Schema::columnState($db, $suffix, $prefix);

        // [] means "could not read it", not "it has no columns" — a readable table always
        // reports at least its key. Same test fact_column_present() uses.
        if ([] === $state['present']) {
            return $declared;
        }

        return $state['present'];
    }

    /**
     * Run the collision probe, distinguishing "clean" from "could not tell".
     *
     * `$wpdb->get_var()` answers null both for "the query found nothing" and for "the
     * query failed", and the purge read that null as clean — then archived with
     * INSERT IGNORE and deleted what it believed it had archived.
     *
     * That is reachable today, not hypothetically. ConvertTablesToUtf8mb4 continues
     * past a table it could not convert ("three of four is better than none"), so an
     * install can sit with slim_stats on utf8mb4 and slim_stats_archive on utf8mb3.
     * sameRow() then compares 33 columns ACROSS those two tables, and two IMPLICIT
     * columns of different charsets raise ER_CANT_AGGREGATE_2COLLATIONS. The guard
     * answered "no collision" and the rows went. A half-completed migration
     * reintroducing the silent loss this guard exists to prevent.
     *
     * Errors are suppressed around the probe because it is a question, not a failure:
     * a mid-conversion install should get a recorded degradation and a skipped purge,
     * not a red admin on every page load.
     *
     * @param \wpdb   $db
     * @param string  $sql  Prepared-statement template.
     * @param array   $args Values for the template.
     * @return bool|null true = a different row owns the key, false = clean,
     *                   null = the probe could not run, so nothing may be deleted.
     */
    public static function probeForCollision($db, $sql, array $args = [])
    {
        $suppressed = $db->suppress_errors(true);
        $found      = $db->get_var($args === [] ? $sql : $db->prepare($sql, ...$args));
        $db->suppress_errors($suppressed);

        if ('' !== (string) $db->last_error) {
            return null;
        }

        return !empty($found);
    }

    /**
     * SQL asserting that the archived row aliased `a` IS the live row about to be archived.
     *
     * NULL-safe equality (`<=>`, because `=` is never true for NULL and most of these
     * columns are nullable — `city` is 100% NULL on the reference dataset) across every
     * copied column except the one already joined on.
     *
     * Anything narrower has false negatives, and each false negative is a live row that
     * INSERT IGNORE silently declined to copy and the DELETE then destroyed. Measured on
     * scratch tables: an archive row agreeing on only (id, dt, notes) passed a three-column
     * discriminator, live events went 1 -> 0, the archive kept the stale payload, and
     * nothing was recorded.
     *
     * @param string[] $columns    Every column the archive INSERT copies.
     * @param string   $key        The column already joined on — the only one skipped.
     * @param string   $live_alias Alias of the live table ('e' events, 's' pageviews).
     * @return string
     */
    public static function sameRow($columns, $key, $live_alias)
    {
        $tests = [];

        foreach ($columns as $column) {
            if ($column === $key) {
                continue;
            }

            $tests[] = sprintf('a.%s <=> %s.%s', $column, $live_alias, $column);
        }

        return implode(' AND ', $tests);
    }
}
