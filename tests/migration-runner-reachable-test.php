<?php
/**
 * The index-repair subsystem must actually be reachable, and asking whether it is needed
 * must not cost a query on every admin page.
 *
 * ── The defect ─────────────────────────────────────────────────────────────────────
 *
 * Two repair paths existed and both were dead, each because of the other:
 *
 *   1. The modern system — nine migrations, a Migration page, a one-click retry notice and
 *      three AJAX endpoints — hung off MigrationService::init(), which **nothing called**.
 *      Verified over a real logged-in admin request: `wp_ajax_slimstat_run_migrations` was
 *      not registered, and no MigrationAdmin callback sat on any hook.
 *
 *   2. The legacy fallback — show_indexes_notice() — suppressed itself with
 *      `class_exists(MigrationAdmin::class)`, meaning "the new system is active". Under a
 *      classmap autoloader that is **always true**, because the class file exists whether
 *      or not anything ever instantiates it. So it returned immediately, on every install.
 *
 * An install whose indexes failed to build — which the installer explicitly tolerates,
 * leaving `goals_indexes` unset "so the modern migration system surfaces a one-click retry
 * notice" — had no way to repair them, and nothing failed loudly.
 *
 * ── Why the cost assertions are here too ───────────────────────────────────────────
 *
 * needsMigration() asks every migration, and every index migration answers with its own
 * `SHOW INDEX`. It is consulted twice per admin page load: once to decide whether to
 * register the Migration page, once to decide whether to show the notice. Measured
 * unguarded on the reference install: **18 queries and 24.7 ms on every admin page** —
 * the same defect class just removed from the admin bar. Wiring the subsystem up without
 * the guards would have traded one defect for another.
 *
 * Measured over real logged-in admin requests:
 *
 *     before (no repair path, legacy notice probing)   273 queries,  6 SHOW INDEX
 *     after, healthy install                           269 queries,  0 SHOW INDEX
 *     after, migration outstanding (notice renders)    278 queries,  8 SHOW INDEX
 *
 * Defect ids live in the workspace performance notes, outside this repository —
 * deliberately not linked, since this file ships to wp.org.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

$read = static function (string $rel) use ($plugin_root, &$failures): string {
    $src = file_get_contents($plugin_root . '/' . $rel);
    if ($src === false) {
        $failures[] = "cannot read {$rel}";
        return '';
    }
    return $src;
};

$bootstrap = $read('wp-slimstat.php');
$admin     = $read('admin/index.php');
$manager   = $read('src/Migration/MigrationManager.php');
$indexBase = $read('src/Migration/AbstractIndexMigration.php');

// ── 1. Something calls MigrationService::init() ─────────────────────────────
if (!preg_match('/MigrationService::init\s*\(\s*\)\s*;/', $bootstrap)) {
    $failures[] = 'nothing calls MigrationService::init(), so the nine migrations, the '
        . 'Migration page, the retry notice and its AJAX endpoints are all dead code — an '
        . 'install whose indexes failed to build has no way to repair them';
}

// ── 2. The legacy fallback does not suppress itself on class existence ──────
$notice = slimstat_function_body($admin, 'show_indexes_notice');
if ($notice === '') {
    $failures[] = 'show_indexes_notice() not found — re-anchor this assertion rather than '
        . 'deleting it';
} elseif (preg_match('/class_exists\s*\(\s*\\\\?SlimStat\\\\Migration/', $notice)) {
    $failures[] = 'the legacy index notice suppresses itself with class_exists() on a '
        . 'migration class. Under a classmap autoloader that is always true whether or not '
        . 'the migration system is wired up, so BOTH repair paths end up dead. Test whether '
        . 'the system is actually running (has_action) rather than whether a file exists';
}

// ── 3. Asking "is a migration needed?" is memoised per request ──────────────
$needs = slimstat_function_body($manager, 'needsMigration');
if ($needs === '') {
    $failures[] = 'needsMigration() not found in MigrationManager';
} else {
    // Match the early RETURN, not merely the name: leaving the assignment in place while
    // deleting the guard reads as memoised and is not.
    if (!preg_match('/if\s*\(\s*null\s*!==\s*\$this->needsMemo\s*\)\s*\{\s*return\s+\$this->needsMemo;/', $needs)) {
        $failures[] = 'needsMigration() does not return early from a per-request memo. It is '
            . 'asked twice on every admin page load — once by registerPage(), once by '
            . 'maybeShowNotice() — and each ask is one SHOW INDEX per migration';
    }
    if (!preg_match('/get_transient\s*\(\s*self::TRANSIENT_PROBE\s*\)/', $needs)) {
        $failures[] = 'needsMigration() probes the database on every request with no cache '
            . 'across requests — measured at 18 queries and 24.7 ms per admin page';
    }
    if (!preg_match('/set_transient\s*\(\s*self::TRANSIENT_PROBE/', $needs)) {
        $failures[] = 'needsMigration() reads a cached answer but never writes one';
    }
}

// Whatever caches the answer must be dropped when the answer can change, or the UI
// reports the state from before the repair ran.
foreach (['runAll', 'dismissNotice', 'resetDismissal'] as $method) {
    $body = slimstat_function_body($manager, $method);
    if ($body !== '' && !preg_match('/forgetProbe/', $body)) {
        $failures[] = "{$method}() changes whether a migration is needed but does not "
            . 'invalidate the cached answer, so the notice and the Migration page keep '
            . 'reporting the previous state';
    }
}

// ── 4. An individual index probe is memoised too ────────────────────────────
// Rendering the notice asks once through needsMigration() and again through
// getRequiredDiagnostics().
$shouldRun = slimstat_function_body($indexBase, 'shouldRun');
if ($shouldRun === '') {
    $failures[] = 'AbstractIndexMigration::shouldRun() not found';
} elseif (!preg_match('/if\s*\(\s*null\s*!==\s*\$this->shouldRunCache\s*\)\s*\{\s*return\s+\$this->shouldRunCache;/', $shouldRun)) {
    $failures[] = 'AbstractIndexMigration::shouldRun() does not return early from its memo, '
        . 'so it issues its SHOW INDEX every time it is asked — and rendering the migration '
        . 'notice asks twice per migration';
}

// ── 5. The migration UI imports only classes that exist ─────────────────────
// Two imports pointed at classes that were never written. Harmless while unused, and a
// fatal the moment someone references them.
$adminUi = $read('src/Migration/Admin/MigrationAdmin.php');
if (preg_match_all('/^use\s+(SlimStat\\\\Migration\\\\Migrations\\\\[A-Za-z]+)\s*;/m', $adminUi, $m)) {
    foreach ($m[1] as $fqcn) {
        $short = substr((string) strrchr($fqcn, '\\'), 1);
        if (!is_file($plugin_root . '/src/Migration/Migrations/' . $short . '.php')) {
            $failures[] = "MigrationAdmin imports {$short}, which does not exist — a fatal "
                . 'as soon as anything references it';
        }
    }
}

// ── Report ─────────────────────────────────────────────────────────────────
if ($failures !== []) {
    fwrite(STDERR, 'FAIL: migration runner reachable (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: migration runner reachable (repair path wired; the needs-migration probe is "
    . "memoised, cached and invalidated)\n";
exit(0);
