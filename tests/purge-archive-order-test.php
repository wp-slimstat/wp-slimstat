<?php
/**
 * The purge must archive what it deletes, and must not rebuild the tables for nothing.
 *
 * `wp_slim_events.id` is a FOREIGN KEY into `wp_slim_stats(id)` ON DELETE CASCADE, created
 * by the plugin itself. The purge ran, in this order:
 *
 *     1. INSERT INTO slim_stats_archive  ... FROM slim_stats  WHERE dt < cutoff
 *     2. DELETE FROM slim_stats WHERE dt < cutoff          <-- cascades, killing the events
 *     3. INSERT INTO slim_events_archive ... FROM slim_events WHERE dt < cutoff
 *     4. DELETE FROM slim_events WHERE dt < cutoff
 *
 * Step 2 destroys exactly the rows step 3 exists to save. Reproduced on scratch tables
 * carrying the real foreign key: 5 events in, 0 archived; with the order corrected, 5 of 5.
 *
 * It is not a partial loss. An event is stamped with its parent pageview's id at the
 * moment the pageview is recorded, so an event is never older than its parent — measured
 * across all 131,147 rows of the reference dataset: `MIN(e.dt - s.dt) = 0`, zero rows with
 * `e.dt < s.dt`, zero orphans. `e.dt < cutoff` therefore implies `s.dt < cutoff`, so every
 * archive-eligible event is cascaded away first. 102,573 of 102,573 at a 30-day cutoff.
 *
 * Two further properties are pinned here because each of them, on its own, silently
 * disables retention or destroys data:
 *
 *  - The archive INSERTs must be idempotent, and must carry `event_id`. A run interrupted
 *    between archive and delete leaves rows in the archive; the next run then fails on a
 *    duplicate primary key, the `false !== $is_copy_done` guard skips the DELETE, and
 *    retention is wedged forever — silently, every 12 hours. Omitting `event_id` has the
 *    inverse failure: silent duplication.
 *
 *  - OPTIMIZE TABLE must not run on a schedule. On InnoDB it is a full table rebuild
 *    ("Table does not support optimize, doing recreate + analyze instead"), and its cost
 *    tracks table SIZE, not rows removed — measured at 5.5-31.9 s on a 443,535-row /
 *    408 MB replica, and back-to-back runs on a zero-fragmentation table cost the same as
 *    the first. It ran unconditionally, twice a day, even when nothing qualified.
 *
 * Defect id (D1) lives in the workspace performance notes, outside this repository —
 * deliberately not linked, since this file ships to wp.org.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/source-scan.php';

$plugin_root = dirname(__DIR__);
$failures    = [];

$source = file_get_contents($plugin_root . '/wp-slimstat.php');
if ($source === false) {
    fwrite(STDERR, "FAIL: cannot read wp-slimstat.php\n");
    exit(1);
}

$purge = slimstat_function_body($source, 'wp_slimstat_purge');
if ($purge === '') {
    fwrite(STDERR, "FAIL: wp_slimstat_purge() not found in wp-slimstat.php\n");
    exit(1);
}

// Execute the shared batch rather than infer statement order from its old inline layout.
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once $plugin_root . '/src/Utils/PurgeArchive.php';
class PurgeOrderDb {
    public $last_error = '';
    public $queries = [];
    public $probe = 0;
    public $refuseProbe = 0;
    public $errorProbe = 0;
    public $deleted = 1;
    public $keys = ['18446744073709551615'];
    public function suppress_errors($value = true) { return false; }
    public function prepare($sql, ...$args) { return vsprintf(str_replace('%d', '%s', $sql), $args); }
    public function get_col($sql) { $this->queries[] = $sql; return $this->keys; }
    public function get_var($sql) {
        $this->queries[] = $sql;
        ++$this->probe;
        $this->last_error = $this->errorProbe === $this->probe ? 'read failed' : '';
        return $this->refuseProbe === $this->probe ? '1' : null;
    }
    public function query($sql) { $this->queries[] = $sql; return strpos($sql, 'DELETE') === 0 ? $this->deleted : 1; }
}
$eventCols = ['event_id', 'id', 'dt', 'notes'];
$statsCols = ['id', 'dt', 'resource'];
$execute = static function ($db, $archive = true) use ($eventCols, $statsCols) {
    return \SlimStat\Utils\PurgeArchive::purgeBatch($db, 'test_', 100, 200, $archive, $eventCols, $statsCols);
};
$check = static function ($ok, $message) use (&$failures) { if (!$ok) { $failures[] = $message; } };
$db = new PurgeOrderDb(); $result = $execute($db);
$writes = array_values(array_filter($db->queries, static function ($sql) { return preg_match('/^(INSERT|DELETE)/', $sql); }));
$check($result === ['stats' => 1, 'events' => 1, 'error' => ''], 'successful exact-key batch must report both removals');
$check(count($writes) === 4 && strpos($writes[0], 'INSERT IGNORE INTO test_slim_events_archive') === 0
    && strpos($writes[1], 'DELETE e FROM test_slim_events') === 0
    && strpos($writes[2], 'INSERT IGNORE INTO test_slim_stats_archive') === 0
    && strpos($writes[3], 'DELETE s FROM test_slim_stats') === 0, 'events must be archived and deleted before parent deletion');
foreach ([$writes[0], $writes[1], $writes[2], $writes[3]] as $sql) {
    $check(strpos($sql, 'IN (18446744073709551615)') !== false, 'every write must retain exact unsigned keys without integer overflow');
}
$check(strpos($writes[0], '(event_id, id, dt, notes)') !== false && strpos($writes[2], '(id, dt, resource)') !== false,
    'archive INSERT must use the same supplied column list as verification');
$check(strpos($writes[1], 'CAST(a.notes AS BINARY) <=> CAST(e.notes AS BINARY)') !== false
    && strpos($writes[3], 'CAST(a.resource AS BINARY) <=> CAST(s.resource AS BINARY)') !== false,
    'DELETE must recheck every archived value as bytes, not collated text');
$check(strpos($writes[3], 'LEFT JOIN test_slim_events e ON e.id = s.id') !== false
    && strpos($writes[3], 'e.event_id IS NULL') !== false, 'parent DELETE must refuse a concurrent child');
$check(strpos($db->queries[0], 's.dt >=') !== false, 'future events on expired parents must be selected');
$check(strpos($writes[1], 'p.dt >= 100') !== false && strpos($writes[3], 's.dt >= 100') !== false,
    'DELETE must recheck retention dates after concurrent edits');
$check(substr_count(implode(' ', $db->queries), 'LIMIT 500') === 4, 'both union arms and both selections must be bounded');
$check(strpos($db->queries[0], 'STRAIGHT_JOIN test_slim_events') !== false && strpos($db->queries[0], 'UNION') !== false, 'event selection must use separate date ranges instead of scanning retained events');
$check(substr_count(implode(' ', $db->queries), 'IS NULL OR NOT') === 2, 'both copies must be verified before deletion');
foreach ([1, 2, 3, 4] as $probe) {
    foreach (['refuseProbe', 'errorProbe'] as $mode) {
        $db = new PurgeOrderDb(); $db->$mode = $probe; $result = $execute($db);
        $deletes = array_values(array_filter($db->queries, static function ($sql) { return strpos($sql, 'DELETE') === 0; }));
        $check($result['error'] !== '' && $result['stats'] === 0, 'unreadable, colliding or missing archive must stop parent deletion');
        $check(count($deletes) === ($probe <= 2 ? 0 : 1), 'no DELETE may pass a failed archive check');
    }
}
$db = new PurgeOrderDb(); $db->deleted = 0; $result = $execute($db);
$check($result['error'] !== '' && $result['stats'] === 0, 'deleted count mismatch must stop the batch');
$db = new PurgeOrderDb(); $db->keys = ['1 OR 1=1']; $result = $execute($db);
$check($result['error'] !== '' && count($db->queries) === 1, 'invalid database key must never be interpolated');
$db = new PurgeOrderDb(); $execute($db, false);
$check(!preg_grep('/archive/', $db->queries), 'delete-only must not read or write archives');
$check(strpos($purge, 'PurgeArchive::purgeBatch(') !== false, 'cron must invoke the verified batch');
$check(strpos($purge, 'record_degradation') !== false, 'a refused purge must report its failure');

// ── 2d. The archived column lists still match the tables the installer creates ──
//
// The unit test proves every listed column is compared, but it takes the list as given —
// so a column dropped from the list is invisible to it, and a column ADDED to the schema
// is invisible to both. Either way the archive quietly stops carrying that column while
// the purge goes on deleting the live rows. Compare against the CREATE TABLE the installer
// actually issues, which is the only in-repo statement of the real shape.
// Read as source, not via the autoloader: these scans run on PHP-only CI lanes with no
// WordPress and no vendor tree, and PurgeArchive.php exits unless ABSPATH is defined. Schema.php
// is the exception — it is deliberately dependency-free, so the column list can be READ rather
// than recovered with a regex over interpolated SQL, which is what this did while the DDL lived
// in admin/index.php.
$archiveSrc = file_get_contents($plugin_root . '/src/Utils/PurgeArchive.php');
require_once $plugin_root . '/src/Schema/Schema.php';

$constColumns = static function (string $name) use ($archiveSrc): array {
    if ($archiveSrc === false || !preg_match('/const\s+' . $name . '\s*=\s*\[(.*?)\];/s', $archiveSrc, $m)) {
        return [];
    }
    preg_match_all("/'([a-z_]+)'/", $m[1], $cols);
    return $cols[1];
};

$schemaTargets = [
    ['STATS_COLUMNS', 'slim_stats'],
    ['EVENT_COLUMNS', 'slim_events'],
];

foreach ($schemaTargets as [$constant, $table]) {
    $inSchema = array_keys(SlimStat\Schema\Schema::columns($table));
    $declared = $constColumns($constant);

    if ($inSchema === []) {
        $failures[] = "the manifest declares no columns for {$table} — re-anchor this assertion "
            . 'rather than deleting it';
        continue;
    }

    if ($declared === []) {
        $failures[] = "PurgeArchive::{$constant} not found in src/Utils/PurgeArchive.php";
        continue;
    }

    $unarchived = array_values(array_diff($inSchema, $declared));
    $phantom    = array_values(array_diff($declared, $inSchema));

    if ($unarchived !== []) {
        $failures[] = "{$table} has column(s) " . implode(', ', $unarchived) . ' that '
            . "PurgeArchive::{$constant} does not archive — the purge would delete those rows "
            . 'while the archive silently drops that column';
    }
    if ($phantom !== []) {
        $failures[] = "PurgeArchive::{$constant} names " . implode(', ', $phantom) . ' which '
            . "{$table} does not have — the archive INSERT would fail outright and, because "
            . 'the purge fails closed, retention would stop';
    }
}

// ── 2b. The statement is built from the LIVE schema, not from the manifest ──
//
// Section 2 above compares the constants to the MANIFEST, and that is still the right
// question — but it is not the whole one, and believing it was is what shipped the defect.
// The archive tables are declared `reconcile => false`, so `Schema::ensure()` never adds a
// column to them: they are created once with `CREATE TABLE ... LIKE` and frozen at whatever
// `slim_stats` looked like that day. A fresh install is born correct; an upgraded one is not.
//
// `ua_id` was added to STATS_COLUMNS in the same change that declared it on `slim_stats`,
// which section 2 checks and passes. But the migration adding it touches `slim_stats` only,
// and it is OPTIONAL, so `runAll()` skips it — leaving upgraded installs with the column on
// NEITHER table. The probe then named a column that does not exist, `probeForCollision()`
// read the resulting error as "cannot tell" and refused to delete, and it did that on every
// tick forever: events aged out, pageviews never did, and the message blamed utf8mb4.
//
// So the purge must intersect against what the tables actually have at run time.
if (!preg_match('/copyableColumns\s*\(/', $purge)) {
    $failures[] = 'wp_slimstat_purge() does not intersect the declared columns against the live '
        . 'schema. The archive tables are never reconciled, so on an upgraded install the '
        . 'manifest names columns they have never had — and naming one in the collision probe '
        . 'stops retention permanently, with a recorded message that blames the charset migration';
}

// Not "copyableColumns is mentioned somewhere". The constants must no longer reach sameRow()
// and the INSERT directly, or the intersection is decoration sitting beside the defect.
if (preg_match('/PurgeArchive::(?:STATS|EVENT)_COLUMNS\s*;/', $purge)) {
    $failures[] = 'wp_slimstat_purge() still assigns PurgeArchive::STATS_COLUMNS / EVENT_COLUMNS '
        . 'straight into the column list it builds SQL from. The intersection has to be the '
        . 'thing the statement is built from, not an extra call beside it';
}

// A missing key means there is no statement to build, and that must refuse rather than
// proceed — the same refusal probeForCollision() already makes for a question it could not ask.
if (!preg_match("/\\['usable'\\]/", $purge)) {
    $failures[] = 'nothing checks the usable flag, so a purge whose archive table has lost its '
        . 'primary key would build a statement that cannot identify a row and delete through it';
}

// ── 5. OPTIMIZE TABLE is not on a schedule ──────────────────────────────────
$optimizes = preg_match_all('/OPTIMIZE\s+TABLE/i', $purge);
if ($optimizes > 0) {
    // The archive tables are append-only. They do not fragment, so rebuilding them is
    // pure cost with no benefit, forever.
    if (preg_match('/OPTIMIZE\s+TABLE[^;]*_archive/i', $purge)) {
        $failures[] = 'the purge rebuilds an archive table. Archive tables are append-only '
            . 'and never fragment — this is a full InnoDB rebuild bought for nothing';
    }
    // Must be reached only after real work, not on every tick.
    if (!preg_match('/\$rows_removed/', $purge)) {
        $failures[] = 'OPTIMIZE TABLE is not gated on rows actually removed — it is a full '
            . 'InnoDB rebuild whose cost tracks table size rather than rows removed, and it '
            . 'ran twice a day even when zero rows qualified';
    }
    // A transient is evictable, and it would fail in the wrong direction: past its
    // retention horizon a site removes rows on every tick, so an evicted flag restores
    // exactly the twice-daily rebuild this gate exists to stop — on the largest sites.
    if (preg_match('/(get|set)_transient\s*\(\s*[\'"]slimstat_purge/', $purge)) {
        $failures[] = 'the OPTIMIZE throttle is stored in a transient — an object cache can '
            . 'evict it, and eviction re-enables the twice-daily table rebuild. Use a '
            . 'non-autoloaded option';
    }
}

// ── 6. A tick with nothing to do costs nothing ──────────────────────────────
// The common case by far: the default retention is 420 days, so on almost every install
// almost every tick has no qualifying rows.
if (!preg_match('/LIMIT\s+1|get_var/i', $purge)) {
    $failures[] = 'the purge does not probe cheaply for work before doing any — with the '
        . 'shipped 420-day retention, nearly every one of the twice-daily ticks has nothing '
        . 'to purge and should cost two indexed lookups';
}

// The probe is a question, not a failure: a mid-conversion install should get a
// recorded degradation and a skipped purge, not a red admin on every page load.
$helper = (string) @file_get_contents(dirname(__DIR__) . '/src/Utils/PurgeArchive.php');
// Optional lookup so the curated "re-anchor this scan" diagnostic below survives; the
// default form would throw and replace it with a stack trace.
$probe  = '' === $helper ? '' : (string) slimstat_find_function_body($helper, 'probeForCollision');

if ('' === $probe) {
    $failures[] = 'cannot isolate PurgeArchive::probeForCollision() — re-anchor this scan';
} else {
    // suppress_errors(TRUE) specifically: the restore call at the end of the probe also
    // contains the name, so matching the name alone passed with the enabling call deleted.
    if (!preg_match('/suppress_errors\s*\(\s*true\s*\)/i', $probe)) {
        $failures[] = 'probeForCollision() does not suppress errors around its query, so a '
            . 'half-converted install paints the admin red on every page load';
    }
    if (false === strpos($probe, 'last_error')) {
        $failures[] = 'probeForCollision() does not consult last_error, so it cannot tell a failed '
            . 'comparison from a clean one';
    }
}

// Actual schema probes must not hide fields outside the archive manifest.
define('ARRAY_A', 'ARRAY_A');
class wpdb {
    public $last_error = '';
    public function suppress_errors($value) { return false; }
    public function get_results($sql, $format) {
        return array_map(static function ($name) { return ['Field' => $name, 'Type' => 'varchar(255)']; },
            array_merge(array_keys(\SlimStat\Schema\Schema::columns('slim_stats')), ['custom_payload']));
    }
}
require_once $plugin_root . '/src/Schema/Schema.php';
require_once $plugin_root . '/src/Utils/PurgeArchive.php';
$customPlan = \SlimStat\Utils\PurgeArchive::copyableColumns(new wpdb(), 'wp_', 'slim_stats');
if (!in_array('custom_payload', $customPlan['lost'], true)) {
    $failures[] = 'undeclared custom column must refuse archive-mode deletion';
}

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: purge archive order (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "PASS: purge archive order (events archived and deleted before their parents; archiving "
    . "idempotent; no scheduled table rebuilds)\n";
exit(0);
