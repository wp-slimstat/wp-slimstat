<?php
/**
 * Regression test: issuing a visit ID must cost one query, and must never reissue.
 *
 * `generateNextVisitId()` ran `ensureCounterExists()` first, which is a
 * `SELECT COUNT(*) FROM wp_options WHERE option_name = …` — on every tracked hit, to
 * answer a question the very next statement answers for free. The atomic
 * `INSERT … ON DUPLICATE KEY UPDATE` reports one affected row when it inserted and two
 * when it updated, so the increment itself says whether the counter already existed.
 *
 * Removing the probe is only safe if the seeding it protected still happens, and that
 * is the more important half of this test: a counter created from nothing starts at 1,
 * and handing out visit ID 1 on a site that already has millions of rows would merge
 * a new visitor into an existing visit's history. So:
 *
 *   1. The steady state costs exactly one query.
 *   2. A counter that did not exist is seeded past everything already stored, and the
 *      ID handed out is never one the table already holds.
 *   3. Seeding happens once, not on subsequent hits.
 *   4. A failing increment still yields a usable, non-colliding ID.
 *
 * @see src/Tracker/VisitIdGenerator.php
 * @see tests/bench/hit-cost.sh (the end-to-end measurement this pins)
 */

declare(strict_types=1);

namespace {

use SlimStat\Tracker\VisitIdGenerator;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

/**
 * Stand-in for wpdb that models the two statements this class depends on: the atomic
 * upsert's affected-row contract (1 = inserted, 2 = updated) and LAST_INSERT_ID().
 */
class VicqbFakeWpdb
{
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

    /** @var bool Force the increment to fail. */
    public $increment_fails = false;

    /**
     * Model a connection opened with CLIENT_FOUND_ROWS, where mysqli_affected_rows()
     * reports rows *matched* rather than rows *changed* — so an ON DUPLICATE KEY UPDATE
     * returns 1, exactly like an insert. wpdb passes MYSQL_CLIENT_FLAGS straight to
     * mysqli_real_connect(), so any site can be in this mode.
     *
     * @var bool
     */
    public $client_found_rows = false;

    public function __construct(?int $counter, int $max_visit_id)
    {
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

        if (stripos(ltrim($sql), 'INSERT') === 0) {
            if ($this->increment_fails) {
                return false;
            }
            if (null === $this->counter) {
                $this->counter       = 1;
                $this->insert_id     = 1;
                $this->rows_affected = 1; // inserted
            } else {
                $this->counter++;
                $this->insert_id     = $this->counter;
                $this->rows_affected = $this->client_found_rows ? 1 : 2; // updated
            }
            return 1;
        }

        if (stripos(ltrim($sql), 'UPDATE') === 0 && preg_match('/= *(\d+)/', $sql, $m)) {
            $this->counter       = (int) $m[1];
            $this->rows_affected = 1;
            return 1;
        }

        return 1;
    }

    public function get_var($sql)
    {
        $this->log[] = $this->shape($sql);

        if (stripos($sql, 'MAX(visit_id)') !== false) {
            return (string) $this->max_visit_id;
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
        if (stripos($sql, 'INSERT') === 0) {
            return 'INCREMENT';
        }
        return strtoupper(strtok($sql, ' '));
    }
}

// ── WordPress surface the class touches ─────────────────────────────────────
$GLOBALS['_vicqb_options'] = [];

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

// ── 4. CLIENT_FOUND_ROWS must not be mistaken for "the row was created" ─────
//
// On such a connection the affected-row count is 1 for an update as well as an insert.
// Reading that as "created" would reseed the counter on every single hit: one query
// becomes six, two of them the most expensive statements in this class, and the counter
// is clobbered each time.
$db = vicqb_boot(5000, 4999);
$db->client_found_rows = true;
$id = VisitIdGenerator::generateNextVisitId();
vicqb_assert(
    'an established counter is not reseeded under CLIENT_FOUND_ROWS',
    $db->log === ['INCREMENT'],
    'queries: ' . implode(', ', $db->log)
);
vicqb_assert(
    'the ID is still the next one under CLIENT_FOUND_ROWS',
    $id === 5001,
    "got {$id}, expected 5001"
);

// Seeding must still happen on that same connection when the row really is missing.
$db = vicqb_boot(null, 5000000);
$db->client_found_rows = true;
$id = VisitIdGenerator::generateNextVisitId();
vicqb_assert(
    'a genuinely missing counter is still seeded under CLIENT_FOUND_ROWS',
    $id > 5000000,
    "issued {$id} while the table already holds visit IDs up to 5,000,000"
);

// ── 5. A failing increment still yields a usable ID ─────────────────────────
$db = vicqb_boot(5000, 4999);
$db->increment_fails = true;
$id = VisitIdGenerator::generateNextVisitId();
vicqb_assert(
    'a failed increment falls back to a non-zero ID',
    $id > 0,
    "fallback produced {$id}"
);

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
