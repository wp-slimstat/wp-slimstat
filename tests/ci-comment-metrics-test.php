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
 * This gate does not fix three numbers. It fixes the CLASS, by making every figure that has an
 * authoritative home in the repo checkable against that home on every push:
 *
 *   - a claim about the mutation registry's size must equal `tests/mutations/FLOOR`;
 *   - a claim about how many entries gate on one script — written `N ... on `gate`` — must equal
 *     the number of .mutation files naming it;
 *   - a claim about PHPUnit test or assertion counts must equal `tests/ASSERTION-FLOOR.json`;
 *   - and a count spelled as a WORD beside `entries` is refused, because none of the above can
 *     read it. That is not fastidiousness: it is the form four wrong numbers were written in.
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

// ── Per-gate entry counts ─────────────────────────────────────────────────────────────
//
// A claim like "7 entries on `composer test:seal-negative`" is checkable against the registry
// and was not being checked, so the block carried FOUR wrong numbers about the two suites that
// dominate the step's cost — the sentence that decides when to narrow the run. Same parser as
// tests/mutation-registry-test.php: a private `gate:` regex would count headers differently
// from the file that owns them.
$gate_counts = slimstat_mutation_gate_counts($plugin_root);

if (!$gate_counts) {
    fwrite(STDERR, "FAIL: no .mutation file declares a gate: — every per-gate claim below would "
        . "be unmatchable and pass\n");
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

$claims = ['"N entries" (registry total)' => 0, '"N entries on `gate`"' => 0, 'assertions' => 0, 'Tests:' => 0];

foreach ($blocks as $block) {
    $text  = $block['text'];
    $where = 'ci.yml:' . $block['line'];
    $dated = (bool) preg_match('/\b20\d{2}-\d{2}-\d{2}\b/', $text);

    // ── Claim 1: the mutation registry's size. No exemption. ──────────────────────────
    // preg_match_ALL. With `preg_match` the verdict depended on which count appeared first in
    // the prose, so re-ordering a sentence could fail the build with no metric having changed —
    // and a stale figure later in the same block was never read at all.
    // `registry` ALONE selects the npm-retry blocks too ("Transient registry blips"), and 1a
    // would then read "3 retries on `npm install`" as a per-gate mutation count and fail on a
    // gate that does not exist. The scope is the MUTATION registry; say so.
    if (preg_match('/\bregistry\b/i', $text) && preg_match('/\bmutations?\b/i', $text)) {
        // SENTENCE SCOPE, not block scope. 1b and 1c allow ANY run of non-numeric words between a
        // number and its noun, which is right within a claim and wrong across a paragraph: over a
        // whole block, `twenty sealed dry runs` in one sentence pairs with `entries` three
        // sentences later and reports a claim nobody made. The block happened to pass only
        // because a digit sat between them. A sentence is the unit a claim is actually made in;
        // bounding by it keeps the adjective tolerance and drops the spurious reach, without the
        // arbitrary word limit that let `Seven slow non-filterable seal entries` through.
        // Found by building M4 and watching it fail for the wrong reason.
        foreach (preg_split('/(?<=[.;])\s+/', $text) ?: [] as $text) {
        // ── Claim 1a: SUBSET counts, which NAME the gate they count.
        //
        // A number that names its gate is not a claim about the registry total, and a gate that
        // could not tell the two apart forced every subset figure to be spelled as a word to get
        // past it — which is what this block did, and how four wrong numbers survived inside the
        // paragraph explaining the exemption they were using. Naming the gate in BACKTICKS is the
        // marker and the only exemption: written without them the number is read as a total and
        // fails, which is the correct answer for `40 entries` and the wrong one for `4 on X`.
        preg_match_all(
            '/\b(\d+)\s+(?:[A-Za-z-]+\s+)*?on\s+`([^`]+)`/i',
            $text,
            $subset_matches,
            PREG_SET_ORDER
        );

        foreach ($subset_matches as $subset) {
            $claims['"N entries on `gate`"']++;

            $claimed = (int) $subset[1];
            $gate    = trim($subset[2]);

            if (!isset($gate_counts[$gate])) {
                $failures[] = sprintf(
                    '%s claims %d entries on `%s`, which no .mutation file names as its gate. A '
                        . 'count against a gate that does not exist cannot go stale, because it '
                        . 'was never true',
                    $where,
                    $claimed,
                    $gate
                );
            } elseif ($claimed !== $gate_counts[$gate]) {
                $failures[] = sprintf(
                    '%s claims %d entries on `%s`; %d .mutation file(s) name that gate',
                    $where,
                    $claimed,
                    $gate,
                    $gate_counts[$gate]
                );
            }
        }

        // ── Claim 1b: the registry TOTAL. No exemption, and no word may hide it.
        //
        // preg_match_ALL. With `preg_match` the verdict depended on which count appeared first in
        // the prose, so re-ordering a sentence could fail the build with no metric having changed —
        // and a stale figure later in the same block was never read at all.
        //
        // The gap between number and noun is ANY run of non-numeric words, not a fixed count.
        // Pro carried `(\d+) entries` and then `(\d+)(\s+\w+){0,2} entries`, and a reviewer walked
        // past both — the second with `40 known stale mutation registry entries`. A word limit is
        // a deny-list of phrasings; `(?!\d)` is the property it stands in for, namely that a
        // number may not leap another figure to reach its noun.
        //
        // The trailing lookahead is what keeps 1a's subset claims out, and it replaced an
        // offset-overlap exclusion that did the same job in fifteen more lines and made this
        // scan depend on 1a having run first.
        preg_match_all(
            '/\b(\d+)(?:\s+(?!\d)[A-Za-z][\w-]*)*?\s+entries\b(?!\s+on\s+`)/i',
            $text,
            $entry_counts,
            PREG_SET_ORDER
        );

        foreach ($entry_counts as $hit) {
            $claims['"N entries" (registry total)']++;

            if ((int) $hit[1] !== $registry_floor) {
                $failures[] = sprintf(
                    '%s says "%s"; tests/mutations/FLOOR says %d. This claim is never historical: '
                        . 'FLOOR is a file in this repo, so the only way to be wrong about it is '
                        . 'not to have looked. The phrase is quoted rather than rebuilt from the '
                        . 'number and the noun, which can sit any number of words apart',
                    $where,
                    trim($hit[0]),
                    $registry_floor
                );
            }
        }

        // ── Claim 1c: a count spelled as a WORD beside the noun is refused outright.
        //
        // Not a hypothetical hole — it is the one this block fell through. `SEVEN entries gate on
        // the two seal suites` sat here while the paragraph above it explained that historical
        // figures are "described rather than quoted" so the gate would not read them. That
        // exemption is for prose about the PAST; it is not a way to state a current figure the
        // gate cannot check.
        //
        // Same unbounded non-numeric gap as 1b, deliberately. The first draft bounded this one at
        // two words while leaving 1b unbounded — two bounds for one hazard in one function, and
        // `Seven slow non-filterable seal entries` walked through the narrower one. The vocabulary
        // is still a deny-list ("a dozen", "several" are not in it); if a fourth widening is ever
        // proposed, the answer is to anchor on the NOUN instead — require every `entries` in scope
        // to be covered by a matched 1a or 1b span — which costs three exemptions in this block
        // and no vocabulary at all.
        preg_match_all(
            '/\b(one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|thirteen|fourteen|'
                . 'fifteen|sixteen|seventeen|eighteen|nineteen|twenty|thirty|forty|fifty|sixty|'
                . 'seventy|eighty|ninety|hundred)(?:\s+(?!\d)[A-Za-z][\w-]*)*?\s+entries\b/i',
            $text,
            $word_claims,
            PREG_SET_ORDER
        );

        foreach ($word_claims as $word_claim) {
            $failures[] = sprintf(
                '%s states "%s" — a count spelled as a WORD beside `entries`, which every pattern '
                    . 'above reads digits for and therefore cannot check. Write it in digits, or '
                    . 'do not put a count beside the noun',
                $where,
                trim($word_claim[0])
            );
        }
        }
    }

    // ── Claim 2: PHPUnit assertion counts. Dated history exempt. ──────────────────────
    // Both orders. English puts the count either side of the noun — "56 XSS assertions" and
    // "assertions 602 -> 598" are the same kind of claim, and a pattern that reads only the
    // first shape had to be told so by the vacuity floor when the only sentence it could see
    // was deleted.
    preg_match_all('/\b(\d+)(?:\s+(?!\d)[A-Za-z][\w-]*)*?\s+assertions?\b/i', $text, $before_noun);
    preg_match_all('/\bassertions?\s+(\d+)(?:\s*->\s*(\d+))?/i', $text, $after_noun);

    $assertion_nums = array_merge(
        $before_noun[1],
        $after_noun[1],
        // The optional second group is padded with '' for every match that lacks it.
        array_filter($after_noun[2], static function (string $n): bool { return '' !== $n; })
    );

    foreach ($assertion_nums as $num) {
        $claims['assertions']++;
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
        $claims['Tests:']++;
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
// PER CLASS, and it was a total until 2026-09-04. `$claims < 3` is satisfied by the two
// assertion claims plus one other, so an entire class could be deleted or reworded and this gate
// stayed green — the same defect Pro's twin carried, found there first. A floor over unrelated
// classes measures their sum, which is not a property anyone wants to hold.
foreach ($claims as $class => $n) {
    if (0 === $n) {
        $failures[] = sprintf('no `%s` claim matched anywhere in ci.yml comments. The pattern no '
            . 'longer finds the sentence it was written for, or the sentence is gone — either way '
            . 'this class of claim is now unchecked and the gate is silent about it', $class);
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: ci.yml comment metrics (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

$breakdown = [];
foreach ($claims as $class => $n) {
    $breakdown[] = $n . ' ' . $class;
}

echo 'PASS: ' . array_sum($claims) . ' numeric claim(s) in ci.yml comments match the repo ('
    . implode(', ', $breakdown) . '; registry floor ' . $registry_floor
    . ", assertion floors from tests/ASSERTION-FLOOR.json)\n";
