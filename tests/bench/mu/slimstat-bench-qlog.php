<?php
/**
 * Plugin Name: SlimStat bench — per-request query ledger
 * Description: Counts every SQL statement a request issues, split by table and by
 *              read/write, and appends one JSON line per request. Test instrument only.
 *
 * Why a mu-plugin and not SAVEQUERIES: SAVEQUERIES stores the full SQL text plus a
 * backtrace for every query, which is heavy enough to distort the very latency we are
 * measuring, and Query Monitor's own reporting is UI-driven. This hooks the `query`
 * filter — the single choke point every wpdb statement passes through — and does
 * nothing but increment integers.
 *
 * ARMING IT TAKES TWO THINGS, and only one of them is in this repository.
 * `SLIMSTAT_BENCH` must be defined in wp-config.php, AND the request must carry the
 * `X-Slimstat-Bench` header. The header alone used to be enough, and the header's name
 * is public — so anyone who read the repo could turn a query ledger on for any request
 * on any install that still had this file. The constant is the authorization; the
 * header is only a label saying which run a line belongs to.
 *
 * Output goes OUTSIDE the docroot (sys_get_temp_dir()/slimstat-bench), because
 * wp-content/uploads is web-served and the ledger can contain statement text. The
 * directory is 0700, the file is capped, and option writes are redacted by name — the
 * daily IP-hash salt passes through the same `query` filter this hooks, and a ledger
 * that records it hands over the key to every hash on the site.
 *
 * @package wp-slimstat-tests
 */

if (!defined('ABSPATH')) {
    exit;
}

// Two independent conditions. The constant cannot be set by a request; the header
// cannot be guessed into meaning anything without it.
if (!defined('SLIMSTAT_BENCH') || !SLIMSTAT_BENCH) {
    return;
}

// The label may arrive as a request header OR, under WP-CLI, as an environment variable.
// Both are only LABELS — `SLIMSTAT_BENCH` above is the authorization, and neither of these can
// set it. The env path exists because the schema reconcile, the migration runner and the
// activation sequence have no HTTP request to carry a header, so they were unmeasurable: every
// query-count claim about them came from reading code rather than from counting.
//
// getenv() and not $_ENV: variables passed through `docker compose exec -e` and through WP-CLI
// reach getenv() reliably, while $_ENV depends on the variables_order ini setting.
if (empty($_SERVER['HTTP_X_SLIMSTAT_BENCH']) && false === getenv('SLIMSTAT_BENCH_LABEL')) {
    return;
}

final class SlimStat_Bench_QLog
{
    /** Rotate the ledger past this size rather than filling the disk. */
    private const MAX_LOG_BYTES = 8388608;   // 8 MB

    /** Statements kept per request; the rest are counted, not stored. */
    private const MAX_SQL_ROWS = 200;

    /**
     * Option names whose VALUE must never reach the ledger.
     *
     * These writes pass through the same `query` filter this hooks, so a verbatim
     * capture would record them. slimstat_daily_salt is the key that makes every
     * hashed IP on the site reversible; the auth keys and the tracking secret are
     * self-evident. Matched on the statement text because at this layer that is all
     * there is — the filter sees SQL, not an option API call.
     *
     * @var string[]
     */
    private const REDACT = [
        'slimstat_daily_salt',
        'slimstat_options',
        'auth_key',
        'auth_salt',
        'secret',
        '_transient_',
    ];

    /** @var array<string,int> */
    private static $counts = [];

    /** @var int Statements dropped past MAX_SQL_ROWS — reported, never silent. */
    private static $sql_truncated = 0;

    /** @var string */
    private static $label = '';

    /** @var float */
    private static $started = 0.0;

    /**
     * `writes` records every write statement verbatim, `all` records reads too.
     * Counts tell you a hit costs three option writes; only the text tells you
     * which three.
     *
     * @var string
     */
    private static $sample_sql = '';

    /** @var string[] */
    private static $sql = [];

    public static function boot(): void
    {
        $label = $_SERVER['HTTP_X_SLIMSTAT_BENCH'] ?? getenv('SLIMSTAT_BENCH_LABEL');
        $sql   = $_SERVER['HTTP_X_SLIMSTAT_BENCH_SQL'] ?? getenv('SLIMSTAT_BENCH_SQL');

        self::$sample_sql = strtolower((string) $sql);
        self::$label      = substr(preg_replace('/[^\w.\-]/', '', (string) $label), 0, 64);
        self::$started    = microtime(true);

        add_filter('query', [self::class, 'tally'], 0);
        add_action('shutdown', [self::class, 'flush'], PHP_INT_MAX);

        // The tracking endpoints call exit() from inside their handler. `shutdown`
        // still runs on exit(), but only if WordPress reached it — register a raw
        // shutdown function too so a fatal or an early exit() still yields a row
        // rather than a silently missing sample.
        register_shutdown_function([self::class, 'flush']);
    }

    /**
     * @param string $query
     * @return string
     */
    public static function tally($query)
    {
        static $options = null;
        if (null === $options) {
            $options = isset($GLOBALS['wpdb']) ? $GLOBALS['wpdb']->options : 'wp_options';
        }

        // A statement is a write if it starts with one of the DML/DDL verbs. Matched on
        // the query directly, with `\s*` absorbing the leading whitespace the plugin's
        // heredoc SQL carries — an ltrim() here would copy the entire statement, and on
        // the multi-kilobyte slim_stats INSERT that allocation lands in the very latency
        // this instrument reports.
        $is_write = (bool) preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE)/i', (string) $query);

        self::bump('total');
        self::bump($is_write ? 'writes' : 'reads');

        if (strpos($query, $options) !== false) {
            self::bump($is_write ? 'options_writes' : 'options_reads');
        }
        // Matched on the ESCAPED form too. A `SHOW TABLES LIKE 'wp\_slim\_%'` is a slimstat read,
        // but LIKE-escaping puts a backslash between `slim` and `_`, so the literal `slim_`
        // never appears and the statement went uncounted. Found by a blind adjudication of two
        // arms: it reported slim_reads 9 -> 3 where the true figure was 9 -> 4, because the arm
        // that replaced four per-object probes with one pattern query had that one query
        // silently excluded. A classifier that undercounts the arm it is measuring flatters it.
        if (strpos($query, 'slim_') !== false || strpos($query, 'slim\\_') !== false) {
            self::bump($is_write ? 'slim_writes' : 'slim_reads');
        }

        if (self::$sample_sql === 'all' || (self::$sample_sql === 'writes' && $is_write)) {
            if (count(self::$sql) >= self::MAX_SQL_ROWS) {
                // Counted, not dropped in silence: a truncated ledger that looks
                // complete is worse than no ledger.
                self::$sql_truncated++;
            } else {
                self::$sql[] = self::redact(trim(preg_replace('/\s+/', ' ', (string) $query)));
            }
        }

        return $query;
    }

    public static function flush(): void
    {
        static $written = false;
        if ($written) {
            return;
        }
        $written = true;

        // Outside the docroot and 0700: uploads/ is web-served, and the previous 0777
        // made the ledger world-readable on any shared host.
        $dir = self::directory();
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }

        $file = $dir . '/qlog.jsonl';

        // Append-only and uncapped is a disk-filler on a long run. Rotate rather than
        // truncate, so an interrupted investigation does not lose its evidence.
        if (is_file($file) && filesize($file) > self::MAX_LOG_BYTES) {
            @rename($file, $file . '.1');
        }

        $row = array_merge(
            [
                'label' => self::$label,
                'ms'    => round((microtime(true) - self::$started) * 1000, 2),
            ],
            self::$counts,
            self::$sql === [] ? [] : ['sql' => self::$sql]
        );

        if (self::$sql_truncated > 0) {
            $row['sql_truncated'] = self::$sql_truncated;
        }

        file_put_contents($file, json_encode($row) . "\n", FILE_APPEND | LOCK_EX);
    }

    /** Where the ledger lives. Never under the docroot. */
    private static function directory(): string
    {
        if (defined('SLIMSTAT_BENCH_DIR') && SLIMSTAT_BENCH_DIR) {
            return rtrim((string) SLIMSTAT_BENCH_DIR, '/');
        }

        return rtrim(sys_get_temp_dir(), '/') . '/slimstat-bench';
    }

    /**
     * Replace the whole statement when it touches a secret.
     *
     * Not a value-level scrub: the point is that this instrument should never be the
     * reason a key leaves the database, and a partial redaction is a promise that has
     * to be re-audited every time the SQL shape changes.
     */
    private static function redact(string $sql): string
    {
        $haystack = strtolower($sql);

        foreach (self::REDACT as $needle) {
            if (strpos($haystack, $needle) !== false) {
                return '[redacted: statement referenced ' . $needle . ']';
            }
        }

        return $sql;
    }

    private static function bump(string $key): void
    {
        self::$counts[$key] = (self::$counts[$key] ?? 0) + 1;
    }
}

SlimStat_Bench_QLog::boot();
