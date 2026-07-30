<?php

namespace SlimStat\Utils;

/**
 * Single-flight claims on a `wp_options` row.
 *
 * WHY NOT THE OPTIONS API. `add_option()` looks like a claim and is not one: it does a
 * PHP-level `get_option()` pre-check and then `INSERT ... ON DUPLICATE KEY UPDATE`,
 * which OVERWRITES — so the unique index never rejects the loser and every concurrent
 * request believes it won. `wp_cache_add()` is atomic only against a PERSISTENT object
 * cache, which most wp.org installs lack, and without one it grants the claim to
 * everybody. Neither offers a compare-and-swap at all.
 *
 * So the two primitives here are raw statements: a bare INSERT, where the unique index
 * on `option_name` picks exactly one winner, and a conditional UPDATE that only matches
 * the value the caller actually read.
 *
 * WHY IT EXISTS AS A CLASS. This was hand-rolled twice — the schema-upgrade lock and
 * the daily-salt mint — and the copies had already diverged on cache invalidation in a
 * way neither author could have got right by looking at the other. The tracker's
 * table-repair guard was going to be the third. Two was the agreed ceiling.
 *
 * @since 5.6.0
 */
final class OptionClaim
{
    /**
     * Create the row, or lose.
     *
     * @param string $name     Option name.
     * @param string $value    Already-serialized value.
     * @param string $autoload 'yes' or 'no' — drives cache invalidation, see flush().
     * @return bool True when THIS caller created it.
     */
    public static function insert($name, $value, $autoload = 'no')
    {
        global $wpdb;

        $suppressed = $wpdb->suppress_errors(true);
        $won        = $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, %s)",
            $name,
            $value,
            $autoload
        ));
        $wpdb->suppress_errors($suppressed);

        // A duplicate-key rejection is the mechanism working, not a fault: only a real
        // write earns an invalidation, and on a read-only database it earns nothing.
        if ($won) {
            self::flush($name, $autoload);
        }

        return (bool) $won;
    }

    /**
     * Replace the row, but only if it still holds exactly what the caller read.
     *
     * The comparison is the claim. Two requests that read the same value both try to
     * swap it; one UPDATE matches, the other affects zero rows and must adopt the
     * winner's value rather than keep its own.
     *
     * @param string $name     Option name.
     * @param string $expected The already-serialized value the caller read.
     * @param string $value    The already-serialized replacement.
     * @param string $autoload 'yes' or 'no' — must match how the row was created.
     * @return bool True when THIS caller swapped it.
     */
    public static function compareAndSwap($name, $expected, $value, $autoload = 'no')
    {
        global $wpdb;

        $suppressed = $wpdb->suppress_errors(true);
        $won        = $wpdb->query($wpdb->prepare(
            "UPDATE `{$wpdb->options}` SET option_value = %s WHERE option_name = %s AND option_value = %s",
            $value,
            $name,
            $expected
        ));
        $wpdb->suppress_errors($suppressed);

        // `false` is a hard error, `0` is a lost race. Neither wrote, so neither has
        // anything to invalidate — and on an unwritable database, invalidating
        // 'alloptions' on every attempt turns a fault into a per-request cache rebuild.
        if ($won) {
            self::flush($name, $autoload);
        }

        return (bool) $won;
    }

    /**
     * Drop the caches the row was just written behind the back of.
     *
     * The autoload flag decides which ones, and getting this wrong is silent: an
     * autoloaded value is served from the `alloptions` blob, so without dropping that
     * the next `get_option()` returns pre-write bytes. A non-autoloaded row is never
     * in that blob, so dropping it there would be a needless cache-wide invalidation.
     * `notoptions` matters either way — a `get_option()` miss before the write caches
     * the row's non-existence.
     *
     * @param string $name
     * @param string $autoload
     * @return void
     */
    private static function flush($name, $autoload)
    {
        wp_cache_delete($name, 'options');
        wp_cache_delete('notoptions', 'options');

        if ('yes' === $autoload) {
            wp_cache_delete('alloptions', 'options');
        }
    }
}
