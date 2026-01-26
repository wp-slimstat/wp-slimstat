<?php
/**
 * Filterable Interface
 *
 * @package SlimStat
 * @since 5.4.0
 */

declare(strict_types=1);

namespace SlimStat\Reports\Contracts;

/**
 * Interface FilterableInterface
 *
 * For reports that support filtering capabilities.
 */
interface FilterableInterface {
	/**
	 * Get the column name used for filtering.
	 *
	 * @return string Column name
	 */
	public function get_filter_column(): string;

	/**
	 * Get the filter operator (equals, contains, etc.).
	 *
	 * @return string Filter operator
	 */
	public function get_filter_operator(): string;

	/**
	 * Apply filters to the report data.
	 *
	 * @param array<string, mixed> $filters Filter parameters
	 * @return void
	 */
	public function apply_filters( array $filters ): void;
}
