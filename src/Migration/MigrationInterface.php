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
	 * Is the work this migration has to do OWED, or merely OFFERED?
	 *
	 * Separate from shouldRun(), which answers whether there is work at all. An offered
	 * migration is listed on the migration screen with its own control, and it never raises the
	 * admin notice and is never part of "Apply All".
	 *
	 * DECLARED HERE rather than consulted through `method_exists()`, which is what the first
	 * version did on the stated grounds that "MigrationInterface is implemented outside
	 * AbstractMigration in the test suite". That was checked afterwards and is false: across
	 * free, Pro and the whole test tree, `AbstractMigration` is the only implementer, and every
	 * double in the suite extends it. So the guard was unconditionally true and bought nothing —
	 * while costing the two things an interface gives: a misspelled override
	 * (`isOptionnal()`) is silently treated as owed instead of failing, and PHPStan cannot see
	 * the method on a `MigrationInterface`-typed parameter.
	 *
	 * @since 6.0.0
	 */
	public function isOptional(): bool;

	/**
	 * Return a detailed diagnostics map for technical UI.
	 *
	 * @return array<int,array{key:string,exists:bool,table:string,columns:string}>
	 */
	public function getDiagnostics(): array;
}
