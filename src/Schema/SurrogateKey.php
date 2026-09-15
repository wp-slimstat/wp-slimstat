<?php

declare(strict_types=1);

namespace SlimStat\Schema;

/**
 * Deterministic surrogate keys for the star schema's dimension tables (F10 / C44).
 *
 * A dimension row's primary key is DERIVED FROM ITS VALUE, not assigned by the database. That
 * one property is what makes the star schema affordable on the write path.
 *
 * The alternative — an AUTO_INCREMENT dimension key — forces the tracker to ask the database
 * "have I seen this user agent before, and if so what number did you give it" on every hit. That
 * is a SELECT, and on a miss a second round trip to INSERT, and under concurrency a race that
 * needs either a unique index and a retry or a lock. Multiplied by the number of dimensions,
 * ADR-9's Layer 1 would add more per-hit queries than the whole programme removes.
 *
 * With a derived key the tracker computes it locally and writes the fact row immediately.
 * `INSERT IGNORE` into the dimension is fire-and-forget: if the row is already there, nothing
 * happens; if two requests race, both write the same key and the loser is a no-op. No read, no
 * lock, no retry.
 *
 * ── Why 64 bits, when P2 forced 128 for the visitor ────────────────────────────────────────
 *
 * P2 ratified `vid_hash` as a full BINARY(16) because a 32-bit visitor id collides at ~50% by
 * ~77,000 cookieless visitors, and a collision there means a subject-access export returns
 * ANOTHER PERSON'S browsing history. That is a privacy breach, and no collision rate is
 * acceptable.
 *
 * A dimension collision is a different kind of event: two user-agent strings would share a row,
 * so one report line would merge two browsers. Wrong, but bounded, non-personal, and correctable
 * by widening later — a dimension key is not stored on a fact row that must survive an ALTER
 * (H2's gate forbids widening a FACT column; a dimension can be rebuilt).
 *
 * The arithmetic, stated rather than assumed. Birthday collision probability is approximately
 * n^2 / 2^65 for a 64-bit key:
 *
 *     10,000 distinct values     ~ 2.7e-12
 *     1,000,000 distinct values  ~ 2.7e-8
 *     100,000,000 distinct       ~ 2.7e-4
 *
 * The reference dump holds 852 distinct user agents; a large site holds tens of thousands. At
 * one million the chance of any collision at all is about one in forty million, and the
 * consequence is one merged report row.
 *
 * ── Why not the natural key ────────────────────────────────────────────────────────────────
 *
 * A `VARCHAR(2048)` user agent cannot be a primary key — InnoDB's index limit is 3072 bytes and
 * a prefix index would collide constantly on strings that differ only in a version suffix at the
 * end. Storing the full string on every fact row is what ADR-4 already does; the point of Layer 1
 * is to stop doing that for the PARSED dimensions, per ratified decision P4.
 */
final class SurrogateKey
{
    /**
     * Key width in bytes. Also the width of the BINARY() column in the manifest, and the two
     * MUST agree — a mismatch silently truncates, so every key ending in the same 8 bytes
     * becomes one row.
     */
    public const WIDTH = 8;

    /**
     * The hash. Not a cryptographic choice — this is a lookup key, not a secret — but it must be
     * STABLE ACROSS PHP VERSIONS AND PLATFORMS, because a key computed on one host has to match
     * one computed on another writing to the same external database (topology B/D).
     *
     * xxh3 would be faster and is available from PHP 8.1, but the plugin's floor is 7.4, and a
     * key that changes when the host upgrades PHP would orphan every dimension row already
     * written. md5 is available everywhere and its distribution is far better than this needs.
     */
    private const ALGO = 'md5';

    /**
     * Derive the surrogate key for a dimension value.
     *
     * Returns raw binary, sized to WIDTH, suitable for a BINARY(WIDTH) column.
     *
     * NORMALISATION IS PART OF THE KEY, and it is deliberately minimal: trim and nothing else.
     * Lowercasing would merge user agents that genuinely differ, and any richer normalisation
     * becomes a second parser that must agree with whatever reads the dimension back — the
     * "two parsers, one format" shape that has already cost this programme twice.
     *
     * @param string $value The dimension's natural value.
     */
    public static function for(string $value): string
    {
        return substr(hash(self::ALGO, trim($value), true), 0, self::WIDTH);
    }

    /**
     * The same key as lowercase hex, for logging, tests and SQL literals.
     *
     * `bin2hex()` rather than a second hash call: deriving the hex form independently would be
     * two implementations of one key, free to disagree.
     */
    public static function hex(string $value): string
    {
        return bin2hex(self::for($value));
    }

    /**
     * The key for the EMPTY value, which is a real case and not an error.
     *
     * A hit with no user agent still needs a dimension row, or the fact row carries a key that
     * joins to nothing and the report silently drops it. Giving empty its own deterministic key
     * keeps the join total.
     */
    public static function empty(): string
    {
        return self::for('');
    }
}
