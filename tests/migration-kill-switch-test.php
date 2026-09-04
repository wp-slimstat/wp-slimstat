<?php
/**
 * Source-level: the migration framework honours the kill switch, and runs single-flight.
 *
 * G2 — THE KILL SWITCH. wp.org has no staged rollout, no canary, no telemetry and no remote
 * kill switch, so `define('SLIMSTAT_DISABLE_MIGRATIONS', true)` in wp-config.php is the ONLY
 * abort mechanism that exists for a migration going wrong on a live site.
 *
 * It was moved to Phase 0 because a switch that ships after the code needing killing is a
 * post-mortem artefact — every hazard it guards is already on this branch.
 *
 * THE STATE THIS FIXES IS WORSE THAN "ABSENT". The constant already existed and already
 * guarded the LEGACY schema DDL path (admin/index.php), with tests/schema-ddl-gate-test.php
 * pinning it. What ignored it was the whole of src/Migration/ — the framework Phase G rides
 * on. So a site owner who set the constant had every reason to believe migrations were off,
 * and the runner proceeded anyway. A switch that is documented, tested, and silently partial
 * is more dangerous than one that does not exist, because it is trusted.
 *
 * FOUR PLACES, NOT ONE. Checking only at the entry point leaves the others reachable:
 *
 *   MigrationService::init()          registration — nothing should even be built
 *   MigrationManager::needsMigration() the notice must not appear, or a user clicks a
 *                                      button that should not exist
 *   MigrationManager::runAll()         the execution path itself
 *   MigrationAdmin::ajaxRunMigrations() A STALE NONCE IN AN OPEN TAB. The switch is thrown
 *                                      because something is going wrong; the tab that was
 *                                      already open is exactly what starts the next run.
 *
 * X7 — SINGLE FLIGHT. `manage_options` is held by every subsite Administrator, and the
 * endpoint had no mutual exclusion, no rate limit and no kill-switch check. N parallel POSTs
 * give N concurrent ALGORITHM=COPY rebuilds contending on the metadata lock, each holding a
 * connection for lock_wait_timeout plus rebuild time.
 *
 * Scoped to constructs, comments and strings blanked first — this file names every symbol it
 * requires, and a raw-text scan would match itself.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

$read = static function (string $rel) use ($plugin_root): string {
    $raw = (string) file_get_contents($plugin_root . '/' . $rel);
    return slimstat_strip_comments_and_strings($raw);
};

$service = $read('src/Migration/MigrationService.php');
$manager = $read('src/Migration/MigrationManager.php');
$admin   = $read('src/Migration/Admin/MigrationAdmin.php');

// ── The switch is honoured at all four entry points ─────────────────────────
$sites = [
    'MigrationService::init()'           => [$service, 'init'],
    'MigrationManager::needsMigration()' => [$manager, 'needsMigration'],
    'MigrationManager::runAll()'         => [$manager, 'runAll'],
    'MigrationAdmin::ajaxRunMigrations()' => [$admin, 'ajaxRunMigrations'],
];

foreach ($sites as $label => [$source, $function]) {
    $body = slimstat_function_body($source, $function);

    if (false === strpos($body, 'migrationsDisabled')) {
        $failures[] = "{$label} does not consult the kill switch. Guarding only some of the "
            . 'four leaves the rest reachable — and the AJAX endpoint is reachable from a tab '
            . 'that was already open when the switch was thrown, which is precisely when it is';
    }
}

// ── One implementation of the check, not four ──────────────────────────────
// Four hand-rolled `defined() && CONSTANT` checks are four chances to spell it differently,
// and the failure mode is silent: the misspelled one simply never fires.
// Optional lookup: absence is one of the things this gate REPORTS, so it must not abort
// before the other findings are collected.
$helper = (string) slimstat_find_function_body($service, 'migrationsDisabled');

if ('' === trim($helper)) {
    $failures[] = 'MigrationService::migrationsDisabled() is empty — the four call sites need '
        . 'one implementation to share, or they will drift';
}

if (false === strpos($helper, 'SLIMSTAT_DISABLE_MIGRATIONS')) {
    $failures[] = 'the kill-switch helper does not read SLIMSTAT_DISABLE_MIGRATIONS';
}

if (!preg_match('/defined\s*\(/', $helper)) {
    $failures[] = 'the helper does not guard with defined(), so a site without the constant '
        . 'raises a warning on every admin request';
}

// The switch must be a filter-free, wp-config-only decision: a plugin that can turn it back
// on is not a kill switch.
if (preg_match('/apply_filters\s*\(/', $helper)) {
    $failures[] = 'the kill switch is filterable. It is the only abort mechanism that exists '
        . 'for wp.org, and anything that can re-enable it defeats the purpose';
}

// ── X7: runAll() is single-flight ──────────────────────────────────────────
$runAll   = slimstat_function_body($manager, 'runAll');
$runOne   = (string) slimstat_find_function_body($manager, 'runOne');
$claimRun = (string) slimstat_find_function_body($manager, 'claimRun');

// BOTH runners must take the claim, and runOne matters most: migration.js posts
// `migration: <id>` once PER STEP and only posts the bare action after every step has already
// run — so runAll() is the cheap final sweep, while runOne() is where a user-triggered
// ALGORITHM=COPY rebuild actually happens. Claiming only in runAll() protects the sweep and
// leaves the rebuild unserialised.
foreach (['runAll' => $runAll, 'runOne' => $runOne] as $fn => $body) {
    if (false === strpos($body, 'claimRun')) {
        $failures[] = "{$fn}() does not take the single-flight claim. manage_options is held "
            . 'by every subsite Administrator, so N parallel POSTs give N concurrent '
            . 'ALGORITHM=COPY rebuilds contending on the metadata lock';
    }

    // Released on every path a finally can see.
    if (false === strpos($body, 'finally')) {
        $failures[] = "{$fn}() does not release its claim in a finally block. A throw mid-run "
            . 'would leave the claim held, and the next legitimate attempt is refused';
    }
}

if (false === strpos($claimRun, 'OptionClaim')) {
    $failures[] = 'claimRun() does not use OptionClaim. add_option() is not atomic — it '
        . 'pre-checks then INSERTs ON DUPLICATE KEY UPDATE, which overwrites, so every '
        . 'concurrent caller believes it won';
}

// A claim with no takeover has no TTL. `finally` is exception-safe, not crash-safe: it does
// not run on a fatal, an OOM or max_execution_time, which is exactly how a multi-minute
// rebuild dies. Without takeover the runner wedges permanently while the UI reports success.
if (false === strpos($claimRun, 'compareAndSwap')) {
    $failures[] = 'claimRun() cannot take over a stale claim, so a run killed mid-rebuild '
        . 'strands the row forever and every later attempt returns empty while reporting success';
}

// ── The switch beats the cache ─────────────────────────────────────────────
// needsMigration() memoises and caches in a transient. If the switch is consulted after
// those, a 12 h cached "dirty" keeps the notice up after migrations were disabled.
$needs = slimstat_function_body($manager, 'needsMigration');
$switchAt = strpos($needs, 'migrationsDisabled');
$memoAt   = strpos($needs, 'needsMemo');

if (false !== $switchAt && false !== $memoAt && $switchAt > $memoAt) {
    $failures[] = 'needsMigration() consults the kill switch AFTER its memo/transient. A '
        . 'cached "dirty" answer would then outlive the switch being thrown, and the notice '
        . 'keeps offering a button that must not be pressed';
}

if ($failures) {
    fwrite(STDERR, 'FAIL: migration kill switch (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: SLIMSTAT_DISABLE_MIGRATIONS honoured at all 4 entry points; runAll() is single-flight\n";
