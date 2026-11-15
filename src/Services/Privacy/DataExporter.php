<?php
declare(strict_types=1);

namespace SlimStat\Services\Privacy;

/**
 * WordPress Privacy API Data Exporter for SlimStat
 *
 * Implements GDPR Article 15 - Right to Access Personal Data
 *
 * Exports personal data linked to the requested email address from live and
 * archive tables, formatted for the WordPress Privacy Tools.
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
	/** Read one bounded, uncached page from each applicable storage table. */
	private static function getExportRows($email_address, $page, $events)
	{
		$db = \wp_slimstat::$wpdb ?? $GLOBALS['wpdb'];
		$prefix = $GLOBALS['wpdb']->prefix;
		$number = 500;
		$offset = (max(1, (int) $page) - 1) * $number;
		$rows = [];
		$done = true;
		foreach (['', '_archive'] as $suffix) {
			$stats_table = $prefix . 'slim_stats' . $suffix;
			$table = $events ? $prefix . 'slim_events' . $suffix : $stats_table;
			if ($suffix) {
				$exists = $db->get_var($db->prepare('SHOW TABLES LIKE %s', $db->esc_like($table)));
				if ($db->last_error) { return new \WP_Error('slimstat_privacy_export_failed', __('An error occurred while fetching data', 'wp-slimstat')); }
				if (!$exists) { continue; }
			}
			// Legacy tables without an email column contain no email-linked records.
			$email_column = $db->get_var("SHOW COLUMNS FROM {$stats_table} LIKE 'email'");
			$metadata_error = $db->last_error;
			if ($metadata_error) { return new \WP_Error('slimstat_privacy_export_failed', __('An error occurred while fetching data', 'wp-slimstat')); }
			if (!$email_column) { continue; }
			$sql = $events
				? "SELECT e.* FROM {$table} e INNER JOIN {$stats_table} s ON e.id = s.id WHERE s.email = %s ORDER BY e.dt DESC, e.event_id DESC LIMIT %d OFFSET %d"
				: "SELECT * FROM {$table} WHERE email = %s ORDER BY dt DESC, id DESC LIMIT %d OFFSET %d";
			$records = $db->get_results($db->prepare($sql, $email_address, $number, $offset));
			if ($db->last_error || !is_array($records)) { return new \WP_Error('slimstat_privacy_export_failed', __('An error occurred while fetching data', 'wp-slimstat')); }
			$done = $done && count($records) < $number;
			$id_column = $events ? 'event_id' : 'id';
			foreach ($records as $record) {
				// After an ID reset, live and archived IDs can identify different visits.
				$rows[] = ['row' => $record, 'id' => ($events ? 'event' : 'pageview') . ($suffix ? '-archive' : '') . '-' . $record->$id_column];
			}
		}
		return ['rows' => $rows, 'done' => $done];
	}

	/**
	 * Export pageview data for a user
	 *
	 * @param string $email_address The user's email address
	 * @param int    $page          Page number for pagination
	 * @return array|\WP_Error WordPress Privacy API formatted export data
	 */
	public static function exportPageviews($email_address, $page = 1)
	{
		$records = self::getExportRows($email_address, $page, false);
		if (is_wp_error($records)) { return $records; }
		$data_to_export = [];

		foreach ($records['rows'] as $record) {
			$pageview = $record['row'];
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

			$personal_fields = [
				'other_ip' => __('Forwarded IP Address', 'wp-slimstat'),
				'location' => __('Location', 'wp-slimstat'),
				'searchterms' => __('Search Terms', 'wp-slimstat'),
				'notes' => __('Notes', 'wp-slimstat'),
				'outbound_resource' => __('Outbound Links', 'wp-slimstat'),
				'dt_out' => __('Last Activity Timestamp', 'wp-slimstat'),
				'screen_width' => __('Screen Width', 'wp-slimstat'),
				'screen_height' => __('Screen Height', 'wp-slimstat'),
				'language' => __('Language', 'wp-slimstat'),
				'utm_source' => __('Campaign Source', 'wp-slimstat'),
				'utm_medium' => __('Campaign Medium', 'wp-slimstat'),
				'utm_campaign' => __('Campaign Name', 'wp-slimstat'),
				'utm_term' => __('Campaign Term', 'wp-slimstat'),
				'utm_content' => __('Campaign Content', 'wp-slimstat'),
				'traffic_source' => __('Traffic Source', 'wp-slimstat'),
			];
			foreach ($personal_fields as $column => $label) {
				if (isset($pageview->$column) && '' !== (string) $pageview->$column) {
					$item_data[] = ['name' => $label, 'value' => 'notes' === $column ? \SlimStat\Reports\ReportPrivacy::redactConsentMarker((string) $pageview->$column) : $pageview->$column];
				}
			}

            if (!empty($pageview->vid_hash)) {
                $item_data[] = ['name' => __('Daily Visit Identifier', 'wp-slimstat'), 'value' => bin2hex($pageview->vid_hash)];
            }

			// Add to export
			$data_to_export[] = [
				'group_id'    => 'slimstat_pageviews',
				'group_label' => __('SlimStat Pageviews', 'wp-slimstat'),
				'item_id'     => $record['id'],
				'data'        => $item_data,
			];
		}

		return [
			'data' => $data_to_export,
			'done' => $records['done'],
		];
	}

	/**
	 * Export event data for a user
	 *
	 * @param string $email_address The user's email address
	 * @param int    $page          Page number for pagination
	 * @return array|\WP_Error WordPress Privacy API formatted export data
	 */
	public static function exportEvents($email_address, $page = 1)
	{
		$records = self::getExportRows($email_address, $page, true);
		if (is_wp_error($records)) { return $records; }
		$data_to_export = [];

		foreach ($records['rows'] as $record) {
			$event = $record['row'];
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
				'item_id'     => $record['id'],
				'data'        => $item_data,
			];
		}

		return [
			'data' => $data_to_export,
			'done' => $records['done'],
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
