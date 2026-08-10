<?php
declare(strict_types=1);

namespace SlimStat\Migration\Migrations;

use SlimStat\Migration\AbstractMigration;
use SlimStat\Schema\Schema;
use SlimStat\Schema\SurrogateKey;

/**
 * ADR-9 Layer 1 — add `ua_id` to the fact table and backfill the user-agent dimension (F10/G3).
 *
 * TWO STEPS, DELIBERATELY SEPARABLE, because they fail differently and at different cost.
 *
 *   1. `ALTER TABLE … ADD COLUMN ua_id BINARY(8)` — a fact-table rebuild. Measured (Run 7):
 *      INSTANT is REFUSED below MySQL 8.0.12, so ADR-2's floor pays INPLACE — 4.7 s at 152k
 *      rows, ~14 s at the 443k reference table, ~5 minutes at 10M.
 *   2. Backfill the dimension from `SELECT DISTINCT` over the facts. Interruptible, resumable,
 *      and cheap to repeat.
 *
 * If step 1 succeeds and step 2 is interrupted, re-running does the right thing: the column is
 * already there, and the backfill is an `INSERT IGNORE` over rows it may have inserted before.
 * Neither step is destructive and neither needs the other to have completed atomically.
 *
 * ALGORITHM=INPLACE IS REQUESTED EXPLICITLY, and that is the whole safety story for step 1.
 * INPLACE rebuilds the table but permits concurrent DML on MySQL 5.6+, so tracking keeps
 * working while it runs. COPY is 3.9x slower and BLOCKS WRITES for the duration — on a large
 * site that is the "long table rebuild reachable from an anonymous pageview" hazard S7 already
 * had to fix once. MySQL silently falls back between algorithms, so asking for one by name is
 * the only way to know which was used.
 *
 * WHY THE TRACKER NEVER WRITES THE DIMENSION (ratified M6). The obvious alternative is
 * `INSERT IGNORE` after each fact row — correct, race-free, and one extra query on EVERY tracked
 * pageview, on a branch committed to ~13 fewer. Caching does not rescue it: without a persistent
 * object cache `wp_cache` is per-request, and the sites that most need the star schema are the
 * least likely to run Redis. So the dimension is derived here and refreshed by the maintenance
 * cron, and the tracker's cost stays a local hash.
 *
 * That is affordable only because P4 keeps `browser`/`browser_version`/`browser_type`/`platform`
 * ON the fact row in v6.0.0. The dimension is an index over data that is already there, so a
 * report whose `LEFT JOIN` finds no dimension row still counts the hit from the fact row's own
 * columns. The failure mode is "no faster", never "no data".
 */
class AddUserAgentDimension extends AbstractMigration
{
    /** Rows read per backfill pass. Bounded so one pass cannot exhaust memory on a large table. */
    private const BATCH = 500;

    public function getId(): string
    {
        return 'add-user-agent-dimension';
    }

    public function getName(): string
    {
        return __('Build the browser dimension', 'wp-slimstat');
    }

    public function getDescription(): string
    {
        return __(
            'Optional. Adds a compact browser key to the analytics table and builds a lookup of '
                . 'browsers and platforms — groundwork for a future release. It does not make any '
                . 'report faster today, and on a large table it can take several minutes. Your '
                . 'existing data is not modified and reports keep working while it runs.',
            'wp-slimstat'
        );
    }

    /**
     * OFFERED, NOT OWED — and the honest description above says why.
     *
     * Run 9 measured what this buys on the read path: nothing, and it cannot buy anything while
     * P4 keeps `browser`/`browser_version`/`browser_type`/`platform` on the fact row. The
     * dimension is an index over data that is already there, and `idx_dt_browser_browser_version`
     * already indexes it better — a covering range scan the dimension join can only lose to.
     *
     * A fresh install still gets `ua_id` for free: it is declared in the manifest, so it arrives
     * inside CREATE TABLE with no ALTER at all, and Layer 2 will find it waiting when P4 moves.
     * What is opt-in is the part that COSTS something — the fact-table rebuild an existing site
     * would otherwise pay on the migration screen (~14 s at 443k rows, ~5 min at 10M) for a
     * column nothing reads yet.
     */
    public function isOptional(): bool
    {
        return true;
    }

    public function shouldRun(): bool
    {
        // Two independent conditions, and the OR matters: a run interrupted between the ALTER
        // and the backfill leaves the column present and the dimension empty, which is a state
        // that must still report "needs to run".
        return !$this->factColumnExists() || $this->dimensionIsBehind();
    }

    public function run(): bool
    {
        $stats = $this->tablePrefix() . 'slim_stats';

        if (!$this->factColumnExists()) {
            // The column DDL comes from the manifest, never from here. This class wrote
            // `BINARY(8) NULL` while Schema declared `BINARY(8) DEFAULT NULL` — equivalent to
            // MySQL, but two spellings of one fact, and it was two spellings only because for
            // one commit there was no declaration at all (PITFALLS 30). Naming the column
            // through Schema is what makes that unrepresentable: addColumnSql() throws on a
            // column the manifest does not declare, so the next migration cannot add one
            // silently the way this one did.
            //
            // The algorithm hint is appended rather than declared: it is how the statement runs,
            // not what the schema is. INPLACE by name — see the class docblock; the fallback is
            // silent and 3.9x worse, and on the floor it blocks writes.
            $add     = Schema::addColumnSql('slim_stats', 'ua_id', $this->tablePrefix());
            $altered = $this->wpdb->query($add . ', ALGORITHM=INPLACE, LOCK=NONE');

            if (false === $altered) {
                // Retried WITHOUT the algorithm hint before giving up. A server that cannot do
                // INPLACE for this change refuses the statement outright rather than falling
                // back, and refusing to add the column at all would be worse than a slower
                // rebuild the admin has explicitly started from the migration screen.
                $altered = $this->wpdb->query($add);
            }

            if (false === $altered) {
                \wp_slimstat::record_degradation(
                    'add_user_agent_dimension',
                    sprintf('could not add ua_id to %s: %s', $stats, (string) $this->wpdb->last_error)
                );

                return false;
            }
        }

        return $this->backfill();
    }

    /**
     * Populate the dimension from the facts, and stamp each fact row with its key.
     *
     * Reads DISTINCT combinations rather than rows: a site with a million pageviews has a few
     * thousand distinct browser/platform tuples, so the work is bounded by cardinality and not
     * by table size. That is the property that makes this affordable to re-run from cron.
     */
    private function backfill(): bool
    {
        $stats     = $this->tablePrefix() . 'slim_stats';
        $dimension = $this->tablePrefix() . 'slim_user_agents';

        $rows = $this->wpdb->get_results(
            "SELECT DISTINCT browser, browser_version, browser_type, platform
               FROM `{$stats}`
              WHERE ua_id IS NULL
              LIMIT " . self::BATCH,
            ARRAY_A
        );

        if ($this->probeFailed()) {
            return false;
        }

        if (empty($rows)) {
            return true; // nothing left to key
        }

        foreach ($rows as $row) {
            $natural = $this->naturalKey($row);
            $key     = SurrogateKey::for($natural);

            // INSERT IGNORE: if two passes race, or a previous run already inserted this tuple,
            // the loser is a no-op. No read, no lock, no retry — the same property that makes
            // the derived key worth having.
            $this->wpdb->query($this->wpdb->prepare(
                "INSERT IGNORE INTO `{$dimension}`
                    (ua_id, browser, browser_version, browser_type, platform, first_seen)
                 VALUES (%s, %s, %s, %d, %s, %d)",
                $key,
                $row['browser'],
                $row['browser_version'],
                (int) $row['browser_type'],
                $row['platform'],
                time()
            ));

            // Stamp every fact row sharing this tuple. Bounded by the tuple, not the table.
            $this->wpdb->query($this->wpdb->prepare(
                "UPDATE `{$stats}` SET ua_id = %s
                  WHERE ua_id IS NULL
                    AND browser <=> %s AND browser_version <=> %s
                    AND browser_type <=> %d AND platform <=> %s",
                $key,
                $row['browser'],
                $row['browser_version'],
                (int) $row['browser_type'],
                $row['platform']
            ));
        }

        // Resumable by construction: the next call picks up whatever is still NULL. Reporting
        // "not finished" here is honest rather than looping until a timeout kills the request.
        return !$this->dimensionIsBehind();
    }

    /**
     * The string the surrogate key is derived from.
     *
     * ONE definition, used by both the backfill and anything that later needs to compute a key
     * for a live hit. Two implementations of a key are free to disagree, and the disagreement
     * would show up as a dimension row that nothing joins to.
     *
     * `<=>` is used in the queries above rather than `=` for the same reason this concatenates
     * with a separator: these columns are nullable, and NULL is a real value here — an unparsed
     * user agent is not the same tuple as an empty one.
     *
     * @param array<string,mixed> $row
     */
    private function naturalKey(array $row): string
    {
        return implode("\x1f", [
            (string) $row['browser'],
            (string) $row['browser_version'],
            (string) (int) $row['browser_type'],
            (string) $row['platform'],
        ]);
    }

    private function factColumnExists(): bool
    {
        $stats = $this->tablePrefix() . 'slim_stats';

        $found = $this->wpdb->get_var(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = '{$stats}'
                AND COLUMN_NAME = 'ua_id'"
        );

        return !$this->probeFailed() && (int) $found > 0;
    }

    private function dimensionIsBehind(): bool
    {
        if (!$this->factColumnExists()) {
            return true;
        }

        $stats = $this->tablePrefix() . 'slim_stats';

        // LIMIT 1 rather than COUNT(*): the question is "is any row unkeyed", and on a 10M-row
        // table a full count to answer a yes/no is the kind of probe this programme removes.
        $pending = $this->wpdb->get_var("SELECT 1 FROM `{$stats}` WHERE ua_id IS NULL LIMIT 1");

        return !$this->probeFailed() && null !== $pending;
    }

    /** @return array<int,array{key:string,exists:bool,table:string,columns:string}> */
    public function getDiagnostics(): array
    {
        return [[
            'key'     => 'ua_id',
            'exists'  => $this->factColumnExists(),
            'table'   => $this->tablePrefix() . 'slim_stats',
            'columns' => implode(', ', array_keys(Schema::columns('slim_user_agents'))),
        ]];
    }
}
