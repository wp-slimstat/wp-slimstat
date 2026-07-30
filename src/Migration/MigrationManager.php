<?php
declare(strict_types=1);

namespace SlimStat\Migration;


class MigrationManager
{
    private const OPTION_STATUS = 'slimstat_migration_status';

    private const OPTION_DISMISSED = 'slimstat_migration_dismissed';

    /**
     * How long the probe's answer stays good.
     *
     * Everything that can change the answer — running a migration, dismissing the notice,
     * undismissing it — calls forgetProbe(), so the cache is correct by invalidation rather
     * than by expiry. The TTL only bounds the one case invalidation cannot see: an admin
     * adding or dropping an index outside this UI.
     */
    private const PROBE_TTL = 12 * HOUR_IN_SECONDS;

    private const TRANSIENT_PROBE = 'slimstat_migration_probe';

    /** @var array<int, MigrationInterface> */
    private $migrations = [];

    /** Per-request memo — needsMigration() is asked twice per admin page load. */
    private $needsMemo;

    /**
     * @return array<int, MigrationInterface>
     */
    public function getMigrations(): array
    {
        return $this->migrations;
    }

    /**
     * Get only migrations that need to run.
     * @return array<int, MigrationInterface>
     */
    public function getRequiredMigrations(): array
    {
        return array_filter($this->migrations, fn($migration) => $migration->shouldRun());
    }

    public function register(MigrationInterface $migration): void
    {
        $this->migrations[] = $migration;
    }

    /**
     * Is anything outstanding?
     *
     * Asking is not free: every index migration answers shouldRun() with its own
     * `SHOW INDEX`, and this is consulted twice per admin page load — once to decide
     * whether to register the Migration page, once to decide whether to show the notice.
     * Measured unguarded on the reference install: **18 queries and 24.7 ms on every
     * admin page**, which is the same defect class that was just removed from the admin
     * bar. So the answer is memoised per request and the negative is cached across
     * requests.
     */
    public function needsMigration(): bool
    {
        if (null !== $this->needsMemo) {
            return $this->needsMemo;
        }

        if ('yes' === get_option(self::OPTION_DISMISSED)) {
            return $this->needsMemo = false;
        }

        $cached = get_transient(self::TRANSIENT_PROBE);
        if ('clean' === $cached || 'dirty' === $cached) {
            return $this->needsMemo = ('dirty' === $cached);
        }

        $needs       = false;
        $unavailable = false;
        foreach ($this->migrations as $migration) {
            if ($migration->shouldRun()) {
                $needs = true;
                break;
            }
            // A probe that could not reach the database answers "nothing to do" — the
            // safe answer, but not a KNOWN one.
            $unavailable = $unavailable
                || (method_exists($migration, 'probeUnavailable') && $migration->probeUnavailable());
        }

        // Never persist "I could not look" as "nothing to do". This cache has a
        // twelve-hour life and only run/dismiss/reset clear it, so caching an
        // unreachable database would hide the migration screen for half a day after
        // the admin fixed the very configuration that broke it — with no way to force
        // a re-probe.
        if (!$unavailable) {
            set_transient(self::TRANSIENT_PROBE, $needs ? 'dirty' : 'clean', self::PROBE_TTL);
        }

        return $this->needsMemo = $needs;
    }

    /**
     * Drop both the per-request memo and the cached negative.
     *
     * Anything that changes what the probe would answer — running a migration, dismissing
     * the notice, undismissing it — has to call this, or the UI reports the previous state.
     */
    public function forgetProbe(): void
    {
        $this->needsMemo = null;
        delete_transient(self::TRANSIENT_PROBE);
    }

    public function dismissNotice(): void
    {
        update_option(self::OPTION_DISMISSED, 'yes', false);
        $this->forgetProbe();
    }

    public function resetDismissal(): void
    {
        delete_option(self::OPTION_DISMISSED);
        $this->forgetProbe();
    }

    public function getStatus(): array
    {
        $status = get_option(self::OPTION_STATUS, []);
        return is_array($status) ? $status : [];
    }

    public function runAll(): array
    {
        $results = [];
        foreach ($this->migrations as $migration) {
            // Only run if needed, but always record status
            $ok = !$migration->shouldRun() || $migration->run();
            $results[$migration->getName()] = $ok;
        }

        update_option(self::OPTION_STATUS, $results, false);

        // Re-probe against the database we just changed, not against the answer cached
        // before we changed it.
        $this->forgetProbe();

        if (!$this->needsMigration()) {
            $this->dismissNotice();
        }

        return $results;
    }

    /**
     * Return a detailed diagnostics map for technical UI.
     * @return array<int,array{key:string,exists:bool,table:string,columns:string}>
     */
    public function getAllDiagnostics(): array
    {
        $diagnostics = [];
        foreach ($this->migrations as $migration) {
            $diagnostics = array_merge($diagnostics, $migration->getDiagnostics());
        }

        return $diagnostics;
    }
}
