<?php
/**
 * Every call into the WP Consent API is guarded, so a site without that plugin still works.
 *
 * ── WHY THIS FILE EXISTS, AND WHAT IT REPLACES ──────────────────────────────────────────────
 *
 * `wp_has_consent()`, `wp_set_consent()` and `wp_get_consent_type()` are declared by the
 * `wp-consent-api` plugin and by nothing else. Calling one on a site that does not have it is
 * a fatal — `Call to undefined function` — on whichever request reaches it, which for the
 * tracker is every frontend pageview. That is the #325 white-screen class, and this plugin has
 * shipped it before.
 *
 * `tests/e2e/consent-no-dependency.spec.ts` covers the same ground, and covered it by ACCIDENT:
 * its assertion could only fail because CI happened not to install the consent plugin. E0 made
 * CI install it — correctly, because 49 to 130 E2E tests were self-skipping without it — and in
 * doing so removed the one environment where that spec's central assertion could go red. A
 * guard that stops being able to fail is this workspace's defining defect, and closing one gap
 * by silently opening another is how it usually happens.
 *
 * So the property moves here, where it does not depend on a plugin being absent: it is a
 * statement about the source, it runs on all six PHP lanes through `composer test:source-level`,
 * and it cannot be switched off by an environment.
 *
 * ── WHAT COUNTS AS GUARDED ──────────────────────────────────────────────────────────────────
 *
 * Either the call is inside the same statement as a `function_exists()` test for the function
 * it calls — `function_exists('wp_has_consent') && wp_has_consent(…)`, or the corresponding
 * `if` — or it lives in `Consent.php`'s own wrapper, which opens with that test and returns
 * early. The wrapper is the reason a per-call scan is right rather than a per-file one: the
 * file legitimately contains unguarded-looking calls BELOW a guard that already returned.
 *
 * ── WHAT THIS DOES NOT ESTABLISH ────────────────────────────────────────────────────────────
 *
 * That the guarded fallback is CORRECT — that consent defaults the safe way when no CMP is
 * present. That is a behavioural question, and it is what the E2E spec still answers on the
 * lane where the plugin is installed. This answers the narrower one the spec can no longer
 * ask: that the absence itself cannot fatal.
 *
 * 7.4-safe: bare PHP, no PHPUnit, no WordPress, no vendor autoloader.
 *
 * Run: php tests/consent-api-optional-test.php
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

/** Functions the wp-consent-api plugin declares, and which exist nowhere else. */
$consent_api = ['wp_has_consent', 'wp_set_consent', 'wp_get_consent_type'];

$files = slimstat_own_php_files(
    [$plugin_root . '/src', $plugin_root . '/admin', $plugin_root . '/wp-slimstat.php'],
    $plugin_root . '/src/Dependencies'
);

$call_sites = 0;
$guarded    = 0;

foreach ($files as $file) {
    // Comments blanked, strings kept: `function_exists('wp_has_consent')` puts the function
    // NAME inside a string literal, so stripping strings would erase the guard while leaving
    // the call — the gate would then report every guarded site as unguarded.
    $code = slimstat_blank_comments((string) file_get_contents($file));
    $rel  = slimstat_rel_path($plugin_root, $file);

    foreach (preg_split('/\R/', $code) ?: [] as $n => $line) {
        foreach ($consent_api as $fn) {
            // A CALL, not a mention: the name followed by an opening paren, and not preceded
            // by a character that would make it part of a longer identifier. `function_exists`
            // takes its argument as a string, so it never matches this.
            if (!preg_match('/(?<![\w$>])\\\\?' . preg_quote($fn, '/') . '\s*\(/', $line)) {
                continue;
            }

            $call_sites++;

            // Guarded on the same line — the `&&` and ternary forms — or by the early return
            // of the wrapper that owns the file's remaining calls.
            $same_line = false !== strpos($line, "function_exists('" . $fn . "')")
                || false !== strpos($line, 'function_exists("' . $fn . '")');

            // The wrapper form: a `function_exists` test for THIS function anywhere above, in
            // a file whose guard returns. Scoped by looking back, not by trusting the file.
            $above    = implode("\n", array_slice(preg_split('/\R/', $code) ?: [], 0, $n));
            $wrapped  = (false !== strpos($above, "function_exists('" . $fn . "')")
                    || false !== strpos($above, 'function_exists("' . $fn . '")'))
                && preg_match('/function_exists\([\'"]' . preg_quote($fn, '/') . '[\'"]\)\s*\)\s*\{\s*return/', $above);

            if ($same_line || $wrapped) {
                $guarded++;
                continue;
            }

            $failures[] = sprintf(
                '%s:%d calls %s() without a function_exists() guard. That function is declared '
                    . 'by the wp-consent-api plugin and by nothing else, so on a site without it '
                    . 'this is a fatal on every request that reaches the line',
                $rel,
                $n + 1,
                $fn
            );
        }
    }
}

// VACUITY FLOOR, MEASURED RATHER THAN GUESSED. Exactly TWO real invocations exist, both in
// src/Utils/Consent.php: a guarded wp_get_consent_type() at :100, and the wp_has_consent()
// at :110 inside the wrapper that has already returned on the absent case.
//
// The first draft of this floor said eight, taken from a grep that counted the ten
// `function_exists('wp_has_consent')` GUARDS as though they were calls. They are the opposite
// of a call site — each one is a place that is already safe. A floor set from a number nobody
// checked fails the gate on a healthy tree, which is how a correct gate gets deleted.
if ($call_sites < 2) {
    $failures[] = sprintf(
        'only %d WP Consent API invocation(s) found across %d shipped file(s); two exist. The '
            . 'scan has stopped matching, and every check above then passes by having nothing '
            . 'to check — which is also what "the integration was removed" looks like, and the '
            . 'two must not be confused',
        $call_sites,
        count($files)
    );
}

if ($failures) {
    fwrite(STDERR, 'FAIL: WP Consent API optionality (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: all {$call_sites} WP Consent API call site(s) are guarded, so a site without that "
    . "plugin cannot fatal on one\n";
