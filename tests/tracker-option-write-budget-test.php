<?php
/**
 * Regression test: the tracker's diagnostic options must not be written per hit (D30).
 *
 * `Utils::logError()` stores `[code, time()]`. Because the timestamp changes every
 * second, `update_option()`'s "value unchanged, skip the write" short-circuit can never
 * fire, so a site whose tracker rejects traffic — bots are ~23.5% of hits on the
 * reference dataset — writes `wp_options` on every rejected request. The four
 * diagnostic options were also autoloaded, so each of those writes additionally
 * invalidated the whole `alloptions` cache.
 *
 * The invariants asserted here are the ones that make that safe to fix, not the shape
 * of any particular fix:
 *
 *   1. Repeating one condition must not cost a write per occurrence.
 *   2. A *different* condition must still be recorded immediately — a throttle that
 *      swallows new diagnostics is a worse bug than the one it replaces.
 *   3. The record must not go permanently stale: once the throttle window passes, the
 *      same condition writes again so "last seen" stays meaningful.
 *   4. Debug mode still refreshes every time, so support gets a live reproduction.
 *   5. One declared autoload policy, applied uniformly. Deliberately *not* "these must
 *      not be autoloaded": that was tried, measured, and reverted — see invariant 5
 *      below and TRACKING-PATH-D29-D30.md.
 *
 * @see src/Tracker/Utils.php
 * @see tests/bench/hit-cost.sh (the end-to-end measurement this pins)
 */

declare(strict_types=1);

namespace {

use SlimStat\Tracker\Utils;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

// isDebugMode() ORs in WP_DEBUG. Pin it off so the throttle assertions are
// deterministic no matter what the caller's environment defines.
if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', false);
}

/** Controllable clock — the whole defect is about a value that moves every second. */
$GLOBALS['_towb_now']     = 1700000000;
$GLOBALS['_towb_options'] = [];
$GLOBALS['_towb_writes']  = [];

if (!function_exists('get_option')) {
    function get_option($option, $default = false)
    {
        return $GLOBALS['_towb_options'][$option] ?? $default;
    }
}

if (!function_exists('do_action')) {
    function do_action($hook, ...$args) {}
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str)
    {
        return is_string($str) ? trim(strip_tags($str)) : '';
    }
}

if (!class_exists('wp_slimstat')) {
    class wp_slimstat
    {
        /** @var array<string,mixed> */
        public static $settings = [];

        public static function update_option($key = '', $value = '', $autoload = null)
        {
            // Mirror the real update_option() short-circuit: an identical value is not
            // a write. Without this the test would credit the fix for writes WordPress
            // was already skipping.
            if (array_key_exists($key, $GLOBALS['_towb_options'])
                && $GLOBALS['_towb_options'][$key] === $value) {
                return false;
            }

            $GLOBALS['_towb_options'][$key] = $value;
            $GLOBALS['_towb_writes'][]      = ['option' => $key, 'autoload' => $autoload];
            return true;
        }

        public static function date_i18n($format)
        {
            return 'U' === $format ? (string) $GLOBALS['_towb_now'] : '';
        }

        public static function get_stat()
        {
            return [];
        }

        public static function log($message, $level = 'info') {}
    }
}

require_once __DIR__ . '/../src/Tracker/Utils.php';

// ─── Harness ────────────────────────────────────────────────────────────────

$failures = [];
$passes   = 0;

function towb_assert(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $passes;
    if ($ok) {
        $passes++;
        return;
    }
    $failures[] = $name . ($detail !== '' ? " — {$detail}" : '');
}

function towb_reset(bool $debug = false): void
{
    $GLOBALS['_towb_options'] = [];
    $GLOBALS['_towb_writes']  = [];
    $GLOBALS['_towb_now']     = 1700000000;
    \wp_slimstat::$settings   = ['slimstat_debug' => $debug ? 'on' : 'off'];
}

/** Fire one condition repeatedly, one simulated second apart — the real defect shape. */
function towb_repeat(callable $fn, int $times): void
{
    for ($i = 0; $i < $times; $i++) {
        $fn();
        $GLOBALS['_towb_now']++;
    }
}

/** @return array<int,array{option:string,autoload:mixed}> */
function towb_writes_to(string $option): array
{
    return array_values(array_filter(
        $GLOBALS['_towb_writes'],
        static fn(array $w): bool => $w['option'] === $option
    ));
}

// ─── 1. One repeated condition must not cost a write per occurrence ─────────
//
// A minute of steady bot traffic. The clock advances every call, which is precisely
// why update_option()'s own short-circuit cannot help here: before the fix this
// produced 120 writes (error + detail) for 60 calls.
towb_reset();
towb_repeat(static fn() => Utils::logError(313), 60);
$writes = count($GLOBALS['_towb_writes']);
towb_assert(
    'a repeated error writes at most twice per minute of occurrences',
    $writes <= 2,
    "{$writes} wp_options writes for 60 logError(313) calls one second apart"
);

// The same must hold OUTSIDE the 3xx band. The original throttle covered only
// 300-399, which left 429 — the rate limiter's own rejection — writing every time.
towb_reset();
towb_repeat(static fn() => Utils::logError(429), 60);
$writes = count($GLOBALS['_towb_writes']);
towb_assert(
    'the throttle is not limited to the 3xx band',
    $writes <= 2,
    "{$writes} writes for 60 logError(429) calls — 429 is the rate limiter's own code"
);

// Warnings share the defect and the fix.
towb_reset();
towb_repeat(static fn() => Utils::logWarning(102), 60);
$writes = count(towb_writes_to('slimstat_tracker_warning'));
towb_assert(
    'a repeated warning writes at most twice per minute of occurrences',
    $writes <= 2,
    "{$writes} writes for 60 logWarning(102) calls"
);

// ─── 2. A different condition must be recorded immediately ──────────────────
towb_reset();
Utils::logError(313);
$before = count($GLOBALS['_towb_writes']);
Utils::logError(304);
towb_assert(
    'a changed error code is recorded immediately',
    count($GLOBALS['_towb_writes']) > $before,
    'logError(304) right after logError(313) produced no write — new diagnostics are being swallowed'
);
$stored = $GLOBALS['_towb_options']['slimstat_tracker_error'] ?? [];
towb_assert(
    'the stored code is the new one',
    (int) ($stored[0] ?? 0) === 304,
    'stored code is ' . var_export($stored[0] ?? null, true)
);

// ─── 3. The record must not go permanently stale ────────────────────────────
towb_reset();
Utils::logError(313);
$GLOBALS['_towb_writes'] = [];
$GLOBALS['_towb_now'] += 86400; // a day of the same condition
Utils::logError(313);
towb_assert(
    'the same condition refreshes once the throttle window elapses',
    count($GLOBALS['_towb_writes']) > 0,
    'after a day the timestamp was still not refreshed — "last seen" would read as stale forever'
);

// ─── 4. Debug mode refreshes every time ─────────────────────────────────────
towb_reset(true);
Utils::logError(313);
$GLOBALS['_towb_writes'] = [];
$GLOBALS['_towb_now']++;
Utils::logError(313);
towb_assert(
    'debug mode still refreshes on every occurrence',
    count($GLOBALS['_towb_writes']) > 0,
    'support cannot see a live reproduction with debug on'
);

// ─── 5. One declared autoload policy, applied uniformly ───────────────
//
// NOT "these must be non-autoloaded". That was tried and measured: de-autoloading
// them cost exactly one extra query on every tracked hit — a dedicated SELECT on a
// path that has already loaded `alloptions` — and saved nothing, because the throttle
// above had already removed the writes whose cache invalidation was the reason to
// move them. See jaan-to/outputs/dev/v6-performance/TRACKING-PATH-D29-D30.md.
//
// What must hold is that the policy is decided in ONE place and cannot drift into
// per-call-site guesses: every write agrees, and none of them forces autoload on.
towb_reset();
Utils::logError(313);
Utils::logError(304);
Utils::logWarning(102);
Utils::logGeoIpError('database missing');
Utils::clearDiagnostic('slimstat_tracker_error');

$diagnostic = [
    'slimstat_tracker_error',
    'slimstat_tracker_error_detail',
    'slimstat_tracker_warning',
    'slimstat_geoip_error',
];
$policies = [];
foreach ($diagnostic as $option) {
    foreach (towb_writes_to($option) as $w) {
        $policies[] = var_export($w['autoload'], true);
    }
}
towb_assert(
    'the policy assertion actually observed writes',
    count($policies) >= 4,
    count($policies) . ' diagnostic writes were seen; the checks below would pass vacuously'
);
towb_assert(
    'every diagnostic write applies the same autoload policy',
    count(array_unique($policies)) === 1,
    'call sites disagree: ' . implode(', ', array_unique($policies))
);
towb_assert(
    'no diagnostic write forces autoload on',
    !in_array('true', $policies, true),
    'a call site passes autoload=true, overriding the declared policy'
);

// And the policy is one declared constant, not a literal repeated per call site.
$utils = (string) file_get_contents(__DIR__ . '/../src/Tracker/Utils.php');
towb_assert(
    'the autoload policy is a single declared constant',
    (bool) preg_match('/DIAGNOSTIC_AUTOLOAD\s*=/', $utils),
    'no DIAGNOSTIC_AUTOLOAD constant — the policy is spread across call sites'
);
foreach ($diagnostic as $option) {
    towb_assert(
        "no write of {$option} hardcodes an autoload literal",
        !preg_match('/update_option\(\s*[\'"]' . preg_quote($option, '/') . '[\'"][^)]*,\s*(?:true|false)\s*\)/', $utils),
        'a call site passes a literal instead of self::DIAGNOSTIC_AUTOLOAD'
    );
}

// ─── 6. The empty detail must not be re-written ─────────────────────────────
//
// logError() clears `_detail` on every non-200 code. Once it is already empty that
// clear is pure overhead, and it runs on the hottest rejection paths.
towb_reset();
Utils::logError(313);
$GLOBALS['_towb_writes'] = [];
Utils::logError(304);
towb_assert(
    'the error detail is not re-cleared when already empty',
    towb_writes_to('slimstat_tracker_error_detail') === [],
    'slimstat_tracker_error_detail was written again while already empty'
);

// ─── Report ─────────────────────────────────────────────────────────────────
if ($failures !== []) {
    fwrite(STDERR, 'FAIL: tracker option write budget (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

printf("PASS: tracker option write budget (%d assertions)\n", $passes);
exit(0);

}
