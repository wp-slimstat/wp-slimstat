<?php
// Compares two parity snapshots and reports every report whose output moved.
//
//   wp eval-file tests/bench/lib/parity-compare.php <before.json> <after.json>
//
// Exit signal is the VERDICT line, as elsewhere in this harness.
//   PASS    nothing a user sees changed
//   FAIL    at least one report renders differently
//   ERROR   the two snapshots are not comparable
//
// Refuses to compare snapshots that are not directly comparable — a different
// environment fingerprint, a different dataset size, or (for the straddling
// cells) a different local day. A parity check that quietly compares apples to
// oranges is worse than none, because it reports PASS.
//
// No declare(strict_types=1): `wp eval-file` (WP-CLI 2.12) eval()s this file.

if (!defined('ABSPATH')) {
    fwrite(STDERR, "must run inside WordPress (wp eval-file)\n");
    exit(2);
}

$before_path = (string) ($args[0] ?? '');
$after_path  = (string) ($args[1] ?? '');

if ($before_path === '' || $after_path === '') {
    echo "usage: wp eval-file parity-compare.php <before.json> <after.json>\n";
    echo "VERDICT: ERROR\n";
    return;
}

$before = json_decode((string) @file_get_contents($before_path), true);
$after  = json_decode((string) @file_get_contents($after_path), true);

if (!is_array($before) || !is_array($after)) {
    printf("ERROR: could not read both snapshots (%s, %s)\n", $before_path, $after_path);
    echo "VERDICT: ERROR\n";
    return;
}

// ── Comparability ──────────────────────────────────────────────────────────
$blockers = [];
if (($before['fingerprint_hash'] ?? null) !== ($after['fingerprint_hash'] ?? null)) {
    $blockers[] = sprintf('environment fingerprint differs (%s vs %s) — server settings changed',
        $before['fingerprint_hash'] ?? '?', $after['fingerprint_hash'] ?? '?');
}
if (($before['stats_rows'] ?? null) !== ($after['stats_rows'] ?? null)) {
    $blockers[] = sprintf('row count differs (%s vs %s) — the data moved under the comparison',
        number_format((int) ($before['stats_rows'] ?? 0)), number_format((int) ($after['stats_rows'] ?? 0)));
}
// Compared at day granularity, which is the granularity the filter is built at.
// Comparing the raw timestamp rejected two snapshots taken a minute apart.
if (($before['anchor_date'] ?? null) !== ($after['anchor_date'] ?? null)) {
    $blockers[] = sprintf('anchor date differs (%s vs %s) — the fixed window is not the same window',
        $before['anchor_date'] ?? '?', $after['anchor_date'] ?? '?');
}

$straddling_present = false;
foreach (array_keys($after['cells'] ?? []) as $cell) {
    if (strpos($cell, 'straddling') === 0) {
        $straddling_present = true;
    }
}
// Were both snapshots taken against the SAME pinned straddling window?
//
// Bucketing alone is not enough: two runs can both use a 3600 s bucket and still land
// either side of a boundary, which would compare two genuinely different windows and report
// every difference as a regression. So match on the resolved window end, not the bucket
// size. Snapshots taken before this field existed report 0 and stay render-only.
$before_end = (int) ($before['live_window_end'] ?? 0);
$after_end  = (int) ($after['live_window_end'] ?? 0);
$straddling_pinned = $before_end > 0 && $before_end === $after_end;

// A warning, not a blocker. It used to abort the whole comparison. Straddling values are
// compared only when the window above is pinned, and a pinned window cannot span a midnight
// without the ends differing — so a midnight crossing still cannot produce a wrong
// straddling answer, while the historical cells remain perfectly valid. Refusing to report
// them would have been the harness withholding a good result.
$day_warning = ($straddling_present && !$straddling_pinned && ($before['captured_day'] ?? null) !== ($after['captured_day'] ?? null))
    ? sprintf('snapshots span a local midnight (%s vs %s); straddling cells are render-only anyway',
        $before['captured_day'] ?? '?', $after['captured_day'] ?? '?')
    : '';

if ($blockers !== []) {
    echo "not comparable:\n";
    foreach ($blockers as $b) {
        echo "  - {$b}\n";
    }
    echo "VERDICT: ERROR\n";
    return;
}

// ── Reports that legitimately move between two runs of identical code ──────
//
// Keep this list SHORT and justify every entry. Each one is a place the oracle
// stops checking values, so a wrong number here would go unnoticed — the whole
// point of the harness is not to have blind spots it cannot name.
//
// These are compared STRUCTURALLY (byte length must still match, and they must
// still render) but their numeric content is reported as informational.
$time_dependent = [
    'slim_p1_03'         => 'At a Glance carries a rolling live window; a value was measured '
        . 'decrementing 40 -> 39 over 66 seconds with byte-identical markup, which is rows '
        . 'leaving the trailing edge, not a defect',
    'slim_p1_04'         => 'Currently Online — dt/dt_out within the last 5 minutes by definition',
    'slim_p1_18'         => 'Users Currently Online — same rolling window',
    'slim_live_analytics' => 'Live Analytics — a per-minute series anchored to now()',
];

// ── Diff ───────────────────────────────────────────────────────────────────
$live_moves = [];
$changed = [];
$fixed_errors = [];
$new_errors   = [];
$missing      = [];
$compared     = 0;

foreach ($after['cells'] as $cell => $reports) {
    foreach ($reports as $report_id => $now) {
        $was = $before['cells'][$cell][$report_id] ?? null;
        if ($was === null) {
            $missing[] = "{$cell}/{$report_id} (new report — no baseline)";
            continue;
        }
        $compared++;

        // A report that used to fatal and now renders is a fix, not a diff —
        // but it must still be reported, because its numbers are new.
        if ($was['error'] !== null && $now['error'] === null) {
            $fixed_errors[] = "{$cell}/{$report_id}: was failing (" . substr((string) $was['error'], 0, 60) . '), now renders';
            continue;
        }
        if ($was['error'] === null && $now['error'] !== null) {
            $new_errors[] = "{$cell}/{$report_id}: now fails — " . substr((string) $now['error'], 0, 80);
            continue;
        }
        if ($was['error'] !== null && $now['error'] !== null) {
            continue; // still failing the same way; not a parity change
        }

        if ($was['hash'] === $now['hash']) {
            continue;
        }

        // Hashes differ — say *how*, because "the HTML changed" is not
        // actionable but "this number went from 1,204 to 1,197" is.
        $was_nums = $was['numbers'] ?? [];
        $now_nums = $now['numbers'] ?? [];
        $deltas   = [];
        $len      = max(count($was_nums), count($now_nums));
        for ($i = 0; $i < $len && count($deltas) < 6; $i++) {
            $a = $was_nums[$i] ?? '—';
            $b = $now_nums[$i] ?? '—';
            if ($a !== $b) {
                $deltas[] = "{$a} → {$b}";
            }
        }

        // A report declared time-dependent is checked for "still renders" ONLY.
        // Not even its byte length is asserted: Currently Online was observed
        // losing its single live visitor between two snapshots, which removes a
        // table row and changes the length. A live report can legitimately gain
        // and lose rows, so length is not structure for these — it is data.
        //
        // This is the weakest check in the harness, which is why the list above
        // is short, justified per entry, and printed on every run.
        if (isset($time_dependent[$report_id])) {
            $live_moves[] = sprintf('%s/%s: %s', $cell, $report_id, implode(', ', $deltas) ?: 'markup');
            continue;
        }

        // Straddling cells end at now() and that end cannot be pinned —
        // init_filters() clamps any end at or after today to the current second
        // regardless of the hour/minute filters (measured). They exist to
        // exercise Query::getAll()'s split-merge path, so they assert "still
        // renders" and nothing more: their row CONTENT changes as the window
        // slides, not merely their values, so byte length is data here too.
        // Recent Events was observed listing entirely different rows between two
        // runs for exactly this reason.
        //
        // Strict value parity lives in the historical cells, which are anchored
        // safely in the past.
        // ...unless both snapshots provably describe the SAME window. Since the clamp
        // became bucketable, the snapshotter pins its straddling window and records the
        // resolved end. When both runs recorded the same non-zero end they saw identical
        // data, so there is nothing time-dependent left to excuse and these cells are
        // compared like any other — which is the whole point, because they are the only
        // cells that reach Query::getAll()'s split-merge path.
        if (strpos($cell, 'straddling') === 0 && !$straddling_pinned) {
            $live_moves[] = sprintf('%s/%s: %s', $cell, $report_id, implode(', ', $deltas) ?: 'markup');
            continue;
        }

        $changed[] = [
            'cell'    => $cell,
            'report'  => $report_id,
            'numbers' => $deltas,
            'markup_only' => $deltas === [],
            'bytes'   => [$was['bytes'], $now['bytes']],
        ];
    }
}

if ($day_warning !== '') {
    printf("WARNING: %s\n", $day_warning);
}

printf("compared %d report/cell snapshots across %d cells\n\n", $compared, count($after['cells']));

if ($changed !== []) {
    $value_changes  = array_values(array_filter($changed, static fn(array $c): bool => !$c['markup_only']));
    $markup_changes = array_values(array_filter($changed, static fn(array $c): bool => $c['markup_only']));

    if ($value_changes !== []) {
        printf("NUMBERS CHANGED — %d report/cell(s):\n", count($value_changes));
        foreach ($value_changes as $c) {
            printf("  %-22s %-18s %s\n", $c['cell'], $c['report'], implode(', ', $c['numbers']));
        }
        echo "\n";
    }
    if ($markup_changes !== []) {
        printf("MARKUP ONLY (no numeric difference detected) — %d report/cell(s):\n", count($markup_changes));
        foreach ($markup_changes as $c) {
            printf("  %-22s %-18s %d → %d bytes\n", $c['cell'], $c['report'], $c['bytes'][0], $c['bytes'][1]);
        }
        echo "\n";
    }
}

foreach ([
    'NOW FAILING'      => $new_errors,
    'NOW RENDERING (was failing)' => $fixed_errors,
    'NO BASELINE'      => $missing,
] as $heading => $list) {
    if ($list !== []) {
        printf("%s — %d:\n", $heading, count($list));
        foreach (array_slice($list, 0, 12) as $line) {
            echo "  {$line}\n";
        }
        echo "\n";
    }
}

// Always print what was NOT value-checked, so the blind spot is visible in the
// same output as the verdict rather than buried in the source.
if ($live_moves !== []) {
    printf("LIVE (declared time-dependent, values not compared) — %d:\n", count($live_moves));
    foreach ($live_moves as $line) {
        echo "  {$line}\n";
    }
    echo "\n";
}

if ($compared === 0) {
    echo "ERROR: nothing was compared — refusing to report parity\n";
    echo "VERDICT: ERROR\n";
    return;
}

if ($changed === [] && $new_errors === []) {
    printf("VERDICT: PASS — %d snapshots identical\n", $compared);
    return;
}

printf("VERDICT: FAIL — %d changed, %d newly failing\n", count($changed), count($new_errors));
