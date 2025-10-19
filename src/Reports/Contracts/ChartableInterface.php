<?php
/**
 * Chartable Interface
 *
 * @package SlimStat
 * @since 5.4.0
 */

declare(strict_types=1);

namespace SlimStat\Reports\Contracts;

/**
 * Interface ChartableInterface
 *
 * For reports that display chart/graph visualizations.
 */
interface ChartableInterface {
	/**
	 * Get the chart data configuration.
	 *
	 * @return array<string, mixed> Chart data (data1, data2, where clause, etc.)
	 */
	public function get_chart_data(): array;

	/**
	 * Get the chart labels.
	 *
	 * @return array<string> Array of translated label strings
	 */
	public function get_chart_labels(): array;

	/**
	 * Get the chart type.
	 *
	 * @return string Chart type (line, bar, pie, etc.)
	 */
	public function get_chart_type(): string;
}
