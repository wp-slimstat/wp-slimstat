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

const KILLED   = 'KILLED';
const SURVIVED = 'SURVIVED';
const INVALID  = 'INVALID';

/** @return array{0:int,1:string} [exit code, combined output] */
function mut_run(string $cmd, string $cwd): array
{
    $out = [];
    $rc  = 0;
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
 * Apply one mutation and classify it. Always leaves the tree as it found it.
 *
 * @return array{verdict:string,detail:string}
 */
function mut_evaluate(string $repo, array $spec): array
{
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

    // 3. Classify, then revert unconditionally.
    [$rc_mutated, $out_mutated] = mut_run($spec['gate'], $repo);
    mut_run('git checkout -- ' . escapeshellarg($spec['target']), $repo);

    // 4. Refuse to continue with a dirty tree — a leaked mutation would silently
    //    contaminate every mutation after this one.
    if (!mut_tree_is_clean($repo)) {
        fwrite(STDERR, "VERDICT: ABORTED — tree still dirty after reverting {$spec['target']}. Stopping so the next mutation is not measured against a mutated tree.\n");
        exit(2);
    }

    if (0 === $rc_mutated) {
        return ['verdict' => SURVIVED, 'detail' => 'gate still passed — the assertion does not pin this'];
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
    mut_run('git add -A && git commit -q -m init', $tmp);

    $removes_guard = "--- a/subject.php\n+++ b/subject.php\n@@ -1,2 +1,2 @@\n <?php\n-function guarded() { return 'GUARD'; }\n+function guarded() { return 'NOPE'; }\n";
    $touches_only_comment = "--- a/subject.php\n+++ b/subject.php\n@@ -1,2 +1,2 @@\n <?php\n-function guarded() { return 'GUARD'; }\n+function guarded() { return 'GUARD'; } // still here\n";

    $cases = [
        ['expect' => KILLED,   'label' => 'a real gate notices the construct disappearing',
         'spec' => ['target' => 'subject.php', 'gate' => 'grep -c GUARD subject.php', 'kind' => 'construct-removal', 'expect' => '', 'rationale' => 'x', 'diff' => $removes_guard]],
        ['expect' => SURVIVED, 'label' => 'a gate that only checks a NAME does not notice a comment-only change',
         'spec' => ['target' => 'subject.php', 'gate' => 'grep -c GUARD subject.php', 'kind' => 'name-only', 'expect' => '', 'rationale' => 'x', 'diff' => $touches_only_comment]],
        ['expect' => INVALID,  'label' => 'an already-red gate is INVALID, never KILLED',
         'spec' => ['target' => 'subject.php', 'gate' => 'false', 'kind' => 'construct-removal', 'expect' => '', 'rationale' => 'x', 'diff' => $removes_guard]],
        ['expect' => INVALID,  'label' => 'a gate that fails for the WRONG reason is INVALID, never KILLED',
         'spec' => ['target' => 'subject.php', 'gate' => 'grep -c GUARD subject.php', 'kind' => 'construct-removal',
                    'expect' => 'a string this gate never prints', 'rationale' => 'x', 'diff' => $removes_guard]],
        ['expect' => INVALID,  'label' => 'a stale diff is INVALID, never SURVIVED',
         'spec' => ['target' => 'subject.php', 'gate' => 'grep -c GUARD subject.php', 'kind' => 'construct-removal', 'expect' => '', 'rationale' => 'x',
                    'diff' => "--- a/subject.php\n+++ b/subject.php\n@@ -1,2 +1,2 @@\n <?php\n-function absent() { return 'X'; }\n+function absent() { return 'Y'; }\n"]],
    ];

    echo "CONTROLS\n";
    $ok = true;
    foreach ($cases as $case) {
        $got = mut_evaluate($tmp, $case['spec'])['verdict'];
        $pass = $got === $case['expect'];
        $ok   = $ok && $pass;
        printf("  [%s] %s (expected %s, got %s)\n", $pass ? 'PASS' : 'FAIL', $case['label'], $case['expect'], $got);
    }
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
