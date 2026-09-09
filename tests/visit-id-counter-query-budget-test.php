<?php
/**
 * Regression test: issuing a visit ID must cost one query, and must never reissue.
 *
 * Native mysqli increments cost one query; non-mysqli drop-ins need a read of
 * LAST_INSERT_ID(). Missing counters must be seeded beyond existing data before
 * any caller can issue an ID, including concurrent initialization.
 *
 * @see src/Tracker/VisitIdGenerator.php
 * @see tests/bench/hit-cost.sh (the end-to-end measurement this pins)
 */

declare(strict_types=1);

namespace SlimStat\Tracker {
    // Model the native connection result independently of wpdb's stale insert_id.
    function mysqli_insert_id($dbh) { return $GLOBALS['wpdb']->counter; }
}

namespace {

if (!class_exists('mysqli')) {
    class mysqli {}
}

use SlimStat\Tracker\VisitIdGenerator;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

/**
 * Stand-in for wpdb's UPDATE affected rows and independent connection insert ID.
 */
class VicqbFakeWpdb
{
    public $dbh;
    public $options    = 'wp_options';
    public $prefix     = 'wp_';
    public $insert_id  = 0;
    public $rows_affected = 0;

    /** @var string[] */
    public $log = [];

    /** @var int|null Counter value, or null when the row does not exist. */
    public $counter;

    /** @var int Highest visit_id already stored. */
    public $max_visit_id = 0;
    public $max_read_fails = false;

    /** @var bool Force the increment to fail. */
    public $increment_fails = false;

    /** A concurrent initializer may win after MAX is read but before the monotonic upsert. */
    public $concurrent_seed = null;

    public function __construct(?int $counter, int $max_visit_id)
    {
        $this->dbh = new \mysqli();
        $this->counter      = $counter;
        $this->max_visit_id = $max_visit_id;
    }

    public function prepare($sql, ...$args)
    {
        foreach ($args as $a) {
            $sql = preg_replace('/%[dsf]/', is_int($a) ? (string) $a : "'" . $a . "'", $sql, 1);
        }
        return $sql;
    }

    public function query($sql)
    {
        $this->log[] = $this->shape($sql);

        if (stripos(ltrim($sql), 'UPDATE') === 0 && strpos($sql, 'LAST_INSERT_ID') !== false) {
            if ($this->increment_fails) return false;
            if (null === $this->counter) return $this->rows_affected = 0;
            $this->counter++;
            // WordPress does NOT update insert_id for UPDATE statements.
            $this->insert_id = 123456;
            return $this->rows_affected = 1;
        }
        if (stripos(ltrim($sql), 'INSERT') === 0) {
            if (null !== $this->concurrent_seed) $this->counter = $this->concurrent_seed;
            preg_match("/VALUES \(.*?, (\\d+),/", $sql, $match);
            $this->counter = max($this->counter ?? 0, (int) $match[1]);
            $GLOBALS['_vicqb_options'][VisitIdGenerator::OPTION_NAME] = $this->counter;
            return 1;
        }

        return 1;
    }

    public function get_var($sql)
    {
        $this->log[] = $this->shape($sql);

        if (stripos($sql, 'LAST_INSERT_ID()') !== false) return (string) $this->counter;
        if (stripos($sql, 'MAX(visit_id)') !== false) {
            return $this->max_read_fails ? null : (string) $this->max_visit_id;
        }
        if (stripos($sql, 'AUTO_INCREMENT') !== false) {
            return null;
        }
        if (stripos($sql, 'COUNT(*)') !== false) {
            return null === $this->counter ? '0' : '1';
        }
        return null;
    }

    /** Collapse a statement to the shape the budget is expressed in. */
    private function shape(string $sql): string
    {
        $sql = trim(preg_replace('/\s+/', ' ', $sql));
        if (stripos($sql, 'COUNT(*)') !== false) {
            return 'EXISTENCE-PROBE';
        }
        if (stripos($sql, 'MAX(visit_id)') !== false) {
            return 'SEED-MAX';
        }
        if (stripos($sql, 'AUTO_INCREMENT') !== false) {
            return 'SEED-AUTOINC';
        }
        if (stripos($sql, 'UPDATE') === 0 && strpos($sql, 'LAST_INSERT_ID') !== false) return 'INCREMENT';
        if ($sql === 'SELECT LAST_INSERT_ID()') return 'READ-ID';
        return strtoupper(strtok($sql, ' '));
    }
}

// ── WordPress surface the class touches ─────────────────────────────────────
$GLOBALS['_vicqb_options'] = [];
function wp_cache_delete($key, $group = '') { return true; }

/**
 * The counter is one row that both the options API and raw SQL address. Without
 * mirroring, an option write is invisible to the fake wpdb and the seeding path looks
 * broken when it is not — an earlier version of this file reported exactly that.
 */
function vicqb_mirror_counter(string $option, $value): void
{
    if (isset($GLOBALS['wpdb']) && VisitIdGenerator::OPTION_NAME === $option) {
        $GLOBALS['wpdb']->counter = (int) $value;
    }
}

if (!function_exists('add_option')) {
    function add_option($option, $value = '', $deprecated = '', $autoload = 'yes')
    {
        if (array_key_exists($option, $GLOBALS['_vicqb_options'])) {
            return false;
        }
        $GLOBALS['_vicqb_options'][$option] = $value;
        vicqb_mirror_counter($option, $value);
        return true;
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false)
    {
        return $GLOBALS['_vicqb_options'][$option] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null)
    {
        $GLOBALS['_vicqb_options'][$option] = $value;
        vicqb_mirror_counter($option, $value);
        return true;
    }
}

if (!class_exists('wp_slimstat')) {
    class wp_slimstat
    {
        public static $wpdb = null;
        public static $settings = ['version' => '6.0.0'];
        public static function log($message, $level = 'info') {}
    }
}

require_once __DIR__ . '/../src/Tracker/VisitIdGenerator.php';

// ── Harness ─────────────────────────────────────────────────────────────────

$failures = [];
$passes   = 0;

function vicqb_assert(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $passes;
    if ($ok) {
        $passes++;
        return;
    }
    $failures[] = $name . ($detail !== '' ? " — {$detail}" : '');
}

function vicqb_boot(?int $counter, int $max_visit_id): VicqbFakeWpdb
{
    $db = new VicqbFakeWpdb($counter, $max_visit_id);
    $GLOBALS['wpdb']           = $db;
    \wp_slimstat::$wpdb        = $db;
    $GLOBALS['_vicqb_options'] = null === $counter ? [] : [VisitIdGenerator::OPTION_NAME => $counter];

    return $db;
}

// ── 1. The steady state costs one query ─────────────────────────────────────
$db = vicqb_boot(5000, 4999);
$id = VisitIdGenerator::generateNextVisitId();
vicqb_assert('an existing counter issues the next ID', $id === 5001, "got {$id}, expected 5001");
vicqb_assert(
    'an existing counter costs exactly one query',
    $db->log === ['INCREMENT'],
    'queries: ' . implode(', ', $db->log)
);
vicqb_assert(
    'no existence probe runs on the hot path',
    !in_array('EXISTENCE-PROBE', $db->log, true),
    'a SELECT COUNT(*) on wp_options still runs on every tracked hit'
);

// ── 2. A missing counter is seeded past everything already stored ───────────
//
// This is the invariant the removed probe existed to protect. A counter created from
// nothing starts at 1; handing that out on a site with 5,000,000 rows would attach a
// new visitor to an existing visit.
$db = vicqb_boot(null, 5000000);
$id = VisitIdGenerator::generateNextVisitId();
vicqb_assert(
    'a missing counter never reissues an existing visit ID',
    $id > 5000000,
    "issued {$id} while the table already holds visit IDs up to 5,000,000"
);
vicqb_assert(
    'the seed is read from the stored data',
    in_array('SEED-MAX', $db->log, true),
    'queries: ' . implode(', ', $db->log)
);

// ── 3. Seeding happens once, not per hit ────────────────────────────────────
$db->log = [];
$second  = VisitIdGenerator::generateNextVisitId();
vicqb_assert(
    'the next ID after seeding is consecutive',
    $second === $id + 1,
    "got {$second}, expected " . ($id + 1)
);
vicqb_assert(
    'seeding does not repeat on subsequent hits',
    $db->log === ['INCREMENT'],
    'queries on the second hit: ' . implode(', ', $db->log)
);

// A concurrent winner must not be overwritten by the stale MAX read.
$db = vicqb_boot(null, 5000000);
$db->concurrent_seed = 5000010;
$id = VisitIdGenerator::generateNextVisitId();
vicqb_assert('concurrent initializer is not reset', $id === 5000011);
vicqb_assert('wpdb stale insert_id is not returned', $id !== $db->insert_id);

// ── 5. A failing increment refuses an unverified ID ─────────────────────────
$db = vicqb_boot(5000, 4999);
$db->increment_fails = true;
$id = VisitIdGenerator::generateNextVisitId();
vicqb_assert(
    'a failed increment refuses an unverified ID',
    $id === 0,
    "fallback produced {$id}"
);

$db = vicqb_boot(null, 5000000);
$db->max_read_fails = true;
$id = VisitIdGenerator::generateNextVisitId();
vicqb_assert('failed historical MAX refuses an ID', $id === 0);
vicqb_assert('failed MAX never seeds a zero counter', $db->counter === null);
vicqb_assert('failed MAX does not execute seed INSERT', !in_array('INSERT', $db->log, true));

$db = vicqb_boot(5000, 4999);
$db->dbh = null;
$id = VisitIdGenerator::generateNextVisitId();
vicqb_assert('non-mysqli drop-in reads the connection ID', $id === 5001);
vicqb_assert('non-mysqli drop-in uses one documented extra read', $db->log === ['INCREMENT', 'READ-ID']);

$db = vicqb_boot(3, 5000000);
VisitIdGenerator::initializeCounter();
vicqb_assert('explicit upgrade repairs an existing low counter', VisitIdGenerator::generateNextVisitId() === 5000001);
$db = vicqb_boot(5000010, 5000000);
VisitIdGenerator::initializeCounter();
vicqb_assert('upgrade never reduces a higher live counter', VisitIdGenerator::generateNextVisitId() === 5000011);

define('SLIMSTAT_ANALYTICS_VERSION', '6.0.0');
\wp_slimstat::$settings['version'] = '5.5.0';
$db = vicqb_boot(3, 5000000);
vicqb_assert('legacy allocation repairs before returning an ID', VisitIdGenerator::generateNextVisitId() === 5000001);
$db = vicqb_boot(3, 5000000);
$db->max_read_fails = true;
vicqb_assert('legacy failed MAX refuses an ID', VisitIdGenerator::generateNextVisitId() === 0);
vicqb_assert('legacy failed MAX preserves existing counter', $db->counter === 3);
\wp_slimstat::$settings['version'] = '6.0.0';

// ── Report ──────────────────────────────────────────────────────────────────
if ($failures !== []) {
    fwrite(STDERR, 'FAIL: visit ID counter query budget (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

printf("PASS: visit ID counter query budget (%d assertions)\n", $passes);
exit(0);

}
