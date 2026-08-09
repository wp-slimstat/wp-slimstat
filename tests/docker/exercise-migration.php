<?php
// Run the F10 Layer 1 migration against a seeded table and assert what it actually did.
//
// The opening PHP tag is required and declare(strict_types=1) is not — WP-CLI's eval-file
// evaluates a close-tag followed by the file contents, so a file lacking the tag is echoed as
// text and a declare() inside that wrapper is no longer the first statement.
//
// WHY A CONTAINER AND NOT A UNIT TEST. A migration that has only ever run against a mock is a
// claim. This one issues an ALTER whose algorithm the server chooses, an INSERT IGNORE whose
// whole point is what happens on a duplicate, and an UPDATE using `<=>` because the columns are
// nullable. None of those behaviours exist in a double.

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "runs under WP-CLI\n");
    exit(1);
}

require_once WP_PLUGIN_DIR . '/wp-slimstat/src/Migration/Migrations/AddUserAgentDimension.php';

global $wpdb;

$stats     = $wpdb->prefix . 'slim_stats';
$dimension = $wpdb->prefix . 'slim_user_agents';
$failures  = [];

$rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$stats}`");
$distinct = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM (SELECT DISTINCT browser, browser_version, browser_type, platform FROM `{$stats}`) t"
);

echo "CONTROLS\n";
printf("  [%s] fact table is non-trivial: %d rows\n", $rows > 10000 ? 'PASS' : 'FAIL', $rows);
printf("  [%s] distinct browser tuples: %d\n", $distinct > 1 ? 'PASS' : 'FAIL', $distinct);

if ($rows <= 10000 || $distinct <= 1) {
    echo "\nVERDICT: ABORTED — nothing here could fail\n";
    exit(1);
}

$migration = new SlimStat\Migration\Migrations\AddUserAgentDimension($wpdb, $wpdb);

// ── 1. It must ASK to run on a table that has never seen it ─────────────────
if (!$migration->shouldRun()) {
    $failures[] = 'shouldRun() is false before the migration has ever run';
}

// ── 2. Run to completion. It is resumable by design, so loop. ───────────────
$passes = 0;
while ($migration->shouldRun() && $passes < 200) {
    $migration->run();
    $passes++;
}

printf("\n  completed in %d pass(es) of %d distinct tuples\n", $passes, $distinct);

// ── 3. The column exists and EVERY fact row is keyed ────────────────────────
$unkeyed = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$stats}` WHERE ua_id IS NULL");
if (0 !== $unkeyed) {
    $failures[] = sprintf('%d fact rows still have no ua_id after the migration reported done', $unkeyed);
}

// ── 4. The dimension holds exactly the distinct tuples, no more, no fewer ───
$dim_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$dimension}`");
if ($dim_rows !== $distinct) {
    $failures[] = sprintf(
        'dimension holds %d rows for %d distinct tuples — %s',
        $dim_rows,
        $distinct,
        $dim_rows > $distinct ? 'duplicates were inserted' : 'tuples were lost'
    );
}

// ── 5. EVERY fact row joins. This is the property reports depend on. ────────
$orphans = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM `{$stats}` s LEFT JOIN `{$dimension}` d ON s.ua_id = d.ua_id WHERE d.ua_id IS NULL"
);
if (0 !== $orphans) {
    $failures[] = sprintf('%d fact rows carry a ua_id that joins to nothing', $orphans);
}

// ── 6. The key is DETERMINISTIC across rows sharing a tuple ─────────────────
// If two rows with identical browser tuples got different keys, the dimension would be
// duplicated and every grouped report would split one browser into two lines.
$split = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM (
        SELECT browser, browser_version, browser_type, platform, COUNT(DISTINCT ua_id) AS keys_used
          FROM `{$stats}` GROUP BY browser, browser_version, browser_type, platform
        HAVING keys_used > 1
     ) t"
);
if (0 !== $split) {
    $failures[] = sprintf('%d browser tuples were assigned more than one ua_id', $split);
}

// ── 7. RE-RUNNING IS A NO-OP ────────────────────────────────────────────────
// The migration runner may invoke this again — after an interrupted run, or from cron. A second
// pass must not duplicate dimension rows or re-stamp facts.
$before = $dim_rows;
$migration->run();
$after = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$dimension}`");
if ($after !== $before) {
    $failures[] = sprintf('re-running changed the dimension from %d rows to %d — not idempotent', $before, $after);
}
if ($migration->shouldRun()) {
    $failures[] = 'shouldRun() is still true after the migration completed';
}

// ── 8. A NEW tuple arriving later is picked up ──────────────────────────────
// The M6 design says the cron refreshes the dimension. If a row inserted after the backfill is
// not noticed, that design does not work.
$wpdb->insert($stats, [
    'ip' => '10.5.5.5', 'resource' => '/after/', 'visit_id' => 999999, 'dt' => time(),
    'browser' => 'BrandNewBrowser', 'browser_version' => '1.0', 'browser_type' => 0, 'platform' => 'NewOS',
], ['%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s']);

if (!$migration->shouldRun()) {
    $failures[] = 'a fact row inserted after the backfill did not make shouldRun() true again — '
        . 'the cron refresh in M6 would never notice new browsers';
}

$migration->run();
$still_null = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$stats}` WHERE ua_id IS NULL");
if (0 !== $still_null) {
    $failures[] = sprintf('%d rows unkeyed after picking up a newly-arrived tuple', $still_null);
}

echo "\n";
if ($failures) {
    fwrite(STDERR, 'FAIL: F10 Layer 1 migration (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

printf(
    "PASS: %d rows keyed, %d dimension rows for %d distinct tuples, 0 orphans, idempotent, "
        . "picks up new tuples\n",
    $rows,
    $after,
    $distinct
);
