<?php
/**
 * Source-level: the migration UI reports what happened, and dismissal is versioned.
 *
 * S7 — THE UI PAINTS FAILURE AS SUCCESS. migration.js's final handler branches on
 * `success && data && data.all_complete`, and its ELSE branch — reached precisely when the
 * run did NOT complete — set the badge to "success" and the text to "done". A user whose
 * utf8mb4 conversion failed on three of four tables was told the migration finished.
 *
 * The same handler has no `timeout:` and no `.fail()`, so a 504 (entirely ordinary for a
 * multi-minute ALGORITHM=COPY rebuild behind a proxy) leaves the UI spinning forever with no
 * error and no way to know it should be retried.
 *
 * S8 — DISMISSAL IS NOT VERSIONED. `slimstat_migration_dismissed` stored the literal 'yes',
 * and needsMigration() short-circuits on it. So **any migration added in v6.1 never
 * announces itself on a site that completed v6.0's** — a forward-compatibility hole in the
 * exact mechanism the whole star-schema programme is designed to ride on. Store the
 * migration-set fingerprint, so a changed set re-arms the notice by construction.
 *
 * Scoped to constructs; comments and strings are blanked in the PHP half, and the JS half
 * matches on structure rather than on a name appearing.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

$js      = (string) file_get_contents($plugin_root . '/admin/assets/js/migration.js');
$managerRaw = (string) file_get_contents($plugin_root . '/src/Migration/MigrationManager.php');

// Two views, deliberately. Construct checks use the stripped source so a name in prose cannot
// satisfy them; the literal check below needs string CONTENTS, which stripping blanks — the
// first draft asserted on 'yes' against stripped source and could never have fired. Comments
// are blanked in both, so neither can match its own explanation.
$manager    = slimstat_strip_comments_and_strings($managerRaw);
$managerLit = slimstat_blank_comments($managerRaw);

// ── S7: the failure branch must not claim success ───────────────────────────
// Count how many times the JS declares the run finished successfully. There is exactly one
// legitimate site — inside the `all_complete` branch. A second means the else branch is
// lying again.
// End-of-run state is decided in exactly one place. An earlier form of this assertion counted
// literal setStatusBadge("success") calls — which pinned the code into the duplicated shape
// the fix exists to remove. A gate that forbids its own refactor is not a gate.
if (1 !== preg_match_all('/function finishRun\s*\(/', $js)) {
    $failures[] = 'migration.js does not funnel end-of-run state through a single finishRun(). '
        . 'Two hand-written teardowns is how the else branch came to claim success';
}

if (preg_match('/setStatusBadge\(\s*[\'"]success[\'"]\s*\)/', $js)) {
    $failures[] = 'migration.js hard-codes a "success" badge outside finishRun()';
}

if (!preg_match('/finishRun\(\s*[\'"]error[\'"]/', $js)) {
    $failures[] = 'no failure path routes through finishRun("error", …)';
}

// BOTH requests, not just the final sweep. runOne() is where the ALGORITHM=COPY rebuild
// happens, so the per-step call is the one that actually meets a proxy's 504 — hardening only
// the cheap sweep leaves S7's symptom alive on its most likely path.
if (preg_match('/\$\.post\s*\(\s*SlimstatMigration\.ajaxUrl/', $js)) {
    $failures[] = 'a migration request still goes through $.post(), which cannot carry a '
        . 'timeout. The per-step call is where the rebuild happens, so it is the one that '
        . 'meets the 504';
}

// ── S7: a request that never returns must be reported ───────────────────────
if (!preg_match('/timeout\s*:/', $js)) {
    $failures[] = 'migration.js sets no `timeout:`. A multi-minute ALGORITHM=COPY rebuild '
        . 'behind a proxy returns 504, and with no timeout the UI spins forever';
}

if (!preg_match('/\.fail\s*\(/', $js)) {
    $failures[] = 'migration.js has no `.fail()` handler, so a transport error (504, 502, a '
        . 'dropped connection) produces no message at all — the spinner simply never stops';
}

// ── S8: dismissal is keyed to the migration set, not to "yes" ───────────────
$dismiss    = slimstat_function_body($manager, 'dismissNotice');
$dismissLit = slimstat_function_body($managerLit, 'dismissNotice');

if (preg_match('/[\'"]yes[\'"]/', $dismissLit)) {
    $failures[] = 'dismissNotice() still stores the literal "yes". needsMigration() '
        . 'short-circuits on that flag, so any migration added in v6.1 never announces itself '
        . 'on a site that completed v6.0\'s — the forward-compatibility hole in the exact '
        . 'mechanism the star-schema programme rides on';
}

if (false === strpos($dismiss, 'migrationSetFingerprint')) {
    $failures[] = 'dismissNotice() does not record the migration-set fingerprint, so a '
        . 'changed set cannot re-arm the notice';
}

$needs = slimstat_function_body($manager, 'needsMigration');

if (false === strpos($needs, 'migrationSetFingerprint')) {
    $failures[] = 'needsMigration() does not compare the stored dismissal against the current '
        . 'migration set, so dismissal outlives the set it was for';
}

$fingerprint = (string) slimstat_find_function_body($manager, 'migrationSetFingerprint');

if ('' === trim($fingerprint)) {
    $failures[] = 'MigrationManager::migrationSetFingerprint() is missing';
} elseif (false === strpos($fingerprint, 'getId')) {
    // getName() is __() -wrapped: keying on it means changing site language orphans every
    // dismissal (C34). getId() is stable.
    $failures[] = 'the fingerprint is not built from getId(). getName() is translated, so '
        . 'keying on it makes a language change look like a new migration set';
}

// i18n: the error sentences come from localized labels, not baked into the JS. Three of these
// six labels migration.js has read since it was written and MigrationAdmin never supplied, so
// every locale silently got the English fallback.
$adminSrc = (string) file_get_contents($plugin_root . '/src/Migration/Admin/MigrationAdmin.php');

foreach (['notComplete', 'timedOut', 'requestFailed'] as $label) {
    if (false === strpos($js, 'labels.' . $label)) {
        $failures[] = "migration.js does not use the localized `{$label}` label, so its error "
            . 'text is hardcoded English in a file where every other string is translated';
    }
}

foreach (['notComplete', 'timedOut', 'requestFailed', 'idle', 'runningShort', 'failedHelp'] as $label) {
    if (false === strpos($adminSrc, "'" . $label . "'")) {
        $failures[] = "MigrationAdmin does not supply the `{$label}` label that migration.js "
            . 'reads, so every locale falls back to the English baked into the JS';
    }
}

// ── A long step reports progress as progress, and can finish ───────────────────────────
//
// THREE DEFECTS, ONE SHAPE: the runner could not survive its own work.
//
// (a) NO BUDGET. The client allows 15 minutes (migration.js); PHP's default
//     max_execution_time is 30 seconds. The server died nine minutes before the client gave
//     up, and .fail() deliberately does not advance — so the run halted with an outcome
//     nobody could read.
//
// (b) PROGRESS REPORTED AS FAILURE. backfill() returned !dimensionIsBehind(), so a pass that
//     stamped 500 tuples correctly and left 4,000 to go returned false, runOne() recorded
//     false, and the UI painted "Failed" on a run that did exactly what it was asked.
//
// (c) HTML BEFORE JSON. wpdb prints an error block when show_errors is on — which it is
//     whenever WP_DEBUG and WP_DEBUG_DISPLAY are — and that echo lands before
//     wp_send_json_success(). jQuery's .done() then receives undefined, `resp && resp.success`
//     is false, and a migration that SUCCEEDED reported as failed, on precisely the installs
//     whose owners had turned debugging on to find out why.
$ajaxBody = slimstat_find_function_body(
    slimstat_strip_comments_and_strings($adminSrc),
    'ajaxRunMigrations'
);

if (null === $ajaxBody) {
    $failures[] = 'ajaxRunMigrations() not found in MigrationAdmin.php';
} else {
    if (false === strpos($ajaxBody, 'ignore_user_abort')) {
        $failures[] = 'ajaxRunMigrations() does not raise the request budget, so a rebuild that '
            . 'outlives max_execution_time is killed mid-run with no outcome the client can read';
    }

    // The ACQUIRING call specifically. `suppress_errors` also appears in both restores, so a
    // mutation that neutered only the acquire left the name present three times over and
    // survived this gate — measured, not supposed.
    if (!preg_match('/suppress_errors\(\s*true\s*\)/', $ajaxBody)) {
        $failures[] = 'ajaxRunMigrations() does not suppress wpdb errors around the run. Under '
            . 'WP_DEBUG_DISPLAY the printed HTML lands before wp_send_json_*, the response is '
            . 'HTML-then-JSON, and a migration that succeeded reports as failed';
    } else {
        // Suppression that is not restored leaks global state on a shared handle for the rest
        // of the request. Restoring AFTER the response is unreachable — wp_send_json_* exits.
        if (substr_count($ajaxBody, 'suppress_errors') < 3) {
            $failures[] = 'ajaxRunMigrations() suppresses wpdb errors but never restores the '
                . 'previous state before responding — wp_send_json_* exits, so anything after '
                . 'it never runs, and suppression is global on a shared handle';
        }
    }

    if (false === strpos($ajaxBody, 'remaining')) {
        $failures[] = 'the single-step response does not report whether work remains. Without '
            . 'it the client cannot tell "this pass worked and there is more" from "this '
            . 'failed", which is how a successful partial pass came to be painted red';
    }
}

$backfill = slimstat_find_function_body(
    slimstat_strip_comments_and_strings(
        (string) file_get_contents($plugin_root . '/src/Migration/Migrations/AddUserAgentDimension.php')
    ),
    'backfill'
);

if (null === $backfill) {
    $failures[] = 'AddUserAgentDimension::backfill() not found';
} else {
    // set_time_limit() is deliberately NOT the assertion. It is a silent no-op under
    // disabled_functions and under PHP-FPM's request_terminate_timeout — the hosting class
    // most likely to kill a long rebuild — so a gate requiring the CALL would be green on
    // every install where it does nothing. The in-request deadline is the bound that binds.
    if (false === strpos($backfill, 'microtime')) {
        $failures[] = 'backfill() has no in-request deadline, so one pass runs until the host '
            . 'kills it. There is no index on ua_id, so each UPDATE is a full table scan and '
            . 'the cost of a pass grows with the table — a row-count batch does not bound it';
    }

    if (preg_match('/return\s+!\$this->dimensionIsBehind\(\)\s*;/', $backfill)) {
        $failures[] = 'backfill() still returns !dimensionIsBehind(), which reports a pass that '
            . 'made real progress as a failure. "Did it work" and "is it finished" are '
            . 'different questions; shouldRun() answers the second';
    }
}

if (!preg_match('/data\.remaining/', $js)) {
    $failures[] = 'migration.js never reads `remaining`, so it advances past a step that has '
        . 'more work to do and reports the run complete when it is not';
}

// A retry loop whose exit condition lives on the other end of the network needs its own
// bound, or a server that always answers "more" spins the browser forever.
// ── The retry helper must be reachable from BOTH callers ───────────────────────────────
//
// runStep() is called by the run loop (inside runAll()) and by the offered-step click handler,
// which is a SIBLING of runAll(). Declaring it inside runAll() therefore made the click handler
// throw ReferenceError — after it had already disabled the button and started the spinner, so
// the offered migration was unrunnable and said nothing at all. `node --check` passes on that;
// only running it finds it. This is the structural form of that check.
$runAllStart = strpos($js, 'function runAll()');
$runAllEnd   = $runAllStart;

if (false !== $runAllStart) {
    $depth   = 0;
    $started = false;
    for ($i = $runAllStart, $len = strlen($js); $i < $len; $i++) {
        if ('{' === $js[$i]) {
            $depth++;
            $started = true;
        } elseif ('}' === $js[$i]) {
            $depth--;
        }
        if ($started && 0 === $depth) {
            $runAllEnd = $i;
            break;
        }
    }
}

foreach (['runStep', 'postMigration', 'transportMessage', 'MAX_PASSES'] as $shared) {
    $decl = strpos($js, 'runStep' === $shared || 'postMigration' === $shared || 'transportMessage' === $shared
        ? 'function ' . $shared . '('
        : 'var ' . $shared . ' =');

    if (false === $decl) {
        $failures[] = "migration.js declares no {$shared}, which both the run loop and the "
            . 'offered-step handler need';
        continue;
    }

    if ($decl > $runAllStart && $decl < $runAllEnd) {
        $failures[] = "migration.js declares {$shared} inside runAll(), but the offered-step "
            . 'click handler is a sibling of runAll() and calls it — that is a ReferenceError '
            . 'thrown after the button is disabled and the spinner started, so the offered '
            . 'migration cannot run and reports nothing';
    }
}

// The comparison, not the declaration: `var MAX_PASSES = 120;` stays behind when the guard
// that uses it is deleted, and that mutation survived this gate until it was measured.
if (!preg_match('/pass\s*<\s*MAX_PASSES/', $js)) {
    $failures[] = 'migration.js re-posts a step while the server reports work remaining, with '
        . 'no cap — a step that never converges would loop indefinitely';
}

if ($failures) {
    fwrite(STDERR, 'FAIL: migration UI honesty (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: the migration UI reports failure as failure; dismissal is keyed to the migration set\n";
