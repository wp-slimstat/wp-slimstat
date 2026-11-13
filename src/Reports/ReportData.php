<?php
/**
 * Shared data preparation for registered reports.
 *
 * @license GPL-2.0-or-later
 * @package SlimStat
 */

namespace SlimStat\Reports;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keep HTML reports and authorized analysis on the same query and display units. */
final class ReportData {
	/**
	 * Prepare and execute the registered reader once.
	 *
	 * @param array $args Registered report arguments, including the prepared WHERE clause.
	 * @return array Report rows.
	 */
	public static function rawData( array &$args ): array {
		$where_params = $args['where_params'] ?? null;
		if ( ! empty( $args['raw'] ) && is_array( $args['raw'] ) && isset( $args['raw'][0] ) && method_exists( $args['raw'][0], 'get_combined_where' ) ) {
			// Currently Online reports intentionally ignore the selected historical date range.
			$use_date_filters = $args['use_date_filters'] ?? true;
			$args['where']    = call_user_func(
				array( $args['raw'][0], 'get_combined_where' ),
				$args['where'] ?? '',
				'',
				$use_date_filters,
				'',
				$where_params
			);
		}
		$rows = call_user_func( $args['raw'], $args );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Preserve summary units in HTML reports and authorized AI exports.
	 *
	 * @param array $row Summary metric and value.
	 * @return string Display value, without HTML escaping.
	 */
	public static function summaryValue( array $row ): string {
		$percent_metrics = array( __( 'Bounce rate', 'wp-slimstat' ), __( 'New Visitors Rate', 'wp-slimstat' ) );
		return (string) $row['value'] . ( in_array( $row['metric'], $percent_metrics, true ) ? '%' : '' );
	}
}
