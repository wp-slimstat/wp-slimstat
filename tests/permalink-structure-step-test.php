<?php
/**
 * The Tier 2 lane runs pretty permalinks, and proves it over HTTP before the E2E step.
 *
 * WordPress installs with an EMPTY permalink_structure. Under it Apache never hands /wp-json/
 * to WordPress and the obfuscated /request/{hash}/ endpoint has no rewrite rule to exist under,
 * while the tracker's own transport degrades to index.php?rest_route= and keeps working — so
 * tracking passes, data-collection-rest passes, and only the specs that NAME the pretty path
 * fail. That asymmetry is why it read as 25 unrelated product failures for the whole programme
 * (uncapped census, Run 65: H-PERM, the largest single family of the 132).
 *
 * WHAT IS PINNED: exactly one ci.yml step sets a non-empty rewrite structure; it verifies over
 * HTTP against the REST index rather than by reading back the option it just wrote; it can fail
 * the lane; it is not soft; and it runs BEFORE the E2E step, since a flush after the specs is
 * the same as no flush.
 *
 * The verification shape is the whole point. `wp option get permalink_structure` reports what
 * the line above it wrote and says nothing about whether the web server routes the path — the
 * observed failure was Apache's own HTML 404 body, not WordPress's rest_no_route JSON. A gate
 * that accepted the option read would have gone green over the exact state it exists to catch.
 *
 * Run: php tests/permalink-structure-step-test.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit(1);
}

error_reporting(E_ALL);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

$ci_code = slimstat_yaml_strip_comments((string) file_get_contents($plugin_root . '/.github/workflows/ci.yml'));
$steps   = slimstat_ci_steps($ci_code);

// ── 1. Exactly one step sets the structure, and the structure is not empty ──────────────
$rewrite_indexes = slimstat_ci_step_indexes($steps, 'wp rewrite structure');
$rewrite_step    = 1 === count($rewrite_indexes) ? $steps[$rewrite_indexes[0]] : '';

if ('' === $rewrite_step) {
    $failures[] = sprintf('%d ci.yml step(s) run `wp rewrite structure`; exactly one is expected. '
        . 'Without it the lane runs plain permalinks, /wp-json/ is not routed, and every spec '
        . 'naming the pretty REST path or the /request/ endpoint fails on transport',
        count($rewrite_indexes));
}

if ('' !== $rewrite_step) {
    // The argument, not merely the command. `wp rewrite structure ''` is the state being
    // fixed spelled as the fix, and it would satisfy every other check in this file.
    if (!preg_match('/wp rewrite structure\s+([\'"])(.+?)\1/', $rewrite_step, $m) || '' === trim($m[2])) {
        $failures[] = 'the rewrite step does not pass a quoted, non-empty structure — an empty '
            . 'structure IS plain permalinks, so the step would set the very state it exists '
            . 'to replace';
    } elseif (false === strpos($m[2], '%')) {
        $failures[] = sprintf('the rewrite structure `%s` contains no %% tag, so it is not a '
            . 'permalink structure WordPress will build rules from', $m[2]);
    }
}

// ── 2. It verifies the TRANSPORT, not the option it just wrote ──────────────────────────
//
// Scoped to the run block with echo lines removed, and that is not fussiness. The first
// version of this file searched the WHOLE step, and the step's own name and success message
// both say "/wp-json/" and "slimstat/v1" — so replacing the fetch with a permalink_structure
// read left two of the three assertions green on the step's prose. A needle that appears only
// in an echo is a message, not a check.
if ('' !== $rewrite_step) {
    $run_at = strpos($rewrite_step, "\n        run:");
    $run    = false === $run_at ? '' : substr($rewrite_step, $run_at);
    $checks = implode("\n", array_filter(
        explode("\n", $run),
        static fn(string $l): bool => !preg_match('/^\s*echo\b/', $l)
    ));

    if ('' === $run) {
        $failures[] = 'the rewrite step has no `run:` block, so it executes nothing';
    }

    if (!preg_match('#curl[^\n]*/wp-json/#', $checks)) {
        $failures[] = 'the rewrite step never fetches /wp-json/ over HTTP. Reading '
            . 'permalink_structure back only reports what the step itself wrote; the failure '
            . 'being pinned is the web server not routing that path to WordPress at all';
    }

    // The REST index is the one body Apache's 404 page cannot forge, and the namespace check
    // is what separates "WordPress answered" from "WordPress answered and the plugin is on it".
    foreach (['"namespaces"', 'slimstat/v1'] as $needle) {
        if (false === strpos($checks, $needle)) {
            $failures[] = sprintf('the rewrite step does not require `%s` in the response body. '
                . 'A 200 alone does not prove WordPress answered, and WordPress answering does '
                . 'not prove the plugin routes are registered', $needle);
        }
    }

    if (false === strpos($checks, 'exit 1')) {
        $failures[] = 'the rewrite step does not `exit 1` when the fetch fails; a check that '
            . 'only logs leaves the lane on plain permalinks with a green tick';
    }

    if (false !== strpos($rewrite_step, 'continue-on-error')) {
        $failures[] = 'the rewrite step is soft; a check that cannot fail the lane cannot '
            . 'protect the 25 specs that depend on the property';
    }
}

// ── 3. It runs before the E2E step ──────────────────────────────────────────────────────
// A structure set after the specs have run is indistinguishable from never setting it, and
// step order is the kind of thing a merge reorders without anyone reading it.
$e2e_indexes = slimstat_ci_step_indexes($steps, 'npm run test:e2e');

if (!$e2e_indexes) {
    $failures[] = 'no ci.yml step runs `npm run test:e2e`, so this file cannot check that the '
        . 'permalink step precedes the specs that need it';
} elseif ($rewrite_indexes) {
    $first_e2e = min($e2e_indexes);
    if ($rewrite_indexes[0] > $first_e2e) {
        $failures[] = sprintf('the rewrite step (step %d) runs AFTER the first E2E step '
            . '(step %d) — permalinks flushed after the specs are permalinks the specs never had',
            $rewrite_indexes[0], $first_e2e);
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: permalink structure step (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: the Tier 2 lane sets a non-empty permalink structure, proves /wp-json/ is answered "
    . "by WordPress with slimstat/v1 registered, fails the lane if not, and does it before the "
    . "E2E step\n";
