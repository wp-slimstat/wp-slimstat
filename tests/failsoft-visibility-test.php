<?php
/**
 * Source-level contract: every fail-soft guard added for issue #325 must RECORD a
 * persisted degradation, and that recorder must never itself be able to fatal.
 *
 * Background: the #325 guards stop a class-load failure from white-screening the
 * site, but they originally routed only through wp_slimstat::log(), which writes
 * nothing unless WP_DEBUG is on. On a normal production site a dead tracker or a
 * missing consent banner therefore left no trace anywhere — the plugin looked
 * healthy while a sub-feature was gone. wp_slimstat::record_degradation() persists
 * the failure and wp_slimstat_admin::show_degradation_notice() surfaces it.
 *
 * The recorder deliberately lives in wp-slimstat.php (always loaded) and NOT in
 * src/. A recorder that autoloads a src/ class could itself fail to load inside
 * the very catch block that exists because src/ classes may be unloadable — the
 * new \Error would escape the catch and re-create the WSOD. Assertion 3 pins that.
 *
 * Behavioural coverage of the guards themselves lives in rest-api-fail-soft-test.php
 * and gdpr-service-fail-soft-test.php; this file pins the contract those guards
 * must keep across the whole bootstrap.
 *
 * 7.4-safe: pure source-text analysis, loads no WordPress and no plugin code.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);

$sources = [];
foreach (['wp-slimstat.php', 'admin/index.php', 'src/Providers/RestApiManager.php', 'src/Utils/Consent.php'] as $rel) {
    $contents = @file_get_contents($plugin_root . '/' . $rel);
    if (!is_string($contents) || '' === $contents) {
        fwrite(STDERR, "FAIL: could not read {$rel}\n");
        exit(1);
    }
    $sources[$rel] = $contents;
}

$assertions = 0;
$failures   = [];

function contract_assert(bool $condition, string $message): void
{
    global $assertions, $failures;
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
    }
}

$main = $sources['wp-slimstat.php'];

// --- 1) The API exists on the always-loaded main class. ---
foreach (['record_degradation', 'reconcile_degradations', 'get_degradations'] as $method) {
    contract_assert(
        (bool) preg_match('/public static function ' . $method . '\s*\(/', $main),
        "wp_slimstat::{$method}() is missing"
    );
}

// --- 2) record_degradation() PERSISTS, it does not only log. ---
$recorder_body = slimstat_function_body($main, 'record_degradation');
contract_assert('' !== $recorder_body, 'could not isolate the record_degradation() body');
contract_assert(
    false !== strpos($recorder_body, 'update_option('),
    'record_degradation() must persist via update_option() — a WP_DEBUG-gated log is invisible in production'
);

// --- 3) The recorder must not depend on the autoloader (re-fatal hazard). ---
contract_assert(
    false === strpos($recorder_body, 'SlimStat\\'),
    'record_degradation() must not reference a SlimStat\\ class: it runs inside catch blocks that exist '
    . 'precisely because src/ classes may be unloadable, so an autoload failure here would re-create the WSOD'
);

// --- 4) Every #325 bootstrap guard records; none is left silently logging. ---
// Scoped deliberately to the functions the #325 guards live in. Other \Throwable
// catches in the codebase are already visible by other means — the GeoIP AJAX
// handlers answer with wp_send_json_error(), and the GeoIP cron path writes
// slimstat_geoip_error — so requiring record_degradation() there would be noise.
$guarded_functions = [
    // on_activate/on_deactivate run DDL and cron cleanup from whatever request performed
    // the (de)activation — including `wp plugin activate`. A throw there white-screens
    // the plugins screen or aborts the CLI mid-DDL.
    'wp-slimstat.php'                  => ['init', 'init_plugin', 'enqueue_tracker', 'render_gdpr_banner', 'on_activate', 'on_deactivate'],
    // load_controllers' guard is the one with no behavioural coverage — the injected
    // controller in rest-api-fail-soft-test.php throws in register_routes(), not in
    // construction — so it is pinned here. register_routes and bannerHasConsentSafe
    // are proven at runtime by that test and gdpr-service-fail-soft-test.php.
    'src/Providers/RestApiManager.php' => ['load_controllers'],
    // update_tables_and_options() runs on admin_init with nothing above it to catch a
    // throw, and it stamps the schema version as its LAST statement — so an uncaught
    // error there white-screens wp-admin permanently (S1 shipped exactly that). It was
    // a hole in this convention rather than an exception to it.
    'admin/index.php'                  => ['update_tables_and_options'],
];

$guarded_catches = 0;
foreach ($guarded_functions as $rel => $functions) {
    foreach ($functions as $function) {
        $body = slimstat_function_body($sources[$rel], $function);
        if ('' === $body) {
            contract_assert(false, "{$rel}: could not isolate {$function}() — did it get renamed?");
            continue;
        }

        $catches = slimstat_throwable_catch_bodies($body);

        // Per-function floor. The aggregate floor below only catches TOTAL extraction
        // failure: measured, deleting one listed function's guard entirely left the
        // aggregate above its threshold and the run still reported OK. Each listed
        // function is listed because it must not be able to fatal, so each must carry
        // at least one guard of its own.
        contract_assert(
            [] !== $catches,
            sprintf('%s: %s() has no \\Throwable guard — it is listed here because it must not be able to fatal', $rel, $function)
        );

        foreach ($catches as $i => $catch) {
            $guarded_catches++;
            contract_assert(
                false === strpos($catch, '::log('),
                sprintf('%s: %s() catch #%d still calls log() directly — use record_degradation()', $rel, $function, $i + 1)
            );
            contract_assert(
                false !== strpos($catch, 'record_degradation'),
                sprintf('%s: %s() catch #%d does not record a degradation', $rel, $function, $i + 1)
            );
        }
    }
}

// Vacuity guard: if the body/catch extraction silently stops matching, every
// assertion above turns into a no-op that still prints OK. Pin the count.
contract_assert(
    $guarded_catches >= 8,
    "only found {$guarded_catches} guarded catch blocks (expected at least 8) — the source scan is stale, "
    . 'fix it rather than trusting this run'
);

// --- 5) The notice is wired, permission-gated, and escapes what it prints. ---
$admin = $sources['admin/index.php'];
contract_assert(
    (bool) preg_match("/add_action\(\s*'admin_init'\s*,\s*\[\s*'wp_slimstat'\s*,\s*'reconcile_degradations'/", $admin),
    'reconcile_degradations() must be hooked on admin_init or stale notices never clear'
);
contract_assert(
    (bool) preg_match("/add_action\(\s*'admin_notices'\s*,\s*\[\s*'wp_slimstat_admin'\s*,\s*'show_degradation_notice'/", $admin),
    'show_degradation_notice() must be hooked on admin_notices'
);

$notice_body = slimstat_function_body($admin, 'show_degradation_notice');
contract_assert('' !== $notice_body, 'could not isolate the show_degradation_notice() body');
contract_assert(
    false !== strpos($notice_body, "current_user_can('manage_options')"),
    'show_degradation_notice() must be gated on manage_options'
);
contract_assert(
    false !== strpos($notice_body, 'esc_html($message)'),
    'show_degradation_notice() must escape the recorded message — it is attacker-influenceable text'
);

// ── C34: the degradation option must never autoload ─────────────────────────
// update_option() with no third argument puts the value in `alloptions`, fetched on EVERY
// request — on precisely the sites already unhealthy enough to be recording degradations.
// It is read only by get_degradations(), which runs on admin screens, and H2's own
// governance gate fails a new autoloaded option.
//
// Counted across ALL writers rather than checked at one: WordPress takes the autoload value
// from whichever write happened last, so a single un-flagged writer silently undoes every
// flagged one. There were two, and only one was found by reading.
$main_src   = slimstat_strip_comments_and_strings((string) file_get_contents($plugin_root . '/wp-slimstat.php'));
$deg_writes = preg_match_all('/update_option\s*\(\s*self::DEGRADATION_OPTION\b/', $main_src);
$deg_safe   = preg_match_all('/update_option\s*\(\s*self::DEGRADATION_OPTION\s*,[^;]*?,\s*false\s*\)/', $main_src);
$assertions++;

if ($deg_writes !== $deg_safe) {
    $failures[] = sprintf(
        '%d of %d writer(s) of the degradation option pass autoload=false. Every writer must: '
        . 'WordPress takes the flag from the last write, so one un-flagged call puts the '
        . 'option back into alloptions on every request',
        $deg_safe,
        $deg_writes
    );
}

if (0 === $deg_writes) {
    $failures[] = 'no writers of the degradation option were found — the scan is broken, not '
        . 'the tree, and a zero here would read as "all safe"';
}

if ($failures) {
    fwrite(STDERR, "FAIL: fail-soft visibility contract violated:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

fwrite(STDOUT, "OK: {$assertions} assertions passed (fail-soft failures are recorded, surfaced, and cannot re-fatal)\n");
exit(0);
