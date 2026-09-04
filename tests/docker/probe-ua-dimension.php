<?php
// Does F10 Layer 2's read-path switch actually pay, and does it give the same answers?
//
// Opening tag required, declare(strict_types=1) not — WP-CLI's eval-file wraps this.
//
// THE CLAIM. Layer 1 put `ua_id BINARY(8)` on the fact row and built slim_user_agents. Layer 2
// is supposed to collect: reports stop grouping the fact table by two VARCHARs and group by the
// narrow surrogate key instead, joining the dimension only for the labels.
//
//     before   SELECT browser, browser_version, COUNT(*) FROM slim_stats
//               WHERE dt BETWEEN … GROUP BY browser, browser_version
//
//     after    SELECT d.browser, d.browser_version, COUNT(*) FROM slim_stats s
//               LEFT JOIN slim_user_agents d ON s.ua_id = d.ua_id
//               WHERE s.dt BETWEEN … GROUP BY s.ua_id
//
// THE ANSWER IS SECTION 1, AND IT IS NOT ABOUT THE JOIN. A surrogate key is only useful at the
// grain the question is asked in. `ua_id` is derived from FOUR columns (browser,
// browser_version, browser_type, platform — the fields the tracker already holds, per M6); the
// reports group by TWO. If the key is finer, `GROUP BY ua_id` fragments each report row and a
// top-N shows the largest piece. That is a wrong number, not a coarse one, and it happens at
// 100% backfill — so it is not M6's un-backfilled fallback.
//
// WHY THIS RUNS BEFORE ANY CODE IS WRITTEN. A5 proposed dropping the chart's totals query on
// exactly this kind of reading — two queries "differing only in GROUP BY" look like a sum of
// parts. On the corpus it was an 84x error on the most-viewed number in the product. Reading
// would never have said so; one query did.
//
// AND WHY THE COST QUESTION IS ABOUT AN INDEX, NOT A JOIN. `idx_dt_browser_browser_version` is
// (dt, browser, browser_version) and predates F10, so the BEFORE shape is already a covering
// range scan. The AFTER shape has no index on (dt, ua_id). Section 4 prints what each shape had
// available, so a counter loss is attributed rather than mistaken for evidence against the idea.

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "runs under WP-CLI\n");
    exit(1);
}

global $wpdb;

$facts = $wpdb->prefix . 'slim_stats';
$dim   = $wpdb->prefix . 'slim_user_agents';

$now   = time();
$start = $now - 90 * 86400;
$limit = 20;

/**
 * Abort immediately, the way exercise-migration.php does. Printing 250 lines of results that a
 * control has just declared meaningless, and putting the ABORTED line underneath them, invites
 * reading the results.
 */
$abort = static function (string $why) {
    echo "  [FAIL] {$why}\n";
    echo "VERDICT: ABORTED\n";
    exit(1);
};

// ── CONTROLS ───────────────────────────────────────────────────────────────
// Every one of these is a way this probe could run to completion while measuring nothing.
echo "CONTROLS\n";

$in_range = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$facts}` WHERE dt BETWEEN {$start} AND {$now}");
$rows     = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$facts}`");
printf("  rows total %d · in the 90-day range %d\n", $rows, $in_range);

if ($in_range < 1000) {
    $abort('fewer than 1000 rows in range — no two plans can be separated here');
}
echo "  [PASS] the range holds enough rows to separate two plans\n";

// `SHOW TABLES LIKE` returns the table NAME, so the answer is a non-empty STRING. Casting it to
// int gives 0 for a table that is plainly there, and the first run of this probe aborted with
// "Layer 1 has not run here" against a database holding 6,464 dimension rows. A control that
// fails when the thing it guards is present is worse than no control; the mirror of it — one
// that PASSES while the thing is absent — is what the rest of this block exists for.
$dim_exists = '' !== (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $dim));
$has_col    = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$facts}' AND COLUMN_NAME = 'ua_id'"
);

if (!$dim_exists || !$has_col) {
    $abort('Layer 1 has not run here — every comparison below would be one shape against itself');
}
echo "  [PASS] slim_user_agents present · [PASS] ua_id on the fact table\n";

$dim_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$dim}`");
if ($dim_rows < 2) {
    $abort("the dimension holds {$dim_rows} row(s) — it cannot group anything differently");
}
printf("  [PASS] the dimension holds %d rows, enough distinct keys to change a grouping\n", $dim_rows);

// THE CONTROL THIS PROBE SHIPPED WITHOUT, and the one that mattered most. The three above prove
// the dimension TABLE and the fact COLUMN exist. None of them proves the backfill ever stamped a
// row. On an install where the migration created 6,464 dimension rows and its UPDATE half never
// ran, all three printed [PASS], the probe ran to completion, and section 3 published the cost of
// a LEFT JOIN matching ZERO rows under the heading "the only numbers here that are a claim".
$null_rows = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM `{$facts}` WHERE dt BETWEEN {$start} AND {$now} AND ua_id IS NULL"
);

// MATCHED, not merely NOT NULL, and thresholded rather than tested for exactly zero.
//
// Two holes in the first version of this control. It asked `ua_id IS NOT NULL`, which a backfill
// that stamped keys absent from the dimension satisfies while the join still matches nothing —
// and `$dim_rows >= 2` above counts the whole dimension rather than the keys used in range, so
// it cannot catch that either. And it aborted only on `$null_rows === $in_range`, so one keyed
// row in 200,000 printed "[PASS] 1 of 200000 rows in range are keyed (100.00% un-backfilled)" —
// a PASS whose own parenthetical said 100% un-backfilled — and the join cost was published
// anyway, which is the exact outcome the control was added to prevent.
$matched = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM `{$facts}` s
       JOIN `{$dim}` d ON s.ua_id = d.ua_id
      WHERE s.dt BETWEEN {$start} AND {$now}"
);

if ($matched < (int) ($in_range / 2)) {
    $abort(sprintf(
        'only %d of %d rows in range resolve to a dimension row (%d carry no ua_id at all) — a '
            . 'join matching a minority of the corpus does not measure the shape under test',
        $matched,
        $in_range,
        $null_rows
    ));
}
printf(
    "  [PASS] %d of %d rows in range resolve to a dimension row (%.2f%% un-backfilled)\n",
    $matched,
    $in_range,
    $in_range > 0 ? ($null_rows / $in_range) * 100 : 0
);

// The premise the COALESCEd shape rests on, asserted rather than assumed. That shape prefers the
// DIMENSION's label — COALESCE(d.browser, s.browser) — so it preserves the baseline answer only
// while the dimension's labels equal the fact row's. If Layer 1 ever canonicalises, trims or
// truncates a label, the shape differs for a real reason, and without this the probe would
// report "the harness is wrong, not the idea" and blame its own rig for a genuine finding.
$label_drift = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM `{$facts}` s
       JOIN `{$dim}` d ON s.ua_id = d.ua_id
      WHERE s.dt BETWEEN {$start} AND {$now}
        AND NOT (d.browser <=> s.browser AND d.browser_version <=> s.browser_version)"
);

if (0 !== $label_drift) {
    printf(
        "  [NOTE] %d keyed row(s) carry a dimension label different from the fact row's. Any\n"
            . "         difference in the COALESCEd shape below is THAT, not the measurement rig.\n",
        $label_drift
    );
} else {
    echo "  [PASS] every keyed row's dimension label equals its fact label, so the COALESCEd\n"
        . "         shape is answer-preserving by construction rather than by assumption\n";
}

// ── 1. GRAIN — the question that decides everything below it ───────────────
//
// SCOPED TO THE RANGE, like every other section. The first version measured grain over the WHOLE
// table while sections 2-3 measured the 90-day window; seed-bench.sh asserts 30d < 90d < all, so
// on the intended corpus those grain figures described a population about twice the size of the
// one the compared shapes ever touch.
//
// AND GUARDED ON COMPLETE BACKFILL, because `COUNT(DISTINCT ua_id)` EXCLUDES NULLS while
// `GROUP BY browser, browser_version` counts every row. A partial backfill therefore deflates the
// key's grain and leaves the report's untouched — which can drive the comparison into its
// all-clear branch and print "grouping by it is representable" in exactly the state where that is
// least true.
echo "\n1. GRAIN (the key's, against the report's — the answer, so it is printed first)\n";

$pairs = $wpdb->get_row(
    "SELECT COUNT(*) AS pairs_n, MAX(n) AS worst
       FROM (SELECT COUNT(DISTINCT ua_id) AS n
               FROM `{$facts}` WHERE dt BETWEEN {$start} AND {$now}
              GROUP BY browser, browser_version) x",
    ARRAY_A
);

$grain_key = (int) $wpdb->get_var(
    "SELECT COUNT(DISTINCT ua_id) FROM `{$facts}` WHERE dt BETWEEN {$start} AND {$now}"
);
$grain_rep = (int) $pairs['pairs_n'];
$worst     = (int) $pairs['worst'];

printf("  ua_id — the KEY's grain                          %8d\n", $grain_key);
printf("  (browser, browser_version) — the REPORT's grain  %8d\n", $grain_rep);

if (0 !== $null_rows) {
    printf(
        "  NOT COMPARABLE: %d row(s) in range are un-backfilled, and COUNT(DISTINCT ua_id) skips\n"
            . "  NULLs — so the key's grain is understated here and no verdict is drawn from it.\n",
        $null_rows
    );
} elseif ($grain_key > $grain_rep) {
    printf(
        "  the key is %.1fx FINER than the question. One report row spans up to %d key(s), so\n"
            . "  GROUP BY ua_id splits it into that many fragments and a top-N shows the largest.\n",
        $grain_rep > 0 ? $grain_key / $grain_rep : 0,
        $worst
    );
} else {
    echo "  the key is at or coarser than the report grain — grouping by it is representable\n";
}

// ── the three shapes ───────────────────────────────────────────────────────
$before_sql = "SELECT browser, browser_version, COUNT(*) AS counthits
                 FROM `{$facts}`
                WHERE dt BETWEEN {$start} AND {$now}
                GROUP BY browser, browser_version
                ORDER BY counthits DESC, browser ASC, browser_version ASC
                LIMIT {$limit}";

// The naive switch — GROUP BY the surrogate key, label from the dimension.
$naive_sql = "SELECT d.browser, d.browser_version, COUNT(*) AS counthits
                FROM `{$facts}` s
                LEFT JOIN `{$dim}` d ON s.ua_id = d.ua_id
               WHERE s.dt BETWEEN {$start} AND {$now}
               GROUP BY s.ua_id
               ORDER BY counthits DESC, d.browser ASC, d.browser_version ASC
               LIMIT {$limit}";

// The fallback-correct switch — group by the LABEL, from the dimension when present and from the
// fact row when not, so an un-backfilled row stays its own line instead of joining a NULL bucket.
$safe_sql = "SELECT COALESCE(d.browser, s.browser) AS browser,
                    COALESCE(d.browser_version, s.browser_version) AS browser_version,
                    COUNT(*) AS counthits
               FROM `{$facts}` s
               LEFT JOIN `{$dim}` d ON s.ua_id = d.ua_id
              WHERE s.dt BETWEEN {$start} AND {$now}
              GROUP BY COALESCE(d.browser, s.browser), COALESCE(d.browser_version, s.browser_version)
              ORDER BY counthits DESC, browser ASC, browser_version ASC
              LIMIT {$limit}";

// ONE execution per shape, serving BOTH the answers and the counters. The first version ran each
// query twice — once for the answers, once for the counters — which is not merely six scans where
// three would do: it warmed the buffer pool for BEFORE differently from the two AFTER shapes,
// putting an order effect inside the one section this file calls deterministic.
//
// EXPLAIN is taken first and is NOT a second execution: it optimises without running, and it sits
// before FLUSH STATUS so it cannot contribute to the counters.
//
// The counter list matches report-answers.php's, deliberately. Two independently written readers
// of one surface had already drifted — this one was missing Select_scan, which is exactly the
// counter answering section 4's question of whether a shape degraded into a full scan — and both
// feed the same Run write-ups, where they have to be comparable.
$counter_names = [
    'Handler_read_first', 'Handler_read_key', 'Handler_read_next', 'Handler_read_rnd_next',
    'Created_tmp_tables', 'Created_tmp_disk_tables', 'Sort_rows', 'Sort_scan', 'Select_scan',
];

$run = static function (string $sql) use ($wpdb, $counter_names, $abort) {
    $plan = $wpdb->get_results("EXPLAIN {$sql}", ARRAY_A);

    // Checked, not assumed. A FLUSH STATUS that silently failed would leave the first arm
    // carrying the counters of every scan before it, and every later shape would look
    // dramatically cheaper for no reason at all.
    if (false === $wpdb->query('FLUSH STATUS')) {
        $abort('FLUSH STATUS failed — the counters would be cumulative, not per-shape');
    }

    $rows = $wpdb->get_results($sql, ARRAY_A);

    // THE STATEMENT IS CHECKED, NOT ONLY THE FLUSH THAT PRECEDES IT. `get_results()` returns an
    // empty array on error, and `show_errors` is off unless WP_DEBUG && WP_DEBUG_DISPLAY — which
    // the bench container does not set — so a failed query is completely silent. Measured: a
    // shape that errors leaves EVERY counter at zero, so it prints "0 row(s) DIFFERS" (which
    // this probe deliberately treats as its finding, not a failure) and its DELTAS column
    // publishes the whole baseline magnitude as a negative. "Dramatically cheaper", exit 0,
    // VERDICT: MEASURED. And if the BASELINE errors too, `[] === []` prints IDENTICAL and the
    // one must-fail branch never fires either.
    //
    // The previous version checked FLUSH STATUS on the line above and not the statement two
    // lines below it — a guard placed next to the thing it was not guarding.
    if ('' !== (string) $wpdb->last_error) {
        $abort('a measured shape failed to execute: ' . $wpdb->last_error);
    }

    $counters = [];
    $list     = "'" . implode("','", $counter_names) . "'";
    foreach ((array) $wpdb->get_results(
        "SHOW SESSION STATUS WHERE Variable_name IN ({$list})",
        ARRAY_A
    ) as $r) {
        $counters[$r['Variable_name']] = (int) $r['Value'];
    }

    return ['plan' => $plan, 'rows' => $rows, 'counters' => $counters];
};

$before_label = 'BEFORE — group by browser, browser_version';
$naive_label  = 'AFTER  — group by ua_id';
$safe_label   = 'AFTER  — group by COALESCEd label';

$shapes = [
    $before_label => $run($before_sql),
    $naive_label  => $run($naive_sql),
    $safe_label   => $run($safe_sql),
];

// ── 2. ANSWERS ─────────────────────────────────────────────────────────────
//
// NOT "the gate", which is what an earlier draft called it while never touching the exit status.
// A DIFFERS on the ua_id shape is this probe's FINDING, not its failure — exiting non-zero on the
// result it exists to report would make the artifact unusable in a `&&` chain. What IS a failure
// is the COALESCEd shape differing: that form is constructed to be answer-preserving, so a
// difference there means the harness is wrong rather than the idea.
echo "\n2. ANSWERS (a cheaper query that answers differently is not cheaper)\n";

$norm = static function (array $rows) {
    $out = [];
    foreach ($rows as $r) {
        $out[] = sprintf(
            '%s|%s|%d',
            (string) ($r['browser'] ?? ''),
            (string) ($r['browser_version'] ?? ''),
            (int) $r['counthits']
        );
    }
    return $out;
};

$fail     = 0;
$answers  = [];
$baseline = $norm($shapes[$before_label]['rows']);

foreach ($shapes as $label => $shape) {
    $answers[$label] = $norm($shape['rows']);
    printf(
        "  %-44s %2d row(s)  %s\n",
        $label,
        count($answers[$label]),
        $label === $before_label
            ? '(baseline)'
            : ($baseline === $answers[$label] ? 'IDENTICAL' : 'DIFFERS')
    );
}

if ($baseline !== $answers[$safe_label]) {
    if (0 !== $label_drift) {
        printf(
            "  [NOTE] the COALESCEd shape differs, and %d keyed row(s) carry a dimension label\n"
                . "         unequal to the fact row's — that is a Layer 1 finding about the dimension,\n"
                . "         not a broken measurement. Not failed.\n",
            $label_drift
        );
    } else {
        echo "  [FAIL] the answer-preserving shape does not preserve the answer, and every keyed\n"
            . "         label matches — so the harness is wrong, not the idea, and nothing below\n"
            . "         can be read until that is resolved\n";
        $fail = 1;
    }
}

foreach ($answers as $label => $got) {
    if ($got === $baseline) {
        continue;
    }
    echo "  --- {$label} ---\n";
    for ($i = 0, $n = max(count($baseline), count($got)); $i < $n && $i < 8; $i++) {
        $b = $baseline[$i] ?? '(absent)';
        $g = $got[$i] ?? '(absent)';
        if ($b !== $g) {
            printf("      row %-2d  baseline %-40s  this shape %s\n", $i, $b, $g);
        }
    }
}

// ── 3. PLANS AND COUNTERS ──────────────────────────────────────────────────
// Deterministic, so they can carry a conclusion; milliseconds cannot (PITFALLS 28).
echo "\n3. PLANS AND COUNTERS (deterministic — the only numbers here that are a claim)\n";

foreach ($shapes as $label => $shape) {
    echo "  {$label}\n";
    foreach ($shape['plan'] as $row) {
        printf(
            "    plan  table=%-10s type=%-8s key=%-34s rows=%-8s Extra=%s\n",
            (string) ($row['table'] ?? '?'),
            (string) ($row['type'] ?? '?'),
            (string) ($row['key'] ?? 'NULL'),
            (string) ($row['rows'] ?? '?'),
            (string) ($row['Extra'] ?? '')
        );
    }
    foreach ($counter_names as $k) {
        printf("    %-24s %d\n", $k, $shape['counters'][$k] ?? 0);
    }
}

printf("\n  DELTAS versus the baseline (negative is cheaper)\n    %-24s %12s %12s\n",
    '', 'by ua_id', 'by COALESCEd');

foreach ($counter_names as $k) {
    printf(
        "    %-24s %+12d %+12d\n",
        $k,
        ($shapes[$naive_label]['counters'][$k] ?? 0) - ($shapes[$before_label]['counters'][$k] ?? 0),
        ($shapes[$safe_label]['counters'][$k] ?? 0) - ($shapes[$before_label]['counters'][$k] ?? 0)
    );
}

// ── 4. WHAT INDEX EACH SHAPE COULD USE ─────────────────────────────────────
echo "\n4. INDEX AVAILABILITY (the actual variable — the join is not what costs)\n";

$keys = [];
foreach ((array) $wpdb->get_results("SHOW INDEX FROM `{$facts}`", ARRAY_A) as $r) {
    $keys[$r['Key_name']][(int) $r['Seq_in_index']] = $r['Column_name'];
}

// Resolved by COLUMNS, never by name. An earlier draft tested the covering index with
// isset($keys['idx_dt_browser_browser_version']) while the sibling check two lines away tested
// columns — so it answered "present" for any index merely carrying that name, and "absent" for
// the right columns under a different one. Two views of one fact that could disagree, inside a
// probe whose whole subject is two views of one fact disagreeing.
$leads = static function (array $keys, array $want) {
    foreach ($keys as $cols) {
        ksort($cols);
        if (array_slice(array_values($cols), 0, count($want)) === $want) {
            return true;
        }
    }
    return false;
};

foreach ($keys as $name => $cols) {
    ksort($cols);
    printf("    %-40s (%s)\n", $name, implode(', ', $cols));
}

printf(
    "\n    (dt, browser, browser_version) present: %s\n    (dt, ua_id) present:                   %s\n",
    $leads($keys, ['dt', 'browser', 'browser_version']) ? 'yes' : 'no',
    $leads($keys, ['dt', 'ua_id']) ? 'yes' : 'no'
);

if (!$leads($keys, ['dt', 'ua_id'])) {
    echo "    So the AFTER shapes ran WITHOUT a supporting index. A counter LOSS is explained by\n"
        . "    that and is not evidence against Layer 2; a counter WIN would be despite it.\n";
}

echo "\nVERDICT: " . (0 === $fail ? 'MEASURED' : 'FAILED — see the [FAIL] above') . "\n";
exit($fail);
