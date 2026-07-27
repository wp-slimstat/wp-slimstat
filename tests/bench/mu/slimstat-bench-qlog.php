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
 * It is INERT unless the request carries the `X-Slimstat-Bench` header, so it can sit
 * in mu-plugins/ without affecting ordinary browsing.
 *
 * Output: wp-content/uploads/slimstat-bench/qlog.jsonl (one object per bench request).
 *
 * @package wp-slimstat-tests
 */

if (!defined('ABSPATH')) {
    exit;
}

if (empty($_SERVER['HTTP_X_SLIMSTAT_BENCH'])) {
    return;
}

final class SlimStat_Bench_QLog
{
    /** @var array<string,int> */
    private static $counts = [];

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
        self::$sample_sql = strtolower((string) ($_SERVER['HTTP_X_SLIMSTAT_BENCH_SQL'] ?? ''));
        self::$label   = substr(preg_replace('/[^\w.\-]/', '', (string) $_SERVER['HTTP_X_SLIMSTAT_BENCH']), 0, 64);
        self::$started = microtime(true);

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
        if (strpos($query, 'slim_') !== false) {
            self::bump($is_write ? 'slim_writes' : 'slim_reads');
        }

        if (self::$sample_sql === 'all' || (self::$sample_sql === 'writes' && $is_write)) {
            self::$sql[] = trim(preg_replace('/\s+/', ' ', (string) $query));
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

        $dir = WP_CONTENT_DIR . '/uploads/slimstat-bench';
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            return;
        }

        $row = array_merge(
            [
                'label' => self::$label,
                'ms'    => round((microtime(true) - self::$started) * 1000, 2),
            ],
            self::$counts,
            self::$sql === [] ? [] : ['sql' => self::$sql]
        );

        file_put_contents($dir . '/qlog.jsonl', json_encode($row) . "\n", FILE_APPEND | LOCK_EX);
    }

    private static function bump(string $key): void
    {
        self::$counts[$key] = (self::$counts[$key] ?? 0) + 1;
    }
}

SlimStat_Bench_QLog::boot();
