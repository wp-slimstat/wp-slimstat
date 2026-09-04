<?php
/**
 * get_results() must execute the caller's SQL verbatim — the Query converter it carried
 * could only ever mangle what it matched, and it stays deleted.
 *
 * ── What the converter actually did ─────────────────────────────────────────────────
 *
 * The parse was `/SELECT (.+) FROM [^ ]+ WHERE (.+?)( GROUP BY …)?( ORDER BY …)?…/is`.
 * Every group after the WHERE capture is optional, so the lazy `(.+?)` matched exactly
 * ONE character. Driven through the real method against a recording handle:
 *
 *     caller sent:     SELECT username, COUNT(*) AS pageviews FROM wp_slim_stats
 *                      WHERE username IS NOT NULL GROUP BY username
 *     handle received: SELECT username, COUNT(*) AS pageviews FROM slim_stats
 *                      WHERE (u) LIMIT 100 OFFSET 0
 *
 * One character of the WHERE clause, the GROUP BY dropped, a LIMIT 100 the caller never
 * wrote — and the wrong rows cached for ten minutes under the md5 of the ORIGINAL sql.
 * This held for every statement the regex matched, since the day it was written.
 *
 * ── Why no report ever showed it ────────────────────────────────────────────────────
 *
 * The regex demands literal single spaces around FROM and WHERE. Every shipped caller
 * ships tab-indented multiline SQL, so none ever converted (the same formatting
 * asymmetry D72 measured: 3 calls on slimview3, 0 converted). Pro's UserOverviewAddon
 * even carried a comment crediting its `t1` alias as the "load-bearing" protection —
 * the alias does also defeat the regex, but the operative accident was the indentation.
 * A guard nobody can name is a whitespace reformat away from not being one.
 *
 * get_var()'s parsers are NOT this defect: they anchor with `…$/`, which forces the
 * lazy quantifier to capture the full WHERE clause. They stay.
 *
 * This test drives the REAL method and asserts the post-deletion contract: any SELECT —
 * single-line or multiline — reaches the handle byte-for-byte, nothing invents a LIMIT,
 * and the D72 cache symmetry (a transient read on entry is written on exit for a
 * historical window) survives on the verbatim path.
 */

declare(strict_types=1);

error_reporting(E_ALL);

// ── minimal WordPress surface, one stub per function the exercised paths touch ──────
// ABSPATH first: src/Utils/*.php open with a direct-access guard that exit(0)s without
// it — a silent green-shaped death this harness must not be able to die.
define('ABSPATH', '/');
define('MINUTE_IN_SECONDS', 60);
define('OBJECT', 'OBJECT');
define('ARRAY_A', 'ARRAY_A');

$GLOBALS['__transient_writes'] = [];

function apply_filters($tag, $value)
{
    return $value;
}

function get_transient($key)
{
    return false;
}

function set_transient($key, $value, $ttl = 0)
{
    $GLOBALS['__transient_writes'][] = $key;
    return true;
}

function __($text, $domain = null)
{
    return $text;
}

/**
 * Records every statement that reaches the database, whitespace-normalised. All four read
 * methods record: a restored converter must be caught whichever wpdb entry point the
 * builder routes through.
 */
class SlimstatRecorderWpdb
{
    public $prefix = 'wp_';

    public $queries = [];

    /** THE comparison policy of this test — the expected side must use the same one. */
    public static function normalise($sql)
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) $sql));
    }

    public function get_results($sql, $output = null)
    {
        $this->queries[] = self::normalise($sql);
        return [];
    }

    public function get_row($sql, $output = null)
    {
        $this->queries[] = self::normalise($sql);
        return null;
    }

    public function get_var($sql)
    {
        $this->queries[] = self::normalise($sql);
        return null;
    }

    public function query($sql)
    {
        $this->queries[] = self::normalise($sql);
        return 0;
    }

    public function prepare($sql, ...$args)
    {
        return $sql;
    }
}

class wp_slimstat
{
    public static $settings = ['show_sql_debug' => 'no'];

    public static $wpdb;
}

$plugin_root = dirname(__DIR__);
require_once $plugin_root . '/src/Utils/NetworkMerge.php';
require_once $plugin_root . '/src/Utils/Query.php';
require_once $plugin_root . '/admin/view/wp-slimstat-db.php';

$failures = [];

// A historical window, so the D72 write-back gate (window_is_cacheable) is open and the
// cache-symmetry assertion below is REACHABLE — with the gate shut, assertion 3 could
// never fail and would be vacuous.
wp_slimstat_db::$filters_normalized['utime'] = ['start' => 1, 'end' => 1000000];

// The single-line shape is the one the deleted regex matched; the multiline twin is what
// every shipped caller actually sends. Same statement, same expected treatment.
$single_line = 'SELECT username, COUNT(*) AS pageviews FROM wp_slim_stats WHERE username IS NOT NULL GROUP BY username';
$multiline   = "\n\t\t\tSELECT username, COUNT(*) AS pageviews\n\t\t\tFROM wp_slim_stats\n\t\t\tWHERE username IS NOT NULL\n\t\t\tGROUP BY username";
$normalised  = SlimstatRecorderWpdb::normalise($multiline);

foreach (['single-line' => $single_line, 'multiline' => $multiline] as $shape => $sql) {
    wp_slimstat::$wpdb = new SlimstatRecorderWpdb();
    // The method reads $GLOBALS['wpdb']->prefix for the table name and executes on
    // wp_slimstat::$wpdb — same object here, as on any install without the external-DB addon.
    $GLOBALS['wpdb'] = wp_slimstat::$wpdb;
    $GLOBALS['__transient_writes'] = [];

    try {
        wp_slimstat_db::get_results($sql);
    } catch (\Throwable $t) {
        // No `continue`: a converter that THROWS mid-rewrite still leaves the recorder
        // empty, so the verbatim assertion below fires either way — one expect string for
        // the mutation gate, whether the restored defect mangles or dies.
        $failures[] = "$shape call threw " . get_class($t) . ': ' . $t->getMessage();
    }

    // ── 1. verbatim, exactly once ───────────────────────────────────────────────────
    $expected = 'single-line' === $shape ? $sql : $normalised;
    if ([$expected] !== wp_slimstat::$wpdb->queries) {
        $failures[] = "$shape statement did not reach the handle verbatim-once; it received: "
            . (wp_slimstat::$wpdb->queries ? implode(' || ', wp_slimstat::$wpdb->queries) : '(nothing)');
    }

    // ── 2. nothing invents a LIMIT the caller never wrote ───────────────────────────
    foreach (wp_slimstat::$wpdb->queries as $executed) {
        if (false !== stripos($executed, 'LIMIT')) {
            $failures[] = "$shape path invented a LIMIT the caller never wrote: $executed";
        }
    }

    // ── 3. D72 symmetry: the transient read on entry is written on exit ─────────────
    if ([] === $GLOBALS['__transient_writes']) {
        $failures[] = "$shape path read a transient it never wrote — the D72 asymmetry is back";
    }
}

if ([] !== $failures) {
    foreach ($failures as $f) {
        fwrite(STDERR, "FAIL: $f\n");
    }
    exit(1);
}

echo "OK: get_results() executes verbatim in both shapes, invents nothing, and caches symmetrically\n";
