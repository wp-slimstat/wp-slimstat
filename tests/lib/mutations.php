<?php
/**
 * The .mutation file format — one parser, because two parsers are two formats.
 *
 * The runner and the PR gate both read these files and had already drifted before either
 * landed: the gate rejected an empty diff, the runner accepted one. An empty diff applies
 * as a no-op, the gate stays green, and the runner reports SURVIVED — the one verdict that
 * accuses working code of not pinning what it claims. A format whose two readers disagree
 * cannot say which of them is right.
 *
 * 7.4-safe: plain functions, no autoloader, no WordPress.
 */

declare(strict_types=1);

/**
 * Headers every .mutation file must carry.
 *
 * `expect` is not decoration. A gate holds many assertions — live-window-clamp holds seven —
 * so "exit code was non-zero" does not say WHICH one fired. Measured: the D62 name-only
 * mutation trips two of that gate's checks, and only one is the call-site count it claims to
 * prove. Delete that one and D62 still reports KILLED off the other. That is exactly the
 * ADR-E8 failure — a gate returning non-zero regardless of the mutation — moved from the
 * before-side to the after-side, where nothing was guarding it.
 */
const SLIMSTAT_MUTATION_HEADERS = ['target', 'gate', 'kind', 'expect', 'rationale'];

/**
 * Prove the hunk-arithmetic check can FAIL — and that it stays silent on legal shapes.
 *
 * A registry of well-formed files is exactly what a check that does nothing also produces, so
 * "PASS 49/floor 49" is not evidence about any check inside this parser. PITFALLS 64 is that
 * shape one level out: a guard announced in prose and never written.
 *
 * It lives HERE, beside the check it proves, and not in either registry test, because this file
 * is a byte-twin gated by jaan-to/bin/check-twins.sh while mutation-registry-test.php is
 * deliberately exempt from that gate (it carries four real per-repo divergences). A control that
 * is identical by nature but hand-copied into the exempt file is an ungated island inside the one
 * place drift cannot be seen — the exact failure this programme keeps recording. One edit here
 * covers both repos, and the twin gate enforces it.
 *
 * The three must-NOT-fire cases are not padding: each is a legal unified-diff shape that
 * RESEMBLES a lie, and without them a check that fired on everything would still pass.
 *
 * @return string[] problems; empty means the check behaves in both directions
 */
function slimstat_mutation_parse_selftest(): array
{
    $cases = [
        // [label, hunk, must this fire?]
        ['an honest header',       "@@ -1,2 +1,2 @@\n ctx\n-old\n+new\n",                         false],
        ['a header understating',  "@@ -1,1 +1,1 @@\n ctx\n-old\n+new\n",                         true],
        ['a header overstating',   "@@ -1,9 +1,9 @@\n ctx\n-old\n+new\n",                         true],
        ['an absent count (== 1)', "@@ -1 +1 @@\n-old\n+new\n",                                   false],
        ['a no-newline marker',    "@@ -1,1 +1,1 @@\n-old\n\\ No newline at end of file\n+new\n", false],
    ];

    $problems = [];
    $tmp      = tempnam(sys_get_temp_dir(), 'slimstat-hunk-');
    foreach ($cases as [$label, $hunk, $should_fire]) {
        file_put_contents(
            $tmp,
            "target:    x\ngate:      x\nkind:      x\nexpect:    x\nrationale: x\n---\n"
            . "diff --git a/x b/x\n--- a/x\n+++ b/x\n" . $hunk
        );
        // Substring, not count: the stub headers are `x`, so other checks may also speak for the
        // same synthetic file. This isolates the one under test.
        $fired = false !== strpos(implode("\n", slimstat_mutation_parse($tmp)['problems']), 'hunk header');
        if ($fired !== $should_fire) {
            $problems[] = $should_fire
                ? "the hunk-arithmetic check did NOT fire on {$label} — it cannot catch what it exists for"
                : "the hunk-arithmetic check fired on {$label}, which is a LEGAL unified-diff shape";
        }
    }
    @unlink($tmp);

    return $problems;
}

/**
 * Every registered mutation file, sorted so a failure list is stable between machines.
 *
 * @return string[]
 */
function slimstat_mutation_files(string $plugin_root): array
{
    $files = glob($plugin_root . '/tests/mutations/*.mutation') ?: [];
    sort($files);

    return $files;
}

/** The anti-deletion ratchet, or null when FLOOR is absent — which is itself a failure. */
function slimstat_mutation_floor(string $plugin_root, string $file = 'FLOOR'): ?int
{
    $path = $plugin_root . '/tests/mutations/' . $file;

    return is_readable($path) ? (int) trim((string) file_get_contents($path)) : null;
}

/**
 * Parse one .mutation file into its headers plus 'diff'.
 *
 * Returns PROBLEMS rather than null: the PR gate reports which header is missing, the runner
 * only needs to know that one is, and a bool cannot be turned back into a message. Handing
 * back the detail is what lets one parser serve both.
 *
 * @return array{spec:array<string,string>,problems:string[]}
 */
function slimstat_mutation_parse(string $path): array
{
    $raw = (string) file_get_contents($path);
    $cut = strpos($raw, "\n---\n");

    if (false === $cut) {
        return ['spec' => [], 'problems' => ['no `---` separating the headers from the diff']];
    }

    $spec = [];
    foreach (explode("\n", substr($raw, 0, $cut)) as $line) {
        if (preg_match('/^(\w[\w-]*):\s*(.*)$/', trim($line), $m)) {
            $spec[strtolower($m[1])] = trim($m[2]);
        }
    }
    $spec['diff'] = substr($raw, $cut + 5);

    $problems = [];
    foreach (SLIMSTAT_MUTATION_HEADERS as $header) {
        if (!isset($spec[$header]) || '' === $spec[$header]) {
            $problems[] = "missing or empty `{$header}:` header";
        }
    }
    if ('' === trim($spec['diff'])) {
        $problems[] = 'the diff is empty — it applies as a no-op, the gate stays green, and a '
            . 'working assertion gets reported SURVIVED';
    }

    // A blank CONTEXT line in a unified diff is a single space; the empty spelling is what
    // `git apply` calls a corrupt patch, and it has now produced INVALID runs on two separate
    // dates (see tests/verify/results/). INVALID at run time proves nothing about the gate —
    // this catches the malformed file at registry-test time instead, naming the line. The
    // final terminating newline is not a diff line, hence the rtrim.
    foreach (explode("\n", rtrim($spec['diff'], "\n")) as $i => $line) {
        if ('' === $line) {
            $problems[] = sprintf(
                'diff line %d is a truly-empty line — a blank context line must be a single '
                . 'SPACE, or `git apply` rejects the patch as corrupt and the mutation reports '
                . 'INVALID (which proves nothing in either direction)',
                $i + 1
            );
        }
    }

    // A hunk header STATES its own body size: `@@ -a,b +c,d @@` promises b lines on the old side
    // (context + removed) and d on the new (context + added); an omitted count means 1. `git
    // apply` re-derives both from the body and applies a contradicting hunk anyway, so a header
    // that lies is invisible to every consumer — which is how a fabricated trailing context line
    // reached five registry files by copy before anything noticed.
    //
    // Asking this here does NOT reintroduce the second-parser problem the delegation to
    // `git apply --check` exists to avoid. That check answers "does this apply to the tree?";
    // this one answers "does this file contradict itself?" — a question about the artifact alone,
    // whose answer cannot drift as the tree moves.
    $lines = explode("\n", $spec['diff']);
    foreach ($lines as $i => $line) {
        if (!preg_match('/^@@ -\d+(?:,(\d+))? \+\d+(?:,(\d+))? @@/', $line, $m)) {
            continue;
        }
        // An absent count is the unified-diff spelling of 1, not of 0.
        $want_old = ('' === ($m[1] ?? '')) ? 1 : (int) $m[1];
        $want_new = ('' === ($m[2] ?? '')) ? 1 : (int) $m[2];

        $old = $new = 0;
        foreach (array_slice($lines, $i + 1) as $body) {
            // '' is tested FIRST: indexing [0] on an empty string warns on PHP 8. It also ends
            // the diff — a blank CONTEXT line is a single space, enforced by the loop above.
            if ('' === $body || '@' === $body[0] || 0 === strpos($body, 'diff ')) {
                break;
            }
            switch ($body[0]) {
                case '-':
                    $old++;
                    break;
                case '+':
                    $new++;
                    break;
                case '\\':
                    break;                  // "\ No newline at end of file" belongs to neither side
                default:
                    $old++;
                    $new++;                 // context counts on both sides
            }
        }

        if ($old !== $want_old || $new !== $want_new) {
            $problems[] = sprintf(
                'the hunk header on diff line %d declares -%d,+%d but its body holds -%d,+%d. '
                . '`git apply` re-derives the counts and applies it regardless, so nothing '
                . 'downstream can see this — regenerate the file with `git diff -U3` instead of '
                . 'hand-editing the header.',
                $i + 1,
                $want_old,
                $want_new,
                $old,
                $new
            );
        }
    }

    return ['spec' => $spec, 'problems' => $problems];
}

/**
 * gate: value => how many .mutation files name it.
 *
 * The walk over slimstat_mutation_files() + slimstat_mutation_parse() to aggregate `gate:` had
 * been written three times across the two repos, aggregating three different ways — count in
 * one, presence in another — which is precisely the drift this byte-twinned file exists to stop.
 * A private `gate:` regex would be worse still: it would count headers differently from the
 * parser that owns the format.
 *
 * @return array<string, int>
 */
function slimstat_mutation_gate_counts(string $plugin_root): array
{
    $counts = [];
    foreach (slimstat_mutation_files($plugin_root) as $file) {
        $gate = slimstat_mutation_parse($file)['spec']['gate'] ?? '';
        if ('' !== $gate) {
            $counts[$gate] = ($counts[$gate] ?? 0) + 1;
        }
    }

    return $counts;
}
