<?php
/**
 * Has Filters Trait
 *
 * @package SlimStat
 * @since 5.4.0
 */

declare(strict_types=1);

namespace SlimStat\Reports\Traits;

/**
 * Trait HasFilters
 *
 * Provides filtering capabilities to reports.
 */
trait HasFilters {
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
	 * Filter operator
	 *
	 * @var string
	 */
	protected string $filter_op = 'equals';

	/**
	 * Use date filters
	 *
	 * @var bool
	 */
	protected bool $use_date_filters = true;

	/**
	 * Set the WHERE clause
	 *
	 * @param string              $where  WHERE clause
	 * @param array<mixed>|null $params Parameters for prepared statement
	 * @return self
	 */
	public function set_where( string $where, ?array $params = null ): self {
		$this->where        = $where;
		$this->where_params = $params;
		return $this;
	}

	/**
	 * Set the filter operator
	 *
	 * @param string $operator Filter operator (equals, contains, etc.)
	 * @return self
	 */
	public function set_filter_operator( string $operator ): self {
		$this->filter_op = $operator;
		return $this;
	}

	/**
	 * Enable or disable date filters
	 *
	 * @param bool $use Whether to use date filters
	 * @return self
	 */
	public function set_use_date_filters( bool $use ): self {
		$this->use_date_filters = $use;
		return $this;
	}

	/**
	 * Get the combined WHERE clause
	 *
	 * @return string
	 */
	protected function get_combined_where(): string {
		if ( empty( $this->where ) ) {
			return '';
		}

		if ( ! class_exists( 'wp_slimstat_db' ) ) {
			return $this->where;
		}

		// Use wp_slimstat_db to combine with date filters if needed
		if ( $this->use_date_filters && method_exists( 'wp_slimstat_db', 'get_combined_where' ) ) {
			return wp_slimstat_db::get_combined_where(
				$this->where,
				'',
				true,
				'',
				$this->where_params
			);
		}

		return $this->where;
	}
}
