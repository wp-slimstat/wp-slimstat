<?php
// Phase Q (F7) — are the recorded query defects real, and what do they cost?
//
// Opening tag required, declare(strict_types=1) not — WP-CLI's eval-file wraps this.
//
// READ THE CLAIM BEFORE FIXING IT. EXPECTED-DIFFS records:
//
//   "count_bouncing_pages() selects an ungrouped visit_id, so it errors under ONLY_FULL_GROUP_BY
//    and Bounce Pages silently reads 0. (count_exit_pages() has the same defect and zero callers
//    — it is dead code.)"
//
// That was written from reading the SQL. But `wpdb::set_sql_mode()` REMOVES ONLY_FULL_GROUP_BY
// from the session on every connection — it is in WordPress's own incompatible-modes list. So
// the note may describe a defect that cannot occur in the only context this code ever runs in.
//
// Two of this programme's three "measure it first" wins were exactly this shape: A5's redundant
// totals query (refuted, 84x error) and F10 Layer 2's read-path switch (refuted, wrong key
// grain). Both looked correct on the page. So this probe ASKS rather than assumes, and prints
// the session mode next to the answer so the reader can see which world the number came from.
//
// It also prints the SCAN COST of each candidate, because "collapse N scans to 2" is a claim
// about work done, and Handler_read_* is how that is counted rather than argued.

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "runs under WP-CLI\n");
    exit(1);
}

global $wpdb;

if (!class_exists('wp_slimstat_db')) {
    require_once WP_PLUGIN_DIR . '/wp-slimstat/admin/view/wp-slimstat-db.php';
}
if (method_exists('wp_slimstat_db', 'init')) {
    wp_slimstat_db::init();
}

$fail = 0;

// THE DATE WINDOW IS SET TO THE CORPUS, NOT TO ZERO.
//
// The first version of this probe set start and end to 0, meaning to "turn the time axis off".
// It does the opposite: get_combined_where() renders `dt BETWEEN 0 AND 0`, which matches nothing,
// so count_bouncing_pages() returned 0 and its scan cost was 3 row reads. Both were then compared
// against an independent query carrying NO date clause at all — two different questions, and the
// probe reported the plugin as defective on the strength of its own control.
//
// Neither function accepts a use_date_filters flag, so the only honest way to take the time axis
// out is to widen the window to cover the whole corpus. Read from the data rather than assumed,
// because a hardcoded range is the same mistake one layer down.
$span = $wpdb->get_row("SELECT MIN(dt) lo, MAX(dt) hi FROM {$wpdb->prefix}slim_stats", ARRAY_A);
wp_slimstat_db::$filters_normalized['utime']['start'] = (int) $span['lo'];
wp_slimstat_db::$filters_normalized['utime']['end']   = (int) $span['hi'];

echo "CONTROLS\n";

$rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}slim_stats");
printf("  corpus: %d rows, dt %d..%d (%d days)\n", $rows, (int) $span['lo'], (int) $span['hi'],
    (int) (((int) $span['hi'] - (int) $span['lo']) / 86400));

// The window must actually select the corpus, or every "returns 0" below is the probe's own
// doing. This control is here because it already caught exactly that.
$in_window = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}slim_stats WHERE dt BETWEEN %d AND %d",
    (int) $span['lo'],
    (int) $span['hi']
));
printf("  rows inside the configured window: %d\n", $in_window);
if ($in_window !== $rows) {
    echo "  [FAIL] the window does not cover the corpus — results below would measure the window\n";
    echo "VERDICT: ABORTED\n";
    exit(1);
}
echo "  [PASS] the configured window covers every row\n";
if ($rows < 10000) {
    echo "  [FAIL] corpus too small to separate a scan from a lookup\n";
    echo "VERDICT: ABORTED\n";
    exit(1);
}
echo "  [PASS] corpus is non-trivial\n";

$visits = (int) $wpdb->get_var("SELECT COUNT(DISTINCT visit_id) FROM {$wpdb->prefix}slim_stats WHERE visit_id > 0");
printf("  distinct visits: %d\n", $visits);
if ($visits < 100) {
    echo "  [FAIL] too few visits — the bounce/exit questions would be degenerate\n";
    echo "VERDICT: ABORTED\n";
    exit(1);
}
echo "  [PASS] the visit axis is populated\n";

// ── 1. What sql_mode is actually in force? ─────────────────────────────────
echo "\n1. SESSION sql_mode — the premise the recorded defect rests on\n";

$session = (string) $wpdb->get_var('SELECT @@SESSION.sql_mode');
$global  = (string) $wpdb->get_var('SELECT @@GLOBAL.sql_mode');

printf("  global : %s\n", $global ?: '(empty)');
printf("  session: %s\n", $session ?: '(empty)');

$ofgb_global  = false !== stripos($global, 'ONLY_FULL_GROUP_BY');
$ofgb_session = false !== stripos($session, 'ONLY_FULL_GROUP_BY');

printf("  ONLY_FULL_GROUP_BY  global=%s  session=%s\n", $ofgb_global ? 'ON' : 'off', $ofgb_session ? 'ON' : 'off');

if ($ofgb_global && !$ofgb_session) {
    echo "  -> wpdb stripped it, exactly as WordPress documents. The recorded defect's premise\n";
    echo "     does not hold on this connection.\n";
}

// ── 2. Does count_bouncing_pages() actually return 0? ──────────────────────
echo "\n2. count_bouncing_pages() — the recorded claim is that this silently reads 0\n";

$wpdb->last_error = '';
$bouncing = wp_slimstat_db::count_bouncing_pages();
$err      = (string) $wpdb->last_error;

printf("  returns: %d%s\n", $bouncing, '' === $err ? '' : "   ERROR: {$err}");

// The independent answer, computed a different way, so "it returned a number" is not mistaken
// for "it returned the RIGHT number".
$truth = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM (
        SELECT resource FROM {$wpdb->prefix}slim_stats
         WHERE visit_id > 0 AND content_type <> '404' AND dt BETWEEN %d AND %d
         GROUP BY resource HAVING COUNT(visit_id) = 1
     ) t",
    (int) $span['lo'],
    (int) $span['hi']
));
printf("  independently: %d\n", $truth);

if ($bouncing === $truth && $truth > 0) {
    echo "  [PASS] it agrees with an independently computed answer, and that answer is not zero\n";
    echo "  -> THE RECORDED DEFECT DOES NOT REPRODUCE on a WordPress connection.\n";
} elseif (0 === $bouncing) {
    echo "  [CONFIRMED] returns 0 — the recorded defect is real here\n";
    $fail = 1;
} else {
    printf("  [FAIL] returns %d against an independent %d — a different defect\n", $bouncing, $truth);
    $fail = 1;
}

// ── 3. …and what it would do if the mode WERE on ───────────────────────────
echo "\n3. The same query with ONLY_FULL_GROUP_BY forced ON — the world the note described\n";

$restore = $session;
$wpdb->query("SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',ONLY_FULL_GROUP_BY')");

// RE-READ IT. Without this the else-branch below cannot tell "the server accepted the query with
// the mode on" from "the SET silently failed and the query ran in the old world" — and it would
// report the second as the first, which is the class of defect this probe exists to avoid.
$forced_mode = (string) $wpdb->get_var('SELECT @@SESSION.sql_mode');
if (false === stripos($forced_mode, 'ONLY_FULL_GROUP_BY')) {
    echo "  [FAIL] could not turn ONLY_FULL_GROUP_BY on for this session — nothing below is a\n";
    echo "         statement about that world\n";
    $fail = 1;
}

$suppressed = $wpdb->suppress_errors(true);
$wpdb->last_error = '';
$forced = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM (
        SELECT resource, visit_id FROM {$wpdb->prefix}slim_stats
         WHERE visit_id > 0 AND content_type <> '404' AND dt BETWEEN %d AND %d
         GROUP BY resource HAVING COUNT(visit_id) = 1
     ) t",
    (int) $span['lo'],
    (int) $span['hi']
));
$forced_err = (string) $wpdb->last_error;
$wpdb->suppress_errors($suppressed);
$wpdb->query($wpdb->prepare('SET SESSION sql_mode = %s', $restore));

if ('' !== $forced_err) {
    printf("  the ungrouped visit_id IS rejected: %s\n", substr($forced_err, 0, 120));
    echo "  -> so the SQL is genuinely non-conforming, and only wpdb's mode-stripping hides it.\n";
    echo "     Worth fixing on those grounds, but it is NOT 'Bounce Pages reads 0' today.\n";
} else {
    printf("  accepted even with the mode on, returning %s\n", var_export($forced, true));
}

// ── 4. Scan cost of the three Phase Q candidates ───────────────────────────
echo "\n4. SCAN COST — what 'collapse N scans' would be collapsing\n";

$counters = static function () use ($wpdb) {
    $out = [];
    foreach ($wpdb->get_results("SHOW SESSION STATUS WHERE Variable_name IN
        ('Handler_read_rnd_next','Handler_read_next','Handler_read_key','Created_tmp_disk_tables','Sort_rows')", ARRAY_A) as $row) {
        $out[$row['Variable_name']] = (int) $row['Value'];
    }
    return $out;
};

// Cache purge before every measured execution, copied in intent from report-answers.php.
// count_bouncing_pages() falls through get_var() to a CACHEABLE Query path — it caches whenever
// the window end predates today, and this probe sets that end to MAX(dt). On a freshly seeded
// corpus MAX(dt) is near now, so the cache is cold and this is latent rather than live; on a
// persisted fixture or a next-day re-run, section 4 would print a wp_options read under the
// heading "SCAN COST" and still emit VERDICT: MEASURED.
$flush = static function () use ($wpdb) {
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
          WHERE option_name LIKE '\\_transient\\_slimstat%'
             OR option_name LIKE '\\_transient\\_timeout\\_slimstat%'"
    );
    wp_cache_flush();
};

$measure = static function (string $label, callable $fn) use ($counters, $flush) {
    $flush();
    $before = $counters();
    $t0     = microtime(true);
    $fn();
    $ms    = (microtime(true) - $t0) * 1000;
    $after = $counters();

    $delta = [];
    foreach ($after as $k => $v) {
        $delta[$k] = $v - ($before[$k] ?? 0);
    }

    printf(
        "  %-32s rnd_next=%-9d next=%-9d key=%-9d tmp_disk=%-3d sort_rows=%-8d  %.1f ms\n",
        $label,
        $delta['Handler_read_rnd_next'] ?? 0,
        $delta['Handler_read_next'] ?? 0,
        $delta['Handler_read_key'] ?? 0,
        $delta['Created_tmp_disk_tables'] ?? 0,
        $delta['Sort_rows'] ?? 0,
        $ms
    );
};

$measure('count_bouncing_pages', static function () {
    wp_slimstat_db::count_bouncing_pages();
});
$measure('get_max_and_average_pages', static function () {
    wp_slimstat_db::get_max_and_average_pages_per_visit();
});
$measure('count_records(id)', static function () {
    wp_slimstat_db::count_records('id', '', false);
});
$measure('count_records(visit_id)', static function () {
    wp_slimstat_db::count_records('visit_id', '', false);
});

// ── 5. count_exit_pages() — deleted ────────────────────────────────────────
echo "\n5. count_exit_pages() — measured as dead, then removed\n";

// It carried the same non-conforming shape (`SELECT resource, dt … GROUP BY resource HAVING
// dt = MAX(dt)`) and had zero callers, measured by this probe before deletion. Asserting its
// ABSENCE rather than its caller count, because the question has changed: it is gone, and what
// matters now is that nothing reintroduces it.
$declared = false;
$callers  = [];
$root     = dirname(dirname(__DIR__));
$it       = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

foreach ($it as $f) {
    $path = $f->getPathname();
    if ('.php' !== substr($path, -4) || false !== strpos($path, '/vendor/') || false !== strpos($path, '/tests/')) {
        continue;
    }
    $src = (string) file_get_contents($path);
    if (preg_match('/function\s+count_exit_pages/', $src)) {
        $declared = true;
    }
    if (preg_match('/count_exit_pages\s*\(/', preg_replace('/function\s+count_exit_pages/', '', $src))) {
        $callers[] = str_replace($root . '/', '', $path);
    }
}

if (!$declared && [] === $callers) {
    echo "  [PASS] gone, and nothing calls it\n";
} else {
    printf("  [FAIL] declared=%s callers=%s\n", $declared ? 'yes' : 'no', $callers ? implode(', ', $callers) : 'none');
    $fail = 1;
}

// ── 6. The two SQL forms, A-B-B-A, in ONE session ──────────────────────────
//
// Two container runs gave tmp_disk=1 before and tmp_disk=0 after. That is one observation each
// way, across two servers with two buffer pools, which this programme has already been burned by
// once: an ~8% "speedup" that was a warm cache, and a null control showing +12.7 ms with no code
// difference at all. So both forms are run HERE, alternating, against the same table in the same
// session, and the counters are compared directly.
echo "\n6. OLD vs NEW inner SELECT, alternated in one session\n";

$old_sql = $wpdb->prepare(
    "SELECT COUNT(*) counthits FROM (
        SELECT resource, visit_id FROM {$wpdb->prefix}slim_stats
         WHERE visit_id > 0 AND content_type <> '404' AND dt BETWEEN %d AND %d
         GROUP BY resource HAVING COUNT(visit_id) = 1
     ) ts1",
    (int) $span['lo'],
    (int) $span['hi']
);
$new_sql = $wpdb->prepare(
    "SELECT COUNT(*) counthits FROM (
        SELECT resource FROM {$wpdb->prefix}slim_stats
         WHERE visit_id > 0 AND content_type <> '404' AND dt BETWEEN %d AND %d
         GROUP BY resource HAVING COUNT(visit_id) = 1
     ) ts1",
    (int) $span['lo'],
    (int) $span['hi']
);

$run = static function (string $sql) use ($wpdb, $counters) {
    $before = $counters();
    $t0     = microtime(true);
    $answer = (int) $wpdb->get_var($sql);
    $ms     = (microtime(true) - $t0) * 1000;
    $after  = $counters();

    return [
        'answer'   => $answer,
        'ms'       => $ms,
        'tmp_disk' => ($after['Created_tmp_disk_tables'] ?? 0) - ($before['Created_tmp_disk_tables'] ?? 0),
        'rnd_next' => ($after['Handler_read_rnd_next'] ?? 0) - ($before['Handler_read_rnd_next'] ?? 0),
    ];
};

$seq = ['old', 'new', 'new', 'old'];
$acc = ['old' => [], 'new' => []];
$log = [];

foreach ($seq as $pos => $which) {
    $flush();
    $r             = $run('old' === $which ? $old_sql : $new_sql);
    $acc[$which][] = $r;
    $log[]         = [$pos + 1, $which, $r];
}

// PRINTED IN EXECUTION ORDER, not grouped by arm. The A-B-B-A schedule exists to balance drift
// across the session — TempTable's RAM pool makes later statements likelier to spill — and
// grouping the output by arm throws that balance away: a reader cannot tell whether a spill was
// the session's first statement or its last, which is the only thing the ordering was for.
foreach ($log as [$pos, $which, $r]) {
    printf(
        "  #%d %-4s answer=%-5d tmp_disk=%d  rnd_next=%-9d  %.1f ms\n",
        $pos,
        $which,
        $r['answer'],
        $r['tmp_disk'],
        $r['rnd_next'],
        $r['ms']
    );
}

$old_answers = array_column($acc['old'], 'answer');
$new_answers = array_column($acc['new'], 'answer');

if (array_unique(array_merge($old_answers, $new_answers)) !== [$old_answers[0]]) {
    echo "  [FAIL] the two forms disagree, or one is unstable — the rewrite is not equivalent\n";
    $fail = 1;
} else {
    printf("  [PASS] both forms answer %d on every run\n", $old_answers[0]);
}

$old_spills = array_column($acc['old'], 'tmp_disk');
$new_spills = array_column($acc['new'], 'tmp_disk');

printf("  spills to disk: old=[%s]  new=[%s]\n", implode(',', $old_spills), implode(',', $new_spills));

// BOTH REPLICATES MUST AGREE before this says anything. Summing them let one spill in four runs
// print a conclusion — the single-observation inference this section was added to replace,
// relocated into one session. Consistency across the pair is the minimum that distinguishes a
// property of the statement from an accident of when it ran.
$old_consistent = 1 === count(array_unique($old_spills));
$new_consistent = 1 === count(array_unique($new_spills));

if (!$old_consistent || !$new_consistent) {
    echo "  -> INCONCLUSIVE: a form spilled on one replicate and not the other, so spill behaviour\n";
    echo "     here is not a property of the statement. No claim.\n";
} elseif ($old_spills[0] > $new_spills[0]) {
    echo "  -> CONSISTENT both ways: dropping the unused column keeps the derived table in memory.\n";
} elseif ($old_spills[0] === $new_spills[0]) {
    echo "  -> CONSISTENT both ways, and equal: no spill difference on this server. The change is\n";
    echo "     then correctness-only, and any millisecond delta is environmental.\n";
} else {
    echo "  [FAIL] the NEW form spills consistently MORE than the old one\n";
    $fail = 1;
}

echo "\nVERDICT: " . (0 === $fail ? 'MEASURED' : 'FINDINGS') . "\n";
exit(0);
