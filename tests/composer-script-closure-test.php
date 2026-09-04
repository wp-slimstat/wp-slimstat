<?php
/**
 * Every `test:*` composer script is reachable from `test:all`, or says why it is not.
 *
 * ── THE GAP THIS CLOSES ─────────────────────────────────────────────────────────────────────
 *
 * `no-orphan-test-files-test.php` asks "is this FILE named by a script or a workflow?" — and its
 * own failure text tells you to "wire it into a script that test:source-level or test:all
 * reaches", a property it never verifies. So a script that names a file, and is itself named by
 * nothing, satisfies that gate completely: the file looks invoked, the script runs nowhere.
 *
 * Found live by an altitude review on 2026-09-04: `test:fp-negative-linux` was reachable from
 * neither `test:all` nor any workflow — referenced only by its own definition. It is a real
 * gate (it re-runs the fp-negative suite under dash, because the one property in that suite
 * which depends on the shell is worded differently by bash, so a green macOS host run says
 * nothing about the six Linux lanes) and it had been running nowhere.
 *
 * Pro has carried this check since its own `ci-integrity-test.php`; free did not. This is the
 * port, narrowed to the closure question free was missing.
 *
 * ── STANDALONE IS A REASON, NOT A LIST ──────────────────────────────────────────────────────
 *
 * A script outside the aggregate is fine when something else runs it, or when it cannot be run
 * unattended. Each such script states WHY here, and a stale exemption — one whose script has
 * since been added to `test:all` — is its own failure, because the note tells the next reader
 * something untrue.
 *
 * Run: php tests/composer-script-closure-test.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit(1);
}

error_reporting(E_ALL);

$plugin_root = dirname(__DIR__);
$failures    = [];

$composer = json_decode((string) file_get_contents($plugin_root . '/composer.json'), true);
$scripts  = $composer['scripts'] ?? [];

if (!is_array($scripts) || [] === $scripts) {
    fwrite(STDERR, "FAIL: composer.json declares no scripts — nothing to check.\n");
    exit(1);
}

// Transitive closure of `@name` references from test:all.
$reachable = [];
$walk      = static function (string $name) use (&$walk, $scripts, &$reachable): void {
    foreach ((array) ($scripts[$name] ?? []) as $entry) {
        if (!is_string($entry) || '@' !== substr($entry, 0, 1)) {
            continue;
        }
        $ref = substr($entry, 1);
        if (isset($reachable[$ref])) {
            continue;
        }
        $reachable[$ref] = true;
        $walk($ref);
    }
};
$walk('test:all');

// Standalone by design. Each entry states WHY, because "it is in the list" is not a reason, and
// a list anyone may append to without argument is not a gate.
$standalone = [
    'test:phpstan' => 'runs in its own PHPStan lane with vendor installed; static analysis is not '
        . 'a test suite and its baseline is ratcheted separately by phpstan-baseline-ratchet',
    'test:mutations' => 'runs as its own ci.yml step on all six Tier 1 lanes. It applies patches '
        . 'to the working tree and reverts with `git checkout --`, so it must never run inside an '
        . 'aggregate that another gate is also mutating',
    'test:fp-negative-linux' => 'builds a Docker image and runs the fp-negative suite under dash. '
        . 'It cannot run on a host without Docker, so it must not be in an aggregate every lane '
        . 'executes; PITFALLS 93 rule 2 is why it exists separately from test:fp-negative',
];

// `test:reports-escaping` is deliberately NOT here. It needs a live WordPress and its CI home is
// the Tier 2 wp-env lane — but it IS a member of test:all, and this gate rejected a standalone
// note for it on its first run. That is the stale-exemption check doing its job on the author:
// membership and "runs in its own lane" are different claims, and only the first is checkable
// here. perf-gate-integrity §8 is what pins the lane.

$test_scripts = array_values(array_filter(
    array_keys($scripts),
    static function (string $k): bool {
        return 0 === strpos($k, 'test:') && 'test:all' !== $k && 0 !== strpos($k, '_comment');
    }
));

// VACUITY FLOOR. Free declares well over a hundred; a handful means the filter broke and every
// check below passes by looking at nothing.
if (count($test_scripts) < 100) {
    $failures[] = sprintf('found only %d test:* script(s) in composer.json; the suite declares far '
        . 'more, so the filter is wrong and the closure check below is inert', count($test_scripts));
}

if (!$reachable) {
    $failures[] = 'test:all resolves to no @-referenced scripts — the closure is broken, so every '
        . 'script below would read as unreachable';
}

foreach ($test_scripts as $name) {
    if (isset($reachable[$name]) || isset($standalone[$name])) {
        continue;
    }
    $failures[] = sprintf(
        'composer script "%s" is reachable from neither test:all nor the standalone list. It runs '
            . 'only if someone types it, which means it does not run — add it to an aggregate, or '
            . 'name it standalone here WITH the reason',
        $name
    );
}

// A stale exemption is its own defect: it claims a script sits outside the aggregate when the
// script has since been added to it, and the next reader trusts the note.
foreach (array_keys($standalone) as $name) {
    if (!isset($scripts[$name])) {
        $failures[] = sprintf('the standalone list names "%s", which is not a composer script. '
            . 'A reason for a script that does not exist is a note nobody can check', $name);
    } elseif (isset($reachable[$name])) {
        $failures[] = sprintf('"%s" is listed as standalone but IS reachable from test:all. The '
            . 'note now says something untrue; delete the entry', $name);
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: composer script closure (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo sprintf("PASS: all %d test:* script(s) are reachable from test:all or standalone with a "
    . "stated reason (%d exempt)\n", count($test_scripts), count($standalone));
