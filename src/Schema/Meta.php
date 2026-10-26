<?php
/**
 * The C48 identity and lease primitives on `slim_meta`.
 *
 * Every method takes the wpdb HANDLE it should speak to, because the whole point of the
 * table is that it lives on the connection it describes — the analytics database,
 * whichever server that is. Passing `\wp_slimstat::$wpdb` reads the analytics install's
 * identity; passing `$GLOBALS['wpdb']` would read a different table on a plain install
 * and nothing at all on an external-DB one.
 *
 * Identity (`install_uuid`, `owner_site_url`) is written with a bare INSERT IGNORE so
 * the PRIMARY KEY rejects the loser of a concurrent mint, and callers are answered from
 * a RE-READ — what the table holds, not what this process tried to write. A lease is one
 * upsert whose steal condition lives in SQL (expired, or already mine), then a read-back
 * that alone decides the verdict: rows-affected answers 0, 1 or 2 here and none of those
 * says who holds the row under a concurrent writer.
 *
 * @package WpSlimstat
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace SlimStat\Schema;

use wpdb;

final class Meta
{
    public const KEY_INSTALL_UUID   = 'install_uuid';
    public const KEY_OWNER_SITE_URL = 'owner_site_url';

    /** One value by key, or null when the row (or the table) is absent. */
    public static function get(wpdb $db, string $prefix, string $key): ?string
    {
        $value = $db->get_var($db->prepare(
            "SELECT meta_value FROM {$prefix}slim_meta WHERE meta_key = %s",
            $key
        ));

        return null === $value ? null : (string) $value;
    }

    /**
     * A bare INSERT IGNORE — the PRIMARY KEY rejects the loser, and losing is fine:
     * first-writer-wins is the contract, so callers re-read rather than trust this.
     */
    public static function putIfAbsent(wpdb $db, string $prefix, string $key, string $value): bool
    {
        return false !== $db->query($db->prepare(
            "INSERT IGNORE INTO {$prefix}slim_meta (meta_key, meta_value, dt) VALUES (%s, %s, 0)",
            $key,
            $value
        ));
    }

    /**
     * Unconditional upsert — for the one caller allowed to overwrite: the
     * SLIMSTAT_EXT_DB_ADOPT re-claim, which exists precisely to replace another
     * install's owner_site_url with this site's. Everything else wants putIfAbsent().
     */
    public static function put(wpdb $db, string $prefix, string $key, string $value): bool
    {
        return false !== $db->query($db->prepare(
            "INSERT INTO {$prefix}slim_meta (meta_key, meta_value, dt) VALUES (%s, %s, 0)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
            $key,
            $value
        ));
    }

    /**
     * Mint `install_uuid` + `owner_site_url` if absent, and return what the table HOLDS
     * afterwards — under a concurrent mint that is the winner's identity, not ours.
     *
     * @return array{install_uuid: ?string, owner_site_url: ?string}
     */
    public static function ensureIdentity(wpdb $db, string $prefix): array
    {
        $uuid  = self::get($db, $prefix, self::KEY_INSTALL_UUID);
        $owner = self::get($db, $prefix, self::KEY_OWNER_SITE_URL);

        return [
            self::KEY_INSTALL_UUID   => $uuid ?? self::mint($db, $prefix, self::KEY_INSTALL_UUID, \wp_generate_uuid4()),
            self::KEY_OWNER_SITE_URL => $owner ?? self::mint($db, $prefix, self::KEY_OWNER_SITE_URL, (string) \home_url()),
        ];
    }

    /** putIfAbsent, then answer from what the table HOLDS — the winner's value, not ours. */
    private static function mint(wpdb $db, string $prefix, string $key, string $value): ?string
    {
        self::putIfAbsent($db, $prefix, $key, $value);

        return self::get($db, $prefix, $key);
    }

    /**
     * Claim (or renew) a lease. TRUE only when the READ-BACK row holds $holder with an
     * unexpired dt — never inferred from the upsert's return value.
     *
     * The steal condition is in the SQL so it is atomic under the row lock: the update
     * fires when the standing lease is expired (dt < now) or already mine. Assignment
     * order matters and is load-bearing: `dt` is decided FIRST, while `meta_value` still
     * holds the OLD holder; `meta_value` then keys off whether `dt` just became the new
     * expiry (MySQL evaluates ON DUPLICATE KEY UPDATE assignments left to right, each
     * seeing the previous ones' effects).
     */
    public static function claimLease(wpdb $db, string $prefix, string $name, string $holder, int $ttl, int $now): bool
    {
        $key    = 'lease:' . $name;
        $expiry = $now + $ttl;

        $written = $db->query($db->prepare(
            "INSERT INTO {$prefix}slim_meta (meta_key, meta_value, dt) VALUES (%s, %s, %d)
             ON DUPLICATE KEY UPDATE
                 dt         = IF(meta_value = VALUES(meta_value) OR dt < %d, VALUES(dt), dt),
                 meta_value = IF(dt = VALUES(dt), VALUES(meta_value), meta_value)",
            $key,
            $holder,
            $expiry,
            $now
        ));

        if (false === $written) {
            return false;
        }

        $row = $db->get_row($db->prepare(
            "SELECT meta_value, dt FROM {$prefix}slim_meta WHERE meta_key = %s",
            $key
        ));

        return null !== $row
            && $holder === (string) $row->meta_value
            && (int) $row->dt > $now;
    }

    /** Holder-guarded: cannot release a lease someone else has since stolen. */
    public static function releaseLease(wpdb $db, string $prefix, string $name, string $holder): bool
    {
        return false !== $db->query($db->prepare(
            "DELETE FROM {$prefix}slim_meta WHERE meta_key = %s AND meta_value = %s",
            'lease:' . $name,
            $holder
        ));
    }
}
