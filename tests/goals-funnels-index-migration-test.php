<?php
/**
 * Regression: the Goals & Funnels composite indexes must fail safely.
 *
 * Bug (pre-fix): update_tables_and_options() ran three `ALTER TABLE … ADD INDEX`
 * with no error handling and set `goals_indexes = 'on'` unconditionally. On a
 * large table where the ALTER times out, the flag was set and `version` bumped
 * right after, so the migration never ran again — the indexes were never built
 * and never retried (goal/funnel queries ran unindexed forever, silently).
 *
 * Fix:
 *  1. The legacy auto-build block only sets `goals_indexes = 'on'` when every
 *     ALTER succeeds (result !== false), mirroring the 5.4.3 dt_visit pattern.
 *  2. The three indexes are registered as AbstractIndexMigration classes in the
 *     modern migration system, so a failed/large-table build surfaces the
 *     Migration notice + one-click retry (MigrationManager::runAll).
 *
 * @see admin/index.php update_tables_and_options() goals_indexes block
 * @see src/Migration/Migrations/CreateGoalQueriesIndex.php (+ Funnel, EventsNotesDt)
 * @see src/Migration/MigrationService.php registration
 */

declare(strict_types=1);

$plugin_root = dirname(__DIR__);
$failures    = [];

function gfim_assert(bool $cond, string $label, array &$failures): void
{
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) {
        $failures[] = $label;
    }
}

// --- Minimal WP shims so the namespaced migration classes load standalone ---
if (!function_exists('__')) {
    function __($text, $domain = 'default')
    {
        return $text;
    }
}
if (!class_exists('wpdb')) {
    class wpdb
    {
        public $prefix = 'wp_';
        /** @var mixed */
        public $queryReturn = 1;
        /** @var mixed */
        public $getVarReturn = null;
        public $lastQuery = '';

        public function query($sql)
        {
            $this->lastQuery = $sql;
            return $this->queryReturn;
        }

        public function get_var($sql)
        {
            return $this->getVarReturn;
        }

        public function prepare($query, ...$args)
        {
            // Naive placeholder fill is enough for these tests.
            $query = str_replace('%s', is_array($args[0] ?? null) ? '' : (string) ($args[0] ?? ''), $query);
            return $query;
        }
    }
}

// --- Load the migration base + concrete classes directly ---
$files = [
    '/src/Migration/MigrationInterface.php',
    '/src/Migration/AbstractMigration.php',
    '/src/Migration/AbstractIndexMigration.php',
    '/src/Migration/Migrations/CreateGoalQueriesIndex.php',
    '/src/Migration/Migrations/CreateFunnelQueriesIndex.php',
    '/src/Migration/Migrations/CreateEventsNotesDtIndex.php',
];
foreach ($files as $f) {
    if (!is_file($plugin_root . $f)) {
        gfim_assert(false, "missing file: {$f}", $failures);
    } else {
        require_once $plugin_root . $f;
    }
}

$ns  = 'SlimStat\\Migration\\Migrations\\';
// short class => the exact CREATE INDEX statement run() must emit when the index is missing.
$spec = [
    'CreateGoalQueriesIndex'   => 'CREATE INDEX idx_goal_queries ON wp_slim_stats (resource(191), dt, fingerprint(20))',
    'CreateFunnelQueriesIndex' => 'CREATE INDEX idx_funnel_queries ON wp_slim_stats (fingerprint(20), dt, resource(191))',
    'CreateEventsNotesDtIndex' => 'CREATE INDEX idx_events_notes_dt ON wp_slim_events (dt, notes(64))',
];

foreach ($spec as $short => $expectedSql) {
    $class = $ns . $short;
    if (!class_exists($class)) {
        gfim_assert(false, "class exists: {$short}", $failures);
        continue;
    }
    // shouldRun() memoises its SHOW INDEX for the life of the instance — it is asked twice
    // per admin page render, once via needsMigration() and once via the notice's
    // diagnostics, and the probe is a SHOW INDEX against a 443k-row table. So each scenario
    // below gets a fresh instance, which is how callers use it: a migration object lives
    // for one request, and the only thing that changes the answer mid-request is run(),
    // which invalidates its own cache.
    $fresh = static function ($indexPresent) use ($class, $short) {
        $db = new wpdb();
        $db->getVarReturn = $indexPresent ? $short : null;  // non-empty -> index present
        return [new $class($db), $db];
    };

    // shouldRun(): true when index missing, false when present.
    [$m, $db] = $fresh(false);
    gfim_assert($m->shouldRun() === true, "{$short}: shouldRun() true when index missing", $failures);

    [$m, $db] = $fresh(true);
    gfim_assert($m->shouldRun() === false, "{$short}: shouldRun() false when index present", $failures);

    // run() on a missing index emits the right DDL (name + table + prefix-length columns).
    [$m, $db] = $fresh(false);
    $db->queryReturn = 0;        // wpdb->query() returns int rows on success (>= 0, !== false)
    $db->lastQuery   = '';
    gfim_assert($m->run() === true, "{$short}: run() succeeds when CREATE INDEX succeeds", $failures);
    gfim_assert($db->lastQuery === $expectedSql, "{$short}: emits correct CREATE INDEX SQL", $failures);

    // Failure must propagate (false), not be swallowed.
    [$m, $db] = $fresh(false);
    $db->queryReturn = false;    // simulate ALTER/CREATE failure (e.g. large-table timeout)
    gfim_assert($m->run() === false, "{$short}: run() returns false when CREATE INDEX fails", $failures);

    // No-op (and no failure) when the index already exists.
    [$m, $db] = $fresh(true);
    $db->lastQuery = '';
    gfim_assert($m->run() === true && $db->lastQuery === '', "{$short}: run() is a no-op when index already exists", $failures);

    // And the memo must not outlive the change run() makes: after creating the index, a
    // second run() in the same request must be a no-op rather than re-issuing the DDL.
    [$m, $db] = $fresh(false);
    $db->queryReturn = 0;
    $m->run();
    $db->getVarReturn = $short;  // the index now exists
    $db->lastQuery    = '';
    gfim_assert($m->run() === true && $db->lastQuery === '', "{$short}: run() twice does not re-issue the DDL", $failures);
}

// --- Source scan: the three classes are registered in MigrationService ---
$svc = (string) @file_get_contents($plugin_root . '/src/Migration/MigrationService.php');
foreach (array_keys($spec) as $short) {
    gfim_assert(strpos($svc, "new {$short}(") !== false, "MigrationService registers {$short}", $failures);
}

// --- Source scan: the legacy auto-build block guards the flag on success ---
$admin = (string) @file_get_contents($plugin_root . '/admin/index.php');
$start = strpos($admin, "empty(wp_slimstat::\$settings['goals_indexes'])");
$assignPos = $start !== false ? strpos($admin, "wp_slimstat::\$settings['goals_indexes'] = 'on';", $start) : false;
$block = ($start !== false && $assignPos !== false) ? substr($admin, $start, $assignPos - $start) : '';

gfim_assert(
    $block !== '' && strpos($block, 'false === wp_slimstat::$wpdb->query') !== false,
    "legacy block checks the ALTER result (false === ...->query)",
    $failures
);
// The assignment must be wrapped in the success guard, not unconditional after the loop.
gfim_assert(
    $block !== '' && strpos($block, 'if ($goal_indexes_built) {') !== false,
    "goals_indexes='on' is set inside the if (\$goal_indexes_built) guard",
    $failures
);

echo "\n";
if ($failures) {
    echo count($failures) . " FAILURE(S)\n";
    exit(1);
}
echo "ALL PASS\n";
exit(0);
