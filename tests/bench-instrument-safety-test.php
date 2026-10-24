<?php
/**
 * Source-level: the bench instrument cannot be armed by a request, and cannot write a
 * secret or a web-served file (W4).
 *
 * The original X5 report claimed a completed bench run left a web-readable SQL/PII
 * ledger on any install carrying this file. That was REFUTED and withdrawn: hit-cost.sh
 * sends only `X-Slimstat-Bench`, never the `-Sql` variant, so the capture branch never
 * fires and a run leaves a label, a duration and seven integers. The PII ledger that did
 * exist was traced by label and mtime to a hand-rolled curl, not the harness.
 *
 * What survived the refutation is hygiene, and it is what this pins:
 *
 *   - ARMING. A header alone used to be enough, and the header's name is in the public
 *     repository. It now also requires SLIMSTAT_BENCH in wp-config.php — something no
 *     request can set. The header is demoted to a label.
 *   - LOCATION. The ledger was written to wp-content/uploads, which is web-served, with
 *     mkdir(0777). It goes outside the docroot at 0700.
 *   - SECRETS. slimstat_daily_salt is written through the same `query` filter this
 *     hooks, so a verbatim capture would record the key that makes every hashed IP on
 *     the site reversible. Statements naming a secret are replaced wholesale.
 *   - BOUNDS. Append-only and uncapped fills a disk on a long run, and a silently
 *     truncated ledger reads as a complete one.
 *
 * And the instrument must not outlive the run: hit-cost.sh registered its cleanup trap
 * on the line AFTER the cp that installs the mu-plugin, so an interrupt in that window
 * stranded it. That ordering is asserted here because it is one line and it has already
 * gone wrong once on this workstation.
 */

declare(strict_types=1);

// Never executable over HTTP: these scripts run to completion, write to
// STDOUT/STDERR (undefined under a web SAPI) and can disclose absolute paths.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit(1);
}

require_once __DIR__ . '/lib/source-scan.php';

$root     = dirname(__DIR__);
$failures = [];

// ── The mu-plugin ───────────────────────────────────────────────────────────
$mu = (string) @file_get_contents($root . '/tests/bench/mu/slimstat-bench-qlog.php');
if ('' === $mu) {
    fwrite(STDERR, "FAIL: cannot read tests/bench/mu/slimstat-bench-qlog.php\n");
    exit(1);
}

// Code only. The docblock explains the removed header-only arming at length, and a
// raw-text scan would read that prose as the defect — three times over, this suite has
// learned that the file describing a fix is where the fix looks like the bug.
$code = slimstat_blank_comments($mu);

if (!preg_match("/!\s*defined\s*\(\s*'SLIMSTAT_BENCH'\s*\)/", $code)) {
    $failures[] = 'the ledger is not gated on a SLIMSTAT_BENCH constant. A header alone arms it, '
        . 'and the header name is in the public repository';
}

if (preg_match('/uploads/', $code)) {
    $failures[] = 'the ledger writes under wp-content/uploads, which is web-served. Statement text '
        . 'belongs outside the docroot';
}

if (preg_match('/mkdir\s*\([^)]*0777/', $code)) {
    $failures[] = 'the ledger directory is created world-writable';
}

if (!preg_match('/mkdir\s*\([^)]*0700/', $code)) {
    $failures[] = 'the ledger directory is not created 0700';
}

foreach (['slimstat_daily_salt' => 'the daily IP-hash salt', 'auth_key' => 'the auth keys'] as $needle => $what) {
    if (false === strpos($code, $needle)) {
        $failures[] = sprintf(
            'the capture does not redact %s. It is written through the same `query` filter this '
                . 'hooks, so a verbatim ledger records it',
            $what
        );
    }
}

// Scoped to the body that WRITES the ledger, not the whole file: a constant and a
// property survive the deletion of every use, so matching their names anywhere would
// pass on exactly the change being guarded against. Measured — both of these
// assertions did pass that way before being scoped.
$flush = slimstat_function_body($code, 'flush');

if ('' === $flush) {
    $failures[] = 'cannot isolate flush() — re-anchor this scan rather than trusting the run';
} else {
    if (!preg_match('/filesize\s*\(/', $flush) || false === strpos($flush, 'MAX_LOG_BYTES')) {
        $failures[] = 'flush() does not consult the size cap — append-only on a long run fills the disk';
    }

    if (false === strpos($flush, 'sql_truncated')) {
        $failures[] = 'flush() does not report statements dropped past the per-request cap. A '
            . 'truncated ledger that reads as complete is worse than no ledger';
    }
}

// ── The harness ─────────────────────────────────────────────────────────────
$sh = (string) @file_get_contents($root . '/tests/bench/hit-cost.sh');
if ('' === $sh) {
    $failures[] = 'cannot read tests/bench/hit-cost.sh';
} else {
    $trap_at = strpos($sh, 'trap ');
    $cp_at   = strpos($sh, 'cp "$ROOT/tests/bench/mu/');

    if (false === $trap_at || false === $cp_at) {
        $failures[] = 'cannot locate the install/cleanup pair in hit-cost.sh — re-anchor this scan';
    } elseif ($trap_at > $cp_at) {
        $failures[] = 'hit-cost.sh registers its cleanup trap AFTER copying the mu-plugin in. An '
            . 'interrupt in that window strands the instrument on the install';
    }

    if (false === strpos($sh, 'INT TERM')) {
        $failures[] = 'hit-cost.sh traps only EXIT; Ctrl-C during a run leaves the instrument behind';
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: bench instrument safety (' . count($failures) . " problem(s))\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: bench ledger needs a wp-config constant, writes outside the docroot, redacts "
    . "secrets and is bounded; the harness cleans up before it installs\n");
exit(0);
