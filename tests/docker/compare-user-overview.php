<?php
/**
 * compare-user-overview.php <mode> <a.json> <b.json>
 *
 * The mechanical adjudicator for measure-f6-useroverview.sh's arms — keyed comparison,
 * never positional, of two probe-user-overview.php answers.
 *
 *   mode `parity` — a and b are the SAME state from the before/after arms (states
 *     `local` and `samehost`): every user's every field must be identical, or the split
 *     changed an answer it promised not to change.
 *   mode `fix` — a is the BROKEN state (before/separate), b is the after arm's answer
 *     for it, and a REFERENCE answer comes via SLIMSTAT_UO_REFERENCE (the before arm's
 *     `samehost` answer — the working join's own output over the identical corpus,
 *     itself verified against the hand-computed oracle). PASS means: a was genuinely
 *     broken (zero rows + a database error), and b equals the reference.
 *
 * ONE declared normalisation, stated here rather than discovered in a diff:
 * `user_registered` is stamped by each cell's `wp user create` at install wall-clock,
 * so its VALUE differs across cells by construction. Across arms it is compared for
 * shape (a real MySQL datetime) only; within an arm it still flows through untouched.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    exit(1);
}

if ($argc < 3) {
    fwrite(STDERR, "usage: compare-user-overview.php <parity|fix> <a.json> <b.json>\n");
    exit(2);
}

[, $mode, $file_a, $file_b] = $argv;

function uo_load(string $f): array
{
    $j = json_decode((string) @file_get_contents($f), true);
    if (!is_array($j) || !isset($j['rows_by_username'])) {
        fwrite(STDERR, "cannot read a probe answer from $f\n");
        exit(2);
    }
    return $j;
}

function uo_rows_diff(array $a, array $b, string $label_a, string $label_b): array
{
    $diffs = [];
    $datetime = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';
    foreach ([[$a, $b, $label_b], [$b, $a, $label_a]] as [$x, $y, $other]) {
        foreach ($x['rows_by_username'] as $user => $_) {
            if (!isset($y['rows_by_username'][$user])) {
                $diffs[] = "user '$user' is missing from $other";
            }
        }
    }
    foreach ($a['rows_by_username'] as $user => $row_a) {
        if (!isset($b['rows_by_username'][$user])) {
            continue;
        }
        $row_b = $b['rows_by_username'][$user];
        foreach ($row_a as $field => $va) {
            $vb = $row_b[$field] ?? null;
            if ('user_registered' === $field) {
                if (!preg_match($datetime, (string) $va) || !preg_match($datetime, (string) $vb)) {
                    $diffs[] = "user '$user': user_registered is not a datetime ($label_a='$va', $label_b='$vb')";
                }
                continue; // value is install wall-clock — normalised across cells, see header
            }
            if ($va !== $vb) {
                $diffs[] = sprintf("user '%s' field %s: %s=%s vs %s=%s",
                    $user, $field, $label_a, var_export($va, true), $label_b, var_export($vb, true));
            }
        }
    }
    return $diffs;
}

$a = uo_load($file_a);
$b = uo_load($file_b);

echo "CONTROLS:\n";
printf("  a: %s (rows=%d, analytics_err=%s)\n", basename($file_a), $a['row_count'],
    '' === $a['analytics_last_err'] ? 'none' : substr($a['analytics_last_err'], 0, 60));
printf("  b: %s (rows=%d, analytics_err=%s)\n", basename($file_b), $b['row_count'],
    '' === $b['analytics_last_err'] ? 'none' : substr($b['analytics_last_err'], 0, 60));
printf("  queries a: core=%d analytics=%d · queries b: core=%d analytics=%d\n",
    $a['queries']['core_handle'], $a['queries']['analytics_handle'],
    $b['queries']['core_handle'], $b['queries']['analytics_handle']);

$failures = [];

if ('parity' === $mode) {
    $failures = uo_rows_diff($a, $b, 'before', 'after');
    if ($a['row_count'] !== $b['row_count']) {
        $failures[] = "row_count moved: {$a['row_count']} → {$b['row_count']}";
    }
    if ($a['sort_order'] !== $b['sort_order']) {
        // Ties under the default last_login sort are not ordered by contract; report,
        // never fail, but say it out loud so a real ordering change cannot hide here.
        printf("  NOTE: sort_order differs (a: %s · b: %s) — adjudicate against the sort key\n",
            implode(',', $a['sort_order']), implode(',', $b['sort_order']));
    }
} elseif ('fix' === $mode) {
    if (0 !== $a['row_count']) {
        $failures[] = "the 'broken' arm has {$a['row_count']} rows — it was not broken, so this run proves nothing";
    }
    if ('' === $a['analytics_last_err']) {
        $failures[] = "the 'broken' arm recorded no database error — the breakage did not happen the way F6 says";
    }
    $ref_file = getenv('SLIMSTAT_UO_REFERENCE') ?: '';
    if ('' === $ref_file) {
        $failures[] = 'fix mode needs SLIMSTAT_UO_REFERENCE (the working join\'s answer over the same corpus)';
    } else {
        $ref = uo_load($ref_file);
        printf("  reference: %s (rows=%d)\n", basename($ref_file), $ref['row_count']);
        $failures = array_merge($failures, uo_rows_diff($ref, $b, 'reference', 'after'));
    }
} else {
    fwrite(STDERR, "unknown mode '$mode'\n");
    exit(2);
}

echo "\n";
if ([] !== $failures) {
    fwrite(STDERR, "VERDICT: FAIL ($mode) — " . count($failures) . " difference(s)\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}
printf("VERDICT: PASS (%s) — %d user(s) compared field-by-field, keyed by username\n",
    $mode, count($a['rows_by_username']) ?: count($b['rows_by_username']));
exit(0);
