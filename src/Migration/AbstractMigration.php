<?php
declare(strict_types=1);

namespace SlimStat\Migration;

use wpdb;

/**
 * Base helper for concrete migrations.
 */
abstract class AbstractMigration implements MigrationInterface
{
    /** @var wpdb */
    protected $wpdb;

    public function __construct(wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
    }

    abstract public function getId(): string;

	public function shouldRun(): bool
	{
		return true; // Default to needing run; override in subclass
	}

	public function getDiagnostics(): array
	{
		return []; // Default to no diagnostics; override in subclass
	}
}
