<?php

namespace SlimStat\Tracker;

/**
 * What a write actually did — inserted, ignored, failed, or nothing to do.
 *
 * WHY THIS EXISTS (C30). `Query::execute()` returns `$this->db->insert_id ?: $result`, which
 * collapses three outcomes into one integer:
 *
 *   > 0     a row was stored, and this is its id
 *   0       INSERT IGNORE swallowed a duplicate, or a FOREIGN KEY refused the row
 *   false   a hard error; $wpdb->last_error is set
 *
 * Every caller tested `false === $id`, so the middle case read as SUCCESS and propagated as
 * `$stat['id'] = 0`. The event insert keyed on it then violated the FK and was silently
 * dropped. Under Phase G's dual write the same value would mean both "the new table write
 * failed" and "it was a legitimate no-op" — which is a divergence the code cannot report,
 * let alone reconcile.
 *
 * So the rule this class encodes: never `?:` on an integer that can legitimately be 0, and
 * never let "it did not happen" and "it happened and produced 0" share a representation.
 *
 * Immutable, and deliberately not a bare array — an array of three keys invites callers to
 * read `$r['id']` without checking `$r['state']`, which is the habit that produced C30.
 */
final class WriteResult
{
    /**
     * A row was written. id() is the row it was.
     *
     * Named STORED rather than INSERTED because both write terminals share this type and
     * updateRow() would otherwise have to report an "insert". "Stored" is also exactly the
     * claim a 0 was falsely making, so the C30 sharpness survives the rename.
     */
    public const STORED = 'stored';

    /** The write stored no row: a swallowed duplicate, an FK refusal, or nothing to write. */
    public const IGNORED = 'ignored';

    /** The statement errored. */
    public const FAILED = 'failed';

    /** @var string */
    private $state;

    /** @var int */
    private $id;

    /** @var string */
    private $error;

    private function __construct($state, $id = 0, $error = '')
    {
        $this->state = $state;
        $this->id    = (int) $id;
        $this->error = (string) $error;
    }

    public static function stored($id)
    {
        return new self(self::STORED, $id);
    }

    /** @param int $id The row the caller was already working with, if any. */
    public static function ignored($id = 0)
    {
        return new self(self::IGNORED, $id);
    }

    public static function failed($error = '')
    {
        return new self(self::FAILED, 0, $error);
    }

    public function state()
    {
        return $this->state;
    }

    /**
     * The row id, or 0 when there is none.
     *
     * A write that stored nothing reports 0 on purpose — handing back anything else is how
     * a non-existent id reached an FK-constrained child insert. The one exception is a caller
     * that passed an id in (updateRow with nothing left to write); that is still its row.
     */
    public function id()
    {
        return $this->id;
    }

    public function error()
    {
        return $this->error;
    }

    public function isStored()
    {
        return self::STORED === $this->state;
    }

    public function isFailed()
    {
        return self::FAILED === $this->state;
    }
}
