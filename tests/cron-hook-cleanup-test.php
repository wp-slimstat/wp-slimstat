<?php
/**
 * Source-level regression: every cron hook SlimStat schedules is declared in
 * src/cron-hooks.php, and that list is cleared on both deactivation and uninstall.
 *
 * `wp_slimstat_generate_daily_salt` was scheduled from the day it was introduced
 * and cleared nowhere, so it kept firing against a deactivated plugin and survived
 * uninstall as an orphan wp-cron entry. `slimstat_daily_cron_hook` had the same
 * problem and was additionally invisible to the first version of this test, which
 * grepped a single file for the literal `wp_schedule_event(` and therefore missed
 * the plugin's own SlimStat\Components\Event::schedule() wrapper — a gate that
 * reported "all hooks cleared" while an orphan sat right next to it.
 *
 * So this scans the WHOLE plugin for every scheduling spelling and reconciles it
 * against the declared list, rather than trusting one file and one call shape.
 *
 * 7.4-safe: pure source-text analysis; loads no WordPress and no plugin code.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);

$declared = require $plugin_root . '/src/cron-hooks.php';
if (!is_array($declared) || !$declared) {
    fwrite(STDERR, "FAIL: src/cron-hooks.php did not return a non-empty array\n");
    exit(1);
}

// --- collect every hook the plugin schedules, however it spells it ---
$scheduled = [];
$scanned   = 0;

$directory = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($plugin_root, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($directory as $file) {
    $path = $file->getPathname();
    if (substr($path, -4) !== '.php') {
        continue;
    }
    // Skip third-party, build and test trees.
    foreach (['/vendor/', '/node_modules/', '/src/Dependencies/', '/tests/', '/packages/'] as $skip) {
        if (strpos($path, $skip) !== false) {
            continue 2;
        }
    }

    $src = (string) file_get_contents($path);
    $scanned++;
    $rel = substr($path, strlen($plugin_root) + 1);

    // wp_schedule_event( $timestamp, $recurrence, 'hook' ) — hook is the 3rd arg.
    if (preg_match_all("/wp_schedule_event\s*\([^;]*?['\"]([a-z0-9_]+)['\"]\s*\)/i", $src, $m)) {
        foreach ($m[1] as $hook) {
            $scheduled[$hook][] = $rel;
        }
    }
    // wp_schedule_single_event( $timestamp, 'hook' ) — hook is the 2nd arg.
    if (preg_match_all("/wp_schedule_single_event\s*\([^;]*?['\"]([a-z0-9_]+)['\"]\s*\)/i", $src, $m)) {
        foreach ($m[1] as $hook) {
            $scheduled[$hook][] = $rel;
        }
    }
    // Event::schedule( 'hook', ... ) — the plugin's own wrapper; hook is the 1st arg.
    if (preg_match_all("/Event::schedule\s*\(\s*['\"]([a-z0-9_]+)['\"]/i", $src, $m)) {
        foreach ($m[1] as $hook) {
            $scheduled[$hook][] = $rel;
        }
    }
}

if (!$scanned) {
    fwrite(STDERR, "FAIL: scanned no PHP files — the walk is broken, refusing to report success\n");
    exit(1);
}
if (!$scheduled) {
    fwrite(STDERR, "FAIL: found no scheduling calls at all in {$scanned} files — the patterns are stale\n");
    exit(1);
}

$failures = [];

// --- 1) every scheduled hook must be declared ---
foreach ($scheduled as $hook => $where) {
    // Event.php contains the generic `wp_schedule_event($timestamp, $recurrence, $hook)`
    // wrapper itself; a variable there is not a literal so it never matches.
    if (!in_array($hook, $declared, true)) {
        $failures[] = sprintf(
            "'%s' is scheduled in %s but is missing from src/cron-hooks.php",
            $hook,
            implode(', ', array_unique($where))
        );
    }
}

// --- 2) the declared list must be cleared in both teardown paths ---
$paths = [
    'admin/index.php' => 'deactivate',
    'uninstall.php'   => 'slimstat_uninstall_cron',
];

foreach ($paths as $rel => $function) {
    $body = slimstat_function_body((string) file_get_contents($plugin_root . '/' . $rel), $function);
    if ('' === $body) {
        $failures[] = "could not isolate {$rel}::{$function}() — did it get renamed?";
        continue;
    }
    // Iterating the shared list counts; so does clearing each hook by name.
    $iterates = strpos($body, 'cron-hooks.php') !== false
        && strpos($body, 'wp_clear_scheduled_hook') !== false;
    if ($iterates) {
        continue;
    }
    foreach ($declared as $hook) {
        if (strpos($body, $hook) === false) {
            $failures[] = "{$rel}::{$function}() neither iterates src/cron-hooks.php nor clears '{$hook}'";
        }
    }
}

if ($failures) {
    fwrite(STDERR, "FAIL: cron hook scheduling/cleanup is out of sync:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    fwrite(STDERR, "\nDeclare every scheduled hook in src/cron-hooks.php; both teardown paths iterate it.\n");
    exit(1);
}

printf(
    "OK: %d scheduled hook(s) across %d files all declared in src/cron-hooks.php and cleared on deactivate + uninstall (%s)\n",
    count($scheduled),
    $scanned,
    implode(', ', array_keys($scheduled))
);
exit(0);
