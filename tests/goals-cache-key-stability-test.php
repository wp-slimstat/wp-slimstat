<?php
/**
 * Regression test: goal / funnel / unique-visitor cache keys must be hittable (D33, D37).
 *
 * Every one of these keys embedded the current date-range WHERE clause, and
 * `init_filters()` clamps a live range's end to **the current second**. So the key
 * changed on every request: a 5- or 15-minute transient was written, never read back,
 * and left to expire. Measured on the reference install — two calls two seconds apart:
 *
 *     ... t1.dt BETWEEN 1782777600 AND 1785185410   ->  key 2427ed36…
 *     ... t1.dt BETWEEN 1782777600 AND 1785185412   ->  key 59897c15…
 *
 * Every goals render therefore ran `2 × goals + 1` uncached queries — including
 * `count_unique_visitors()`, among the heaviest statements in the plugin — and wrote
 * two dead `wp_options` rows per goal. The funnel path had already solved this by
 * bucketing the range end to the hour; goals and the unique-visitor denominator were
 * left behind with a comment saying so.
 *
 * The invariants below are what make bucketing safe. Four of them are about NOT
 * sharing a key: a cache key that is too stable serves one segment's numbers for
 * another, which is far worse than a cache that never hits.
 *
 * @see admin/view/wp-slimstat-db.php
 * @see admin/index.php  clear_goals_cache() — the GC that has to keep finding these rows
 */

declare(strict_types=1);

namespace {

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

require_once __DIR__ . '/lib/source-scan.php';
require_once __DIR__ . '/../admin/view/wp-slimstat-db.php';

if (!method_exists('wp_slimstat_db', 'results_cache_key')) {
    fwrite(STDERR, "FAIL: wp_slimstat_db::results_cache_key() does not exist — the three cache\n"
        . "      keys still each build their own, so there is nothing to test\n");
    exit(1);
}

// ── Harness ─────────────────────────────────────────────────────────────────

$failures = [];
$passes   = 0;

function gck_assert(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $passes;
    if ($ok) {
        $passes++;
        return;
    }
    $failures[] = $name . ($detail !== '' ? " — {$detail}" : '');
}

/**
 * A representative live window: 30 days back, ending "now".
 *
 * The end sits 60 seconds INTO an hour bucket (1785182400 is a boundary). Bucketing
 * makes a key stable for the remainder of its bucket, not for an arbitrary interval,
 * so a test anchored at a random offset would pass or fail depending on where in the
 * hour the constant happened to land. The boundary case is asserted explicitly below
 * rather than left to chance.
 */
const GCK_START = 1782777600;
const GCK_END   = 1785182460;

/**
 * Build a key through the real read path.
 *
 * results_cache_key() reads the window and the column filters from
 * $filters_normalized rather than taking them as arguments, so a caller cannot pair
 * one request's filters with another's window. The test drives the same statics
 * production does; `filters` here is an opaque marker standing for a distinct filter
 * set, since the signature is md5(serialize($columns)).
 */
function gck_key(array $over = []): string
{
    $a = array_merge([
        'prefix'    => 'goal',
        'scope'     => '7',
        'filters'   => ['browser_type' => ['equals', '0']],
        'start'     => GCK_START,
        'end'       => GCK_END,
        'cache_ver' => '100',
    ], $over);

    \wp_slimstat_db::$filters_normalized = [
        'utime'   => ['start' => $a['start'], 'end' => $a['end']],
        'columns' => $a['filters'],
    ];

    $m = new ReflectionMethod('wp_slimstat_db', 'results_cache_key');
    if (PHP_VERSION_ID < 80100) {
        $m->setAccessible(true);
    }
    return $m->invoke(null, $a['prefix'], $a['scope'], $a['cache_ver']);
}

// ── 1. The key survives the clock ticking — the defect itself ───────────────
gck_assert(
    'a key is stable one second later',
    gck_key() === gck_key(['end' => GCK_END + 1]),
    'the key still moves with the wall clock, so the transient can never be read back'
);
gck_assert(
    'a key is stable across the 15-minute TTL, within its bucket',
    gck_key() === gck_key(['end' => GCK_END + 900]),
    'the longest transient TTL outlives its own key'
);

// ── 2. But it is not stable forever ─────────────────────────────────────────
//
// Bucketing trades key churn for bounded staleness. The bound has to exist: if the
// key never changed, a range whose end has genuinely moved on would keep serving the
// old window's numbers for as long as the transient lives.
gck_assert(
    'the key changes once the bucket rolls over',
    gck_key(['end' => GCK_END]) !== gck_key(['end' => GCK_END + 7200]),
    'two hours later the key is unchanged — the range end is being ignored entirely'
);

// The accepted cost of bucketing, pinned so nobody rediscovers it as a bug: a pair of
// renders straddling an hour boundary does NOT share a key and misses the cache once.
// It self-heals on the next render, and the alternative — a key that never changes —
// would serve a stale window indefinitely.
gck_assert(
    'a boundary-straddling pair misses, and that is the documented trade',
    gck_key(['end' => GCK_END - 61]) !== gck_key(['end' => GCK_END]),
    'the bucket boundary has moved or disappeared; re-derive the staleness bound'
);

// ── 3-6. What must NOT share a key ──────────────────────────────────────────
//
// These are the correctness half. A key that collides across any of these serves one
// segment's numbers under another segment's label.
gck_assert(
    'different column filters do not share a key',
    gck_key() !== gck_key(['filters' => ['browser_type' => ['equals', '1']]]),
    'two different filtered segments would read each other\'s cached numbers'
);
gck_assert(
    'an unfiltered render does not share a key with a filtered one',
    gck_key(['filters' => []]) !== gck_key(),
    'removing every filter would serve the previous segment\'s cached numbers'
);
gck_assert(
    'different range starts do not share a key',
    gck_key() !== gck_key(['start' => GCK_START - 86400]),
    'a 30-day and a 31-day window would share one cached result'
);
gck_assert(
    'a bumped cache version invalidates the key',
    gck_key() !== gck_key(['cache_ver' => '101']),
    'goal/funnel CRUD would no longer invalidate cached results'
);
gck_assert(
    'different goals do not share a key',
    gck_key(['scope' => '7']) !== gck_key(['scope' => '8']),
    'two goals would report each other\'s conversions'
);
gck_assert(
    'different result types do not share a key',
    gck_key(['prefix' => 'goal', 'scope' => '']) !== gck_key(['prefix' => 'uv', 'scope' => '']),
    'a goal result and the unique-visitor denominator would collide'
);

// ── 7. The keys stay inside both sweepers' reach ────────────────────────────
//
// clear_goals_cache() and uninstall.php each delete these rows by LIKE prefix. A key
// shape that no longer matches would leave them to accumulate one row per range,
// forever, invisibly — and uninstall would silently stop removing them.
$admin = (string) file_get_contents(__DIR__ . '/../admin/index.php');
// uninstall.php escapes the underscores for LIKE, and does it inside a double-quoted
// PHP string, so the file text carries two backslashes before each one. Strip every
// backslash rather than guessing the escaping depth.
$uninstall = str_replace('\\', '', (string) file_get_contents(__DIR__ . '/../uninstall.php'));

foreach (['goal' => '7', 'funnel' => 'abc', 'uv' => ''] as $prefix => $scope) {
    $key = gck_key(['prefix' => $prefix, 'scope' => $scope]);
    gck_assert(
        "the {$prefix} key starts with the prefix both sweepers look for",
        strpos($key, 'slimstat_' . $prefix . '_') === 0,
        "key '{$key}' does not carry the swept prefix"
    );
    gck_assert(
        "clear_goals_cache() sweeps the {$prefix} prefix",
        strpos($admin, "'_transient_slimstat_{$prefix}_'") !== false,
        'admin/index.php no longer names it'
    );
    // The per-prefix uninstall assertion is hoisted below. Inside this loop it had become
    // three identical checks wearing three different labels, which reads as three times the
    // coverage it has.
}

// RE-ANCHORED TO THE FAMILY, and asserted once. uninstall.php used to name `slimstat_goal_`,
// `slimstat_funnel_` and `slimstat_uv_` literally — a hand-maintained list that covered four of
// the ~20 transient families this plugin writes, and had gone stale twice. It now sweeps
// `_transient_slimstat_%`, which subsumes all three by construction and cannot go stale when a
// fourth goals-related key is added. Requiring the individual names here would forbid that
// generalisation while proving nothing extra: each key is covered exactly when it starts with
// the family prefix, which the per-prefix assertion inside the loop already checks.
gck_assert(
    'uninstall.php sweeps the slimstat_ transient family the three keys live in',
    strpos($uninstall, '_transient_slimstat_') !== false,
    'uninstalling would leave these rows behind'
);

// ── 8. Every call site goes through the one builder ─────────────────────────
//
// The point of the fix is that the bucketing rule exists once. A fourth key built by
// hand would silently reintroduce the defect for whatever it caches.
$db = (string) file_get_contents(__DIR__ . '/../admin/view/wp-slimstat-db.php');
preg_match_all("/'slimstat_(goal|funnel|uv)_'/", $db, $literals);
gck_assert(
    'no cache key prefix is hand-assembled outside the shared builder',
    $literals[0] === [],
    'found ' . implode(', ', array_unique($literals[0])) . ' built by hand — such a key '
        . 'skips the bucketing and reintroduces the defect for whatever it caches'
);
foreach (['get_goal_results', 'get_total_unique_visitors', 'funnel_cache_key'] as $fn) {
    // slimstat_function_body() rather than a local regex: tests/lib/source-scan.php
    // exists because per-test extraction regexes diverged on indentation and some
    // silently matched nothing.
    $body = slimstat_function_body($db, $fn);
    gck_assert(
        "{$fn}() builds its key through results_cache_key()",
        $body !== '' && strpos($body, 'results_cache_key(') !== false,
        $body === '' ? 'method not found' : 'builds its own key'
    );
}

// ── Report ──────────────────────────────────────────────────────────────────
if ($failures !== []) {
    fwrite(STDERR, 'FAIL: goals cache key stability (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

printf("PASS: goals cache key stability (%d assertions)\n", $passes);
exit(0);

}
