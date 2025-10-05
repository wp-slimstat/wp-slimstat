<?php
declare(strict_types=1);

namespace SlimStat\Migration;

use SlimStat\Components\View;
use wpdb;

class MigrationManager
{
    private const OPTION_STATUS = 'slimstat_migration_status';
    private const OPTION_DISMISSED = 'slimstat_migration_dismissed';

    /** @var wpdb */
    private $wpdb;

    /** @var array<int, MigrationInterface> */
    private $migrations = [];

    public function __construct(wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
    }

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
        return array_filter($this->migrations, function($migration) {
            return $migration->shouldRun();
        });
    }

    public function register(MigrationInterface $migration): void
    {
        $this->migrations[] = $migration;
    }

    public function needsMigration(): bool
    {
        if ('yes' === get_option(self::OPTION_DISMISSED)) {
            return false;
        }

        foreach ($this->migrations as $migration) {
            if ($migration->shouldRun()) {
                return true;
            }
        }
		return false;
    }

    public function dismissNotice(): void
    {
        update_option(self::OPTION_DISMISSED, 'yes', false);
    }

    public function resetDismissal(): void
    {
        delete_option(self::OPTION_DISMISSED);
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
