<?php
/**
 * Every OWN asset is enqueued with SLIMSTAT_ANALYTICS_VERSION, never a retyped number.
 *
 * ── WHAT WENT WRONG ─────────────────────────────────────────────────────────────────────────
 *
 * Query Monitor on the migration screen, 2026-09-05:
 *
 *     slimstat-live-analytics   admin/assets/js/live-analytics.js   Version 5.4.1
 *
 * The plugin is 6.0.0. The version string on a WordPress asset is its cache-buster: the browser
 * keys its cache on `?ver=5.4.1`, so a live-analytics.js that changed in 5.4.2, 5.5.0 and 6.0.0
 * is served from cache on every install that ever loaded it at 5.4.1 — until the literal is
 * bumped by hand, which is the one thing a hand-typed literal reliably is not. Three own assets
 * carried one: live-analytics.js (`'5.4.1'`), live-analytics.css (`'5.4.0'`) and
 * slimstat-chart.js (`'1.3'`), each frozen at the release that introduced it.
 *
 * Vendor files are the exception, and the ONLY exception: chart.min.js is Chart.js 4.2.1 and
 * that is its version, not ours. Each such pin is listed below with its path, so a new vendor
 * file cannot be waved through by looking like one.
 *
 * ── WHAT THIS DOES NOT ESTABLISH ────────────────────────────────────────────────────────────
 *
 * The same Query Monitor row also reported a MISSING DEPENDENCY: live-analytics.js depended on
 * `slimstat_admin`, a handle only the report screens register, so on the migration screen
 * WordPress silently dropped the script. That is a per-screen fact no static scan can see. It
 * was closed by deleting the dependency — live-analytics.js references nothing admin.js
 * defines, verified by grep — not by a gate, and this file does not claim otherwise.
 *
 * Run: php tests/asset-version-contract-test.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit(1);
}

error_reporting(E_ALL);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

// Vendor assets, pinned to the VENDOR's version on purpose. Path fragment => the literal that
// is allowed. Anything else under our asset directories must use SLIMSTAT_ANALYTICS_VERSION.
$vendor_pins = [
    'admin/assets/js/chartjs/chart.min.js'                  => '4.2.1',  // Chart.js
    'admin/assets/js/daterangepicker/moment.min.js'         => '2.30.2', // Moment.js
    'admin/assets/js/daterangepicker/daterangepicker.min.js' => '3.1.0',  // daterangepicker
    'admin/assets/css/daterangepicker/daterangepicker.css'  => '3.1.0',  // daterangepicker
    'admin/assets/js/jqvmap/jquery.vmap.min.js'             => '1.5.1',  // jqvmap
    'admin/assets/js/jqvmap/jquery.vmap.world.min.js'       => '1.5.1',  // jqvmap world map
];

$calls_of_interest = [
    'wp_enqueue_script'  => true,
    'wp_enqueue_style'   => true,
    'wp_register_script' => true,
    'wp_register_style'  => true,
];

$files = slimstat_own_php_files(
    [$plugin_root . '/wp-slimstat.php', $plugin_root . '/admin', $plugin_root . '/src'],
    $plugin_root . '/vendor'
);

$seen = 0;

foreach ($files as $file) {
    $rel    = ltrim(str_replace($plugin_root, '', $file), '/');
    // The lib's tokeniser, not a raw regex over text: comments are T_COMMENT tokens the
    // name-token check never matches, which is what makes a comment unable to satisfy this.
    $tokens = slimstat_tokenize((string) file_get_contents($file));
    $count  = count($tokens);
    $names  = slimstat_name_token_types();

    for ($i = 0; $i < $count; $i++) {
        $tok = $tokens[$i];
        if (!is_array($tok) || !isset($names[$tok[0]])) {
            continue;
        }
        $fn = slimstat_last_name_segment($tok[1]);
        if (!isset($calls_of_interest[$fn])) {
            continue;
        }

        $open = slimstat_next_significant($tokens, $i);
        if ($open >= $count || '(' !== slimstat_token_text($tokens[$open])) {
            continue;
        }
        $close = slimstat_token_paren_end($tokens, $open, $count);
        if (null === $close) {
            continue;
        }

        $args = slimstat_call_args($tokens, $open, $close);
        $seen++;

        // A bare handle re-enqueue (`wp_enqueue_script('slimstat_chartjs')`) carries no src and no
        // version; there is nothing to check.
        if (count($args) < 2) {
            continue;
        }

        // slimstat_call_args() returns each slot as its own 0-indexed token list, so the lib's
        // range helper is exactly the concatenation this needs — no local copy of that loop.
        $handle = slimstat_arg_string($args[0]) ?? '(dynamic)';
        $src    = trim(slimstat_token_text_range($args[1], 0, count($args[1])));
        $ver    = isset($args[3]) ? trim(slimstat_token_text_range($args[3], 0, count($args[3]))) : '';
        $line   = is_array($tok) ? $tok[2] : 0;

        // Own asset = a literal path under our asset directories. A src built from a variable is
        // reported as UNKNOWN rather than passed silently.
        if (!preg_match('#/(admin/)?assets/#', $src)) {
            continue; // core handle, external URL, or not ours
        }

        $vendor = null;
        foreach ($vendor_pins as $fragment => $pin) {
            if (false !== strpos($src, $fragment)) {
                $vendor = $pin;
                break;
            }
        }

        if (null !== $vendor) {
            if ($ver !== "'" . $vendor . "'" && $ver !== '"' . $vendor . '"') {
                $failures[] = sprintf('%s:%d enqueues vendor asset `%s` with version %s; the pin '
                    . 'recorded for it is %s. Update the pin here in the same commit as the file',
                    $rel, $line, $handle, $ver ?: '(none)', $vendor);
            }
            continue;
        }

        if ('SLIMSTAT_ANALYTICS_VERSION' === $ver) {
            continue;
        }

        if (preg_match('/^[\'"]\d+(\.\d+)*[\'"]$/', $ver)) {
            $failures[] = sprintf('%s:%d enqueues own asset `%s` (%s) with the retyped version %s. '
                . 'That string is the cache-buster: the browser keeps serving the copy it cached at '
                . 'that version until someone remembers to bump the literal. Use '
                . 'SLIMSTAT_ANALYTICS_VERSION, or list the file under $vendor_pins if it is not ours',
                $rel, $line, $handle, $src, $ver);
            continue;
        }

        $failures[] = sprintf('%s:%d enqueues own asset `%s` with version %s, which this gate cannot '
            . 'classify. SLIMSTAT_ANALYTICS_VERSION is the contract; say why here if it cannot apply',
            $rel, $line, $handle, '' === $ver ? '(none)' : $ver);
    }
}

// VACUITY FLOOR. The plugin registers well over thirty assets; a handful means the token walk
// broke and every check above passed by looking at nothing.
if ($seen < 30) {
    $failures[] = sprintf('found only %d wp_enqueue/wp_register call(s) across own code; the walk is '
        . 'broken and the version contract above was checked against almost nothing', $seen);
}

if ($failures) {
    fwrite(STDERR, 'FAIL: asset version contract (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo sprintf("PASS: %d enqueue/register call(s) scanned; every own asset carries "
    . "SLIMSTAT_ANALYTICS_VERSION and %d vendor pin(s) match their record\n", $seen, count($vendor_pins));
