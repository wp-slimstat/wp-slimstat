<?php
/**
 * Source-level: every E2E helper mu-plugin refuses to run unless E2E mode is on (W2).
 *
 * These files are copied into wp-content/mu-plugins by the E2E harness, and WordPress
 * loads everything in that directory unconditionally — there is no activation step and
 * no way to switch one off. Eight of the seventeen had no guard at all, so the only
 * thing standing between them and a live site was somebody remembering to delete the
 * file. An interrupted run leaves it behind exactly as reliably as a deliberate copy.
 *
 * What that meant concretely, worst first:
 *
 *   - mail-sink intercepts EVERY wp_mail() and returns true, so the site sends no
 *     email and nothing anywhere reports a failure. Its own comment read "presence of
 *     this file signals test mode".
 *   - option-mutator exposes an admin-ajax endpoint that rewrites arbitrary
 *     slimstat_options keys.
 *   - custom-db-simulator can repoint the analytics tables at another prefix.
 *   - fileinfo-disabler and calendar-ext-simulator shadow built-ins inside SlimStat
 *     namespaces, making a healthy host look broken to the plugin.
 *
 * tests/ is excluded from the wp.org ZIP, so this is a developer and STAGING risk
 * rather than an end-user one — and staging installs of a 70k-site plugin get pointed
 * at real data.
 *
 * The guard has to be the first thing the file does, not merely present somewhere:
 * a helper that registers its hooks and then checks has already changed the request.
 */

declare(strict_types=1);

// Never executable over HTTP: these scripts run to completion, write to
// STDOUT/STDERR (undefined under a web SAPI) and can disclose absolute paths.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit(1);
}

$helpers = glob(dirname(__DIR__) . '/tests/e2e/helpers/*-mu-plugin.php') ?: [];

// Vacuity guard: an empty or moved directory would make every assertion below a no-op
// that still prints PASS, which is the shape this suite keeps having to re-learn.
if (count($helpers) < 10) {
    fwrite(STDERR, sprintf(
        "FAIL: found only %d helper mu-plugin(s) — the glob is stale, so this scan asserted nothing.\n",
        count($helpers)
    ));
    exit(1);
}

$failures = [];

foreach ($helpers as $path) {
    $name   = basename($path);
    $tokens = token_get_all((string) file_get_contents($path));

    // Walk to the file's FIRST STATEMENT and require it to be the guard.
    //
    // An earlier version allowed T_STRING through so that namespace names could pass —
    // which also let `add_filter(...)` pass, because a function call starts with a
    // T_STRING too. Measured: moving the guard below the hook registration then went
    // undetected, i.e. the ordering check was not checking ordering. A namespace or
    // declare header is now skipped as a unit instead, up to its own terminator.
    $guarded  = false;
    $executed = null;
    $count    = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (is_array($token)) {
            if (in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            // `namespace X;` / `namespace X {` / `declare(...);` — skip the header whole.
            if (in_array($token[0], [T_NAMESPACE, T_DECLARE], true)) {
                for ($i++; $i < $count; $i++) {
                    $inner = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
                    if (';' === $inner || '{' === $inner) {
                        break;
                    }
                }
                continue;
            }

            if (T_IF === $token[0]) {
                $guarded = true;
            } else {
                $executed = $token[2];
            }
            break;
        }

        $executed = 0;
        break;
    }

    $source = (string) file_get_contents($path);

    // Match the CONSTRUCT, not one spelling of it. The tree already carries three:
    // `!defined(..) || !CONST`, the same with WordPress's inner spacing, and
    // `!defined(..) || CONST !== true`. Asserting only the first reported five
    // correctly-guarded files as unguarded — the exact mistake this suite keeps making.
    $guardPattern = "/!\\s*defined\\s*\\(\\s*'SLIMSTAT_E2E_TESTING'\\s*\\)\\s*\\|\\|"
        . "\\s*(?:!\\s*SLIMSTAT_E2E_TESTING|SLIMSTAT_E2E_TESTING\\s*!==?\\s*true)/";

    if (!preg_match($guardPattern, $source)) {
        $failures[] = sprintf(
            '%s has no SLIMSTAT_E2E_TESTING guard. WordPress loads every file in mu-plugins '
                . 'unconditionally, so without one the only protection is remembering to delete it',
            $name
        );
        continue;
    }

    if (!$guarded) {
        $failures[] = sprintf(
            '%s checks SLIMSTAT_E2E_TESTING, but something executes before it%s. A helper that '
                . 'registers its hooks first has already changed the request',
            $name,
            null !== $executed && $executed > 0 ? " (line {$executed})" : ''
        );
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: E2E helper gating (' . count($failures) . " problem(s))\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

printf("PASS: %d E2E helper mu-plugin(s) refuse to run outside SLIMSTAT_E2E_TESTING\n", count($helpers));
exit(0);
