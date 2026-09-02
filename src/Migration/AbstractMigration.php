<?php
declare(strict_types=1);

namespace SlimStat\Migration;

use wpdb;

/**
 * Base helper for concrete migrations.
 */
abstract class AbstractMigration implements MigrationInterface
{
    /** @var wpdb The connection the slim_* analytics tables live on. */
    protected $wpdb;

    /**
     * The connection WordPress core lives on — wp_users, wp_options.
     *
     * The same handle as $wpdb on every ordinary install. It differs only when
     * `slimstat_custom_wpdb` puts the analytics tables on another server, and then the
     * distinction is load-bearing: a migration that asks the analytics server about
     * wp_users gets no answer, and a migration that asks the core server about
     * slim_stats gets no answer either. Both failures are silent, because
     * $wpdb->get_var() returns null for a query that ERRORED exactly as it does for a
     * query that legitimately found nothing.
     *
     * @var wpdb
     */
    protected $coreWpdb;

    /**
     * One stable key for "this install's analytics tables could not be reached".
     *
     * Not per-migration. Ten migrations sharing one root cause produced ten rows in
     * `slimstat_degradations`, ten read-modify-write cycles of that option in a single
     * request (the same-message throttle is per key, so it deduped nothing), and ten
     * unlabelled bullets in the admin notice, since admin/index.php's label map is
     * keyed on stable machine keys and falls through to the raw slug.
     */
    protected const PROBE_DEGRADATION_KEY = 'migration_db_unreachable';

    /** @var bool Did a probe on this instance fail to reach the database? */
    private $probeUnavailable = false;

    public function __construct(wpdb $wpdb, ?wpdb $coreWpdb = null)
    {
        $this->wpdb     = $wpdb;
        $this->coreWpdb = $coreWpdb ?: $wpdb;
    }

    /**
     * The table prefix, taken from the CORE connection.
     *
     * The analytics tables are named with WordPress's prefix even when they live on
     * another server: admin/index.php builds every one of its schema statements as
     * `$GLOBALS['wpdb']->prefix . 'slim_stats'` and then runs it on the analytics
     * handle. Taking the prefix from the analytics handle instead would disagree with
     * the code that CREATED the tables — and `wpdb::$prefix` is `''` until someone
     * calls set_prefix(), which the custom-DB path does not always reach.
     */
    protected function tablePrefix(): string
    {
        return $this->coreWpdb->prefix;
    }

    /**
     * Did the query that just ran fail to reach its table?
     *
     * Every probe in this subsystem asks a question whose "no" and whose "I could not
     * look" are the same value — `get_var()` returns null for an errored query exactly
     * as it does for one that legitimately found nothing. Distinguishing them is the
     * difference between a migration that reports honestly and one that offers to run
     * forever and fails on every click.
     *
     * Read from `last_error` rather than probed with a separate `SHOW TABLES LIKE`.
     * Measured on MySQL 8.0.35: the probe cost ~0.31 ms against ~0.16 ms for the
     * `SHOW INDEX` it was guarding — roughly double, paid on every healthy install, to
     * pre-empt an error that is itself the cheap case. wpdb sets `last_error` on every
     * query and clears it at the start of the next one, so the same information is
     * already there for nothing.
     *
     * Records at most one degradation per instance: a caller in a loop over four tables
     * should leave one trace, not four.
     */
    protected function probeFailed(): bool
    {
        if ('' === (string) $this->wpdb->last_error) {
            return false;
        }

        if (!$this->probeUnavailable) {
            $this->probeUnavailable = true;
            \wp_slimstat::record_degradation(
                static::PROBE_DEGRADATION_KEY,
                sprintf(
                    'the analytics tables could not be read while checking "%s": %s. No migration '
                        . 'will run until this is resolved. If the tables are on a separate database, '
                        . 'check the custom-database settings.',
                    $this->getId(),
                    $this->wpdb->last_error
                ),
                \wp_slimstat::DEGRADATION_OPERATIONAL
            );
        }

        return true;
    }

    /**
     * Whether any probe on this migration could not reach the database.
     *
     * The manager consults this before caching a "clean" verdict for twelve hours: a
     * result of "I could not look" must not be persisted as "nothing to do", or fixing
     * the database configuration leaves the migration screen hidden for half a day with
     * no way to force a re-probe.
     */
    public function probeUnavailable(): bool
    {
        return $this->probeUnavailable;
    }

    abstract public function getId(): string;

	public function shouldRun(): bool
	{
		return true; // Default to needing run; override in subclass
	}

	/**
	 * Is this migration OFFERED rather than OWED?
	 *
	 * An optional migration is listed on the migration screen and can be run there by name, but
	 * it never raises the admin notice, is never part of "Apply All", and never makes
	 * needsMigration() true. `shouldRun()` still answers whether it has work to do — the two
	 * questions are separate, and conflating them is what would make "offered" mean "invisible".
	 *
	 * Exists because F10 Layer 1 is real, measured, and currently buys nothing (Run 9 / M7): the
	 * star schema's read path cannot pay while P4 keeps the parsed columns on the fact row. A
	 * fresh install gets `ua_id` for free — it is in the manifest, so it arrives in CREATE TABLE
	 * with no ALTER at all — while an existing site would pay a fact-table rebuild for a column
	 * nothing reads yet. Charging every upgrading site for that is not something a measured
	 * no-benefit result can justify.
	 *
	 * HOW MUCH THAT REBUILD COSTS is `AddUserAgentDimension::MEASURED_COST`, and this sentence
	 * used to answer it here instead: "~14 s at 443k rows, ~5 min at 10M". That was one of four
	 * copies of a figure nobody had measured for this migration, and it was roughly 34x under the
	 * one figure anyone had. A cost belongs to the migration that pays it, once.
	 *
	 * @since 6.0.0
	 */
	public function isOptional(): bool
	{
		return false;
	}

	/**
	 * What running this migration ACTUALLY cost, once, on a table someone measured — or null.
	 *
	 * A migration that says nothing to the admin about how long it takes leaves this alone. One
	 * that does must render the sentence from this constant rather than typing the number into
	 * it, so the figure exists exactly once and `tests/migration-duration-honesty-test.php` can
	 * tie it to the record that took it. Prose cannot be gated; a constant can.
	 *
	 * A CONSTANT READ THROUGH `static::`, NOT A METHOD, and that is not a style choice. The first
	 * version of this had subclasses override a `measuredCost()` method returning their own
	 * constant, and review proved the seam: replacing one override's body with a fabricated,
	 * uncited array left the gate GREEN while the fabricated figure rendered to the admin — the
	 * gate reads the CONSTANT, so anything the method did instead was invisible to it. Deleting
	 * the method removes the divergence rather than gating it, and takes three identical
	 * four-line overrides with it.
	 *
	 * Shape, every key required:
	 *
	 *   seconds  int|float  wall clock as measured, unrounded — the rounding is the renderer's
	 *   rows     int        the table it was measured on
	 *   engine   string     the server it ran on. Not decoration: MySQL 5.7 and below refuse
	 *                       INSTANT DDL outright, so the same ALTER is a different operation there
	 *   bound    string     'about' when the run was observed to completion; 'floor' when it was
	 *                       INTERRUPTED, so the figure is a lower bound and not a duration
	 *   record   string     which standing record holds the measurement
	 *   anchor   string     the heading in it, which must resolve to exactly one section
	 *   quotes   string[]   verbatim spans from that section which, between them, state the
	 *                       seconds figure and the row count. A citation that resolves to a
	 *                       section saying nothing about the number is decoration
	 *
	 * @var array{seconds:int|float,rows:int,engine:string,bound:string,record:string,anchor:string,quotes:array<int,string>}|null
	 */
	public const MEASURED_COST = null;

	/**
	 * The measured-cost fragment a getDescription() renders into its own sentence.
	 *
	 * A FRAGMENT rather than a whole sentence, because the migrations that have one say different
	 * things around it — one doubles for a populated archive, one is a floor from a run nobody
	 * has seen finish. Returns '' when nothing has been measured, so a caller that gains a
	 * measurement later needs no new branch.
	 */
	final public function measuredCostPhrase(): string
	{
		return null === static::MEASURED_COST ? '' : self::describeMeasuredCost(static::MEASURED_COST);
	}

	/**
	 * Render one measured cost.
	 *
	 * Pure, static and public so a source-level gate can exercise it with no database and no
	 * WordPress. That matters: the rounding below is the only place the admin's number is derived,
	 * and a rounding rule nothing runs is a rounding rule nobody has checked.
	 *
	 * ONE SHAPE FOR ALL OF THEM, and "on a N-row table" rather than "per N rows" deliberately.
	 * "Per" invites the reader to divide, and this class's own subclasses warn against exactly
	 * that: AddVisitIdentity's header records that a Run 7 extrapolation of this operation class
	 * came in 1.7x under when it was finally measured. A point measurement is what we have, so a
	 * point is what it says.
	 *
	 * CONTRACT: at least one second. Below that round() prints "0 seconds" and a max(1, …) guard
	 * would print "1 second" for a figure eight times smaller, so there is no honest wording yet
	 * and migration-duration-honesty-test.php refuses a declaration under 1 s rather than letting
	 * one round into a lie.
	 *
	 * @param array{seconds:int|float,rows:int,engine:string,bound:string} $cost
	 */
	public static function describeMeasuredCost(array $cost): string
	{
		$seconds = (float) $cost['seconds'];
		$minutes = $seconds >= 120.0;
		$amount  = (int) round($minutes ? $seconds / 60.0 : $seconds);

		// _n() rather than picking the plural here: $amount is fixed per migration, so English
		// never needs the singular in minutes mode — but Slavic and Arabic plural rules select
		// different forms at 2, 3, 5 and 21, and only _n() can reach them.
		$duration = sprintf(
			$minutes
				/* translators: %s: a whole number of minutes. */
				? _n('%s minute', '%s minutes', $amount, 'wp-slimstat')
				/* translators: %s: a whole number of seconds. */
				: _n('%s second', '%s seconds', $amount, 'wp-slimstat'),
			number_format_i18n($amount)
		);

		$template = 'floor' === $cost['bound']
			/* translators: 1: a duration, e.g. "8 minutes". 2: a row count, e.g. "440,000". 3: a database server, e.g. "MySQL 8". */
			? __('more than %1$s on a %2$s-row table (%3$s)', 'wp-slimstat')
			/* translators: 1: a duration, e.g. "8 seconds". 2: a row count, e.g. "440,000". 3: a database server, e.g. "MySQL 8". */
			: __('about %1$s on a %2$s-row table (%3$s)', 'wp-slimstat');

		return sprintf($template, $duration, self::describeRowCount((int) $cost['rows']), $cost['engine']);
	}

	/**
	 * A row count rounded to two significant figures, for a reader sizing their own table.
	 *
	 * TWO SIGNIFICANT FIGURES, NOT A FIXED SCALE, and the first version was the fixed scale: it
	 * rounded to the nearest ten thousand, so every measurement under 5,000 rows rendered as
	 * "a 0-row table" and 12,000 rendered as 10,000 — a 17% understatement. That is the identical
	 * defect the seconds contract four lines up exists to prevent, on the field beside it, and
	 * the fixtures at the time never went below 5,000 so nothing could see it. Found by review.
	 *
	 * Integers throughout. `round($rows / $scale) * $scale` divides in floating point and
	 * multiplies the result back up, the exact ordering ADR-17 forbids — the earlier version was
	 * written that way and tests/rounding-contract-test.php rejected it.
	 */
	private static function describeRowCount(int $rows): string
	{
		$digits = strlen((string) max(1, $rows));
		$scale  = $digits > 2 ? (int) pow(10, $digits - 2) : 1;

		return number_format_i18n(intdiv($rows + intdiv($scale, 2), $scale) * $scale);
	}

	public function getDiagnostics(): array
	{
		return []; // Default to no diagnostics; override in subclass
	}

	/**
	 * Does the analytics table have this column?
	 *
	 * Hoisted when AddVisitIdentity grew a second verbatim copy of
	 * AddUserAgentDimension's probe — the C41 guard ("could not look is never not
	 * there") living in two places is the two-parsers shape this codebase keeps
	 * re-recording. Name resolution goes through Schema::hasColumn(), the same read
	 * model ensure()'s drift report uses; the probeFailed() tail is layered here
	 * because it is a MIGRATION policy (a failed probe must not make run() skip its
	 * ALTER), not a property of the question.
	 */
	protected function columnExists(string $suffix, string $column): bool
	{
		$found = \SlimStat\Schema\Schema::hasColumn($this->wpdb, $suffix, $this->tablePrefix(), $column);

		return !$this->probeFailed() && $found;
	}

	/**
	 * Add one manifest-declared column: ALTER from the manifest, INPLACE first,
	 * bare retry, degradation on failure.
	 *
	 * One owner for the policy AddUserAgentDimension established and AddVisitIdentity
	 * copied: the DDL comes from Schema::addColumnSql() (which throws on a column the
	 * manifest does not declare — PITFALLS 30), the ALGORITHM=INPLACE, LOCK=NONE hint
	 * is how the statement RUNS rather than what the schema is, and a server that
	 * refuses the hint gets one bare retry, because refusing to add the column at all
	 * is worse than a slower rebuild the admin explicitly started. That retry is the
	 * path on which tracking writes block, which is why both callers' descriptions say
	 * so — see the note at the retry itself for why it is not recorded instead.
	 *
	 * @return bool True when the column is in place (or already was).
	 */
	protected function addManifestColumn(string $suffix, string $column, string $degradationKey): bool
	{
		if ($this->columnExists($suffix, $column)) {
			return true;
		}

		$add     = \SlimStat\Schema\Schema::addColumnSql($suffix, $column, $this->tablePrefix());
		$altered = $this->wpdb->query($add . ', ALGORITHM=INPLACE, LOCK=NONE');

		// The retry is reached only because the server REFUSED the online hint, so its
		// reachable domain is the BLOCKING case — a MyISAM table (installs created before
		// Schema::ENGINE pinned InnoDB still have them) copies under a write lock, and on a
		// tracking table blocked writes are dropped pageviews.
		//
		// Deliberately not recorded anywhere. record_degradation() was tried and reverted:
		// it renders "failed to load and were disabled … reinstalling the plugin normally
		// clears it" at error severity, which is false after a rebuild that SUCCEEDED, and
		// it self-deletes on the next admin_init (DEGRADATION_TTL), so it could not have
		// answered "which path ran" anyway. The subsystem's actual precedent is
		// ConvertTablesToUtf8mb4: a rebuild that blocks writes says so in its DESCRIPTION,
		// and the degradation channel stays for failures. Both callers' descriptions now do.
		//
		// The right surface is a PRE-run warning — the moment an admin can still schedule
		// the pause — which means an engine probe feeding getDiagnostics(). That widens the
		// diagnostics contract and migration.js with it, so it is post-beta work, recorded
		// here rather than half-built.
		if (false === $altered) {
			$altered = $this->wpdb->query($add);
		}

		if (false === $altered) {
			\wp_slimstat::record_degradation(
				$degradationKey,
				sprintf(
					'could not add %s to %s: %s',
					$column,
					$this->tablePrefix() . $suffix,
					(string) $this->wpdb->last_error
				),
				\wp_slimstat::DEGRADATION_OPERATIONAL
			);

			return false;
		}

		return true;
	}

	/**
	 * Build the manifest indexes that became buildable now that $column exists.
	 *
	 * ── Why a migration has to do this at all ────────────────────────────────────────────────
	 *
	 * `Schema::ensure()` reconciles indexes, but it SKIPS any index naming a column that does not
	 * exist yet (Schema::ensure()'s missing-column branch) and reports the skip into
	 * `indexes_skipped_missing_column`, which nothing reads. On an upgrading install ensure()
	 * runs BEFORE this migration — it is called from the version-gated upgrade, which then stamps
	 * the new version — so an index over a column this migration adds is skipped on the only pass
	 * ensure() gets. The next pass is the next release. Until then the upgraded install runs
	 * without an index the fresh install has had since `CREATE TABLE`.
	 *
	 * That is C39's fresh/upgraded divergence arriving through the skip mechanism instead of
	 * through six competing index creators. Gated by tests/upgrade-index-convergence-test.php,
	 * which derives the obligation from the manifest rather than from a list of index names.
	 *
	 * ── What it deliberately does not do ─────────────────────────────────────────────────────
	 *
	 * It builds ONLY the indexes that name $column, and only mandatory ones. Reconciling the whole
	 * table would make a migration that adds one column rebuild every index a site had switched
	 * off, and calling ensure() outright would also create tables — far more than adding a column
	 * has any business doing.
	 *
	 * Tables that do not reconcile are skipped. `slim_stats_archive` declares the same index set
	 * and reconciles none of it by design; building thirteen indexes on cold storage because a
	 * column arrived would invent an obligation the schema explicitly declines.
	 *
	 * Failure is recorded and swallowed. The column is already in place by the time this runs, so
	 * the migration has succeeded at the thing it exists to do; a missing index is slower, not
	 * broken, and turning it into a failed migration would re-offer an ALTER that already landed.
	 */
	protected function reconcileColumnIndexes(string $suffix, string $column, string $degradationKey): void
	{
		if (!\SlimStat\Schema\Schema::reconciles($suffix)) {
			return;
		}

		$wanted = \SlimStat\Schema\Schema::indexesForColumn($suffix, $column);
		if ([] === $wanted) {
			return;
		}

		$prefix = $this->tablePrefix();

		// One SHOW INDEX for the table, not one probe per index. indexState() returns the manifest
		// KEYS that are missing, which is what createIndexSql() takes, and it reports nothing at
		// all when the table cannot be read — so an unreachable table builds nothing rather than
		// issuing DDL against a name it could not confirm.
		$state = \SlimStat\Schema\Schema::indexState($this->wpdb, $suffix, $prefix);

		foreach ($wanted as $index) {
			if (!in_array($index, $state['missing'], true)) {
				continue;
			}

			if (false === $this->wpdb->query(\SlimStat\Schema\Schema::createIndexSql($suffix, $index, $prefix))) {
				\wp_slimstat::record_degradation(
					$degradationKey,
					sprintf(
						'could not create index %s on %s: %s. The column was added successfully; '
							. 'reports that use it will be slower until the index exists.',
						\SlimStat\Schema\Schema::resolve($index, $prefix),
						$prefix . $suffix,
						(string) $this->wpdb->last_error
					),
					\wp_slimstat::DEGRADATION_OPERATIONAL
				);
			}
		}
	}
}
