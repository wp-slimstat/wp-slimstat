<?php
/**
 * A comment that cites a standing record cites one that exists, and quotes it verbatim.
 *
 * ── WHAT THIS CATCHES, STATED BEFORE WHAT IT IS FOR ─────────────────────────────────────────
 *
 * The first version of this file was written for FOUR citation defects found in one sitting, and
 * an adversarial review measured that it caught **one** of them. That is recorded here rather
 * than fixed by rewording, because a gate named for four defects that catches one is exactly what
 * this codebase calls a guard that looks like it works.
 *
 *   (a) a quotation spliced from two revisions — the sentence existed only BEFORE the entry was
 *       rewritten, so it appeared nowhere in the file the comment named .... CAUGHT
 *   (b) an identifier renamed inside the quote marks to suit the quoting file ......... CAUGHT
 *       (mechanism only — it happened in wp-slimstat-pro, which this gate does not scan)
 *   (c) a measurement attributed to the wrong side of the change it measured ..... NOT CAUGHT
 *   (d) a measurement carried from the pro sibling without being re-run ......... NOT CAUGHT
 *
 * (c) and (d) are prose, not quoted spans, and the fix for both was to stop using quote marks —
 * which moved them out of this gate's reach. PITFALLS 104 says so outright: "Not mechanisable as
 * a gate", because no scanner can tell a correct figure from a plausible one. This file does not
 * pretend otherwise.
 *
 * ── THE HALF THAT ACTUALLY HAS COVERAGE ─────────────────────────────────────────────────────
 *
 * Measured across tests/, src/ and admin/, IN COMMENTS — which is the population this gate
 * walks: **116 bare entry references** (62 `PITFALLS N`, 54 `ADR-N`) against **2 quoted
 * citations**. Roughly sixty to one.
 *
 * So the load-bearing check is not "is this quote verbatim" but "does the entry this comment
 * names exist" — which is unambiguous, cannot false-positive, and breaks loudly if an entry is
 * renumbered or deleted against the **Never delete an entry** rule — which is CLAUDE.md's, not
 * PITFALLS.md's: this sentence attributed it to the record for as long as it existed, in the
 * headline of the gate written to stop misattribution, 21 characters below its own 40-char
 * threshold and therefore invisible to it. Today: 0 dangling. Nothing enforced it until now.
 *
 * ⚠ An earlier version of that paragraph said 531 (393/138). That is the count over ALL file
 * types in those directories — .md, .sh, .json included — not over comments, so the headline
 * sentence of the gate written to end citation defects cited a figure 4.6x its own subject. The
 * file contradicted itself twice over: the control below already named the measured comment-only
 * counts as 62 and 54, and every run prints entry-refs=116. That is defect class (c) from the
 * table above — a measurement attributed to the wrong population — sitting in this gate's own
 * headline, and unreachable by this gate because a bare number carries no quote marks.
 *
 * ASSERTION 1 — every `PITFALLS N` names an entry that exists in PITFALLS.md.
 * ASSERTION 2 — every `ADR-N` names one that exists in DECISIONS.md.
 * ASSERTION 3 — every quoted span of 40+ chars in a comment naming a record appears VERBATIM in
 *               that record. Ellipsis splits it into segments, each checked, so honest elision
 *               survives and a splice does not.
 *
 * ── THE ESCAPE HATCH THE FIRST VERSION SHIPPED WITH ─────────────────────────────────────────
 *
 * A comment may legitimately quote a superseded revision, so a git ref in the same comment
 * redirects the check to `git show <ref>:<path>`. The first version detected that ref with
 * `/\b([0-9a-f]{7,40})\b/` over the whole comment, which has no idea what a commit is. Measured:
 * the English word "defaced" matched. So did the bare number 31544000 — and pro's sibling
 * docblock already carries "31,544 tokens", separated from that fate by one comma. Any 7-digit
 * count in a docblock silently converted every quotation in it to UNCHECKED, and the run still
 * printed PASS.
 *
 * Now: a ref must RESOLVE (`git rev-parse --verify`) and the record must exist at it. A ref that
 * does not resolve is a FAILURE, not a shrug — a citation nobody can check is a citation nobody
 * should trust. And the run refuses to print PASS while anything is unchecked.
 *
 * ── TWIN ────────────────────────────────────────────────────────────────────────────────────
 *
 * wp-slimstat-pro/tests/record-citation-test.php is this file with FIVE stated divergences and no
 * others: the file-gather call (pro has no admin/, and its enumerator takes exclude fragments
 * rather than a prefix), the tokenizer call (pro's lib has no wrapper to prefer), the two control
 * floors (79 files against 377), the rel-path helper prefix, and the docblocks. **Fix one twin,
 * fix both, in the same sitting.**
 *
 * This header is the mechanism, because the automatic one cannot reach the pair:
 * jaan-to/bin/check-twins.sh requires byte-identity from declare() down, and these differ in five
 * code lines. That is the case it already excludes by name for mutation-registry-test.php, whose
 * provenance header this one copies. Registering the pair would fail on line 1.
 *
 * 7.4-safe: bare PHP, no PHPUnit, no WordPress, no vendor autoloader.
 *
 * Run: php tests/record-citation-test.php
 */

declare(strict_types=1);

// Never executable over HTTP: these scripts run to completion, write to STDOUT/STDERR
// (undefined under a web SAPI) and can disclose absolute paths.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit(1);
}

error_reporting(E_ALL);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$repo_root   = dirname($plugin_root);
$records_dir = $repo_root . '/jaan-to/outputs/dev/v6-performance';
$failures    = [];
$controls    = [];

/** The records a comment may quote, and where each lives. */
$records = [
    'STATE.json'               => $records_dir . '/STATE.json',
    'PITFALLS.md'              => $records_dir . '/PITFALLS.md',
    'DECISIONS.md'             => $records_dir . '/DECISIONS.md',
    'VERIFICATION-PROTOCOL.md' => $records_dir . '/VERIFICATION-PROTOCOL.md',
    'EXPECTED-DIFFS.md'        => $records_dir . '/EXPECTED-DIFFS.md',
];

// jaan-to/ is a SIBLING of this plugin, not a part of it, so a standalone checkout — which is
// what every CI lane has — cannot see the records at all. Returning early there is correct.
// Returning early SILENTLY is not: measured on a standalone copy, the first version of this file
// reported 9 failures and four red CONTROLS on a perfectly healthy tree. So the mode is printed,
// and the fixtures below exercise the checker on every run regardless of which mode this is.
//
// DISCRIMINATED ON THE SIBLING ROOT, NEVER ON THE LEAF DIRECTORY. campaign-phase-gate-test.php
// wrote that paragraph first, and this file ignored it while citing the very file it is in.
// Keying on $records_dir answers the wrong question: it degrades to "there is no jaan-to here"
// whenever that one path moves, and then prints that as fact, skips the whole scan and exits 0 —
// in the one checkout that does have records to check. This workspace has moved jaan-to paths
// before; CLAUDE.md carries a correction of exactly that kind. So: no jaan-to/ at all is a
// standalone checkout and skips. A jaan-to/ that is present but has no records directory is a
// FAILURE, because in that checkout the records are supposed to be there.
$standalone = !is_dir($repo_root . '/jaan-to');
if (!$standalone && !is_dir($records_dir)) {
    $failures[] = sprintf('the jaan-to sibling is present but %s is not — if the programme '
        . 'records moved, this gate moved with them and nobody updated it', $records_dir);
}

$can_shell = function_exists('shell_exec')
    && false === stripos((string) ini_get('disable_functions'), 'shell_exec');

/** The cited file's text at $ref, or null when the ref does not resolve or lacks the file. */
function rct_text_at_ref(string $repo_root, string $path, string $ref, bool $can_shell): ?string
{
    if (!$can_shell) {
        return null;
    }
    // Resolve FIRST. Without this, any hex-looking word in the comment was accepted as a ref and
    // the citation was waived — measured: "defaced" and "31544000" both did it.
    $ok = shell_exec(sprintf('git -C %s rev-parse --verify --quiet %s 2>/dev/null',
        escapeshellarg($repo_root), escapeshellarg($ref . '^{commit}')));
    if (null === $ok || '' === trim((string) $ok)) {
        return null;
    }
    $rel = slimstat_rel_path($repo_root, $path);
    $out = shell_exec(sprintf('git -C %s show %s:%s 2>/dev/null',
        escapeshellarg($repo_root), escapeshellarg($ref), escapeshellarg($rel)));

    return (null === $out || '' === trim((string) $out)) ? null : (string) $out;
}

/**
 * Every citation problem in ONE comment. Returns the problems and the counts; decides nothing.
 *
 * Split out from the walk so the fixtures below can drive it, and the reason is not tidiness.
 * The records live in `jaan-to/`, a SIBLING of this plugin — CI checks the plugin out alone, so
 * on every lane there is nothing to cite against. The first version answered that by failing:
 * measured on a standalone copy, 9 failures and all four CONTROLS red on a healthy tree, from a
 * gate wired into `composer test:source-level`. campaign-phase-gate-test.php met the same hole
 * one directory over and answered it properly — print the mode, and exercise the invariants on
 * fixtures regardless, so "the gate skipped" and "the gate passed" never print the same thing.
 *
 * @param array<string,string>            $record_text name => whitespace-normalised body
 * @param array<int,mixed>                $adrs        defined ADR numbers, as a lookup
 * @param callable(string,string):?string $resolve     (record name, git ref) => text, or null
 * @return array{failures:string[],unchecked:string[],quoted:int,checked:int,pitfalls:int,adrs:int}
 */
function rct_comment_problems(
    string $raw,
    string $rel,
    int $line,
    array $record_text,
    int $pitfalls_max,
    array $adrs,
    callable $resolve
): array {
    $out = ['failures' => [], 'unchecked' => [], 'quoted' => 0, 'checked' => 0,
        'pitfalls' => 0, 'adrs' => 0];

    // ── ASSERTION 1 & 2: the entry named exists ──────────────────────────────────────────────
    if ($pitfalls_max > 0 && preg_match_all('/\bPITFALLS (\d+)\b/', $raw, $m)) {
        foreach (array_map('intval', $m[1]) as $n) {
            $out['pitfalls']++;
            if ($n < 1 || $n > $pitfalls_max) {
                $out['failures'][] = sprintf('%s:%d cites PITFALLS %d — PITFALLS.md holds entries 1..%d',
                    $rel, $line, $n, $pitfalls_max);
            }
        }
    }
    if ($adrs && preg_match_all('/\bADR-(\d+)\b/', $raw, $m)) {
        foreach (array_map('intval', $m[1]) as $n) {
            $out['adrs']++;
            if (!isset($adrs[$n])) {
                $out['failures'][] = sprintf('%s:%d cites ADR-%d — DECISIONS.md defines no such ADR',
                    $rel, $line, $n);
            }
        }
    }

    // ── ASSERTION 3: quotations are verbatim ─────────────────────────────────────────────────
    //
    // The record is chosen per QUOTE by nearest preceding mention, not by whichever name appears
    // first in the record list. The first version broke on the second: a comment that mentioned
    // STATE.json in passing and then quoted PITFALLS.md had the PITFALLS quote checked against
    // STATE.json — reproduced against a genuine, correct citation, and a gate that reds on
    // correct citations teaches people to stop quoting.
    $body = (string) preg_replace('/\s+/', ' ', (string) preg_replace('/^\s*\*\s?/m', '', $raw));
    if (!preg_match_all('/"([^"]*)"/', $body, $quotes, PREG_OFFSET_CAPTURE)) {
        return $out;
    }

    $prev_end = 0;
    foreach ($quotes[1] as [$quote, $offset]) {
        $window   = substr($body, $prev_end, $offset - $prev_end);
        $prev_end = $offset + strlen($quote);
        if (strlen($quote) < 40) {
            continue; // emphasis, a key name, a shell fragment — not a citation
        }

        $named = null;
        $best  = -1;
        foreach (array_keys($record_text) as $name) {
            $at = strrpos(substr($body, 0, $offset), $name);
            if (false !== $at && $at > $best) {
                $best  = $at;
                $named = $name;
            }
        }
        if (null === $named) {
            continue; // not a record citation
        }
        $out['quoted']++;

        // Capture every ellipsis segment, INCLUDING short ones — the first version dropped a
        // citation whose segments were all under 20 chars, so it was never checked and never
        // counted. Segments under 12 chars are too weak to assert, and are reported as
        // unchecked rather than silently passed.
        $segments = array_filter(array_map('trim',
            preg_split('/\s*(?:…|\.\.\.)\s*/u', $quote) ?: []));
        foreach ($segments as $segment) {
            if (strlen($segment) < 12) {
                $out['unchecked'][] = sprintf('%s:%d quotes %s with segment(s) too short to assert',
                    $rel, $line, $named);
                break;
            }
        }

        // The pin is scoped to the text since the PREVIOUS quotation, so it governs the one
        // quotation that follows it and no other. Comment-global detection meant a single
        // legitimate `AT <sha>` anywhere in a docblock redirected EVERY quotation in it to that
        // revision — reproduced against a correct current citation, which is the same defect
        // already fixed for the record selector above. Nearest-preceding is not enough either:
        // with no later ref to displace it the first pin still reaches the last quote. A comment
        // that means to pin two quotations to one revision repeats the pin, which is cheap and
        // says so out loud.
        $ref = preg_match_all('/\b(?:at|AT) ([0-9a-f]{7,40})\b/', $window, $r)
            ? (string) end($r[1])
            : null;
        $haystack = $record_text[$named];
        if (null !== $ref) {
            $at_ref = $resolve($named, $ref);
            if (null === $at_ref) {
                $out['failures'][] = sprintf('%s:%d cites %s at %s, but that ref does not resolve to a '
                    . 'commit holding it — a citation nobody can check is one nobody should trust',
                    $rel, $line, $named, $ref);
                continue;
            }
            $haystack = (string) preg_replace('/\s+/', ' ', $at_ref);
        }

        foreach ($segments as $segment) {
            if (strlen($segment) < 12) {
                continue;
            }
            $out['checked']++;
            if (false === strpos($haystack, $segment)) {
                $out['failures'][] = sprintf('%s:%d quotes %s%s, but this does not appear there '
                    . 'verbatim: "%s"', $rel, $line, $named,
                    null === $ref ? ' (at HEAD)' : ' at ' . $ref,
                    strlen($segment) > 90 ? substr($segment, 0, 90) . '…' : $segment);
            }
        }
    }

    return $out;
}

// ── Gather ───────────────────────────────────────────────────────────────────────────────────

$record_text  = [];
$record_raw   = [];
$pitfalls_max = 0;
$adrs         = [];
$files        = [];

$quoted       = 0;
$checked      = 0;
$unchecked    = [];
$pitfall_refs = 0;
$adr_refs     = 0;

if (!$standalone) {
    foreach ($records as $name => $path) {
        $body = @file_get_contents($path);
        if (false === $body) {
            $failures[] = "cannot read {$name} at {$path} — citations against it are unverifiable";
            continue;
        }
        // Both forms, from ONE read. The quotation check needs whitespace normalised so a
        // citation that re-wraps across comment lines still matches; the entry indexes below need
        // the raw text, because `/^## N./m` cannot find a heading once every newline is a space.
        // Re-reading the same two files to recover it also let isset($record_text[…]) stand in
        // for "was readable", which is a different question.
        $record_raw[$name]  = $body;
        $record_text[$name] = (string) preg_replace('/\s+/', ' ', $body);
    }

    // The entries that exist, for the existence assertions.
    if (isset($record_raw['PITFALLS.md'])
        && preg_match_all('/^## (\d+)\./m', $record_raw['PITFALLS.md'], $m)) {
        $pitfalls_max = max(array_map('intval', $m[1]));
    }
    if (isset($record_raw['DECISIONS.md'])
        && preg_match_all('/\bADR-(\d+)\b/', $record_raw['DECISIONS.md'], $m)) {
        $adrs = array_flip(array_map('intval', $m[1]));
    }

    $resolve = static function (string $name, string $ref) use ($repo_root, $records, $can_shell): ?string {
        return rct_text_at_ref($repo_root, $records[$name], $ref, $can_shell);
    };

    $files = slimstat_own_php_files(
        [$plugin_root . '/tests', $plugin_root . '/src', $plugin_root . '/admin'],
        $plugin_root . '/src/Dependencies'
    );

    foreach ($files as $file) {
        $rel = slimstat_rel_path($plugin_root, $file);

        // $is_file = true, stated rather than sniffed. Left unstated, slimstat_tokenize() lexes
        // every file TWICE to look for a T_OPEN_TAG — measured at +22.75 ms over this corpus,
        // +94.7%, which is 12x what the whole per-comment refactor costs. Every input here comes
        // from slimstat_own_php_files(), so the answer is known at the call site.
        foreach (slimstat_tokenize((string) file_get_contents($file), true) as $token) {
            if (!is_array($token) || (T_COMMENT !== $token[0] && T_DOC_COMMENT !== $token[0])) {
                continue;
            }
            $r = rct_comment_problems((string) $token[1], $rel, (int) $token[2],
                $record_text, $pitfalls_max, $adrs, $resolve);

            $failures      = array_merge($failures, $r['failures']);
            $unchecked     = array_merge($unchecked, $r['unchecked']);
            $quoted       += $r['quoted'];
            $checked      += $r['checked'];
            $pitfall_refs += $r['pitfalls'];
            $adr_refs     += $r['adrs'];
        }
    }
}

// ── FIXTURES: the checker is exercised on every run, in both modes ───────────────────────────
//
// These drive rct_comment_problems() directly, so its logic is under test where the records are
// absent as well as where they are present. They are PHP STRING literals, not comments, so the
// walk above never reads them — a fixture citing a deliberately dangling entry would otherwise be
// reported by this very file as a real defect, which is why the numbers are concatenated in.

$fx_now     = 'the seal is opened only after both arms have been adjudicated blind';
$fx_old     = 'the seal was opened before either arm had been adjudicated at all';
$fx_short   = 'the seal is opened after the arms count';
$fx_state   = 'the mapping is unsealed only after adjudication has finished';
$fx_records = [
    'PITFALLS.md'  => 'preamble ' . $fx_now . ' tail',
    'DECISIONS.md' => 'the boundary is set by the entry below',
    // A third record so the nearest-preceding SELECTOR has something to get wrong. With two
    // records that never co-occur in one comment, first-match-wins and nearest-preceding are the
    // same function, and every mutation between them survives.
    'STATE.json'   => 'state preamble ' . $fx_state . ' tail',
];
$fx_adrs    = [17 => true];
$fx_resolve = static function (string $name, string $ref) use ($fx_old): ?string {
    // Exactly one ref resolves, and what it holds is a SUPERSEDED sentence; every other ref is
    // unknown, which is the case that must fail rather than waive the citation.
    return 'ab12cd3' === $ref ? 'old preamble ' . $fx_old . ' tail' : null;
};

// [label, the comment text, [failures, unchecked, quoted, checked, PITFALLS refs, ADR refs]]
//
// All six counts, not just the two problem counts. The four counters are floored by CONTROLS in
// full mode and by nothing at all in standalone mode — which is the mode CI gets — so deleting
// any one of the four `++` lines used to survive the entire table.
$fx_cases = [
    ['an in-range entry passes',                 '// PITFALLS ' . '4 — in range',                     [0, 0, 0, 0, 1, 0]],
    ['a dangling entry fails',                   '// PITFALLS ' . '999 — no such entry',              [1, 0, 0, 0, 1, 0]],
    ['a defined ADR passes',                     '// see ADR-' . '17 for the boundary',               [0, 0, 0, 0, 0, 1]],
    ['an undefined ADR fails',                   '// see ADR-' . '999 for the boundary',              [1, 0, 0, 0, 0, 1]],
    ['a verbatim quotation passes',              '// PITFALLS.md says "' . $fx_now . '"',             [0, 0, 1, 1, 0, 0]],
    ['a fabricated quotation fails',             '// PITFALLS.md says "' . $fx_now . ' every time"',  [1, 0, 1, 1, 0, 0]],
    ['a pinned ref redirects the comparison',    '// PITFALLS.md AT ab12cd3 said "' . $fx_old . '"',  [0, 0, 1, 1, 0, 0]],
    ['the same quote unpinned fails',            '// PITFALLS.md says "' . $fx_old . '"',             [1, 0, 1, 1, 0, 0]],
    ['an unresolvable ref fails, never waives',  '// PITFALLS.md AT 9999999 said "' . $fx_now . '"',  [1, 0, 1, 0, 0, 0]],
    ['a short segment is UNCHECKED, not passed', '// PITFALLS.md says "the seal is opened only after both … arms"', [0, 1, 1, 1, 0, 0]],
    ['a pin governs one quotation, not the rest',
        '// PITFALLS.md AT ab12cd3 said "' . $fx_old . '", and PITFALLS.md now says "' . $fx_now . '"', [0, 0, 2, 2, 0, 0]],
    ['an unpinned superseded quote after a pin still fails',
        '// PITFALLS.md AT ab12cd3 said "' . $fx_now . '", and PITFALLS.md also says "' . $fx_old . '"', [2, 0, 2, 2, 0, 0]],
    // The four below close mutants that the twelve above all survived. Each is the sole killer of
    // a mechanism this file's docblock spends a paragraph on, and none of those paragraphs had a
    // case behind it until the table was mutated rather than read.
    ['a bare hex word is not a ref',             '// PITFALLS.md ab12cd3 says "' . $fx_now . '"',     [0, 0, 1, 1, 0, 0]],
    ['the LAST pin in the window wins',          '// PITFALLS.md AT 9999999 AT ab12cd3 said "' . $fx_old . '"', [0, 0, 1, 1, 0, 0]],
    ['under the 40-char floor is not a citation', '// PITFALLS.md says "' . $fx_short . '"',          [0, 0, 0, 0, 0, 0]],
    // Named in the order that makes the two candidate rules DISAGREE: PITFALLS.md is reached
    // first by the iteration, STATE.json is nearer the quote. Written the other way round this
    // case passes under both rules and proves nothing — measured, by mutation, on the first
    // version of this line.
    ['the record is the NEAREST one named, not the first',
        '// PITFALLS.md is mentioned in passing, and then STATE.json says "' . $fx_state . '"',      [0, 0, 1, 1, 0, 0]],
];

$fx_ok   = 0;
$fx_reds = 0;
foreach ($fx_cases as [$fx_label, $fx_text, $fx_want]) {
    // Required-red is counted and printed, not implied: "16/16 cases behaved" is also what a
    // table of sixteen all-green cases prints, and that table would prove nothing at all.
    $fx_reds += ($fx_want[0] > 0 || $fx_want[1] > 0) ? 1 : 0;

    $fx  = rct_comment_problems($fx_text, 'fixture', 0, $fx_records, 10, $fx_adrs, $fx_resolve);
    $got = [count($fx['failures']), count($fx['unchecked']), $fx['quoted'], $fx['checked'],
        $fx['pitfalls'], $fx['adrs']];
    if ($got === $fx_want) {
        $fx_ok++;
        continue;
    }
    $failures[] = sprintf('FIXTURE "%s": expected [%s], got [%s]',
        $fx_label, implode(',', $fx_want), implode(',', $got));
}

// ── CONTROLS ─────────────────────────────────────────────────────────────────────────────────

echo "CONTROLS\n";
printf("  MODE=%s\n", $standalone
    ? 'standalone — jaan-to/ is absent, so NO citation in this tree was checked; fixtures only'
    : 'full — records present; every PHP comment in the scanned tree checked');

$controls[] = [
    $fx_ok === count($fx_cases),
    sprintf('the checker was exercised by fixtures: %d/%d cases behaved (%d required-red)',
        $fx_ok, count($fx_cases), $fx_reds),
];

if (!$standalone) {
    $controls[] = [
        count($record_text) === count($records),
        sprintf('all %d standing records are readable (%d)', count($records), count($record_text)),
    ];
    $controls[] = [
        $pitfalls_max >= 100 && count($adrs) >= 15,
        sprintf('the entry indexes were parsed: PITFALLS 1..%d, %d ADRs', $pitfalls_max, count($adrs)),
    ];
    $controls[] = [
        // The existence half is the one with coverage; if it finds nothing it is not running.
        // The floors are the MEASURED comment-only counts less a margin — 62 and 54. An earlier
        // draft used 100/20, taken from a grep over whole files rather than over comments, and
        // the control duly failed on a healthy tree. A floor guessed from the wrong population
        // is a floor that teaches you to lower floors.
        $pitfall_refs >= 50 && $adr_refs >= 40,
        sprintf('the scan found entry references to resolve: %d PITFALLS, %d ADR across %d files',
            $pitfall_refs, $adr_refs, count($files)),
    ];
    $controls[] = [
        $quoted >= 1 && $checked >= 1,
        sprintf('the scan found quotations to verify: %d quoted citation(s), %d segment(s) checked',
            $quoted, $checked),
    ];
}

foreach ($controls as [$ok, $label]) {
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $label);
    if (!$ok) {
        $failures[] = 'a CONTROL failed — the result below says nothing until it is fixed';
    }
}
foreach ($unchecked as $u) {
    printf("  [UNCHECKED] %s\n", $u);
}

printf("\nSLIMSTAT-RECORD-CITATION-TEST mode=%s entry-refs=%d quotes=%d segments=%d unchecked=%d failures=%d\n",
    $standalone ? 'standalone' : 'full',
    $pitfall_refs + $adr_refs, $quoted, $checked, count($unchecked), count($failures));

// PASS is refused while anything is unchecked. The first version printed "every quotation …
// appears verbatim" over four unchecked fabrications and exited 0.
if ($failures || $unchecked) {
    if ($failures) {
        fwrite(STDERR, 'FAIL: ' . count($failures) . " citation problem(s)\n");
        foreach ($failures as $f) {
            fwrite(STDERR, "  - {$f}\n");
        }
    }
    if ($unchecked && !$failures) {
        fwrite(STDERR, "FAIL: " . count($unchecked) . " citation(s) could not be checked; PASS is "
            . "not available while any citation is unverified\n");
    }
    exit(1);
}

printf("PASS: record citation gate — %d fixtures (%d required-red), checked against %s\n",
    count($fx_cases), $fx_reds, $standalone
        ? 'FIXTURES ONLY (no jaan-to sibling in this checkout — the invariants still ran)'
        : sprintf('the real records: %d entry reference(s) resolve and %d quoted segment(s) '
            . 'appear verbatim in the record named', $pitfall_refs + $adr_refs, $checked));
