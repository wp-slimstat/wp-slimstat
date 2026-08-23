<?php
/**
 * Mutation runner — turns "the test pins this" from prose into an artifact.
 *
 * WHY. Writing an assertion is not the work; proving it fails is. Every one of the five
 * known name-not-construct instances in this programme was caught by mutation testing and
 * none by the suite going green — and I1 added two more, including one where scoping the
 * assertion correctly STILL left it unkillable.
 *
 * THE RULE THIS ENCODES (ADR-E8). The runner asserts the gate passes on the CLEAN TREE
 * FIRST. Three Processor.php mutations once reported "KILLED by unit" while the runner was
 * filtered to a test that does not touch Processor.php: the production classmap-authoritative
 * autoloader was in place, PHPUnit could not load, and every gate returned non-zero
 * regardless of the mutation. Re-run properly, three of five survived.
 *
 *     A mutation that does not apply looks exactly like a test that does not work.
 *
 * So "gate failed" is only evidence when "gate passed a moment ago, on this tree" is also
 * true. Without that check a broken gate reads as a perfect one.
 *
 * FORMAT — tests/mutations/<id>.mutation
 *
 *     target:       admin/index.php            # repo-relative; reverted with git checkout
 *     gate:         composer test:foo          # must EXIT NON-ZERO under the mutation
 *     kind:         construct-removal          # see KINDS below
 *     expect:       a substring of the gate's output under the mutation. NOT decoration —
 *                   see tests/lib/mutations.php: exit code alone cannot say WHICH of a
 *                   gate's seven assertions fired, so KILLED could name one the mutation
 *                   never touched.
 *     rationale:    one line: what this proves the gate can see
 *     ---
 *     <unified diff, applied with `git apply`>
 *
 * KINDS
 *     construct-removal  delete the construct the gate exists to require
 *     guard-removal      delete a guard/branch and check the gate notices
 *     name-only          MOVE THE CONSTRUCT OUT AND LEAVE THE NAME BEHIND. This is the one
 *                        that matters: it is the mutation every source-scan assertion must
 *                        register, because "the name appears" is satisfied by a comment, a
 *                        string literal and a same-named variable.
 *     boundary           move an off-by-one / threshold
 *
 * VERDICTS
 *     KILLED     gate green on clean tree, red under the mutation. The assertion works.
 *     SURVIVED   gate green both ways. The assertion does not pin what it claims.
 *     INVALID    the gate was already red, the diff did not apply, or the gate failed for
 *                a reason other than `expect:`. Proves NOTHING — and is reported as its own
 *                verdict rather than folded into either of the other two, because that
 *                conflation is what made a whole run false once.
 *
 * Usage:
 *     php tests/verify/bin/run-mutations.php [--selftest]
 *     php tests/verify/bin/run-mutations.php [--filter=<substr>] [--changed=<ref>]
 *
 * --changed=<ref> is the plan's "blocking on changed targets per PR": run only the
 * mutations whose TARGET moved since <ref>. --filter matches the file name, which cannot
 * express that, since ids name defects rather than paths. Either flag makes the run
 * PARTIAL, and a partial run neither checks the floor nor writes a result — its counts are
 * not the registry's counts.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/mutations.php';

// The cache-key guard, and it is load-bearing rather than hygiene.
//
// A compiled-artifact cache keys on (source mtime, size) — CPython validates a .pyc that
// way, PHP's opcache on mtime alone. A mutation that is a pure BLOCK MOVE changes neither:
// S4-oracle-errored-outranks-old-errored-01 reorders two Rule() entries and leaves
// classify.py at exactly 34,760 bytes, so when the apply lands in the same wall-clock
// second as the previous compile, the interpreter reuses the stale artifact and THE GATE
// MEASURES THE UNMUTATED SOURCE. It was reported SURVIVED while being perfectly sound.
//
// Worse than a wrong verdict, it leaks: the stale artifact outlives the revert, so a later
// run on a git-clean tree can fail with the mutation's assertions, and __pycache__ is
// gitignored so mut_tree_is_clean() cannot see it. This is "a gate that silently did not
// run" wearing an interpreter cache (PITFALLS 31, 62), caught only because somebody
// measured the registry instead of reading its verdict.
//
// THE GUARD IS AT THE SEAM, NOT IN THE ENVIRONMENT. The first version of it set
// PYTHONDONTWRITEBYTECODE + PYTHONPYCACHEPREFIX, and two measurements retired that shape:
//
//   * IT COVERS ONE LANGUAGE, AND NOT THE COMMON ONE. Four of the 82 registry entries are
//     size-preserving and THREE TARGET PHP — C48-lease-steal-condition-01,
//     S3-export-stores-text-not-blob-01, S5-percentage-divides-before-multiplying-01 —
//     against one targeting Python. PHP keys on mtime alone, a WIDER target than CPython's
//     (mtime, size), and exposes no PHP_* env var for it: only `-d opcache.enable_cli=0`,
//     i.e. a shell prefix, which cannot be used here (see mut_run()). Those 48 PHP gates
//     were resting on an interpreter default that nothing in this repo asserts.
//   * IT COSTS, AND NOT WHERE IT HELPS. An empty bytecode cache recompiles 37 stdlib
//     modules — 1.0 MB, byte-identical every time — on each of ~102 python processes a
//     registry run spawns, to protect the ~9 ms of it a mutation can actually change.
//     Interleaved, n=9, medians: 42.0 ms unguarded / 115.2 ms with both env vars /
//     43.8 ms bumping mtime. About +7.5 s per registry run, inside a PR-blocking gate.
//
// Bumping the target's mtime at the apply and revert seams invalidates EVERY mtime-keyed
// artifact — .pyc, opcache SHM and file_cache, make, ccache, tsc --incremental — for any
// language, requires no cooperation from the child process, and costs +1.9 ms. Measured
// against the real gate and the real mutation with the race made deterministic (mtime
// pinned across the apply, so size AND mtime-second are identical): unguarded SURVIVED,
// both env vars SURVIVED, mtime bump KILLED.

const KILLED   = 'KILLED';
const SURVIVED = 'SURVIVED';
const INVALID  = 'INVALID';

/** @return array{0:int,1:string} [exit code, combined output] */
function mut_run(string $cmd, string $cwd): array
{
    $out = [];
    $rc  = 0;
    // Gates are free-form strings, and three call sites pass a COMPOUND `$cmd`, so a guard
    // expressed as a shell prefix here would bind to the first simple command only —
    // `composer test:a && composer test:b` guarded on the first half. That is why the
    // cache-key guard lives at the apply/revert seams (top of this file), not in this string.
    exec('cd ' . escapeshellarg($cwd) . ' && ' . $cmd . ' 2>&1', $out, $rc);

    return [$rc, implode("\n", $out)];
}

/**
 * Are all TRACKED files unmodified?
 *
 * Not `git status --porcelain` empty: untracked and newly-added files are irrelevant here
 * (nothing targets them, and `git checkout --` cannot revert them anyway), and demanding a
 * pristine tree would make the runner unusable in the commit that introduces it. What must
 * hold is that no tracked file carries an edit that could be mistaken for — or could mask —
 * a mutation.
 */
function mut_tree_is_clean(string $repo): bool
{
    // --diff-filter=MDRT: files that exist in HEAD and have been Modified, Deleted,
    // Renamed or Type-changed. Deliberately excludes A(dded) and untracked files —
    // nothing targets those, `git checkout --` cannot revert them anyway, and demanding a
    // pristine tree would make the runner unusable in the very commit that introduces it.
    // Hand-parsing `git status --porcelain` codes got this wrong: a new file that is
    // staged and then edited reads as `AM` and was rejected.
    [, $out] = mut_run('git diff --name-only --diff-filter=MDRT HEAD', $repo);

    return '' === trim($out);
}

/** Is this specific path free of uncommitted modifications? */
function mut_target_is_pristine(string $repo, string $target): bool
{
    [$rc] = mut_run('git diff --quiet -- ' . escapeshellarg($target) . ' && git diff --cached --quiet -- ' . escapeshellarg($target), $repo);

    return 0 === $rc;
}

/**
 * Make the target's mtime unrepeatable, so no mtime-keyed cache can answer for it twice.
 *
 * Called at both seams where the target's CONTENT changes while its (mtime, size) need not:
 * after the apply and after the revert. A strictly increasing stamp rather than a bare
 * touch(), because the defect IS two writes landing in the same wall-clock second —
 * touching to "now" reproduces it rather than fixing it whenever the cycle is sub-second.
 */
function mut_break_cache_key(string $repo, string $target): void
{
    static $tick = 0;
    $path = $repo . '/' . $target;
    if (is_file($path)) {
        touch($path, time() + ++$tick);
        clearstatcache(true, $path);
    }
}

/**
 * Apply one mutation and classify it. Always leaves the tree as it found it.
 *
 * @return array{verdict:string,detail:string}
 */
function mut_evaluate(string $repo, array $spec): array
{
    // The parser refuses an empty `expect:` in registry files (tests/lib/mutations.php),
    // and the evaluator must refuse what the parser refuses: a spec reaching the check
    // below without one turns "gate red for ANY reason" into KILLED — the ADR-E8
    // after-side hole. Refused explicitly rather than left to strpos(), whose
    // empty-needle behaviour diverges between the 7.4 floor (false, plus a warning)
    // and 8.0+ (0) — the divergence that read this selftest's KILLED fixture as
    // INVALID in CI while it passed locally on 8.2.
    if ('' === $spec['expect']) {
        return ['verdict' => INVALID, 'detail' => 'empty expect: — the evaluator refuses what the parser refuses'];
    }

    // 0. The target must be pristine, or the revert below would discard a real edit and
    //    the "before" measurement would be of somebody's work in progress.
    if (!mut_target_is_pristine($repo, $spec['target'])) {
        return ['verdict' => INVALID, 'detail' => 'target has uncommitted changes: ' . $spec['target']];
    }

    // 1. ADR-E8: the gate must pass BEFORE the mutation, or nothing after it means anything.
    [$rc_clean] = mut_run($spec['gate'], $repo);
    if (0 !== $rc_clean) {
        return ['verdict' => INVALID, 'detail' => 'gate was already failing on the clean tree (exit ' . $rc_clean . ')'];
    }

    // 2. Apply.
    $patch = tempnam(sys_get_temp_dir(), 'mut');
    file_put_contents($patch, $spec['diff']);
    [$rc_apply, $apply_out] = mut_run('git apply ' . escapeshellarg($patch), $repo);
    unlink($patch);

    if (0 !== $rc_apply) {
        return ['verdict' => INVALID, 'detail' => 'diff did not apply: ' . trim($apply_out)];
    }
    mut_break_cache_key($repo, $spec['target']);

    // 3. Classify.
    [$rc_mutated, $out_mutated] = mut_run($spec['gate'], $repo);

    // 3a. ADR-E8, SURVIVED side — the half that had no control, which is where this
    //     defect lived. The before-side is guarded (the gate must be green on the clean
    //     tree) and the KILLED side is guarded (`expect:`), but SURVIVED — the verdict
    //     that ACCUSES WORKING CODE of not pinning what it claims — was taken on trust.
    //
    //         A gate passing under a mutation is only evidence when the gate
    //         demonstrably read the mutated tree.
    //
    //     So break the cache key again, with the mutated bytes untouched, and re-run. A
    //     verdict that changes when only the cache key changed was never about the code.
    //     mtime rather than a content edit — an appended newline would perturb more —
    //     because gates in this registry checksum their targets, and that would turn a
    //     real SURVIVED into a false INVALID. Costs nothing on a healthy all-KILLED
    //     registry: it runs only for a verdict that is about to be believed.
    $unread = '';
    if (0 === $rc_mutated) {
        mut_break_cache_key($repo, $spec['target']);
        [$rc_recheck] = mut_run($spec['gate'], $repo);
        if (0 !== $rc_recheck) {
            $unread = 'gate passed, then failed (exit ' . $rc_recheck . ') when the target cache key '
                . 'changed with its bytes untouched — the passing run was not reading the mutated source';
        }
    }

    // 3b. Revert unconditionally.
    mut_run('git checkout -- ' . escapeshellarg($spec['target']), $repo);
    mut_break_cache_key($repo, $spec['target']);

    // 4. Refuse to continue with a dirty tree — a leaked mutation would silently
    //    contaminate every mutation after this one.
    if (!mut_tree_is_clean($repo)) {
        fwrite(STDERR, "VERDICT: ABORTED — tree still dirty after reverting {$spec['target']}. Stopping so the next mutation is not measured against a mutated tree.\n");
        exit(2);
    }

    if (0 === $rc_mutated) {
        if ('' !== $unread) {
            return ['verdict' => INVALID, 'detail' => $unread];
        }

        return ['verdict' => SURVIVED, 'detail' => 'gate still passed, and still passed with the target cache key broken — the assertion does not pin this'];
    }

    // ADR-E8, after-side. Non-zero is NOT enough. These gates hold up to seven assertions,
    // so a mutation can fail one it never touched and still read as KILLED — which is the
    // same "the gate returns non-zero regardless" defect that made a whole run false once,
    // moved from before the mutation to after it. `expect:` is what makes a verdict be
    // about a specific assertion rather than about the exit code.
    if (false === strpos($out_mutated, $spec['expect'])) {
        return ['verdict' => INVALID, 'detail' => 'gate failed, but not with the expected assertion: ' . $spec['expect']];
    }

    return ['verdict' => KILLED, 'detail' => 'killed by the expected assertion (exit ' . $rc_mutated . ')'];
}

// ---------------------------------------------------------------------------
// --selftest: prove the runner can tell the three verdicts apart.
// A runner that cannot distinguish INVALID from KILLED is the exact failure this
// tool exists to prevent, so it must demonstrate the distinction on fixtures.
// ---------------------------------------------------------------------------
if (in_array('--selftest', $argv, true)) {
    $tmp = sys_get_temp_dir() . '/mut-selftest-' . getmypid();
    mkdir($tmp);
    mut_run('git init -q . && git config user.email t@t && git config user.name t', $tmp);
    file_put_contents($tmp . '/subject.php', "<?php\nfunction guarded() { return 'GUARD'; }\n");
    // Fixture gates are `grep` and `false` rather than PHP scripts: mut_run() observes
    // nothing but the exit code and the output, so the fixture's implementation language is
    // not part of what the selftest proves — and six PHP interpreter spawns measured 294 ms
    // of the 544 ms selftest, inside a PR-blocking gate. grep is ~13x cheaper.
    //
    // observes.sh is a fixture gate that PASSES its first two invocations and fails the
    // third. The cache-key case below needs a gate whose verdict changes on re-run while
    // the subject's bytes do not — the observable signature of a gate answering from a
    // stale artifact instead of from the tree. A counter, not a real .pyc, because the
    // bytecode race needs the apply to land in the same wall-clock second as the previous
    // compile, and no fixture can force that: `git apply` stamps mtime with "now". The
    // counter reproduces the SIGNATURE deterministically, which is what the control keys on.
    file_put_contents(
        $tmp . '/observes.sh',
        'n=$(cat .n 2>/dev/null || echo 0)' . "\n"
        . 'n=$((n + 1))' . "\n"
        . 'echo $n > .n' . "\n"
        . 'test $n -lt 3' . "\n"
    );
    mut_run('git add -A && git commit -q -m init', $tmp);

    $removes_guard = "--- a/subject.php\n+++ b/subject.php\n@@ -1,2 +1,2 @@\n <?php\n-function guarded() { return 'GUARD'; }\n+function guarded() { return 'NOPE'; }\n";
    $touches_only_comment = "--- a/subject.php\n+++ b/subject.php\n@@ -1,2 +1,2 @@\n <?php\n-function guarded() { return 'GUARD'; }\n+function guarded() { return 'GUARD'; } // still here\n";

    // `0` is a REAL expect — it is what `grep -c` prints once the construct is gone — so
    // the KILLED fixture exercises the expect-match branch: the one whose empty-needle
    // strpos shortcut diverged between PHP 7.4 and 8.0 and failed this selftest in CI.
    $cases = [
        ['expect' => KILLED,   'label' => 'a real gate notices the construct disappearing',
         'spec' => ['target' => 'subject.php', 'gate' => 'grep -c GUARD subject.php', 'kind' => 'construct-removal', 'expect' => '0', 'rationale' => 'x', 'diff' => $removes_guard]],
        ['expect' => SURVIVED, 'label' => 'a gate that only checks a NAME does not notice a comment-only change',
         'spec' => ['target' => 'subject.php', 'gate' => 'grep -c GUARD subject.php', 'kind' => 'name-only', 'expect' => '0', 'rationale' => 'x', 'diff' => $touches_only_comment]],
        ['expect' => INVALID,  'label' => 'an already-red gate is INVALID, never KILLED',
         'spec' => ['target' => 'subject.php', 'gate' => 'false', 'kind' => 'construct-removal', 'expect' => '0', 'rationale' => 'x', 'diff' => $removes_guard]],
        ['expect' => INVALID,  'label' => 'a gate that fails for the WRONG reason is INVALID, never KILLED',
         'spec' => ['target' => 'subject.php', 'gate' => 'grep -c GUARD subject.php', 'kind' => 'construct-removal',
                    'expect' => 'a string this gate never prints', 'rationale' => 'x', 'diff' => $removes_guard]],
        ['expect' => INVALID,  'label' => 'a stale diff is INVALID, never SURVIVED',
         'spec' => ['target' => 'subject.php', 'gate' => 'grep -c GUARD subject.php', 'kind' => 'construct-removal', 'expect' => '0', 'rationale' => 'x',
                    'diff' => "--- a/subject.php\n+++ b/subject.php\n@@ -1,2 +1,2 @@\n <?php\n-function absent() { return 'X'; }\n+function absent() { return 'Y'; }\n"]],
        // Required-red for the cache-key guard. Without the SURVIVED-side control this
        // case reports SURVIVED and the selftest fails — which is the point: the guard
        // that stops a false SURVIVED must itself be provable, or it is one more
        // assertion nobody has watched fail.
        ['expect' => INVALID,  'label' => 'a gate that stops passing when only the cache key changes is INVALID, never SURVIVED',
         'spec' => ['target' => 'subject.php', 'gate' => 'sh observes.sh', 'kind' => 'name-only',
                    'expect' => 'never reached on this path', 'rationale' => 'x', 'diff' => $touches_only_comment]],
        ['expect' => INVALID,  'label' => 'an empty expect is refused, never evaluated',
         'spec' => ['target' => 'subject.php', 'gate' => 'grep -c GUARD subject.php', 'kind' => 'construct-removal', 'expect' => '', 'rationale' => 'x', 'diff' => $removes_guard]],
    ];

    echo "CONTROLS\n";
    $ok = true;
    foreach ($cases as $case) {
        $got = mut_evaluate($tmp, $case['spec'])['verdict'];
        $pass = $got === $case['expect'];
        $ok   = $ok && $pass;
        printf("  [%s] %s (expected %s, got %s)\n", $pass ? 'PASS' : 'FAIL', $case['label'], $case['expect'], $got);
    }
    // Direct control for mut_break_cache_key() itself. The cases above all run through
    // mut_evaluate(), and none of them can exercise the one property that separates this
    // helper from a bare touch(): that two calls INSIDE THE SAME WALL-CLOCK SECOND still
    // produce different stamps. That single second IS the defect — the apply landing in
    // the same second as the previous compile — so it is asserted rather than assumed.
    // Required-red both ways: `touch($path)` with no stamp fails it, and so does a helper
    // that returns early. An end-to-end fixture cannot cover this, because forcing the
    // collision would mean predicting the mtime `git apply` is about to write.
    $probe = $tmp . '/probe.txt';
    file_put_contents($probe, 'x');
    mut_break_cache_key($tmp, 'probe.txt');
    clearstatcache(true, $probe);
    $m1 = filemtime($probe);
    mut_break_cache_key($tmp, 'probe.txt');
    clearstatcache(true, $probe);
    $m2 = filemtime($probe);
    $bump = ($m2 > $m1);
    $ok   = $ok && $bump;
    printf("  [%s] two cache-key breaks in the same second still differ (%d then %d)\n", $bump ? 'PASS' : 'FAIL', $m1, $m2);

    mut_run('rm -rf ' . escapeshellarg($tmp), sys_get_temp_dir());

    echo "\nVERDICT: " . ($ok ? "SELFTEST PASS\n" : "SELFTEST FAIL\n");
    exit($ok ? 0 : 1);
}

// ---------------------------------------------------------------------------
// Normal run
// ---------------------------------------------------------------------------
$repo    = dirname(__DIR__, 3);
$filter  = '';
$changed = '';
foreach ($argv as $arg) {
    if (0 === strpos($arg, '--filter=')) {
        $filter = substr($arg, 9);
    }
    if (0 === strpos($arg, '--changed=')) {
        $changed = substr($arg, 10);
    }
}

echo "CONTROLS\n";
$clean = mut_tree_is_clean($repo);
printf("  [%s] working tree is clean (a mutation cannot be told from an edit otherwise)\n", $clean ? 'PASS' : 'FAIL');
if (!$clean) {
    fwrite(STDERR, "\nVERDICT: ABORTED — commit or stash first.\n");
    exit(1);
}

$all   = slimstat_mutation_files($repo);
$files = $all;

if ('' !== $filter) {
    $files = array_values(array_filter($files, static function ($f) use ($filter) {
        return false !== strpos(basename($f), $filter);
    }));
}

// Per-PR blocking: only the mutations whose TARGET moved. A malformed file is deliberately
// KEPT in the set, because --changed must never be able to hide one.
if ('' !== $changed) {
    [$rc_diff, $diff_out] = mut_run('git diff --name-only ' . escapeshellarg($changed) . '...HEAD', $repo);
    if (0 !== $rc_diff) {
        fwrite(STDERR, "VERDICT: ABORTED — cannot diff against {$changed} (shallow clone? needs fetch-depth: 0)\n");
        exit(1);
    }
    $touched = array_flip(array_filter(explode("\n", trim($diff_out))));
    $files   = array_values(array_filter($files, static function ($f) use ($touched) {
        $parsed = slimstat_mutation_parse($f);
        return $parsed['problems'] || isset($touched[$parsed['spec']['target']]);
    }));
}

$partial = ('' !== $filter || '' !== $changed);
printf("  [%s] found %d mutation file(s)\n", $files ? 'PASS' : 'FAIL', count($files));
if (!$files) {
    fwrite(STDERR, "\nVERDICT: ABORTED — no mutations matched.\n");
    exit(1);
}
echo "\n";

$results = [];
foreach ($files as $file) {
    $id     = basename($file, '.mutation');
    $parsed = slimstat_mutation_parse($file);

    if ($parsed['problems']) {
        $detail = 'malformed: ' . implode('; ', $parsed['problems']);
        $results[] = ['id' => $id, 'verdict' => INVALID, 'kind' => '?', 'target' => '?', 'detail' => $detail];
        printf("  %-9s %-42s %s\n", INVALID, $id, $detail);
        continue;
    }
    $spec = $parsed['spec'];

    $r = mut_evaluate($repo, $spec);
    $results[] = ['id' => $id, 'verdict' => $r['verdict'], 'kind' => $spec['kind'], 'target' => $spec['target'], 'gate' => $spec['gate'], 'rationale' => $spec['rationale'], 'detail' => $r['detail']];
    printf("  %-9s %-42s %s\n", $r['verdict'], $id, $r['detail']);
}

$counts = array_count_values(array_column($results, 'verdict'));
$killed = $counts[KILLED] ?? 0;

[, $sha] = mut_run('git rev-parse --short HEAD', $repo);
if (!$partial) {
    // One results directory, the one VERIFICATION-PROTOCOL.md names — the sibling arm
    // already writes there. A partial run writes nothing: its counts are not the
    // registry's counts, and a file that looks like a full run but is not is worse than
    // no file.
    $out_dir = $repo . '/tests/verify/results';
    @mkdir($out_dir, 0755, true);
    $stamp = getenv('MUTATION_RUN_STAMP') ?: gmdate('Ymd-His');
    file_put_contents(
        $out_dir . '/mutations-' . $stamp . '-' . trim($sha) . '.json',
        json_encode(['sha' => trim($sha), 'counts' => $counts, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );
}

printf("\n%d KILLED · %d SURVIVED · %d INVALID\n", $killed, $counts[SURVIVED] ?? 0, $counts[INVALID] ?? 0);

// The floor ratchets upward, so deleting a mutation fails the build. A registry that can
// shrink silently is a registry that shrinks.
$floor = slimstat_mutation_floor($repo) ?? 0;
printf("floor: %d (registry holds %d)\n", $floor, count($all));

// The floor is a claim about the REGISTRY, not about this run's kill count. Comparing it to
// $killed made every filtered run fail — `--filter=D24` kills 1, reads 1 < 3, and reports
// "a mutation was deleted" when none was.
if (!$partial && count($all) < $floor) {
    fwrite(STDERR, "\nVERDICT: FAIL — registry holds " . count($all) . ", floor is {$floor}. A mutation was deleted.\n");
    exit(1);
}
if (($counts[SURVIVED] ?? 0) > 0 || ($counts[INVALID] ?? 0) > 0) {
    fwrite(STDERR, "\nVERDICT: FAIL — every registered mutation must be KILLED. A SURVIVED mutation names an assertion that does not work; an INVALID one names a gate or a diff that does.\n");
    exit(1);
}

echo "VERDICT: PASS — every registered mutation is killed by its gate\n";
