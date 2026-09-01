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
if (!class_exists('wp_slimstat')) {
    class wp_slimstat
    {
        /** Mirrors wp_slimstat's severity constants; the migration and tracker paths
         *  name them when recording, so a stub without them fatals rather than fails. */
        const DEGRADATION_LOAD = 'load';

        const DEGRADATION_OPERATIONAL = 'operational';

        /** @var array<string,string> Degradations the migration subsystem reported. */
        public static $degradations = [];

        public static function record_degradation($step, $e)
        {
            self::$degradations[$step] = $e instanceof \Throwable ? $e->getMessage() : (string) $e;
        }
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

        /**
         * The error text wpdb leaves after a failed query, '' when it succeeded.
         *
         * shouldRun() reads this to tell "the table is not on this connection" apart
         * from "the index is missing" — get_var() answers null for both (C29).
         *
         * @var string
         */
        public $last_error = '';

        public function suppress_errors($suppress = true)
        {
            return false;
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
//
// Schema first: AbstractIndexMigration reads the index name and columns from the manifest, so a
// subclass now declares only which index it owns. The three assertions below still spell out the
// exact CREATE INDEX text, which is what makes this a real check on that derivation rather than
// a tautology — if the manifest and these expectations ever disagree, this fails.
$files = [
    '/src/Schema/Schema.php',
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

    // C29: on an external-DB install the table is not on this connection, SHOW INDEX
    // errors, and get_var() answers null — the same value as "index missing". Reading
    // that as "yes, run me" produced a migration notice that could never be cleared and
    // a button that failed on every click. A probe that cannot read the table must
    // answer neither clean nor dirty, and must say so.
    wp_slimstat::$degradations = [];
    [$m, $db]                  = $fresh(false);
    $db->last_error            = "Table 'main.wp_slim_stats' doesn't exist";
    gfim_assert($m->shouldRun() === false, "{$short}: shouldRun() false when the table is unreadable", $failures);
    gfim_assert(wp_slimstat::$degradations !== [], "{$short}: an unreadable table records a degradation", $failures);

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

// The block no longer issues its own ALTERs — Schema::ensure() reconciles all three along with
// every other index — so what has to hold is unchanged in substance and different in form: the
// flag may only be set when all three are CONFIRMED PRESENT afterwards. ensure() reports
// `present` from a SHOW INDEX taken after the build, which is strictly stronger than the old
// `query() !== false`: a statement that returned truthy but left no index used to set the flag.
gfim_assert(
    $block !== '' && strpos($block, "\$schema_report['present']") !== false,
    'the goals_indexes flag is decided on indexes confirmed present, not on an unchecked ALTER',
    $failures
);
// Still a guard, not an unconditional assignment after the reconciliation.
gfim_assert(
    $block !== '' && preg_match('/if\s*\(\s*\[\]\s*===\s*array_diff\(/', $block) === 1,
    "goals_indexes='on' is set only when NO required index is missing from the report",
    $failures
);

echo "\n";
if ($failures) {
    echo count($failures) . " FAILURE(S)\n";
    exit(1);
}
echo "ALL PASS\n";
exit(0);
