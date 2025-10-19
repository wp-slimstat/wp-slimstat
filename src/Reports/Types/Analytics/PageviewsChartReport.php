<?php
/**
 * Pageviews Chart Report
 *
 * @package SlimStat
 * @since 5.4.0
 */

declare(strict_types=1);

namespace SlimStat\Reports\Types\Analytics;

use SlimStat\Reports\Abstracts\ChartReport;

/**
 * Class PageviewsChartReport
 *
 * Displays pageviews and unique IPs over time.
 */
class PageviewsChartReport extends ChartReport {
	/**
	 * {@inheritDoc}
	 */
	protected function init(): void {
		parent::init();

		$this->id        = 'slim_p1_01';
		$this->title     = __( 'Pageviews', 'wp-slimstat' );
		$this->locations = [ 'slimview2', 'dashboard' ];
		$this->tooltip   = $this->build_pageviews_tooltip();
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_chart_data(): array {
		return [
			'data1' => 'COUNT( ip )',
			'data2' => 'COUNT( DISTINCT ip )',
		];
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_chart_labels(): array {
		$unique_label = ( 'on' === ( wp_slimstat::$settings['hash_ip'] ?? 'off' ) )
			? __( 'Unique Visitors', 'wp-slimstat' )
			: __( 'Unique IPs', 'wp-slimstat' );

		return [
			__( 'Pageviews', 'wp-slimstat' ),
			$unique_label,
		];
	}

	/**
	 * Build the tooltip for this report
	 *
	 * @return string
	 */
	private function build_pageviews_tooltip(): string {
		return $this->build_chart_tooltip(
			__( 'Pageviews', 'wp-slimstat' ),
			__( 'Shows how many times your site\'s pages have been viewed.', 'wp-slimstat' ),
			[
				__( '— Solid line: current period', 'wp-slimstat' ),
				__( '-- Dashed line: previous period', 'wp-slimstat' ),
				__( 'Tap "Pageviews" or "Unique IPs" to toggle each line.', 'wp-slimstat' ),
				__( 'Use the dropdown (Hourly, Daily, Weekly, Monthly, Yearly) to adjust the chart\'s interval.', 'wp-slimstat' ),
			]
		);
	}
}
