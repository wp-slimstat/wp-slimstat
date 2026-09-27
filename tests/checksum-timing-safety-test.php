<?php
/**
 * Source-level: the tracking cookie's checksum is compared in constant time, and
 * every function that signs or verifies it takes its key from one resolver (X2 / W3).
 *
 * Two properties, neither of which a behavioural test can reach.
 *
 * TIMING. `hash_equals($a, $b)` and `$a === $b` return the same booleans for every
 * input, so a unit test cannot tell them apart — measured: swapping them left the
 * whole UtilsTest suite green. What changes is that `===` short-circuits on the first
 * differing byte, so the comparison's duration leaks a prefix of the expected
 * checksum, and the checksum is the only control over which visit id a request may
 * claim. Pro asserts this for its own copy (tests/tracking-cookie-scheme-test.php);
 * free had no equivalent, and after D6 routed Pro's login-note lookup through free's
 * helper, free is where the comparison lives.
 *
 * ONE KEY. This is the anti-drift half, and it is scoped across FILES rather than one
 * function, because that is how the defect actually presented: three sites resolved
 * the key and two had drifted — the legacy md5 branch in Utils, and
 * Tracker::_get_value_with_checksum(), which had no AUTH_KEY fallback at all and is a
 * live signer. A scan pointed only at Utils.php would have reported the fix as
 * complete while the worst copy sat one file over. The behavioural answers are pinned
 * in tests/Unit/Tracker/UtilsTest.php; what is pinned here is that no NEW site starts
 * reading the raw setting again.
 *
 * Deliberately NOT asserted here: that resolveSecret() exists by that name, and that
 * it mentions AUTH_KEY. Both are already killed behaviourally by UtilsTest, and as
 * source greps they would forbid a legitimate refactor and match a mere comment.
 */

declare(strict_types=1);

// Never executable over HTTP: these scripts run to completion, write to
// STDOUT/STDERR (undefined under a web SAPI) and can disclose absolute paths.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit(1);
}

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);

/** Every function that signs or verifies the tracking cookie. */
$signers = [
    'src/Tracker/Utils.php'   => ['getValueWithChecksum', 'getValueWithoutChecksum', 'resolveSecret'],
    'src/Tracker/Tracker.php' => ['_get_value_with_checksum'],
];

$failures = [];
$bodies   = [];

foreach ($signers as $rel => $functions) {
    $source = (string) @file_get_contents($plugin_root . '/' . $rel);
    if ('' === $source) {
        fwrite(STDERR, "FAIL: cannot read {$rel}\n");
        exit(1);
    }

    foreach ($functions as $function) {
        $body = slimstat_function_body($source, $function);

        // Fatal rather than skipped: with no body every assertion below is a no-op
        // that still prints PASS, which is the failure mode this suite keeps hitting.
        if ('' === $body) {
            fwrite(STDERR, "FAIL: cannot isolate {$rel}::{$function}() — re-anchor this scan rather\n"
                . "  than deleting it; an unfound body asserts nothing and still reports success.\n");
            exit(1);
        }

        $bodies["{$rel}::{$function}"] = $body;
    }
}

// ── 1) The checksum comparison is constant-time ─────────────────────────────
// Anchored on the variable, so writing the comparison either way round is caught,
// and covering the non-obvious spellings too: strcmp() and substr_compare() are
// just as early-exit as ===, and in_array() without strict is worse.
$verify = $bodies['src/Tracker/Utils.php::getValueWithoutChecksum'];

if (preg_match('/\$checksum\s*[!=]==?|[!=]==?\s*\$checksum/', $verify)) {
    $failures[] = 'a checksum is compared with == or === rather than hash_equals(). That comparison '
        . 'short-circuits on the first differing byte, so its duration leaks a prefix of the expected '
        . 'value — and the checksum is the only thing deciding which visit id a request may claim';
}

if (preg_match('/\b(strcmp|strcasecmp|substr_compare|in_array|strpos)\s*\(\s*\$checksum/', $verify)) {
    $failures[] = 'a checksum is compared with an early-exit string function. hash_equals() is the '
        . 'only comparison here whose duration does not depend on how much of the value matched';
}

if (!preg_match('/\bhash_equals\s*\(/', $verify)) {
    $failures[] = 'getValueWithoutChecksum() performs no constant-time comparison at all';
}

// ── 2) Nothing reads the signing key directly ───────────────────────────────
foreach ($bodies as $where => $body) {
    if ('src/Tracker/Utils.php::resolveSecret' === $where) {
        continue;   // the one place that is allowed to
    }

    if (preg_match("/settings\s*\[\s*['\"]secret['\"]\s*\]/", $body)) {
        $failures[] = sprintf(
            '%s() reads settings[secret] directly instead of resolving it in one place. That is '
                . 'exactly how two of the four sites came to key on a different value from the rest',
            $where
        );
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: checksum key and timing (' . count($failures) . " problem(s))\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

printf("PASS: constant-time checksum comparison; %d signing site(s) share one key resolver\n", count($bodies) - 1);
exit(0);
