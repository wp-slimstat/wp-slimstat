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
 * Measured across tests/, src/ and admin/: **531 bare entry references** (393 `PITFALLS N`, 138
 * `ADR-N`) against **2 quoted citations**. Two orders of magnitude. So the load-bearing check is
 * not "is this quote verbatim" but "does the entry this comment names exist" — which is
 * unambiguous, cannot false-positive, and breaks loudly if an entry is renumbered or deleted
 * against PITFALLS.md's own "never delete an entry" rule. Today: 0 dangling. Nothing enforced it
 * until now.
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
    $rel = ltrim(substr($path, strlen($repo_root)), '/');
    $out = shell_exec(sprintf('git -C %s show %s:%s 2>/dev/null',
        escapeshellarg($repo_root), escapeshellarg($ref), escapeshellarg($rel)));

    return (null === $out || '' === trim((string) $out)) ? null : (string) $out;
}

// ── Gather ───────────────────────────────────────────────────────────────────────────────────

$record_text = [];
foreach ($records as $name => $path) {
    $body = @file_get_contents($path);
    if (false === $body) {
        $failures[] = "cannot read {$name} at {$path} — citations against it are unverifiable";
        continue;
    }
    // Whitespace-normalised so a quotation that re-wraps across comment lines still matches.
    $record_text[$name] = (string) preg_replace('/\s+/', ' ', $body);
}

// The entries that exist, for the existence assertions.
$pitfalls_max = 0;
if (isset($record_text['PITFALLS.md'])
    && preg_match_all('/^## (\d+)\./m', (string) @file_get_contents($records['PITFALLS.md']), $m)) {
    $pitfalls_max = max(array_map('intval', $m[1]));
}
$adrs = [];
if (isset($record_text['DECISIONS.md'])
    && preg_match_all('/\bADR-(\d+)\b/', (string) @file_get_contents($records['DECISIONS.md']), $m)) {
    $adrs = array_flip(array_map('intval', $m[1]));
}

$files = slimstat_own_php_files(
    [$plugin_root . '/tests', $plugin_root . '/src', $plugin_root . '/admin'],
    $plugin_root . '/src/Dependencies'
);

$quoted     = 0;
$checked    = 0;
$unchecked  = [];
$pitfall_refs = 0;
$adr_refs     = 0;

foreach ($files as $file) {
    $rel = slimstat_rel_path($plugin_root, $file);

    foreach (slimstat_tokenize((string) file_get_contents($file)) as $token) {
        if (!is_array($token) || (T_COMMENT !== $token[0] && T_DOC_COMMENT !== $token[0])) {
            continue;
        }
        $raw  = (string) $token[1];
        $line = (int) $token[2];

        // ── ASSERTION 1 & 2: the entry named exists ──────────────────────────────────────────
        if ($pitfalls_max > 0 && preg_match_all('/\bPITFALLS (\d+)\b/', $raw, $m)) {
            foreach (array_map('intval', $m[1]) as $n) {
                $pitfall_refs++;
                if ($n < 1 || $n > $pitfalls_max) {
                    $failures[] = sprintf('%s:%d cites PITFALLS %d — PITFALLS.md holds entries 1..%d',
                        $rel, $line, $n, $pitfalls_max);
                }
            }
        }
        if ($adrs && preg_match_all('/\bADR-(\d+)\b/', $raw, $m)) {
            foreach (array_map('intval', $m[1]) as $n) {
                $adr_refs++;
                if (!isset($adrs[$n])) {
                    $failures[] = sprintf('%s:%d cites ADR-%d — DECISIONS.md defines no such ADR',
                        $rel, $line, $n);
                }
            }
        }

        // ── ASSERTION 3: quotations are verbatim ─────────────────────────────────────────────
        //
        // The record is chosen per QUOTE by nearest preceding mention, not by whichever name
        // appears first in $records. The first version broke on the second: a comment that
        // mentioned STATE.json in passing and then quoted PITFALLS.md had the PITFALLS quote
        // checked against STATE.json — reproduced against a genuine, correct citation, and a gate
        // that reds on correct citations teaches people to stop quoting.
        $body = (string) preg_replace('/\s+/', ' ', (string) preg_replace('/^\s*\*\s?/m', '', $raw));
        if (!preg_match_all('/"([^"]*)"/', $body, $quotes, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        foreach ($quotes[1] as [$quote, $offset]) {
            if (strlen($quote) < 40) {
                continue; // emphasis, a key name, a shell fragment — not a citation
            }

            $named = null;
            $best  = -1;
            foreach (array_keys($records) as $name) {
                $at = strrpos(substr($body, 0, $offset), $name);
                if (false !== $at && $at > $best) {
                    $best  = $at;
                    $named = $name;
                }
            }
            if (null === $named || !isset($record_text[$named])) {
                continue; // not a record citation
            }
            $quoted++;

            // Capture every ellipsis segment, INCLUDING short ones — the first version dropped a
            // citation whose segments were all under 20 chars, so it was never checked and never
            // counted. Segments under 12 chars are too weak to assert, and are reported as
            // unchecked rather than silently passed.
            $segments = array_values(array_filter(array_map('trim',
                preg_split('/\s*(?:…|\.\.\.)\s*/u', $quote) ?: [])));
            $weak = array_filter($segments, static function (string $s): bool {
                return strlen($s) < 12;
            });
            if ($weak) {
                $unchecked[] = sprintf('%s:%d quotes %s with segment(s) too short to assert',
                    $rel, $line, $named);
            }

            $ref = preg_match('/\b(?:at|AT) ([0-9a-f]{7,40})\b/', $body, $r) ? $r[1] : null;
            $haystack = $record_text[$named];
            if (null !== $ref) {
                $at_ref = rct_text_at_ref($repo_root, $records[$named], $ref, $can_shell);
                if (null === $at_ref) {
                    $failures[] = sprintf('%s:%d cites %s at %s, but that ref does not resolve to a '
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
                $checked++;
                if (false === strpos($haystack, $segment)) {
                    $failures[] = sprintf('%s:%d quotes %s%s, but this does not appear there '
                        . 'verbatim: "%s"', $rel, $line, $named,
                        null === $ref ? ' (at HEAD)' : ' at ' . $ref,
                        strlen($segment) > 90 ? substr($segment, 0, 90) . '…' : $segment);
                }
            }
        }
    }
}

// ── CONTROLS ─────────────────────────────────────────────────────────────────────────────────

$controls[] = [
    count($record_text) === count($records),
    sprintf('all %d standing records are readable (%d)', count($records), count($record_text)),
];
$controls[] = [
    $pitfalls_max >= 100 && count($adrs) >= 15,
    sprintf('the entry indexes were parsed: PITFALLS 1..%d, %d ADRs', $pitfalls_max, count($adrs)),
];
$controls[] = [
    // The existence half is the one with coverage; if it finds nothing it is not running. The
    // floors are the MEASURED comment-only counts less a margin — 62 and 54. An earlier draft
    // used 100/20, taken from a grep over whole files rather than over comments, and the control
    // duly failed on a healthy tree. A floor guessed from the wrong population is a floor that
    // teaches you to lower floors.
    $pitfall_refs >= 50 && $adr_refs >= 40,
    sprintf('the scan found entry references to resolve: %d PITFALLS, %d ADR across %d files',
        $pitfall_refs, $adr_refs, count($files)),
];
$controls[] = [
    $quoted >= 1 && $checked >= 1,
    sprintf('the scan found quotations to verify: %d quoted citation(s), %d segment(s) checked',
        $quoted, $checked),
];

foreach ($controls as [$ok, $label]) {
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $label);
    if (!$ok) {
        $failures[] = 'a CONTROL failed — the result below says nothing until it is fixed';
    }
}
foreach ($unchecked as $u) {
    printf("  [UNCHECKED] %s\n", $u);
}

printf("\nSLIMSTAT-RECORD-CITATION-TEST entry-refs=%d quotes=%d segments=%d unchecked=%d failures=%d\n",
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

printf("PASS: %d entry reference(s) resolve, and %d quoted segment(s) appear verbatim in the record named\n",
    $pitfall_refs + $adr_refs, $checked);
