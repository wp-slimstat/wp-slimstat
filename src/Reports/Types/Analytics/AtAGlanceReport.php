<?php
/**
 * At a Glance Report
 *
 * @package SlimStat
 * @since 5.4.0
 */

declare(strict_types=1);

namespace SlimStat\Reports\Types\Analytics;

use SlimStat\Reports\Abstracts\SummaryReport;

/**
 * Class AtAGlanceReport
 *
 * Displays a quick overview summary of key metrics.
 */
class AtAGlanceReport extends SummaryReport {
	/**
	 * {@inheritDoc}
	 */
	protected function init(): void {
		parent::init();

		$this->id        = 'slim_p1_03';
		$this->title     = __( 'At a Glance', 'wp-slimstat' );
		$this->locations = [ 'slimview2', 'dashboard' ];
		$this->raw       = [ 'wp_slimstat_db', 'get_overview_summary' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_data(): array {
		if ( ! class_exists( 'wp_slimstat_db' ) ) {
			return [];
		}

		return parent::get_data();
	}
}
