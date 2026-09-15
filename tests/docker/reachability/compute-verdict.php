<?php
// Recompute `wired` from the atoms the differential protocol produced.
//
//   php tests/docker/reachability/compute-verdict.php <evidence-dir>
//
// WHY IT IS A SEPARATE PROGRAM. The thing under test is whether an ANALYSIS can be trusted, so
// the one input this must not accept is an analysis's own verdict. Every check below reads a
// FACT — a digest, an exit code, a control number, an observed output line — and the boolean is
// arithmetic over those facts. Nothing here reads a field named `verdict`, `passed` or `ok` out
// of an agent's report, and the LLM report's own summary is deliberately not part of the sum.
//
// THE RULE FOR A DETECTION, applied identically to both analysers: it counts only if it names
// the PRE-DECLARED control and the PRE-DECLARED relation. Everything else is a MISS —
//   * a generic error, a refusal, a crash or a timeout (status must be `analysed`);
//   * a finding about a different control, or about the right control and the wrong relation;
//   * a finding that was ALREADY made against the baseline (it says nothing about the mutation);
//   * flagging every control at once (that is not detection, it is a broken analyser).
// A miss on either mutation returns wired:false with an exact refused_because, and the Wire step
// does not happen. There is no partial credit, because a gate wired on a half-detection is a
// gate whose next silent break nobody will see either.
//
// 7.4-safe: plain functions, no autoloader, no WordPress.

declare(strict_types=1);

$dir = $argv[1] ?? '/tmp/php-matrix/reachability';

/** Read a JSON atom, or null. A missing atom is a failed check, never a skipped one. */
function atom(string $dir, string $name): ?array
{
    $path = rtrim($dir, '/') . '/' . $name;
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : null;
}

/** One check: a name, a boolean, and the fact that settled it. */
function check(array &$checks, string $id, bool $ok, string $because): bool
{
    $checks[] = ['id' => $id, 'ok' => $ok, 'because' => $because];
    return $ok;
}

/** The set of control numbers an analysis says are broken, by relation. */
function broken_in_static(?array $static, string $relation): array
{
    if (null === $static || !isset($static['controls'])) {
        return [];
    }
    $out = [];
    foreach ($static['controls'] as $c) {
        if ('reachability' === $relation && !$c['reachable']) {
            $out[] = (int) $c['n'];
        } elseif ('exit-effect' === $relation && $c['reachable'] && !$c['exit_effective']) {
            $out[] = (int) $c['n'];
        }
    }
    return $out;
}

/** The same, from an LLM report's `defects` list. */
function broken_in_llm(?array $llm, string $relation): array
{
    if (null === $llm || !isset($llm['defects']) || !is_array($llm['defects'])) {
        return [];
    }
    $out = [];
    foreach ($llm['defects'] as $d) {
        if (isset($d['relation'], $d['control']) && $relation === $d['relation']) {
            $out[] = (int) $d['control'];
        }
    }
    return $out;
}

$checks       = [];
$observations = [];   // looked at, reported, and deliberately NOT summed - see each site
$baseline = atom($dir, 'state-baseline.json');
$sb       = atom($dir, 'static-baseline.json');
$lb       = atom($dir, 'llm-baseline.json');
$live     = atom($dir, 'live-evidence.json');

// ── The baseline must PASS, or nothing below means anything ─────────────────────────────────
// A mutation detected against a subject that was already reported broken proves that the
// analyser says "broken" — not that it can tell the difference.
check($checks, 'baseline.state_recorded', null !== $baseline,
    null !== $baseline ? 'state-baseline.json present, sha256 ' . substr($baseline['sha256'], 0, 16) : 'state-baseline.json missing');

$baseline_sha = $baseline['sha256'] ?? '';

check($checks, 'baseline.php_lint', isset($baseline['php_lint']) && false !== strpos($baseline['php_lint'], 'No syntax errors'),
    $baseline['php_lint'] ?? 'not recorded');

check($checks, 'baseline.static_passes', null !== $sb && 'PASS' === ($sb['verdict'] ?? ''),
    null === $sb ? 'static-baseline.json missing'
        : sprintf('static verdict %s — %d controls, %d reachable, %d exit-effective',
            $sb['verdict'], $sb['summary']['declared'], $sb['summary']['reachable'], $sb['summary']['exit_effective']));

check($checks, 'baseline.static_digest_matches', null !== $sb && ($sb['sha256'] ?? '') === $baseline_sha,
    null === $sb ? 'no static baseline' : 'analysed ' . substr((string) ($sb['sha256'] ?? ''), 0, 16) . ' vs state ' . substr($baseline_sha, 0, 16));

check($checks, 'baseline.llm_present', null !== $lb, null !== $lb ? 'llm-baseline.json present' : 'llm-baseline.json missing — the LLM half of the protocol was not run');

check($checks, 'baseline.llm_digest_matches', null !== $lb && ($lb['subject_sha256'] ?? '') === $baseline_sha,
    null === $lb ? 'no LLM baseline' : 'analysed ' . substr((string) ($lb['subject_sha256'] ?? ''), 0, 16) . ' vs state ' . substr($baseline_sha, 0, 16));

check($checks, 'baseline.llm_status_analysed', null !== $lb && 'analysed' === ($lb['status'] ?? ''),
    null === $lb ? 'no LLM baseline' : 'status ' . (string) ($lb['status'] ?? 'absent'));

$baseline_llm_defects = null === $lb ? [] : (isset($lb['defects']) && is_array($lb['defects']) ? $lb['defects'] : []);
check($checks, 'baseline.llm_reports_no_defect', null !== $lb && 0 === count($baseline_llm_defects),
    null === $lb ? 'no LLM baseline' : count($baseline_llm_defects) . ' defect(s) reported against the untouched subject');

// Any control/relation pair the baseline already flagged is inadmissible as a detection later.
$baseline_flagged = [];
foreach ($baseline_llm_defects as $d) {
    if (isset($d['control'], $d['relation'])) {
        $baseline_flagged[] = $d['control'] . ':' . $d['relation'];
    }
}
foreach (broken_in_static($sb, 'reachability') as $n) {
    $baseline_flagged[] = $n . ':reachability';
}
foreach (broken_in_static($sb, 'exit-effect') as $n) {
    $baseline_flagged[] = $n . ':exit-effect';
}

// ── Per mutation ────────────────────────────────────────────────────────────────────────────
$mutations = [];
foreach (['M1' => 'reachability', 'M2' => 'exit-effect'] as $id => $expected_relation) {
    $state   = atom($dir, "state-{$id}.json");
    $static  = atom($dir, "static-{$id}.json");
    $llm     = atom($dir, "llm-{$id}.json");
    $restore = atom($dir, "restore-{$id}.json");

    // The control number is read out of the SPEC's pre_declared_control, which the driver
    // copied from the mutation file before the analysers ran. Reading it from an analysis
    // instead would let the analysis choose what it was supposed to find.
    $declared = null;
    if (null !== $state && preg_match('/^\s*(\d+)/', (string) ($state['pre_declared_control'] ?? ''), $m)) {
        $declared = (int) $m[1];
    }

    check($checks, "{$id}.applied", null !== $state, null !== $state ? "state-{$id}.json present" : "state-{$id}.json missing");
    check($checks, "{$id}.pre_declared_control_read", null !== $declared,
        null === $declared ? 'no control number in the mutation spec' : 'control ' . $declared . ', relation ' . (string) ($state['pre_declared_relation'] ?? '?'));
    check($checks, "{$id}.digest_changed", null !== $state && ($state['sha256'] ?? '') !== $baseline_sha,
        null === $state ? 'not applied' : substr((string) ($state['sha256'] ?? ''), 0, 16) . ' vs baseline ' . substr($baseline_sha, 0, 16));
    check($checks, "{$id}.still_parses", null !== $state && false !== strpos((string) ($state['php_lint'] ?? ''), 'No syntax errors'),
        null === $state ? 'not applied' : (string) ($state['php_lint'] ?? 'not recorded')
            . ' — a mutation that breaks the parse is detected by the parser, not by the analysis');

    // Static analyser: the pre-declared control, the pre-declared relation, and NOTHING ELSE.
    $s_broken = broken_in_static($static, $expected_relation);
    $s_other  = array_values(array_diff(
        array_merge(broken_in_static($static, 'reachability'), broken_in_static($static, 'exit-effect')),
        [$declared]
    ));
    check($checks, "{$id}.static_digest_matches", null !== $static && ($static['sha256'] ?? '') === ($state['sha256'] ?? 'x'),
        null === $static ? 'no static analysis' : 'analysed ' . substr((string) ($static['sha256'] ?? ''), 0, 16));
    check($checks, "{$id}.static_names_control", null !== $declared && in_array($declared, $s_broken, true),
        sprintf('static reported %s broken by %s; expected control %s',
            $s_broken ? implode(',', $s_broken) : 'no control', $expected_relation, (string) $declared));
    check($checks, "{$id}.static_does_not_mass_flag", 0 === count($s_other),
        $s_other ? 'static also flagged ' . implode(',', $s_other) . ' — a mutation of one control that breaks the analysis of others is not a detection'
            : 'no other control was flagged');

    // LLM analyser: same rule, plus status and the baseline-exclusion.
    $l_broken = broken_in_llm($llm, $expected_relation);
    $l_all    = array_merge(broken_in_llm($llm, 'reachability'), broken_in_llm($llm, 'exit-effect'));
    $l_other  = array_values(array_diff($l_all, [$declared]));
    $is_new   = null !== $declared && !in_array($declared . ':' . $expected_relation, $baseline_flagged, true);

    check($checks, "{$id}.llm_present", null !== $llm, null !== $llm ? "llm-{$id}.json present" : "llm-{$id}.json missing");
    check($checks, "{$id}.llm_status_analysed", null !== $llm && 'analysed' === ($llm['status'] ?? ''),
        null === $llm ? 'absent' : 'status ' . (string) ($llm['status'] ?? 'absent')
            . ' — a refusal, an error or a timeout is a MISS, not a detection');
    check($checks, "{$id}.llm_digest_matches", null !== $llm && ($llm['subject_sha256'] ?? '') === ($state['sha256'] ?? 'x'),
        null === $llm ? 'absent' : 'analysed ' . substr((string) ($llm['subject_sha256'] ?? ''), 0, 16)
            . ' vs state ' . substr((string) ($state['sha256'] ?? ''), 0, 16)
            . ' — an analysis of a different state cannot be evidence about this one');
    check($checks, "{$id}.llm_names_control", null !== $declared && in_array($declared, $l_broken, true),
        sprintf('LLM reported %s broken by %s; expected control %s',
            $l_broken ? implode(',', $l_broken) : 'no control', $expected_relation, (string) $declared));
    // NOT a check. The baseline-exclusion rule is real and enforced, but it is enforced UPSTREAM:
    // `baseline.llm_reports_no_defect` requires the untouched subject to yield an empty defect
    // list and `baseline.static_passes` requires the static baseline to flag nothing, so nothing
    // can reach here already-flagged without one of those having failed first. Presented as a
    // check it would be an entry in the list that no run can turn red — the shape this file
    // exists to keep out of a verdict — so it is reported and not summed.
    $observations[] = sprintf('%s.llm_finding_is_new: %s (implied by the two baseline checks)',
        $id, $is_new ? 'new' : 'ALREADY FLAGGED ON BASELINE');
    check($checks, "{$id}.llm_does_not_mass_flag", 0 === count($l_other),
        $l_other ? 'the LLM also flagged ' . implode(',', $l_other) : 'no other control was flagged');

    // Independent evidence: the relationship really broke, observed by RUNNING the subject.
    $ev = null;
    if (null !== $live && isset($live['mutations']) && is_array($live['mutations'])) {
        foreach ($live['mutations'] as $entry) {
            if (($entry['id'] ?? '') === $id) {
                $ev = $entry;
            }
        }
    }
    check($checks, "{$id}.live_evidence_present", null !== $ev,
        null === $ev ? 'no live entry — the break was asserted by two readings and never observed'
            : 'observed: ' . (string) ($ev['observed'] ?? ''));
    check($checks, "{$id}.live_evidence_confirms", null !== $ev && !empty($ev['relationship_broken']) && ($ev['control'] ?? -1) === $declared,
        null === $ev ? 'absent' : sprintf('control %s, relationship_broken=%s',
            (string) ($ev['control'] ?? '?'), !empty($ev['relationship_broken']) ? 'true' : 'false'));
    check($checks, "{$id}.live_evidence_digest_matches", null !== $ev && ($ev['subject_sha256'] ?? '') === ($state['sha256'] ?? 'x'),
        null === $ev ? 'absent' : 'ran ' . substr((string) ($ev['subject_sha256'] ?? ''), 0, 16));

    check($checks, "{$id}.restored_byte_identical", null !== $restore && !empty($restore['byte_identical']) && ($restore['restored_sha256'] ?? '') === $baseline_sha,
        null === $restore ? "restore-{$id}.json missing" : 'restored ' . substr((string) ($restore['restored_sha256'] ?? ''), 0, 16));

    $mutations[$id] = [
        'declared_control'  => $declared,
        'expected_relation' => $expected_relation,
        'sha256'            => $state['sha256'] ?? null,
        'static_broken'     => $s_broken,
        'llm_broken'        => $l_broken,
        'live'              => $ev,
    ];
}

// ── The two analysers must AGREE, or the reading is unstable ────────────────────────────────
// Disagreement does not decide which is right; it decides that neither may be relied on yet,
// which is the honest output when the only tie-breaker is a third reading of the same file.
$disagreements = [];
foreach ($mutations as $id => $m) {
    if ($m['static_broken'] !== $m['llm_broken']) {
        $disagreements[] = sprintf('%s: static says %s, LLM says %s', $id,
            $m['static_broken'] ? implode(',', $m['static_broken']) : 'none',
            $m['llm_broken'] ? implode(',', $m['llm_broken']) : 'none');
    }
}
// Also NOT a check, for the same reason: `{id}.static_names_control` + `static_does_not_mass_flag`
// already force the static set to be exactly [declared], and the LLM pair forces the same, so by
// the time both have passed the two sets cannot differ. Disagreement is what it was written to
// surface, and disagreement is surfaced — by whichever of those four checks the disagreeing
// analyser fails. Reported here so the reader can see it was looked at.
$observations[] = 'analysers_agree: ' . ($disagreements ? implode('; ', $disagreements)
    : 'the deterministic and LLM analyses name the same control on both mutations');

$failed = array_values(array_filter($checks, function ($c) { return !$c['ok']; }));
$wired  = 0 === count($failed);

$verdict = [
    'protocol'        => 'differential reachability, two pre-declared mutations, verdict recomputed from atoms',
    'evidence_dir'    => $dir,
    'subject'         => $baseline['subject'] ?? null,
    'subject_sha256'  => $baseline_sha,
    'git_revision'    => $baseline['git_revision'] ?? null,
    'checks'          => $checks,
    'observations'    => $observations,
    'mutations'       => $mutations,
    'wired'           => $wired,
    'refused_because' => $wired ? null : implode(' | ', array_map(function ($c) {
        return $c['id'] . ': ' . $c['because'];
    }, $failed)),
];

file_put_contents(rtrim($dir, '/') . '/reachability-verdict.json',
    json_encode($verdict, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

printf("SLIMSTAT-REACHABILITY subject=%s sha256=%s rev=%s checks=%d failed=%d wired=%s\n",
    basename((string) ($baseline['subject'] ?? '?')), substr($baseline_sha, 0, 16),
    substr((string) ($baseline['git_revision'] ?? '?'), 0, 12), count($checks), count($failed),
    $wired ? 'true' : 'false');
foreach ($checks as $c) {
    printf("  [%s] %-34s %s\n", $c['ok'] ? 'OK' : '!!', $c['id'], $c['because']);
}
foreach ($observations as $o) {
    printf("  [--] %s\n", $o);
}
if (!$wired) {
    fwrite(STDERR, "REFUSED: the reachability map is not evidence, so the Wire step does not happen.\n");
    foreach ($failed as $c) {
        fwrite(STDERR, "  - {$c['id']}: {$c['because']}\n");
    }
    exit(1);
}
echo "WIRED: both pre-declared mutations were detected by both analysers, each naming the "
    . "pre-declared control and relation, each confirmed by running the subject, and the subject "
    . "was restored byte-identical after each.\n";
