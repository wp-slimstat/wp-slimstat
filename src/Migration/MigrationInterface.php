<?php
declare(strict_types=1);

namespace SlimStat\Migration;

/**
 * Public contract for a SlimStat DB migration unit.
 *
 * @license GPL-2.0-or-later
 */
interface MigrationInterface
{
	/**
	 * Human-readable name for this migration.
	 */
	public function getName(): string;

	/**
	 * Unique non-translatable ID for this migration.
	 */
	public function getId(): string;

	/**
	 * Short description of what this migration does.
	 */
	public function getDescription(): string;

	/**
	 * Execute the migration. Must be idempotent and safe to re-run.
	 *
	 * Return true on success, false on handled failure.
	 */
	public function run(): bool;

	/**
	 * Check if this migration needs to be run.
	 *
	 * @return bool True if migration is needed.
	 */
	public function shouldRun(): bool;

	/**
	 * Return a detailed diagnostics map for technical UI.
	 *
	 * @return array<int,array{key:string,exists:bool,table:string,columns:string}>
	 */
	public function getDiagnostics(): array;
}
