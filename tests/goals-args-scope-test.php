<?php
/**
 * get_goals_raw() must honour the caller's WHERE — and its caches must key on it.
 *
 * ── D58 ─────────────────────────────────────────────────────────────────────────────
 *
 * The per-author email loop ANDs `author = %s` into the args of every report it mails.
 * get_goals_raw()/get_funnels_raw() declared $_args and read none of it, so every
 * author was mailed the SITE-WIDE numbers under their own name (the registered
 * Expected Diff is R9: each author's numbers FALL to their own).
 *
 * The WHERE was half the defect. The caches were the other half: the goal transient
 * was keyed on (goal id, window, filters), the funnel transient on (steps, window,
 * filters), the unique-visitor denominator on (window, filters), and a per-request
 * memo sat in front of each — none of them scope-aware. The email cron runs EVERY
 * author in ONE request, so even a fixed WHERE without fixed keys serves the first
 * caller's numbers to all the rest.
 *
 * This test drives the REAL get_goals_raw() AND get_funnels_raw() against a recording
 * handle and an in-memory transient store, in the cron's own calling order, and pins
 * both halves for both paths:
 *
 *   1. the scoped call's SQL carries the caller's WHERE;
 *   2. a scoped call after an unscoped one runs its own queries — it is not served
 *      the unscoped result out of the transient written moments earlier.
 *
 * The stub handle cannot verify funnel NUMBERS (the temp-table pipeline needs a real
 * server; the run-goals-author-scope.sh cell owns that, with hand-computed truths),
 * but it CAN verify the funnel statements carry the scope: the pipeline's strict
 * `false === $created` check lets a recorder that answers 0 sail into step one, whose
 * CREATE ... AS SELECT embeds the scoped $date_where. Without this half, reverting
 * the funnel threading would pass every composer gate and only an on-demand docker
 * cell could notice — the "discarded one call deeper" shape all over again.
 */

declare(strict_types=1);

// ABSPATH first: src/Utils/*.php open with a direct-access guard that exit(0)s
// without it — a silent green-shaped death this harness must not be able to die.
define('ABSPATH', '/');
define('MINUTE_IN_SECONDS', 60);
define('OBJECT', 'OBJECT');
define('ARRAY_A', 'ARRAY_A');

error_reporting(E_ALL);

// ── WordPress surface: options + a REAL (in-memory) transient store ─────────────────
$GLOBALS['__options'] = [
    'slimstat_goals' => [
        ['id' => 'g1', 'active' => 1, 'name' => 'Buy page', 'dimension' => 'resource', 'operator' => 'contains', 'value' => '/buy'],
    ],
    'slimstat_funnels' => [
        ['id' => 'f1', 'name' => 'Landing to signup', 'steps' => [
            ['name' => 'Landing', 'dimension' => 'resource', 'operator' => 'equals', 'value' => '/f1'],
            ['name' => 'Signup', 'dimension' => 'resource', 'operator' => 'equals', 'value' => '/f2'],
        ]],
    ],
    'slimstat_goals_cache_ver' => '0',
];
$GLOBALS['__transients'] = [];

function get_option($name, $default = false)
{
    return $GLOBALS['__options'][$name] ?? $default;
}

function apply_filters($tag, $value)
{
    // Free's funnel cap defaults to 0 and get_funnels_raw() bails on it; the email
    // cron runs where Pro raises the cap, which is the world under test here.
    return 'slimstat_max_funnels' === $tag ? 10 : $value;
}

// A transient store that actually stores: the cache-poisoning half of D58 is only
// observable if a second call can HIT what the first call wrote.
function get_transient($key)
{
    return $GLOBALS['__transients'][$key] ?? false;
}

function set_transient($key, $value, $ttl = 0)
{
    $GLOBALS['__transients'][$key] = $value;
    return true;
}

function __($text, $domain = null)
{
    return $text;
}

/** Records every statement; answers every scalar with 0 and every row set empty. */
class SlimstatRecorderWpdb
{
    public $prefix = 'wp_';

    public $queries = [];

    /** THE comparison policy of this test — every recorder method uses the same one. */
    public static function normalise($sql)
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) $sql));
    }

    public function get_var($sql)
    {
        $this->queries[] = self::normalise($sql);
        return '0';
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

    public function query($sql)
    {
        $this->queries[] = self::normalise($sql);
        return 0;
    }

    public function prepare($sql, ...$args)
    {
        foreach ($args as $a) {
            $sql = preg_replace('/%[sd]/', is_int($a) ? (string) $a : "'" . $a . "'", $sql, 1);
        }
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

wp_slimstat::$wpdb = new SlimstatRecorderWpdb();
$GLOBALS['wpdb']   = wp_slimstat::$wpdb;

// A pinned historical window — the shape init() would build, minus everything the
// goal path does not read.
wp_slimstat_db::$filters_normalized = [
    'utime'   => ['start' => 1, 'end' => 1000000, 'range' => 999999],
    'columns' => [],
    'misc'    => [],
];

$scoped_where = "author = 'alice'";
$summary      = [];

foreach (['goals' => 'get_goals_raw', 'funnels' => 'get_funnels_raw'] as $path => $method) {
    // ── the cron's order: site-wide first, scoped second ────────────────────────────
    $queries_before_site = count(wp_slimstat::$wpdb->queries);
    wp_slimstat_db::$method([]);
    $site_queries = array_slice(wp_slimstat::$wpdb->queries, $queries_before_site);

    $queries_before_scoped = count(wp_slimstat::$wpdb->queries);
    wp_slimstat_db::$method(['where' => $scoped_where]);
    $scoped_queries = array_slice(wp_slimstat::$wpdb->queries, $queries_before_scoped);

    // ── 1. the site-wide call must NOT carry the scope, and must have queried ───────
    if ([] === $site_queries) {
        $failures[] = "$path: the unscoped call issued no queries — the harness is not exercising the path";
    }
    foreach ($site_queries as $sql) {
        if (false !== stripos($sql, $scoped_where)) {
            $failures[] = "$path: the UNSCOPED call carried the author clause: $sql";
        }
    }

    // ── 2. the scoped call queries again (not served the site-wide transient) ───────
    if ([] === $scoped_queries) {
        $failures[] = "$path: the scoped call issued NO queries — it was served the site-wide "
            . 'result out of a scope-blind cache, which is D58\'s cache half: every author '
            . 'after the first gets the first caller\'s numbers';
    }

    // ── 3. the scoped call's SQL carries the caller's WHERE ─────────────────────────
    $carrying = 0;
    foreach ($scoped_queries as $sql) {
        if (false !== stripos($sql, $scoped_where)) {
            $carrying++;
        }
    }
    if (0 === $carrying) {
        $failures[] = "$path: no scoped query carries the caller's WHERE — \$_args is still "
            . 'declared and discarded, so every author is mailed the site-wide numbers (D58)';
    }

    $summary[] = sprintf('%s %d/%d/%d', $path, $carrying, count($site_queries), count($scoped_queries));
}

if ([] !== $failures) {
    foreach ($failures as $f) {
        fwrite(STDERR, "FAIL: $f\n");
    }
    exit(1);
}

printf(
    "OK: goals AND funnels honour the caller's WHERE (carrying/unscoped/scoped: %s)\n",
    implode(' · ', $summary)
);
