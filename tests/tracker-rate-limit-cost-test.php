<?php
/**
 * Regression test: rate limiting must not cost a database write per hit (D29).
 *
 * The limiter used `get_transient()` + `set_transient()` with a five-second TTL. Since
 * the window is shorter than the gap between most visitors' hits, the timeout row was
 * usually expired, so WordPress deleted both rows and re-inserted them: measured at
 * **2 to 4 `wp_options` writes on every tracked hit** — several times the write work of
 * storing the pageview itself — to save roughly six queries on the rare request it
 * actually refuses.
 *
 * Invariants:
 *
 *   1. Deciding whether to rate limit never touches the options table. Transients are
 *      options; a limiter that writes two of them per hit is more expensive than the
 *      abuse it prevents.
 *   2. Where the counter can live for free — a persistent object cache — the limit is
 *      still enforced, and enforced at the documented threshold.
 *   3. Where it cannot, the limiter stands down *deliberately* and says so through a
 *      filter, rather than quietly costing every site a write per hit.
 *   4. Budgets are per client. One IP exhausting its allowance must not refuse another.
 *   5. The counter expires, so an allowance is a rate and not a lifetime quota.
 *
 * @see src/Tracker/Ajax.php
 * @see tests/bench/hit-cost.sh (the end-to-end measurement this pins)
 */

declare(strict_types=1);

namespace {

use SlimStat\Tracker\Ajax;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

$GLOBALS['_trl_cache']       = [];
$GLOBALS['_trl_ext_cache']   = true;
$GLOBALS['_trl_option_calls'] = [];
$GLOBALS['_trl_filters']     = [];

// ── Options / transients: any call at all is a failure, so they only record ──
//
// Declared explicitly rather than generated with eval(): `php -l` is this repo's
// pre-commit gate, and it cannot see inside an eval'd string.
function trl_record(string $fn)
{
    $GLOBALS['_trl_option_calls'][] = $fn;
    return false;
}

if (!function_exists('get_transient')) {
    function get_transient(...$a) { return trl_record('get_transient'); }
}
if (!function_exists('set_transient')) {
    function set_transient(...$a) { return trl_record('set_transient'); }
}
if (!function_exists('delete_transient')) {
    function delete_transient(...$a) { return trl_record('delete_transient'); }
}
if (!function_exists('get_option')) {
    function get_option(...$a) { return trl_record('get_option'); }
}
if (!function_exists('update_option')) {
    function update_option(...$a) { return trl_record('update_option'); }
}
if (!function_exists('add_option')) {
    function add_option(...$a) { return trl_record('add_option'); }
}

// ── Object cache: a minimal but faithful stand-in, including expiry ──────────
if (!function_exists('wp_using_ext_object_cache')) {
    function wp_using_ext_object_cache(): bool
    {
        return (bool) $GLOBALS['_trl_ext_cache'];
    }
}

if (!function_exists('wp_cache_add')) {
    function wp_cache_add($key, $value, $group = '', $expire = 0): bool
    {
        $slot = $group . '|' . $key;
        if (isset($GLOBALS['_trl_cache'][$slot]) && $GLOBALS['_trl_cache'][$slot]['expires'] > $GLOBALS['_trl_clock']) {
            return false;
        }
        $GLOBALS['_trl_cache'][$slot] = [
            'value'   => $value,
            'expires' => $GLOBALS['_trl_clock'] + ($expire ?: 3600),
        ];
        return true;
    }
}

if (!function_exists('wp_cache_incr')) {
    function wp_cache_incr($key, $offset = 1, $group = '')
    {
        $slot = $group . '|' . $key;
        if (!isset($GLOBALS['_trl_cache'][$slot]) || $GLOBALS['_trl_cache'][$slot]['expires'] <= $GLOBALS['_trl_clock']) {
            return false;
        }
        $GLOBALS['_trl_cache'][$slot]['value'] += $offset;
        return $GLOBALS['_trl_cache'][$slot]['value'];
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value, ...$args)
    {
        $cb = $GLOBALS['_trl_filters'][$hook] ?? null;
        return $cb ? $cb($value, ...$args) : $value;
    }
}

$GLOBALS['_trl_clock'] = 1000;

// ── Harness ─────────────────────────────────────────────────────────────────

$failures = [];
$passes   = 0;

function trl_assert(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $passes;
    if ($ok) {
        $passes++;
        return;
    }
    $failures[] = $name . ($detail !== '' ? " — {$detail}" : '');
}

function trl_reset(bool $ext_cache = true): void
{
    $GLOBALS['_trl_cache']        = [];
    $GLOBALS['_trl_ext_cache']    = $ext_cache;
    $GLOBALS['_trl_option_calls'] = [];
    $GLOBALS['_trl_filters']      = [];
    $GLOBALS['_trl_clock']        = 1000;
}

/** How many of `$n` consecutive hits from one address are allowed through. */
function trl_allowed(string $ip, int $n): int
{
    $allowed = 0;
    for ($i = 0; $i < $n; $i++) {
        if (!Ajax::isRateLimited($ip)) {
            $allowed++;
        }
    }
    return $allowed;
}

// The limiter is loaded on its own. Ajax.php's other methods reach into the wider
// plugin, but the file itself only declares the class, so requiring it is safe.
require_once __DIR__ . '/../src/Tracker/Ajax.php';

if (!method_exists(Ajax::class, 'isRateLimited')) {
    fwrite(STDERR, "FAIL: Ajax::isRateLimited() does not exist — the rate limiter has no testable seam\n");
    exit(1);
}

// ── 1. Deciding never touches the options table ─────────────────────────────
trl_reset();
trl_allowed('203.0.113.9', 40);
trl_assert(
    'rate limiting issues no option or transient calls',
    $GLOBALS['_trl_option_calls'] === [],
    '40 hits called: ' . implode(', ', array_unique($GLOBALS['_trl_option_calls']))
);

// ── 2. The limit is still enforced where the counter is free ────────────────
trl_reset(true);
$allowed = trl_allowed('203.0.113.10', 40);
trl_assert(
    'a persistent object cache still enforces a limit',
    $allowed > 0 && $allowed < 40,
    "{$allowed} of 40 hits allowed — the limiter is not enforcing anything"
);
trl_assert(
    'the enforced threshold is the documented one',
    $allowed === 10,
    "{$allowed} hits allowed, expected 10"
);

// ── 3. Without free storage it stands down, and says so ─────────────────────
trl_reset(false);
$allowed = trl_allowed('203.0.113.11', 40);
trl_assert(
    'no persistent object cache means no per-hit write',
    $GLOBALS['_trl_option_calls'] === [],
    'fell back to the options table: ' . implode(', ', array_unique($GLOBALS['_trl_option_calls']))
);
trl_assert(
    'no persistent object cache means traffic is not refused',
    $allowed === 40,
    "{$allowed} of 40 allowed — refusing without a working counter would drop real visitors"
);

// A site that wants it on regardless must be able to say so.
trl_reset(false);
$GLOBALS['_trl_filters']['slimstat_rate_limit_enabled'] = static fn($enabled, ...$rest): bool => true;
$allowed = trl_allowed('203.0.113.12', 40);
trl_assert(
    'the filter can force limiting on',
    $allowed === 10,
    "{$allowed} of 40 allowed with slimstat_rate_limit_enabled forced true"
);

// ── 4. Budgets are per client ───────────────────────────────────────────────
trl_reset(true);
trl_allowed('203.0.113.20', 40);
$other = trl_allowed('203.0.113.21', 5);
trl_assert(
    'one exhausted client does not refuse another',
    $other === 5,
    "a second address was allowed only {$other} of 5 hits after the first exhausted its budget"
);

// ── 5. The allowance is a rate, not a lifetime quota ────────────────────────
trl_reset(true);
trl_allowed('203.0.113.30', 40);
$GLOBALS['_trl_clock'] += 60;
$after = trl_allowed('203.0.113.30', 5);
trl_assert(
    'the counter expires so the allowance refills',
    $after === 5,
    "only {$after} of 5 hits allowed a minute later — the client is blocked permanently"
);

// ── Report ──────────────────────────────────────────────────────────────────
if ($failures !== []) {
    fwrite(STDERR, 'FAIL: tracker rate limit cost (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

printf("PASS: tracker rate limit cost (%d assertions)\n", $passes);
exit(0);

}
