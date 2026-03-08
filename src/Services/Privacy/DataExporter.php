<?php
declare(strict_types=1);

namespace SlimStat\Services\Privacy;

use SlimStat\Utils\Query;

/**
 * WordPress Privacy API Data Exporter for SlimStat
 *
 * Implements GDPR Article 15 - Right to Access Personal Data
 *
 * This class exports all personal data collected by SlimStat for a given
 * email address or IP address, formatted for the WordPress Privacy Tools.
 *
 * Exported data includes:
 * - Pageviews (URLs visited, timestamps, user agent)
 * - Events (interactions, downloads, outbound links)
 * - Session data (visit IDs, fingerprints if applicable)
 * - Any username/email associated with visits
 *
 * @since 5.4.0
 */
class DataExporter
{
	/**
	 * Export pageview data for a user
	 *
	 * @param string $email_address The user's email address
	 * @param int    $page          Page number for pagination
	 * @return array WordPress Privacy API formatted export data
	 */
	public static function exportPageviews($email_address, $page = 1)
	{
		$number    = 500; // Export 500 records per page
		$page      = (int) $page;
		$offset    = ($page - 1) * $number;
		$data_to_export = [];

		$table = $GLOBALS['wpdb']->prefix . 'slim_stats';

		// Query pageviews by email
		$pageviews = Query::select('*')
			->from($table)
			->where('email', '=', $email_address)
			->orderBy('dt', 'DESC')
			->limit($number, $offset)
			->getResults();

		foreach ($pageviews as $pageview) {
			$item_data = [];

			// Basic visit information
			$item_data[] = [
				'name'  => __('Visit Date', 'wp-slimstat'),
				'value' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $pageview->dt),
			];

			if (!empty($pageview->resource)) {
				$item_data[] = [
					'name'  => __('Page Visited', 'wp-slimstat'),
					'value' => $pageview->resource,
				];
			}

			if (!empty($pageview->referer)) {
				$item_data[] = [
					'name'  => __('Referrer', 'wp-slimstat'),
					'value' => $pageview->referer,
				];
			}

			// User information
			if (!empty($pageview->username)) {
				$item_data[] = [
					'name'  => __('Username', 'wp-slimstat'),
					'value' => $pageview->username,
				];
			}

			if (!empty($pageview->email)) {
				$item_data[] = [
					'name'  => __('Email', 'wp-slimstat'),
					'value' => $pageview->email,
				];
			}

			// Technical information (potentially identifying)
			if (!empty($pageview->ip)) {
				$item_data[] = [
					'name'  => __('IP Address', 'wp-slimstat'),
					'value' => $pageview->ip,
				];
			}

			if (!empty($pageview->fingerprint)) {
				$item_data[] = [
					'name'  => __('Browser Fingerprint', 'wp-slimstat'),
					'value' => $pageview->fingerprint,
				];
			}

			if (!empty($pageview->user_agent)) {
				$item_data[] = [
					'name'  => __('User Agent', 'wp-slimstat'),
					'value' => $pageview->user_agent,
				];
			}

			// Browser and system information
			if (!empty($pageview->browser)) {
				$item_data[] = [
					'name'  => __('Browser', 'wp-slimstat'),
					'value' => $pageview->browser . (!empty($pageview->browser_version) ? ' ' . $pageview->browser_version : ''),
				];
			}

			if (!empty($pageview->platform)) {
				$item_data[] = [
					'name'  => __('Operating System', 'wp-slimstat'),
					'value' => $pageview->platform,
				];
			}

			if (!empty($pageview->resolution)) {
				$item_data[] = [
					'name'  => __('Screen Resolution', 'wp-slimstat'),
					'value' => $pageview->resolution,
				];
			}

			// Location data
			if (!empty($pageview->country)) {
				$item_data[] = [
					'name'  => __('Country', 'wp-slimstat'),
					'value' => strtoupper($pageview->country),
				];
			}

			if (!empty($pageview->city)) {
				$item_data[] = [
					'name'  => __('City', 'wp-slimstat'),
					'value' => $pageview->city,
				];
			}

			// Session information
			if (!empty($pageview->visit_id)) {
				$item_data[] = [
					'name'  => __('Visit ID', 'wp-slimstat'),
					'value' => $pageview->visit_id,
				];
			}

			// Add to export
			$data_to_export[] = [
				'group_id'    => 'slimstat_pageviews',
				'group_label' => __('SlimStat Pageviews', 'wp-slimstat'),
				'item_id'     => 'pageview-' . $pageview->id,
				'data'        => $item_data,
			];
		}

		// Check if there are more pages
		$total = Query::select('COUNT(*)')
			->from($table)
			->where('email', '=', $email_address)
			->getVar();

		$done = ($offset + $number) >= $total;

		return [
			'data' => $data_to_export,
			'done' => $done,
		];
	}

	/**
	 * Export event data for a user
	 *
	 * @param string $email_address The user's email address
	 * @param int    $page          Page number for pagination
	 * @return array WordPress Privacy API formatted export data
	 */
	public static function exportEvents($email_address, $page = 1)
	{
		$number    = 500; // Export 500 records per page
		$page      = (int) $page;
		$offset    = ($page - 1) * $number;
		$data_to_export = [];

		$stats_table = $GLOBALS['wpdb']->prefix . 'slim_stats';
		$events_table = $GLOBALS['wpdb']->prefix . 'slim_events';

		// Query events by joining with stats table to filter by email
		$events = $GLOBALS['wpdb']->get_results(
			$GLOBALS['wpdb']->prepare(
				"SELECT e.* FROM {$events_table} e
				INNER JOIN {$stats_table} s ON e.id = s.id
				WHERE s.email = %s
				ORDER BY e.dt DESC
				LIMIT %d OFFSET %d",
				$email_address,
				$number,
				$offset
			)
		);

		foreach ($events as $event) {
			$item_data = [];

			$item_data[] = [
				'name'  => __('Event Date', 'wp-slimstat'),
				'value' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $event->dt),
			];

			if (!empty($event->type)) {
				$item_data[] = [
					'name'  => __('Event Type', 'wp-slimstat'),
					'value' => $event->type,
				];
			}

			if (!empty($event->event_description)) {
				$item_data[] = [
					'name'  => __('Event Description', 'wp-slimstat'),
					'value' => $event->event_description,
				];
			}

			if (!empty($event->position)) {
				$item_data[] = [
					'name'  => __('Position', 'wp-slimstat'),
					'value' => $event->position,
				];
			}

			if (!empty($event->notes)) {
				$item_data[] = [
					'name'  => __('Notes', 'wp-slimstat'),
					'value' => $event->notes,
				];
			}

			$data_to_export[] = [
				'group_id'    => 'slimstat_events',
				'group_label' => __('SlimStat Events', 'wp-slimstat'),
				'item_id'     => 'event-' . $event->id . '-' . $event->dt,
				'data'        => $item_data,
			];
		}

		// Check if there are more pages
		$total = $GLOBALS['wpdb']->get_var(
			$GLOBALS['wpdb']->prepare(
				"SELECT COUNT(*) FROM {$events_table} e
				INNER JOIN {$stats_table} s ON e.id = s.id
				WHERE s.email = %s",
				$email_address
			)
		);

		$done = ($offset + $number) >= $total;

		return [
			'data' => $data_to_export,
			'done' => $done,
		];
	}

	/**
	 * Register WordPress privacy exporters
	 *
	 * @param array $exporters Existing exporters
	 * @return array Modified exporters array
	 */
	public static function registerExporters($exporters)
	{
		$exporters['slimstat-pageviews'] = [
			'exporter_friendly_name' => __('SlimStat Pageviews', 'wp-slimstat'),
			'callback'               => [self::class, 'exportPageviews'],
		];

		$exporters['slimstat-events'] = [
			'exporter_friendly_name' => __('SlimStat Events', 'wp-slimstat'),
			'callback'               => [self::class, 'exportEvents'],
		];

		return $exporters;
	}
}
