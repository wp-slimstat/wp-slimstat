<?php
declare(strict_types=1);

namespace SlimStat\Migration;

use SlimStat\Utils\OptionClaim;

class MigrationManager
{
    private const OPTION_STATUS = 'slimstat_migration_status';

    /**
     * Single-flight claim for runAll(). Not autoloaded: it is written on the admin path,
     * lives for the duration of one run, and joining `alloptions` for that would invalidate
     * the blob for every request on the site.
     */
    private const OPTION_RUN_CLAIM = 'slimstat_migration_run_claim';

    /**
     * Seconds after which a run claim is treated as abandoned.
     *
     * Longer than any single request's budget on purpose — taking over a claim whose holder
     * is still running is how you get the concurrent rebuilds the claim prevents.
     */
    private const RUN_CLAIM_STALE_AFTER = 900;

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
        return array_filter(
            $this->migrations,
            fn($migration) => !$migration->isOptional() && $migration->shouldRun()
        );
    }

    /**
     * Migrations that are OFFERED rather than owed, and have work to do.
     *
     * The other half of getRequiredMigrations(), and it exists because the first version of this
     * seam had no such method — the admin screen renders exclusively from the *required* set, so
     * excluding an optional migration from that set removed it from the UI entirely. "Opt-in"
     * silently meant "gone": no menu item, no row, and `getDescription()`'s new "Optional…" copy
     * unreachable in every locale. The unit test that was supposed to catch this asserted
     * against `getMigrations()`, which no rendering path calls.
     *
     * @return array<int, MigrationInterface>
     */
    public function getOfferedMigrations(): array
    {
        return array_filter(
            $this->migrations,
            fn($migration) => $migration->isOptional() && $migration->shouldRun()
        );
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
        // Before the memo and the transient, deliberately. Consulted after them, a 12 h
        // cached "dirty" would outlive the switch being thrown and the notice would keep
        // offering a button that must not be pressed.
        if (MigrationService::migrationsDisabled()) {
            return false;
        }

        if (null !== $this->needsMemo) {
            return $this->needsMemo;
        }

        // S8 — dismissal is keyed to the SET that was dismissed, not to the literal 'yes'.
        // needsMigration() short-circuits here, so a bare flag meant any migration added in
        // v6.1 never announced itself on a site that completed v6.0's — a
        // forward-compatibility hole in the exact mechanism the star-schema programme rides
        // on. A changed set produces a different fingerprint and re-arms the notice by
        // construction, with no upgrade step to remember.
        if ($this->migrationSetFingerprint() === get_option(self::OPTION_DISMISSED)) {
            return $this->needsMemo = false;
        }

        $cached = get_transient(self::TRANSIENT_PROBE);
        if ('clean' === $cached || 'dirty' === $cached) {
            return $this->needsMemo = ('dirty' === $cached);
        }

        $needs       = false;
        $unavailable = false;
        foreach ($this->migrations as $migration) {
            // An OFFERED migration never makes the answer true. It is listed on the screen and
            // runnable by name; what it must not do is put a notice on every admin page asking
            // for a fact-table rebuild that Run 9 measured as buying nothing yet.
            if ($migration->isOptional()) {
                continue;
            }

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

    /**
     * Fingerprint of the registered migration set.
     *
     * Built from getId(), never getName(): the latter is __()-wrapped, so keying on it would
     * make a site-language change look like a new set and re-announce every migration (C34
     * records the same defect in the per-migration checkpoints). Sorted so registration
     * order cannot change the answer.
     */
    private function migrationSetFingerprint(): string
    {
        $ids = [];
        foreach ($this->migrations as $migration) {
            $ids[] = $migration->getId();
        }

        sort($ids);

        return md5(implode('|', $ids));
    }

    public function dismissNotice(): void
    {
        update_option(self::OPTION_DISMISSED, $this->migrationSetFingerprint(), false);
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

    /**
     * Run one migration by id, under the same single-flight claim as runAll().
     *
     * This is the branch the concurrency hazard actually travels. migration.js posts
     * `migration: <id>` once PER STEP, and only posts the bare action after every step has
     * already run — so runAll() is the cheap final sweep, while THIS is where a
     * user-triggered ALGORITHM=COPY rebuild happens. Adding the claim to runAll() alone
     * protected the sweep and left the rebuild unserialised.
     *
     * Also keeps the status write and the probe invalidation with the run rather than in the
     * admin layer, where the option name was a hardcoded duplicate of OPTION_STATUS and
     * forgetProbe() was never called at all.
     *
     * @return bool|null null when there is no such migration, or the claim was lost.
     */
    /**
     * Take the single-flight claim, or take over a stale one.
     *
     * `finally` is exception-safe, not crash-safe — it does not run on a fatal, an OOM,
     * max_execution_time or a dropped connection, which are exactly how a multi-minute
     * ALGORITHM=COPY rebuild dies. The claim row has no TTL, so without takeover a killed
     * run wedges the runner permanently while the UI reports "success, nothing happened".
     * claim_schema_lock() learned this already; this is the same shape.
     */
    private function claimRun(): bool
    {
        if (OptionClaim::insert(self::OPTION_RUN_CLAIM, (string) time(), 'no')) {
            return true;
        }

        $held = (int) get_option(self::OPTION_RUN_CLAIM);

        if ($held > 0 && (time() - $held) < self::RUN_CLAIM_STALE_AFTER) {
            return false;
        }

        return OptionClaim::compareAndSwap(self::OPTION_RUN_CLAIM, (string) $held, (string) time(), 'no');
    }

    public function runOne(string $id): ?bool
    {
        if (MigrationService::migrationsDisabled() || !$this->claimRun()) {
            return null;
        }

        try {
            $target = null;
            foreach ($this->migrations as $migration) {
                if ($migration->getId() === $id) {
                    $target = $migration;
                    break;
                }
            }

            if (null === $target) {
                return null;
            }

            $ok = $target->run();

            $status = $this->getStatus();
            $status[$target->getId()] = $ok;
            update_option(self::OPTION_STATUS, $status, false);

            $this->forgetProbe();

            return $ok;
        } finally {
            delete_option(self::OPTION_RUN_CLAIM);
        }
    }

    public function runAll(): array
    {
        if (MigrationService::migrationsDisabled()) {
            return [];
        }

        // X7 — single flight. manage_options is held by every subsite Administrator and the
        // endpoint had no mutual exclusion, so N parallel POSTs gave N concurrent
        // ALGORITHM=COPY rebuilds contending on the metadata lock, each holding a connection
        // for lock_wait_timeout plus rebuild time. A losing caller is refused, not queued:
        // waiting behind a multi-minute table rebuild is how a request becomes a timeout.
        if (!$this->claimRun()) {
            return [];
        }
        $results = [];

        try {
            foreach ($this->migrations as $migration) {
                // "Apply All" applies everything OWED. An offered migration is skipped and left
                // out of the results entirely rather than recorded as true — writing `true` for
                // something that did not run is how a status map starts lying, and this map is
                // what the screen renders.
                if ($migration->isOptional()) {
                    continue;
                }

                // Only run if needed, but always record status
                $ok = !$migration->shouldRun() || $migration->run();
                $results[$migration->getId()] = $ok;
            }
        } finally {
            // Released on every path a `finally` can see. A crash skips it, which is what
            // the takeover above exists for.
            delete_option(self::OPTION_RUN_CLAIM);
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
