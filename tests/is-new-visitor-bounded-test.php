<?php
/**
 * Regression test: deciding whether a visitor is new must not scan their history (D43).
 *
 * `Utils::isNewVisitor()` asked `SELECT COUNT(id) ... WHERE fingerprint = ?` and compared
 * the answer to zero — so it counted every row a visitor had ever generated in order to
 * learn whether they had generated any. It runs on every follow-up event.
 *
 * Measured on the reference install (443k rows, `fingerprint(20)` prefix index):
 *
 *     rows for that fingerprint    COUNT      LIMIT 1
 *     p50  (1)                     0.066 ms   0.093 ms    no gain
 *     p95  (10)                    0.175 ms   0.088 ms    2.0x
 *     p99  (48)                    0.065 ms   0.030 ms    2.2x
 *     heaviest (2,787)             1.825 ms   0.044 ms    41x       2,787 -> 1 rows read
 *
 * So this is not a broad speedup — for a typical visitor it changes nothing. What it
 * removes is *unbounded growth*: the old cost rose linearly with a visitor's history and
 * was paid again on their every event, which is worst for exactly the visitors who
 * generate the most events.
 *
 * The invariants are about the SHAPE of the question, not the timing — timing is
 * environment-dependent and a test that asserts milliseconds is a flaky test. The
 * measurements above were taken against the live table and belong to the write-up, not
 * to an assertion.
 *
 *   1. The emitted statement stops at the first matching row.
 *   2. It asks for existence, not a count — a COUNT cannot short-circuit.
 *   3. It is not cached: the builder keys its cache on the prepared SQL, which carries
 *      the fingerprint, so caching writes two wp_options rows per distinct visitor from
 *      the tracking path — measured at 5 queries on a miss to save one 0.06 ms lookup.
 *   4. The answer is still correct in both directions.
 *   5. The privacy short-circuits still come first: with hashed or anonymized IPs the
 *      function must not query at all.
 *
 * Asserted against the statement the query builder actually emits, not by scanning the
 * source file — a source scan matches the fix's own explanatory comment, which names
 * the COUNT it removed, and it cannot see what the builder finally produces.
 *
 * @see src/Tracker/Utils.php
 */

declare(strict_types=1);

namespace {

use SlimStat\Tracker\Utils;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}
if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', false);
}

$GLOBALS['_inv_queries'] = [];
$GLOBALS['_inv_rows']    = 0;

/**
 * Minimal stand-in for the query builder's terminal call. It records the SQL that would
 * run and answers from a fixture, so the test can assert the query's shape without a
 * database.
 */
class InvFakeWpdb
{
    public $prefix = 'wp_';

    /** @var int How many rows the fixture holds for the fingerprint being asked about. */
    public $rows_for_fingerprint = 0;

    public function prepare($sql, ...$a)
    {
        foreach ($a as $v) {
            $sql = preg_replace('/%[dsf]/', is_int($v) ? (string) $v : "'" . $v . "'", $sql, 1);
        }
        return $sql;
    }

    public function get_var($sql)
    {
        $GLOBALS['_inv_queries'][] = trim(preg_replace('/\s+/', ' ', (string) $sql));

        // A LIMIT-bounded existence probe reads at most that many rows; a COUNT reads
        // every matching row. Modelling that is what lets the test see the difference.
        if (preg_match('/LIMIT\s+(\d+)/i', $sql, $m)) {
            $GLOBALS['_inv_rows'] += min($this->rows_for_fingerprint, (int) $m[1]);
            return $this->rows_for_fingerprint > 0 ? 1 : null;
        }

        $GLOBALS['_inv_rows'] += $this->rows_for_fingerprint;
        return (string) $this->rows_for_fingerprint;
    }

    public function get_results($sql, $mode = null)
    {
        $this->get_var($sql);
        return [];
    }
}

// The builder's caching path calls these. Stubbed so that turning caching on runs to
// completion and trips the "touches no transient" assertion, rather than fataling on an
// undefined function — a fatal is a detected failure, but it is not the assertion doing
// the work, and a later refactor could make it silently disappear.
$GLOBALS['_inv_transient_reads']  = [];
$GLOBALS['_inv_transient_writes'] = [];

if (!function_exists('get_transient')) {
    function get_transient($key)
    {
        $GLOBALS['_inv_transient_reads'][] = $key;
        return false;
    }
}
if (!function_exists('set_transient')) {
    function set_transient($key, $value, $ttl = 0)
    {
        $GLOBALS['_inv_transient_writes'][] = $key;
        return true;
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $flags = 0)
    {
        return json_encode($data, $flags);
    }
}

// ── Harness ─────────────────────────────────────────────────────────────────

$failures = [];
$passes   = 0;

function inv_assert(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $passes;
    if ($ok) {
        $passes++;
        return;
    }
    $failures[] = $name . ($detail !== '' ? " — {$detail}" : '');
}

// ── 1-3. Asserted on the SQL the method actually emits ──────────────────────
//
// Not by scanning the source file: a source scan matches the fix's own explanatory
// comment (which names the COUNT it removed), and it cannot see what the query builder
// finally produces. Capturing the statement is both stronger and immune to prose.
require_once __DIR__ . '/../src/Utils/Query.php';

if (!class_exists('wp_slimstat')) {
    class wp_slimstat
    {
        /** @var array<string,mixed> */
        public static $settings = ['hash_ip' => 'off', 'anonymize_ip' => 'off'];
        public static $wpdb     = null;
        public static function get_stat()
        {
            return ['dt' => 0];
        }
        public static function log($m, $l = 'info') {}
    }
}

$db = new InvFakeWpdb();
$GLOBALS['wpdb']    = $db;
\wp_slimstat::$wpdb = $db;

require_once __DIR__ . '/../src/Tracker/Utils.php';

foreach ([
    'a visitor with no history is new'        => [0, true],
    'a visitor with one prior row is not new' => [1, false],
    'a heavy visitor is not new'              => [2787, false],
] as $label => [$rows, $expected]) {
    $db->rows_for_fingerprint         = $rows;
    $GLOBALS['_inv_queries']          = [];
    $GLOBALS['_inv_rows']             = 0;
    $GLOBALS['_inv_transient_reads']  = [];
    $GLOBALS['_inv_transient_writes'] = [];

    $actual = Utils::isNewVisitor('fingerprint-under-test');
    inv_assert($label, $actual === $expected, 'got ' . var_export($actual, true));

    // And the cost must not track the visitor's history.
    inv_assert(
        "answering costs at most one row for a visitor with {$rows}",
        $GLOBALS['_inv_rows'] <= 1,
        $GLOBALS['_inv_rows'] . ' rows read — the cost still grows with visit history'
    );

    // The statement itself, as the builder finally rendered it. This is what makes the
    // row-count assertion above non-circular: the fake derives its row count from the
    // LIMIT, so the LIMIT has to be shown to be there.
    $sql = $GLOBALS['_inv_queries'][0] ?? '';
    inv_assert(
        "the emitted statement is bounded ({$rows} rows)",
        (bool) preg_match('/\bLIMIT\s+1\b/i', $sql),
        'emitted: ' . $sql
    );
    inv_assert(
        "the emitted statement does not COUNT ({$rows} rows)",
        !preg_match('/\bCOUNT\s*\(/i', $sql),
        'a COUNT must visit every match before it can be compared to zero; emitted: ' . $sql
    );
    inv_assert(
        "the lookup touches no transient ({$rows} rows)",
        $GLOBALS['_inv_transient_reads'] === [] && $GLOBALS['_inv_transient_writes'] === [],
        count($GLOBALS['_inv_transient_reads']) . ' reads / '
            . count($GLOBALS['_inv_transient_writes']) . ' writes — the builder keys its '
            . 'cache on the prepared SQL, which carries the fingerprint, so caching this '
            . 'writes two wp_options rows per distinct visitor from the tracking path'
    );
}

// ── 5. Privacy modes issue no query at all ──────────────────────────────────
foreach (['hash_ip', 'anonymize_ip'] as $setting) {
    \wp_slimstat::$settings = ['hash_ip' => 'off', 'anonymize_ip' => 'off'];
    \wp_slimstat::$settings[$setting] = 'on';
    $GLOBALS['_inv_queries'] = [];

    $result = Utils::isNewVisitor('fingerprint-under-test');
    inv_assert("{$setting}=on issues no query", $GLOBALS['_inv_queries'] === [], 'it queried anyway');
    inv_assert("{$setting}=on reports not-new", $result === false, 'got ' . var_export($result, true));
}

// ── Report ──────────────────────────────────────────────────────────────────
if ($failures !== []) {
    fwrite(STDERR, 'FAIL: isNewVisitor bounded (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

printf("PASS: isNewVisitor bounded (%d assertions)\n", $passes);
exit(0);

}
