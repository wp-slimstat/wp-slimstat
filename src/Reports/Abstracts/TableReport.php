<?php
/**
 * Abstract Table Report Class
 *
 * @package SlimStat
 * @since 5.4.0
 */

declare(strict_types=1);

namespace SlimStat\Reports\Abstracts;

use SlimStat\Reports\Contracts\FilterableInterface;
use SlimStat\Reports\Contracts\PaginatableInterface;

/**
 * Class TableReport
 *
 * Base class for reports that display data in table/list format.
 */
abstract class TableReport extends AbstractReport implements FilterableInterface, PaginatableInterface {
	/**
	 * Report type (top, recent)
	 *
	 * @var string
	 */
	protected string $type = 'top';

	/**
	 * Column(s) to select
	 *
	 * @var string
	 */
	protected string $columns = '';

	/**
	 * WHERE clause
	 *
	 * @var string
	 */
	protected string $where = '';

	/**
	 * WHERE clause parameters
	 *
	 * @var array<mixed>|null
	 */
	protected ?array $where_params = null;

	/**
	 * Column alias (for display)
	 *
	 * @var string
	 */
	protected string $as_column = '';

	/**
	 * Filter operator
	 *
	 * @var string
	 */
	protected string $filter_op = 'equals';

	/**
	 * Additional columns to fetch
	 *
	 * @var string
	 */
	protected string $more_columns = '';

	/**
	 * Results per page
	 *
	 * @var int
	 */
	protected int $results_per_page = -1;

	/**
	 * Use date filters
	 *
	 * @var bool
	 */
	protected bool $use_date_filters = true;

	/**
	 * Database query method
	 *
	 * @var array<callable>
	 */
	protected array $raw = [];

	/**
	 * {@inheritDoc}
	 */
	public function get_filter_column(): string {
		return $this->as_column ?: $this->columns;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_filter_operator(): string {
		return $this->filter_op;
	}

	/**
	 * {@inheritDoc}
	 */
	public function apply_filters( array $filters ): void {
		// Filters are applied via wp_slimstat_db
		// This method can be overridden for custom filter logic
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_results_per_page(): int {
		if ( -1 === $this->results_per_page ) {
			return (int) ( wp_slimstat::$settings['rows_to_show'] ?? 10 );
		}
		return $this->results_per_page;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_current_page(): int {
		$start_from = wp_slimstat_db::$filters_normalized['misc']['start_from'] ?? 0;
		return (int) floor( $start_from / $this->get_results_per_page() ) + 1;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_total_results(): int {
		// This should be calculated from the query result
		return 0;
	}

	/**
	 * {@inheritDoc}
	 */
	public function render_pagination(): string {
		if ( ! class_exists( 'wp_slimstat_reports' ) ) {
			return '';
		}

		return wp_slimstat_reports::report_pagination(
			count( $this->get_data() ),
			$this->get_total_results(),
			false,
			$this->get_results_per_page()
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_renderer(): string {
		return 'SlimStat\Reports\Renderer\TableRenderer';
	}

	/**
	 * {@inheritDoc}
	 */
	public function render_content(): void {
		if ( 'on' === ( wp_slimstat::$settings['async_load'] ?? 'off' ) && ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) ) {
			return;
		}

		if ( ! class_exists( 'wp_slimstat_reports' ) ) {
			return;
		}

		wp_slimstat_reports::raw_results_to_html( $this->get_callback_args() );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_data(): array {
		if ( empty( $this->raw ) || ! is_callable( $this->raw ) ) {
			return [];
		}

		return call_user_func( $this->raw, $this->get_callback_args() );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_callback_args(): array {
		$args = [
			'type'             => $this->type,
			'columns'          => $this->columns,
			'where'            => $this->where,
			'filter_op'        => $this->filter_op,
			'use_date_filters' => $this->use_date_filters,
			'raw'              => $this->raw,
		];

		if ( ! empty( $this->where_params ) ) {
			$args['where_params'] = $this->where_params;
		}

		if ( ! empty( $this->as_column ) ) {
			$args['as_column'] = $this->as_column;
		}

		if ( ! empty( $this->more_columns ) ) {
			$args['more_columns'] = $this->more_columns;
		}

		return $args;
	}
}
