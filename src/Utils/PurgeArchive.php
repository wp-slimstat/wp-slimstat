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
    ];

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
