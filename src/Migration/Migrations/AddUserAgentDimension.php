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
 *      INSTANT is REFUSED below MySQL 8.0.12, so ADR-2's floor pays INPLACE — 4.7 s at 152k rows.
 *   2. Backfill the dimension from `SELECT DISTINCT` over the facts. Interruptible, resumable,
 *      and cheap to repeat.
 *
 * STEP 2 IS THE COST, AND NOTHING HERE SAID SO FOR THE WHOLE OF v6.0.0'S DEVELOPMENT. This
 * docblock, isOptional() below, AbstractMigration::isOptional() and a unit test's header all
 * carried "~14 s at 443k rows, ~5 min at 10M" — an EXTRAPOLATION of step 1's 152k figure, quoted
 * in four places as the price of BOTH steps. It is not. There is no index on `ua_id` (see
 * PASS_SECONDS), so every distinct tuple's UPDATE is a full scan of the fact table, and a pass
 * stops on the clock with the client re-posting for the rest. MEASURED_COST below carries the
 * only figure anyone has taken: past eight minutes on the 443,543-row reference table, and that
 * run was INTERRUPTED — this migration has never been observed finishing. The description the
 * admin reads renders from that constant, so the two cannot drift apart again.
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
 * least likely to run Redis. So the dimension is derived here, and STALENESS RE-OFFERS THIS
 * MIGRATION: in v6.0.0 the tracker writes no ua_id at all (that write — M6's "local hash" —
 * and any cron refresh belong with Layer 2's reopen, M7), so new rows arrive unkeyed and
 * dimensionIsBehind() turns shouldRun() back on. An earlier draft of this paragraph credited
 * a "maintenance cron" refresher; no such code exists, and prose must not outrun the tree.
 *
 * That is affordable only because P4 keeps `browser`/`browser_version`/`browser_type`/`platform`
 * ON the fact row in v6.0.0. The dimension is an index over data that is already there, so a
 * report whose `LEFT JOIN` finds no dimension row still counts the hit from the fact row's own
 * columns. The failure mode is "no faster", never "no data".
 */
class AddUserAgentDimension extends AbstractMigration
{
    /**
     * See AbstractMigration::MEASURED_COST. `bound` is 'floor' and that is the whole point: the
     * run was stopped, so 480 s is a number this migration EXCEEDED, not one it took.
     */
    public const MEASURED_COST = [
        'seconds' => 480,
        'rows'    => 443543,
        'engine'  => 'MySQL 8',
        'bound'   => 'floor',
        'record'  => 'VERIFICATION-PROTOCOL.md',
        'anchor'  => 'Run 58',
        'quotes'  => [
            'it ran past **eight minutes** on this dataset',
            'own live dump: 443,543 rows',
        ],
    ];

    /** Rows read per backfill pass. Bounded so one pass cannot exhaust memory on a large table. */
    private const BATCH = 500;

    /**
     * Seconds one backfill pass may spend stamping fact rows before it stops and reports back.
     *
     * THE BOUND THAT ACTUALLY BINDS. set_time_limit() is a silent no-op under
     * disabled_functions and under PHP-FPM's request_terminate_timeout — which is the hosting
     * class most likely to kill a multi-minute rebuild — so a test asserting the CALL is
     * present would be green on every install where it does nothing. An in-request deadline
     * cannot be disabled by the host.
     *
     * There is no index on `ua_id`, so each UPDATE below is a full scan of the fact table:
     * a pass is bounded by TIME, not by row count, because the cost of one tuple varies with
     * table size. Ten seconds is comfortably inside the default max_execution_time of 30 while
     * leaving room for the DISTINCT probe and the response.
     */
    private const PASS_SECONDS = 10;

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
        return sprintf(
            /* translators: %s: a measured cost, e.g. "more than 8 minutes on a 440,000-row table (MySQL 8)". */
            __(
                'Optional. Adds a compact browser key to the analytics table AND to the archive '
                    . 'table, and builds a lookup of browsers and platforms — groundwork for a '
                    . 'future release. It does not make any report faster today, and it is by far '
                    . 'the slowest step here: %s — and that run was stopped before it finished, so '
                    . 'it is a minimum and not an estimate. On a larger table you may not be able '
                    . 'to complete it from this screen at all. Your existing data is not modified, '
                    . 'and reports keep working while it runs. Tracking normally keeps working '
                    . 'too, but a server that cannot rebuild the table online will pause tracking '
                    . 'writes for the whole rebuild, so on a large site prefer a quiet period.',
                'wp-slimstat'
            ),
            $this->measuredCostPhrase()
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
     * What is opt-in is the part that COSTS something — the rebuild and backfill an existing site
     * would otherwise pay on the migration screen, MEASURED_COST above, for a column nothing
     * reads yet.
     */
    public function isOptional(): bool
    {
        return true;
    }

    public function shouldRun(): bool
    {
        // Three independent conditions, and the ORs matter: a run interrupted between the
        // ALTER and the backfill leaves the column present and the dimension empty, which is a
        // state that must still report "needs to run" — and a run from before the archive was
        // included leaves the fact table done and the archive short, which must too, or the
        // migration reports complete while every future purge silently drops the column.
        return !$this->factColumnExists()
            || !$this->columnExists('slim_stats_archive', 'ua_id')
            || $this->dimensionIsBehind();
    }

    public function run(): bool
    {
        // The ALTER policy — manifest DDL (PITFALLS 30), INPLACE-then-bare retry,
        // degradation on failure — was hoisted to AbstractMigration::addManifestColumn()
        // when AddVisitIdentity would otherwise have carried a second verbatim copy.
        // INPLACE matters here specifically: the fallback rebuild is silent and 3.9x
        // worse, and on the floor it blocks writes (see the class docblock).
        // TWO TABLES, and the archive is not optional here. STATS_COLUMNS names `ua_id`, so
        // an archive that lacks it while the fact table has it is a permanent `lost` column:
        // every purge from then on copies one field fewer into the archive and there is no
        // remedy short of dropping the archive table. AddVisitIdentity's docblock states this
        // rule for `vid_hash` and calls addManifestColumn twice; this one stated the rule in a
        // sibling file and then added the column to one table.
        //
        // Two literal calls, not a loop, for the same reason AddVisitIdentity gives: the
        // schema-single-source gate resolves LITERAL (table, column) arguments against the
        // manifest, and a variable table name is a call site that gate cannot see.
        $ok = $this->addManifestColumn('slim_stats', 'ua_id', 'add_user_agent_dimension')
            && $this->addManifestColumn('slim_stats_archive', 'ua_id', 'add_user_agent_dimension');

        if (!$ok) {
            return false;
        }

        return $this->backfill();
    }

    /**
     * Populate the dimension from the facts, and stamp each fact row with its key.
     *
     * Reads DISTINCT combinations rather than rows: a site with a million pageviews has a few
     * thousand distinct browser/platform tuples, so the work is bounded by cardinality and not
     * by table size. That is the property that makes this affordable to re-run each time
     * staleness re-offers the migration (there is no cron — see the class docblock).
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

        $deadline = microtime(true) + self::PASS_SECONDS;
        $stamped  = 0;

        foreach ($rows as $row) {
            // Stop on the deadline rather than on the batch. Returning here is PROGRESS, not
            // failure: every tuple already stamped stays stamped, and the next pass picks up
            // whatever is still NULL.
            if (microtime(true) >= $deadline) {
                break;
            }

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
            //
            // NULL IS MATCHED WITH `IS NULL`, NOT BOUND. `<=>` is null-safe in SQL, but wpdb
            // cannot BIND a null: _real_escape() returns '' for any non-scalar, so a tuple whose
            // browser_version is NULL — the ordinary case, because UADetector defaults it to ''
            // and the tracker's array_filter drops empties before the row is written — produced
            // `browser_version <=> ''`, which is 0 against NULL. Those rows were never stamped,
            // dimensionIsBehind() never settled, and the migration could not finish. Harmless
            // before, because one pass returned false and stopped; with the client now
            // re-posting while work remains, it would have spun for the whole pass cap.
            $where = ['ua_id IS NULL'];
            $args  = [$key];

            foreach ([
                'browser'         => $row['browser'],
                'browser_version' => $row['browser_version'],
                'browser_type'    => null === $row['browser_type'] ? null : (int) $row['browser_type'],
                'platform'        => $row['platform'],
            ] as $column => $value) {
                if (null === $value) {
                    $where[] = "`{$column}` IS NULL";
                    continue;
                }

                $where[] = "`{$column}` <=> " . (is_int($value) ? '%d' : '%s');
                $args[]  = $value;
            }

            $this->wpdb->query($this->wpdb->prepare(
                "UPDATE `{$stats}` SET ua_id = %s WHERE " . implode(' AND ', $where),
                $args
            ));

            $stamped += max(0, (int) $this->wpdb->rows_affected);
        }

        // NON-CONVERGENCE IS FAILURE, though, and it has to be distinguishable from progress.
        // A pass that found tuples to stamp and stamped no rows is not making progress and will
        // not on the next pass either; saying "true, there is more" forever would hand the
        // client a loop with no exit but its own cap.
        if (0 === $stamped && $this->dimensionIsBehind()) {
            \wp_slimstat::record_degradation(
                'add_user_agent_dimension',
                'the browser-dimension backfill selected rows to key but updated none, so it '
                    . 'cannot finish. The migration has been stopped rather than retried.',
                \wp_slimstat::DEGRADATION_OPERATIONAL
            );

            return false;
        }

        // PROGRESS IS NOT FAILURE, and returning !dimensionIsBehind() here said it was.
        //
        // A pass that stamped 500 tuples correctly and left 4,000 to go returned false, which
        // runOne() recorded as false, which the UI painted red as "Failed" — on a run that had
        // done exactly what it was asked. The admin was told a migration failed, given no
        // indication that clicking again would help, and left with a half-stamped table they
        // had no reason to trust.
        //
        // "Did it work" and "is it finished" are different questions. This answers the first;
        // shouldRun() answers the second, and the AJAX response now carries it so the client
        // can re-post the same step until it is done.
        return true;
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
        // Delegates to the hoisted probe so the C41 guard ("could not look is never
        // not there") has ONE owner across every column migration.
        return $this->columnExists('slim_stats', 'ua_id');
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
