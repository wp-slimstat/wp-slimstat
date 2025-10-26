<?php
/**
 * Paginatable Interface
 *
 * @package SlimStat
 * @since 5.4.0
 */

declare(strict_types=1);

namespace SlimStat\Reports\Contracts;

/**
 * Interface PaginatableInterface
 *
 * For reports that support pagination.
 */
interface PaginatableInterface {
	/**
	 * Get the number of results per page.
	 *
	 * @return int Results per page
	 */
	public function get_results_per_page(): int;

	/**
	 * Get the current page number.
	 *
	 * @return int Current page (1-based)
	 */
	public function get_current_page(): int;

	/**
	 * Get the total number of results.
	 *
	 * @return int Total results count
	 */
	public function get_total_results(): int;

	/**
	 * Render pagination controls.
	 *
	 * @return string Pagination HTML
	 */
	public function render_pagination(): string;
}
