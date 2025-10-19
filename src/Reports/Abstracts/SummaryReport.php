<?php
/**
 * Abstract Summary Report Class
 *
 * @package SlimStat
 * @since 5.4.0
 */

declare(strict_types=1);

namespace SlimStat\Reports\Abstracts;

/**
 * Class SummaryReport
 *
 * Base class for reports that display summary/overview data.
 */
abstract class SummaryReport extends AbstractReport {
	/**
	 * Database query method
	 *
	 * @var array<callable>
	 */
	protected array $raw = [];

	/**
	 * {@inheritDoc}
	 */
	protected function init(): void {
		$this->classes[] = 'normal';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_renderer(): string {
		return 'SlimStat\Reports\Renderer\SummaryRenderer';
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
		return [
			'raw' => $this->raw,
		];
	}
}
