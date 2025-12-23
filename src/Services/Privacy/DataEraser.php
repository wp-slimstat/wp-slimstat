<?php
declare(strict_types=1);

namespace SlimStat\Services\Privacy;

use SlimStat\Utils\Query;

/**
 * WordPress Privacy API Data Eraser for SlimStat
 *
 * Implements GDPR Article 17 - Right to Erasure (Right to be Forgotten)
 *
 * This class erases all personal data collected by SlimStat for a given
 * email address or IP address, complying with WordPress Privacy Tools.
 *
 * Erasure strategy:
 * - Deletes pageviews and events associated with the email
 * - Removes personal identifiers (email, username, IP) from remaining records
 * - Preserves anonymized aggregated data for statistics
 *
 * @since 5.4.0
 */
class DataEraser
{
	/**
	 * Erase pageview data for a user
	 *
	 * @param string $email_address The user's email address
	 * @param int    $page          Page number for pagination
	 * @return array WordPress Privacy API formatted erase response
	 */
	public static function erasePageviews($email_address, $page = 1)
	{
		$number    = 500; // Process 500 records per page
		$page      = (int) $page;
		$offset    = ($page - 1) * $number;
		$items_removed = false;
		$items_retained = false;
		$messages = [];

		$table = $GLOBALS['wpdb']->prefix . 'slim_stats';

		// Get total count before deletion
		$total = Query::select('COUNT(*)')
			->from($table)
			->where('email', '=', $email_address)
			->getVar();

		if ($total > 0) {
			// Delete pageviews for this email (main table)
			$deleted = Query::delete($table)
				->where('email', '=', $email_address)
				->limit($number)
				->execute();

			if ($deleted > 0) {
				$items_removed = true;
				$messages[] = sprintf(
					__('Removed %d pageview record(s) from SlimStat.', 'wp-slimstat'),
					$deleted
				);
			}

			// Also check archive table if it exists
			$archive_table = $GLOBALS['wpdb']->prefix . 'slim_stats_archive';
			$archive_exists = $GLOBALS['wpdb']->get_var(
				$GLOBALS['wpdb']->prepare(
					"SHOW TABLES LIKE %s",
					$archive_table
				)
			);

			if ($archive_exists) {
				$deleted_archive = Query::delete($archive_table)
					->where('email', '=', $email_address)
					->limit($number)
					->execute();

				if ($deleted_archive > 0) {
					$items_removed = true;
					$messages[] = sprintf(
						__('Removed %d archived pageview record(s) from SlimStat.', 'wp-slimstat'),
						$deleted_archive
					);
				}
			}
		}

		// Check if there are more pages to process
		$remaining = Query::select('COUNT(*)')
			->from($table)
			->where('email', '=', $email_address)
			->getVar();

		$done = ($remaining == 0);

		return [
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => $done,
		];
	}

	/**
	 * Erase event data for a user
	 *
	 * @param string $email_address The user's email address
	 * @param int    $page          Page number for pagination
	 * @return array WordPress Privacy API formatted erase response
	 */
	public static function eraseEvents($email_address, $page = 1)
	{
		$number    = 500; // Process 500 records per page
		$page      = (int) $page;
		$offset    = ($page - 1) * $number;
		$items_removed = false;
		$items_retained = false;
		$messages = [];

		$stats_table = $GLOBALS['wpdb']->prefix . 'slim_stats';
		$events_table = $GLOBALS['wpdb']->prefix . 'slim_events';

		// Delete events by joining with stats table to filter by email
		// Get IDs first, then delete (safer than direct join delete)
		$event_ids = $GLOBALS['wpdb']->get_col(
			$GLOBALS['wpdb']->prepare(
				"SELECT e.id FROM {$events_table} e
				INNER JOIN {$stats_table} s ON e.id = s.id
				WHERE s.email = %s
				LIMIT %d OFFSET %d",
				$email_address,
				$number,
				$offset
			)
		);

		if (!empty($event_ids)) {
			$placeholders = implode(',', array_fill(0, count($event_ids), '%d'));
			$deleted = $GLOBALS['wpdb']->query(
				$GLOBALS['wpdb']->prepare(
					"DELETE FROM {$events_table} WHERE id IN ({$placeholders})",
					...$event_ids
				)
			);

			if ($deleted > 0) {
				$items_removed = true;
				$messages[] = sprintf(
					__('Removed %d event record(s) from SlimStat.', 'wp-slimstat'),
					$deleted
				);
			}

			// Also check archive table
			$events_archive_table = $GLOBALS['wpdb']->prefix . 'slim_events_archive';
			$archive_exists = $GLOBALS['wpdb']->get_var(
				$GLOBALS['wpdb']->prepare(
					"SHOW TABLES LIKE %s",
					$events_archive_table
				)
			);

			if ($archive_exists) {
				$deleted_archive = $GLOBALS['wpdb']->query(
					$GLOBALS['wpdb']->prepare(
						"DELETE FROM {$events_archive_table} WHERE id IN ({$placeholders})",
						...$event_ids
					)
				);

				if ($deleted_archive > 0) {
					$items_removed = true;
					$messages[] = sprintf(
						__('Removed %d archived event record(s) from SlimStat.', 'wp-slimstat'),
						$deleted_archive
					);
				}
			}
		}

		// Check if there are more pages
		$remaining = $GLOBALS['wpdb']->get_var(
			$GLOBALS['wpdb']->prepare(
				"SELECT COUNT(*) FROM {$events_table} e
				INNER JOIN {$stats_table} s ON e.id = s.id
				WHERE s.email = %s",
				$email_address
			)
		);

		$done = ($remaining == 0);

		return [
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => $done,
		];
	}

	/**
	 * Anonymize data by IP address
	 *
	 * This is an additional eraser that handles IP-based erasure requests.
	 * It anonymizes the IP instead of deleting the record entirely,
	 * preserving aggregate statistics.
	 *
	 * @param string $ip_address The IP address to anonymize
	 * @param int    $page       Page number for pagination
	 * @return array WordPress Privacy API formatted erase response
	 */
	public static function anonymizeByIp($ip_address, $page = 1)
	{
		$number    = 500; // Process 500 records per page
		$page      = (int) $page;
		$offset    = ($page - 1) * $number;
		$items_removed = false;
		$items_retained = false;
		$messages = [];

		$table = $GLOBALS['wpdb']->prefix . 'slim_stats';

		// Anonymize IP addresses (set to '0.0.0.0' as anonymous marker)
		// Also clear username, email, and fingerprint
		$updated = $GLOBALS['wpdb']->query(
			$GLOBALS['wpdb']->prepare(
				"UPDATE {$table}
				SET ip = '0.0.0.0',
				    other_ip = '',
				    username = '',
				    email = '',
				    fingerprint = ''
				WHERE ip = %s
				LIMIT %d",
				$ip_address,
				$number
			)
		);

		if ($updated > 0) {
			$items_removed = true; // Technically anonymized, not removed
			$messages[] = sprintf(
				__('Anonymized %d record(s) with IP %s in SlimStat.', 'wp-slimstat'),
				$updated,
				$ip_address
			);
		}

		// Check if there are more pages
		$remaining = Query::select('COUNT(*)')
			->from($table)
			->where('ip', '=', $ip_address)
			->getVar();

		$done = ($remaining == 0);

		return [
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => $done,
		];
	}

	/**
	 * Register WordPress privacy erasers
	 *
	 * @param array $erasers Existing erasers
	 * @return array Modified erasers array
	 */
	public static function registerErasers($erasers)
	{
		$erasers['slimstat-pageviews'] = [
			'eraser_friendly_name' => __('SlimStat Pageviews', 'wp-slimstat'),
			'callback'             => [self::class, 'erasePageviews'],
		];

		$erasers['slimstat-events'] = [
			'eraser_friendly_name' => __('SlimStat Events', 'wp-slimstat'),
			'callback'             => [self::class, 'eraseEvents'],
		];

		return $erasers;
	}
}
