<?php
/**
 * Source-level: the tracker's table-repair path is single-flight and narrowly triggered.
 *
 * When Storage::insertRow() returns false, Processor recovered by running
 * wp_slimstat_admin::init_environment() — the whole DDL sequence: four CREATE TABLEs,
 * five CREATE INDEXes, the visit-id counter and flush_rewrite_rules() — and then
 * retrying the insert. From an anonymous front-end request.
 *
 * That was written for a real situation: before C38, `wp plugin activate` registered no
 * activation hook (both registrations sat inside `if (is_admin())`, which is false under
 * WP-CLI), so a CLI-provisioned site genuinely had no tables and this was the ONLY thing
 * that created them. C38 fixed activation, which is what makes this path abnormal again
 * and therefore gateable.
 *
 * Two problems, neither of which was the DDL itself:
 *
 *   - THE TRIGGER WAS ANY FAILURE. A deadlock, a lock-wait timeout, a full disk, a
 *     `max_allowed_packet` rejection — every one of them ran the full DDL and retried.
 *     Almost none of them is a missing table.
 *   - THERE WAS NO SINGLE-FLIGHT. On a busy site a transient failure is not one failure,
 *     it is every concurrent request failing at once — so every one of them ran CREATE
 *     TABLE IF NOT EXISTS and flush_rewrite_rules() together, against a database that
 *     was already the thing struggling.
 *
 * So: repair only when the error actually says the table is missing, and only one
 * request at a time, at most once per cooldown.
 */

declare(strict_types=1);

// Never executable over HTTP: these scripts run to completion, write to
// STDOUT/STDERR (undefined under a web SAPI) and can disclose absolute paths.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit(1);
}

require_once __DIR__ . '/lib/source-scan.php';

$source = (string) @file_get_contents(dirname(__DIR__) . '/src/Tracker/Processor.php');
if ('' === $source) {
    fwrite(STDERR, "FAIL: cannot read src/Tracker/Processor.php\n");
    exit(1);
}

// Code only — the docblock above the guard necessarily describes the ungated version.
$code = slimstat_blank_comments($source);

$failures = [];

// The repair lives in its own method now; find it rather than the whole file, so
// "init_environment appears somewhere in Processor" cannot satisfy any of this.
$repair = slimstat_function_body($code, 'repairSchemaOnce');

if ('' === $repair) {
    $failures[] = 'Processor::repairSchemaOnce() is gone. The DDL recovery has to live behind one '
        . 'named, gated entry point — inlining it again is how it came to run on every failure';
} else {
    // The CLAIM specifically, not the class name: compareAndSwap() also mentions
    // OptionClaim, so matching the name passed with the claim itself deleted.
    if (!preg_match('/OptionClaim::insert\s*\(/', $repair)) {
        $failures[] = 'the repair is not single-flight. On a busy site a transient insert failure '
            . 'is every concurrent request failing at once, and each one would run CREATE TABLE and '
            . 'flush_rewrite_rules() against the database that is already struggling';
    }

    if (false === strpos($repair, 'REPAIR_COOLDOWN')) {
        $failures[] = 'the repair has no cooldown, so a site whose tables genuinely cannot be '
            . 'created retries the whole DDL on every tracked hit, forever';
    }

    if (!preg_match('/catch\s*\(\s*\\\\?Throwable/', $repair)
        || false === strpos($repair, 'record_degradation')) {
        $failures[] = 'the DDL is not fail-soft. It runs on an anonymous front-end request, so a '
            . 'throw here is a white screen for a visitor — and an unrecorded one';
    }

    // The error now arrives as a PARAMETER rather than being re-read from
    // $wpdb->last_error: insertRow() may run a column probe and a retry after the failing
    // statement, and each resets the global, so it no longer describes the write being
    // classified. Assert the narrowing happens on that parameter.
    if (!preg_match('/preg_match\s*\([^,]+,\s*\$error\s*\)/', $repair)) {
        $failures[] = 'the repair does not inspect the error text, so it fires on deadlocks, '
            . 'lock-wait timeouts and full disks — almost none of which is a missing table';
    }

    if (!preg_match("/doesn'?t exist|no such table|1146|ER_NO_SUCH_TABLE/i", $repair)) {
        $failures[] = 'the repair does not narrow to a missing-table error specifically';
    }
}

// And the raw call must not be reachable any other way from the insert path.
//
// Scoped to process() — the method that performs the insert and the single retry.
// The invariant is not "calls init_environment() without mentioning the gate", it is
// "does not call it at all": repairSchemaOnce() is itself the caller, so any condition
// mentioning both is true by construction and can never fire.
//
// Strings are blanked as well as comments. $code is already comment-blanked above, so
// this only adds literals, and nothing in Processor.php names init_environment() inside
// one today — it is insurance against a future SQL string, not a fix for a live match.
$store = slimstat_strip_comments_and_strings(slimstat_function_body($code, 'process'));

if (preg_match('/init_environment\s*\(/', $store)) {
    $failures[] = 'the tracking path calls init_environment() directly. Every DDL recovery has to '
        . 'go through the gated entry point, or the gate is decorative';
}

if ($failures) {
    fwrite(STDERR, 'FAIL: tracker repair gate (' . count($failures) . " problem(s))\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: schema repair from the tracking path is single-flight, cooled down and "
    . "triggered only by a missing table\n");
exit(0);
