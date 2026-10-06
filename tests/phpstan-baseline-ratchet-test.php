<?php
/**
 * The PHPStan baseline is disclosed where contributors read, and it can only shrink.
 *
 * ── WHAT WENT WRONG ─────────────────────────────────────────────────────────────────────────
 *
 * `phpstan-baseline.neon` parks 162 entries suppressing 315 errors, and nothing said so — the
 * number appeared in no document a contributor reads, and nothing stopped it growing.
 * `phpstan.neon.dist` sets `reportUnmatchedIgnoredErrors: false` for a real reason recorded
 * there, and the consequence is that `phpstan --generate-baseline` on a regression simply
 * absorbs it while the CI lane stays green.
 *
 * ── THE RATCHET, AND THE HALF OF IT THAT WAS VACUOUS IN CI ──────────────────────────────────
 *
 * `tests/PHPSTAN-BASELINE-CEILING` holds `entries suppressions`; the baseline must match it
 * EXACTLY, and the ceiling may only DESCEND. A ratchet that turns both ways is a dial.
 *
 * The descent half first compared against `HEAD`. On a push, HEAD is the pushed commit and its
 * ceiling IS the new value; on a PR, HEAD is the merge commit, same thing. So in CI the check
 * compared the new ceiling with itself on every real commit, and saw a previous value only on a
 * dirty working tree — which is what the mutation lane is, so P1 passed while the check it
 * certified never ran for real. Found by the altitude reviewer. Now: when the file on disk equals
 * HEAD's copy (a committed change, i.e. CI), compare against `HEAD^` — Tier 1 checks out with
 * `fetch-depth: 2` precisely so a parent exists (PITFALLS 96); otherwise (a working-tree edit)
 * compare against HEAD. Both are the last committed value from where the change stands.
 *
 * Run: php tests/phpstan-baseline-ratchet-test.php
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

// ── 1. Measure the baseline ─────────────────────────────────────────────────────────────
$baseline = (string) @file_get_contents($plugin_root . '/phpstan-baseline.neon');
if ('' === $baseline) {
    fwrite(STDERR, "FAIL: phpstan-baseline.neon is missing or empty — nothing to ratchet.\n");
    exit(1);
}

$entries      = preg_match_all('/^\s*message:/m', $baseline);
$suppressions = preg_match_all('/^\s*count:\s*(\d+)/m', $baseline, $cm) ? array_sum(array_map('intval', $cm[1])) : 0;

// VACUITY FLOOR: a baseline that parses to nothing is a broken parse, not a clean codebase.
if ($entries < 10 || $suppressions < $entries) {
    $failures[] = sprintf('parsed %d entries / %d suppressions from phpstan-baseline.neon; the file '
        . 'holds far more, so the scan has stopped matching and every check below compares '
        . 'against zero', $entries, $suppressions);
}

// ── 2. The committed ceiling matches exactly, and only ever descends ────────────────────
$parse = static fn(?string $raw): ?array =>
    (null !== $raw && preg_match('/^(\d+)\s+(\d+)$/', trim($raw), $m)) ? [(int) $m[1], (int) $m[2]] : null;

$ceiling_rel = 'tests/PHPSTAN-BASELINE-CEILING';
$ceiling     = $parse(@file_get_contents($plugin_root . '/' . $ceiling_rel) ?: null);

// The last COMMITTED value from where this change stands (see the header).
$on_disk_is_committed = (($parse(slimstat_git_show($plugin_root, 'HEAD', $ceiling_rel))) === $ceiling);
$previous = $parse(slimstat_git_show($plugin_root, $on_disk_is_committed ? 'HEAD^' : 'HEAD', $ceiling_rel));

if (null === $ceiling) {
    $failures[] = $ceiling_rel . ' is missing or malformed; it must hold `<entries> <suppressions>` '
        . 'and match phpstan-baseline.neon exactly';
} elseif ($ceiling !== [$entries, $suppressions]) {
    $failures[] = sprintf(
        'phpstan-baseline.neon holds %d entries / %d suppressions; %s says %d / %d. If the baseline '
            . 'SHRANK, lower the ceiling in this commit. If it GREW, a new error was baselined '
            . 'instead of fixed — phpstan.neon.dist says "never baseline newly introduced errors", '
            . 'and this is the gate that makes that sentence cost something',
        $entries,
        $suppressions,
        $ceiling_rel,
        $ceiling[0],
        $ceiling[1]
    );
}

if (null === $previous) {
    echo "NOTE: no committed ceiling to compare against (new file, or no parent commit) — the "
        . "descent check is skipped this run.\n";
} elseif (null !== $ceiling && ($ceiling[0] > $previous[0] || $ceiling[1] > $previous[1])) {
    $failures[] = sprintf(
        '%s was raised from %d/%d to %d/%d. The ceiling only descends: a baseline is a migration '
            . 'tool for errors that predate it, and raising it is the same act as never having '
            . 'had one',
        $ceiling_rel,
        $previous[0],
        $previous[1],
        $ceiling[0],
        $ceiling[1]
    );
}

// ── 3. CONTRIBUTING.md discloses both numbers, literally ────────────────────────────────
$contributing = (string) @file_get_contents($plugin_root . '/CONTRIBUTING.md');

if (false === stripos($contributing, 'phpstan')) {
    $failures[] = 'CONTRIBUTING.md never mentions PHPStan; the baseline suppresses ' . $suppressions
        . ' errors and a contributor has no way to know that from anything they are asked to read';
} else {
    foreach ([[$entries, 'entries'], [$suppressions, 'suppressed errors']] as [$number, $what]) {
        if (!preg_match('/\b' . $number . '\b/', $contributing)) {
            $failures[] = sprintf('CONTRIBUTING.md does not state the baseline\'s %d %s. The literal '
                . 'number is what makes the disclosure go stale VISIBLY — this gate — rather than '
                . 'by describing a baseline that no longer exists', $number, $what);
        }
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: PHPStan baseline ratchet (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo sprintf(
    "PASS: phpstan-baseline.neon holds %d entries / %d suppressions, matches the committed ceiling, "
        . "did not grow since the last commit, and CONTRIBUTING.md states both numbers\n",
    $entries,
    $suppressions
);
