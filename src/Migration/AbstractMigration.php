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
                )
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
	 * with no ALTER at all — while an existing site would pay a fact-table rebuild (~14 s at
	 * 443k rows, ~5 min at 10M) for a column nothing reads yet. Charging every upgrading site
	 * for that is not something a measured no-benefit result can justify.
	 *
	 * @since 6.0.0
	 */
	public function isOptional(): bool
	{
		return false;
	}

	public function getDiagnostics(): array
	{
		return []; // Default to no diagnostics; override in subclass
	}
}
