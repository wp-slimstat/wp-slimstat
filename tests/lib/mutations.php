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

    return ['spec' => $spec, 'problems' => $problems];
}
