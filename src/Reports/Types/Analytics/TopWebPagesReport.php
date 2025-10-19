<?php
/**
 * Top Web Pages Report
 *
 * @package SlimStat
 * @since 5.4.0
 */

declare(strict_types=1);

namespace SlimStat\Reports\Types\Analytics;

use SlimStat\Reports\Abstracts\TableReport;

/**
 * Class TopWebPagesReport
 *
 * Displays the most viewed pages on the site.
 */
class TopWebPagesReport extends TableReport {
	/**
	 * {@inheritDoc}
	 */
	protected function init(): void {
		$this->id        = 'slim_p1_08';
		$this->title     = __( 'Top Web Pages', 'wp-slimstat' );
		$this->locations = [ 'slimview2', 'dashboard' ];
		$this->classes   = [ 'normal' ];
		$this->tooltip   = __( 'Here a "page" is not just a WordPress page type, but any webpage on your site, including posts, products, categories, and any other custom post type. For example, you can set the corresponding filter where Resource Content Type equals cpt:you_cpt_slug_here to get top web pages for a specific custom post type you have.', 'wp-slimstat' );

		// Query configuration
		$this->type    = 'top';
		$this->columns = 'resource';
		$this->raw     = [ 'wp_slimstat_db', 'get_top' ];
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
