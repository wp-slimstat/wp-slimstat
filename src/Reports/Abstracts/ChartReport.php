<?php
/**
 * Abstract Chart Report Class
 *
 * @package SlimStat
 * @since 5.4.0
 */

declare(strict_types=1);

namespace SlimStat\Reports\Abstracts;

use SlimStat\Reports\Contracts\ChartableInterface;
use SlimStat\Modules\Chart;

/**
 * Class ChartReport
 *
 * Base class for reports that display charts/graphs.
 */
abstract class ChartReport extends AbstractReport implements ChartableInterface {
	/**
	 * Chart type (line, bar, pie, etc.)
	 *
	 * @var string
	 */
	protected string $chart_type = 'line';

	/**
	 * {@inheritDoc}
	 */
	protected function init(): void {
		$this->classes[] = 'chart';
		$this->classes[] = 'extralarge';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_chart_type(): string {
		return $this->chart_type;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_renderer(): string {
		return 'SlimStat\Reports\Renderer\ChartRenderer';
	}

	/**
	 * {@inheritDoc}
	 */
	public function render_content(): void {
		if ( 'on' === ( wp_slimstat::$settings['async_load'] ?? 'off' ) && ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) ) {
			return;
		}

		$chart = new Chart();
		$chart->showChart( $this->get_chart_args() );

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			die();
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_data(): array {
		return $this->get_chart_data();
	}

	/**
	 * Get chart arguments for the Chart module
	 *
	 * @return array<string, mixed>
	 */
	protected function get_chart_args(): array {
		return [
			'id'           => $this->get_id(),
			'chart_data'   => $this->get_chart_data(),
			'chart_labels' => $this->get_chart_labels(),
		];
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_callback_args(): array {
		return [
			'id'           => $this->get_id(),
			'chart_data'   => $this->get_chart_data(),
			'chart_labels' => $this->get_chart_labels(),
		];
	}
}
