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
