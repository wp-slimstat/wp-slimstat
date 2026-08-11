<?php
/**
 * Source-level: the oracle's time-dependence exemptions name VALUES, not whole reports, wherever
 * the report renders labelled values.
 *
 * WHY THE GRAIN MATTERS. `parity-compare.php` used to excuse `slim_p1_03` entirely — the report
 * rendered, and not one of its numbers was asserted. Measured (probe-glance-stability.php, three
 * samples 70 s apart on a static corpus), the report has **eight** values and exactly **one**
 * moves:
 *
 *     Last 30 minutes          4,529 -> 4,526 -> 4,526     rolling, genuinely time-dependent
 *     Pageviews                45,336                      stable
 *     Days in Range            28                          stable
 *     Average Daily Pageviews  1,619                       stable
 *     From Any SERP            0                           stable
 *     Unique IPs               254                         stable
 *     Today                    5,077                       stable
 *     Yesterday                1,426                       stable
 *
 * Today and Yesterday are on that list because F8 fixed them; before that they were a permanent 0
 * and the exemption was hiding it. Seven values were unasserted to excuse one.
 *
 * THE ASYMMETRY THIS FILE EXISTS TO PROTECT. A value seen to move IS time-dependent on a single
 * observation. A value that did NOT move in N samples is only as stable as N — a 30-minute window
 * moves when a row leaves its trailing edge, which may not happen in any given sample. The same
 * probe saw "Last 30 minutes" move in one run and nothing move in the next, and on that second run
 * alone it recommended lifting the exemption entirely. So an entry added here from one quiet run
 * is an assertion nobody has earned.
 *
 * 7.4-safe: pure source analysis. No WordPress, no database.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

$compare_rel  = 'tests/bench/lib/parity-compare.php';
$snapshot_rel = 'tests/bench/lib/parity-snapshot.php';

$compare  = (string) @file_get_contents($plugin_root . '/' . $compare_rel);
$snapshot = (string) @file_get_contents($plugin_root . '/' . $snapshot_rel);

if ('' === $compare || '' === $snapshot) {
    fwrite(STDERR, "FAIL: the parity harness files are missing\n");
    exit(1);
}

// ── 1. The snapshot must capture the pairs the comparator needs ─────────────
//
// Without them every exemption silently degrades to report-level: the comparator's own fallback
// says so explicitly, and a fallback that fires everywhere is the old behaviour wearing the new
// code's name. String CONTENTS kept — the key under test is a literal.
$snapshot_literal = slimstat_blank_comments($snapshot, false);

if (false === strpos($snapshot_literal, "'pairs'")) {
    $failures[] = "{$snapshot_rel} no longer records a 'pairs' entry per cell. Every value-level "
        . 'exemption then falls back to excusing the whole report, which is the blind spot this '
        . 'grain was introduced to remove';
}

// THE PROPERTY, NOT THE SPELLING. An earlier version of this assertion matched one literal regex
// and failed the moment that regex was CORRECTED — a gate pinned to the shape of its subject
// rather than to what the subject must do. What must hold is that extraction cannot cross a
// paragraph boundary:
//
//   `#<p>(.*?)<span>(.*?)</span></p>#s` looks right and produces WRONG pairs, because the lazy
//   match runs past its own </p> whenever the value span is not immediately followed by one.
//   Measured on this plugin's real markup: a `details` block turns 8 pairs into 7 with a label
//   absorbed into the previous value, and a preceding non-conforming paragraph deletes another.
//   A wrong label is worse than no label — an exemption naming a real one would never match.
if (false === strpos($snapshot_literal, '</p>') || false === strpos($snapshot_literal, '<span')) {
    $failures[] = "{$snapshot_rel} no longer extracts label/value pairs at all — the labels the "
        . 'comparator exempts by would be absent, and every narrowed exemption would degrade';
}

if (preg_match('#<span[^>]*>\\(\\.\\*\\?\\)</span></p>#', $snapshot_literal)) {
    $failures[] = "{$snapshot_rel} extracts pairs with a pattern that spans `</p>`. A lazy match "
        . 'crossing a paragraph boundary merges paragraphs and DELETES labels, so an exemption '
        . 'would be applied to a label that is not the one it names. Split on `</p>` first';
}

// ── 2. Every exemption declares its grain and its reason ────────────────────
$compare_literal = slimstat_blank_comments($compare, false);

if (!preg_match('/\$time_dependent\s*=\s*\[(.*?)\n\];/s', $compare_literal, $m)) {
    $failures[] = "{$compare_rel} no longer declares a \$time_dependent map — this check cannot "
        . 'locate the exemptions it inspects';
} else {
    $block = $m[1];

    // NESTING-AWARE. A non-greedy `\[(.*?)\]` stops at the first `]`, which for an entry whose
    // 'labels' is itself an array is the end of THAT array — so 'why' fell outside the captured
    // body and every narrowed entry was reported as unjustified. The first version of this file
    // failed on exactly the entry it exists to protect.
    preg_match_all(
        "/'(slim_[a-z0-9_]+)'\s*=>\s*\[((?:[^\[\]]|\[[^\]]*\])*)\]/s",
        $block,
        $entries,
        PREG_SET_ORDER
    );

    if (count($entries) < 3) {
        $failures[] = sprintf(
            'only %d exemption entr(y|ies) parsed out of $time_dependent — the parse is stale, so '
                . 'every assertion below ran on almost nothing',
            count($entries)
        );
    }

    foreach ($entries as $entry) {
        [$whole, $report_id, $body] = $entry;

        if (false === strpos($body, "'labels'")) {
            $failures[] = "{$report_id} does not declare a 'labels' key. An exemption must say "
                . 'WHICH values it excuses, or state null for "this report renders none to name"';
        }

        if (false === strpos($body, "'why'")) {
            $failures[] = "{$report_id} does not declare a 'why'. Each entry is a place the oracle "
                . 'stops checking values; an unjustified one is a blind spot nobody chose';
        }
    }

    // slim_p1_03 is the measured one and must stay narrowed. A regression to `null` here would
    // silently return seven asserted values to being unasserted.
    if (preg_match("/'slim_p1_03'\s*=>\s*\[((?:[^\[\]]|\[[^\]]*\])*)\]/s", $block, $glance)) {
        if (false === strpos($glance[1], 'Last 30 minutes')) {
            $failures[] = "slim_p1_03's exemption no longer names 'Last 30 minutes'. Measured over "
                . 'three samples, that is the ONLY one of its eight values that moves — including '
                . 'Today and Yesterday, which F8 fixed and which this exemption used to hide';
        }
        if (preg_match("/'labels'\s*=>\s*null/", $glance[1])) {
            $failures[] = "slim_p1_03's exemption has been widened back to the whole report. "
                . 'Seven of its eight values are stable and measured as such';
        }
    } else {
        $failures[] = 'slim_p1_03 has no entry in $time_dependent — if the exemption was lifted '
            . 'entirely, this check should be updated deliberately rather than left passing';
    }
}

// ── 3. The comparator must actually branch on the labels ───────────────────
//
// Read as CODE: the label strings above are literals, but this is about the mechanism, and the
// docblock at the top of this very file quotes "Last 30 minutes" more than once.
$compare_code = slimstat_strip_comments_and_strings($compare, false);

if (false === strpos($compare_code, 'unexcused')) {
    $failures[] = "{$compare_rel} no longer computes the set of NON-exempt values that moved. "
        . 'Without it the labels are recorded and never consulted, which reads as a value-level '
        . 'exemption while behaving as a report-level one';
}

if ($failures) {
    fwrite(STDERR, 'FAIL: parity exemption grain (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: exemptions name values where values exist, and the comparator checks the rest\n";
