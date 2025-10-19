<?php
/**
 * Has Pagination Trait
 *
 * @package SlimStat
 * @since 5.4.0
 */

declare(strict_types=1);

namespace SlimStat\Reports\Traits;

/**
 * Trait HasPagination
 *
 * Provides pagination capabilities to reports.
 */
trait HasPagination {
	/**
	 * Results per page
	 *
	 * @var int
	 */
	protected int $results_per_page = -1;

	/**
	 * Total results count
	 *
	 * @var int
	 */
	protected int $total_results = 0;

	/**
	 * Current page results count
	 *
	 * @var int
	 */
	protected int $page_results = 0;

	/**
	 * Set the number of results per page
	 *
	 * @param int $per_page Results per page
	 * @return self
	 */
	public function set_results_per_page( int $per_page ): self {
		$this->results_per_page = $per_page;
		return $this;
	}

	/**
	 * Set the total results count
	 *
	 * @param int $total Total results
	 * @return self
	 */
	public function set_total_results( int $total ): self {
		$this->total_results = $total;
		return $this;
	}

	/**
	 * Set the current page results count
	 *
	 * @param int $count Current page results
	 * @return self
	 */
	public function set_page_results( int $count ): self {
		$this->page_results = $count;
		return $this;
	}

	/**
	 * Get the number of results per page
	 *
	 * @return int
	 */
	public function get_results_per_page(): int {
		if ( -1 === $this->results_per_page ) {
			return (int) ( wp_slimstat::$settings['rows_to_show'] ?? 10 );
		}
		return $this->results_per_page;
	}

	/**
	 * Get the current page number
	 *
	 * @return int
	 */
	public function get_current_page(): int {
		if ( ! class_exists( 'wp_slimstat_db' ) ) {
			return 1;
		}

		$start_from = wp_slimstat_db::$filters_normalized['misc']['start_from'] ?? 0;
		return (int) floor( $start_from / $this->get_results_per_page() ) + 1;
	}

	/**
	 * Get the total number of results
	 *
	 * @return int
	 */
	public function get_total_results(): int {
		return $this->total_results;
	}

	/**
	 * Render pagination controls
	 *
	 * @return string Pagination HTML
	 */
	public function render_pagination(): string {
		if ( ! class_exists( 'wp_slimstat_reports' ) ) {
			return '';
		}

		return wp_slimstat_reports::report_pagination(
			$this->page_results,
			$this->total_results,
			false,
			$this->get_results_per_page()
		);
	}
}
