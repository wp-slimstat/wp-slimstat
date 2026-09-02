<?php
/**
 * Source-level: numbers ci.yml states about this repo must match the repo.
 *
 * THREE FIGURES IN ci.yml's COMMENTS DESCRIBED A TREE THAT NO LONGER EXISTED.
 *
 * "The full registry is ~160s at 101 entries" — the registry held 141. "so 56 XSS assertions
 * over the report render path ran in no lane" — the same CI log the sentence was written from
 * printed a different count. A comment is the only documentation a CI step has, and these
 * particular comments are the ones a reader consults precisely when deciding whether a lane is
 * worth its runtime. Wrong by 40% is worse than absent: absent prompts a measurement.
 *
 * This gate does not fix three numbers. It fixes the CLASS, by making the two figures that have
 * an authoritative home in the repo checkable against that home on every push:
 *
 *   - a claim about the mutation registry's size must equal `tests/mutations/FLOOR`;
 *   - a claim about PHPUnit test or assertion counts must equal `tests/ASSERTION-FLOOR.json`.
 *
 * THE ONE EXEMPTION, AND WHY IT IS NARROW. A comment narrating a past incident — "assertions
 * 602 -> 598 was the entire signal" — is history, not a stale claim, and rewriting it to today's
 * numbers would destroy the evidence it exists to carry. Such a sentence is exempt only if its
 * block states an explicit ISO date. The registry-size claim gets NO exemption at all: FLOOR is
 * one file in this repo, so there is no state of the world in which citing a different number is
 * historical rather than wrong.
 *
 * WHAT THIS DOES NOT ESTABLISH. It cannot check a number with no authoritative file behind it.
 * The escaping gate's assertion count is one: `tests/reports-output-escaping-test.php` counts at
 * runtime and needs a live WordPress, so nothing static can read it. The remedy taken there was
 * to delete the figure rather than pin an unverified one — a comment that states the property
 * without a count cannot go stale, and inventing a number to satisfy a gate is the defect this
 * file is named after.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/mutations.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

$ci_path = $plugin_root . '/.github/workflows/ci.yml';
if (!is_file($ci_path)) {
    fwrite(STDERR, "FAIL: .github/workflows/ci.yml is missing\n");
    exit(1);
}

// ── Authoritative sources ─────────────────────────────────────────────────────────────

// slimstat_mutation_floor() rather than a third string literal for the same path. This gate
// exists to make FLOOR the single authority for the registry's size; locating it by a private
// copy of the path would undercut that in the file that asserts it.
$registry_floor = slimstat_mutation_floor($plugin_root);
if (null === $registry_floor) {
    fwrite(STDERR, "FAIL: tests/mutations/FLOOR is missing — the registry claim has no authority\n");
    exit(1);
}

$af_path = $plugin_root . '/tests/ASSERTION-FLOOR.json';
$assertion_floor = is_file($af_path)
    ? json_decode((string) file_get_contents($af_path), true)
    : null;

if (!is_array($assertion_floor)) {
    fwrite(STDERR, "FAIL: tests/ASSERTION-FLOOR.json is missing or unreadable\n");
    exit(1);
}

$known_tests      = [];
$known_assertions = [];
foreach ($assertion_floor as $suite) {
    if (isset($suite['tests'])) {
        $known_tests[(int) $suite['tests']] = true;
    }
    if (isset($suite['assertions'])) {
        $known_assertions[(int) $suite['assertions']] = true;
    }
}

// VACUITY. A truncated or empty ASSERTION-FLOOR.json leaves both sets empty, which makes every
// claim below unmatchable and therefore silently exempt — while the claim count floor is still
// satisfied by the registry claim alone. Two vacuous checks hiding behind one live one.
if (!$known_tests || !$known_assertions) {
    fwrite(STDERR, "FAIL: tests/ASSERTION-FLOOR.json declares no test or assertion counts — "
        . "claims 2 and 3 below would match nothing and pass\n");
    exit(1);
}

// ── Comment blocks: contiguous runs of `#` lines, joined ──────────────────────────────
//
// Joined rather than read line by line because these sentences wrap: "so 56 XSS" ends one line
// and "assertions over the report render path" begins the next, and a per-line scan sees neither
// a number nor a noun. That is not hypothetical — it is how the 56 survived the first draft.

$lines  = explode("\n", (string) file_get_contents($ci_path));
$blocks = [];
$cur    = [];
$start  = 0;

foreach ($lines as $i => $line) {
    if (preg_match('/^\s*#(.*)$/', $line, $m)) {
        if (!$cur) {
            $start = $i + 1;
        }
        $cur[] = trim($m[1]);
        continue;
    }
    if ($cur) {
        $blocks[] = ['line' => $start, 'text' => implode(' ', $cur)];
        $cur      = [];
    }
}
if ($cur) {
    $blocks[] = ['line' => $start, 'text' => implode(' ', $cur)];
}

// VACUITY FLOOR — ci.yml is heavily commented; a handful of blocks means the scan broke.
if (count($blocks) < 40) {
    $failures[] = 'parsed only ' . count($blocks) . ' comment block(s) from ci.yml; expected at '
        . 'least 40 — the block scan is broken and every check below passes by finding nothing';
}

$claims = 0;

foreach ($blocks as $block) {
    $text  = $block['text'];
    $where = 'ci.yml:' . $block['line'];
    $dated = (bool) preg_match('/\b20\d{2}-\d{2}-\d{2}\b/', $text);

    // ── Claim 1: the mutation registry's size. No exemption. ──────────────────────────
    // preg_match_ALL. With `preg_match` the verdict depended on which count appeared first in
    // the prose, so re-ordering a sentence could fail the build with no metric having changed —
    // and a stale figure later in the same block was never read at all.
    if (preg_match('/\bregistry\b/i', $text)) {
        preg_match_all('/\b(\d+)\s+entries\b/', $text, $entry_counts);

        foreach ($entry_counts[1] as $entry_count) {
            $claims++;
            if ((int) $entry_count !== $registry_floor) {
                $failures[] = sprintf(
                    '%s says the mutation registry holds %d entries; tests/mutations/FLOOR says '
                        . '%d. This claim is never historical: FLOOR is a file in this repo, so '
                        . 'the only way to be wrong about it is not to have looked. The sentence '
                        . 'is also the one that decides when to narrow the run, which is why it '
                        . 'is pinned',
                    $where,
                    (int) $entry_count,
                    $registry_floor
                );
            }
        }
    }

    // ── Claim 2: PHPUnit assertion counts. Dated history exempt. ──────────────────────
    // Both orders. English puts the count either side of the noun — "56 XSS assertions" and
    // "assertions 602 -> 598" are the same kind of claim, and a pattern that reads only the
    // first shape had to be told so by the vacuity floor when the only sentence it could see
    // was deleted.
    preg_match_all('/\b(\d+)\s+(?:[A-Za-z-]+\s+)?assertions?\b/i', $text, $before_noun);
    preg_match_all('/\bassertions?\s+(\d+)(?:\s*->\s*(\d+))?/i', $text, $after_noun);

    $assertion_nums = array_merge(
        $before_noun[1],
        $after_noun[1],
        // The optional second group is padded with '' for every match that lacks it.
        array_filter($after_noun[2], static function (string $n): bool { return '' !== $n; })
    );

    foreach ($assertion_nums as $num) {
        $claims++;
        if (!isset($known_assertions[(int) $num]) && !$dated) {
            $failures[] = sprintf(
                '%s states "%d assertions" but no suite in tests/ASSERTION-FLOOR.json reports '
                    . 'that count (known: %s), and the block carries no ISO date marking it as '
                    . 'history. Either correct it, date it if it narrates a past incident, or '
                    . 'delete the figure — a count with no authoritative file behind it is the '
                    . 'one shape this gate cannot check and must not be asked to',
                $where,
                (int) $num,
                implode(', ', array_keys($known_assertions))
            );
        }
    }

    // ── Claim 3: PHPUnit test counts. Dated history exempt. ───────────────────────────
    preg_match_all('/\bTests:\s*(\d+)\b/i', $text, $test_counts);

    foreach ($test_counts[1] as $num) {
        $claims++;
        if (!isset($known_tests[(int) $num]) && !$dated) {
            $failures[] = sprintf(
                '%s states "Tests: %d" but no suite in tests/ASSERTION-FLOOR.json reports that '
                    . 'count (known: %s), and the block carries no ISO date',
                $where,
                (int) $num,
                implode(', ', array_keys($known_tests))
            );
        }
    }
}

// VACUITY FLOOR — if no claim matched, the patterns have drifted from the prose and a green
// result here says nothing at all about ci.yml.
if ($claims < 3) {
    $failures[] = 'matched only ' . $claims . ' numeric claim(s) in ci.yml comments; expected at '
        . 'least 3 — the patterns no longer find the sentences they were written for, so this '
        . 'gate is passing because it looked at nothing';
}

if ($failures) {
    fwrite(STDERR, 'FAIL: ci.yml comment metrics (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo 'PASS: ' . $claims . ' numeric claim(s) in ci.yml comments match the repo (registry floor '
    . $registry_floor . ", assertion floors from tests/ASSERTION-FLOOR.json)\n";
