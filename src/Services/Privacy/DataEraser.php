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
	 * @return array|\WP_Error WordPress Privacy API formatted erase response
	 */
	public static function erasePageviews($email_address, $page = 1)
	{
		$items_removed = false;
		$messages = [];
		$done = true;
		$tables = [$GLOBALS['wpdb']->prefix . 'slim_stats'];
		$archive = $GLOBALS['wpdb']->prefix . 'slim_stats_archive';
		$exists = $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SHOW TABLES LIKE %s', $archive));
		$failed = '' !== (string) $GLOBALS['wpdb']->last_error;
		if ($exists) {
			$tables[] = $archive;
		}

		// Each call drains the first batch; archives may outlive every live pageview.
		foreach ($tables as $table) {
			$deleted = Query::delete($table)->where('email', '=', $email_address)->limit(500)->execute();
			$failed = $failed || false === $deleted;
			if ($deleted > 0) {
				$items_removed = true;
				\wp_slimstat::bump_data_version();
				$messages[] = sprintf(
					/* translators: %d: number of pageview records removed. */
					__('Removed %d pageview record(s) from SlimStat.', 'wp-slimstat'),
					$deleted
				);
			}
			$remaining = Query::select('COUNT(*)')->from($table)->where('email', '=', $email_address)->getVar();
			$failed = $failed || '' !== (string) $GLOBALS['wpdb']->last_error;
			$done = $done && 0 === (int) $remaining;
		}

		if ($failed) {
			return new \WP_Error('slimstat_erasure_failed', __('SlimStat could not finish erasing the data. Please try again.', 'wp-slimstat'));
		}

		return [
			'items_removed' => $items_removed,
			'items_retained' => $failed,
			'messages' => $messages,
			'done' => $done,
		];
	}

	/**
	 * Erase event data for a user
	 *
	 * @param string $email_address The user's email address
	 * @param int    $page          Page number for pagination
	 * @return array|\WP_Error WordPress Privacy API formatted erase response
	 */
	public static function eraseEvents($email_address, $page = 1)
	{
		$db = $GLOBALS['wpdb'];
		$items_removed = false;
		$messages = [];
		$done = true;
		$suffixes = [''];
		$archive = $db->prefix . 'slim_events_archive';
		$exists = $db->get_var($db->prepare('SHOW TABLES LIKE %s', $archive));
		$failed = '' !== (string) $db->last_error;
		if ($exists) {
			$suffixes[] = '_archive';
		}

		// Live and archived IDs can belong to different people after an ID reset.
		foreach ($suffixes as $suffix) {
			$stats_table = $db->prefix . 'slim_stats' . $suffix;
			$events_table = $db->prefix . 'slim_events' . $suffix;
			$ids = $db->get_col($db->prepare(
				"SELECT e.event_id FROM {$events_table} e INNER JOIN {$stats_table} s ON e.id = s.id
				 WHERE s.email = %s ORDER BY e.event_id LIMIT 500",
				$email_address
			));
			$failed = $failed || '' !== (string) $db->last_error;
			if ($ids) {
				$placeholders = implode(',', array_fill(0, count($ids), '%d'));
				$deleted = $db->query($db->prepare("DELETE FROM {$events_table} WHERE event_id IN ({$placeholders})", ...$ids));
				$failed = $failed || false === $deleted;
				if ($deleted > 0) {
					$items_removed = true;
					\wp_slimstat::bump_data_version();
					$messages[] = sprintf(
						/* translators: %d: number of event records removed. */
						__('Removed %d event record(s) from SlimStat.', 'wp-slimstat'),
						$deleted
					);
				}
			}
			$remaining = $db->get_var($db->prepare(
				"SELECT COUNT(*) FROM {$events_table} e INNER JOIN {$stats_table} s ON e.id = s.id WHERE s.email = %s",
				$email_address
			));
			$failed = $failed || '' !== (string) $db->last_error;
			$done = $done && 0 === (int) $remaining;
		}
		if ($failed) {
			return new \WP_Error('slimstat_erasure_failed', __('SlimStat could not finish erasing the data. Please try again.', 'wp-slimstat'));
		}
		return [
			'items_removed' => $items_removed,
			'items_retained' => false,
			'messages' => $messages,
			'done' => $done,
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
		if (\SlimStat\Ecommerce\Integration::ready()) {
			$commerce = \SlimStat\Ecommerce\Integration::erase($ip_address, $page, 'ip');
			if (!$commerce['done']) {
				return $commerce;
			}
		}
		$number    = 500; // Process 500 records per page
		$page      = (int) $page;
		$offset    = ($page - 1) * $number;
		$items_removed = false;
		$items_retained = false;
		$messages = [];

		$table = $GLOBALS['wpdb']->prefix . 'slim_stats';

		// Anonymize IP addresses (set to '0.0.0.0' as anonymous marker)
		// Also clear username, email, fingerprint — and vid_hash, which is DERIVED from
		// the fingerprint (or ip + user agent): clearing the inputs while keeping the
		// value computed from them would leave the "anonymized" row carrying a visitor
		// identifier this same UPDATE just promised to erase. visit_id stays: it is a
		// sequential session number and carries nothing about the person.
		//
		// The column is probed first because an upgraded install may not have run the
		// AddVisitIdentity migration yet — and naming a column the table lacks fails the
		// WHOLE statement, which here means a GDPR erasure request silently erasing
		// nothing. A schema that predates vid_hash has nothing to clear. Asked through
		// the manifest's own read model (Schema::hasColumn) rather than a third
		// hand-rolled probe; its conservative failure mode — unreadable table answers
		// false — is safe here, because a table this connection cannot read will fail
		// the UPDATE below loudly anyway.
		$has_vid_hash = \SlimStat\Schema\Schema::hasColumn(
			$GLOBALS['wpdb'],
			'slim_stats',
			$GLOBALS['wpdb']->prefix,
			'vid_hash'
		);

		$updated = $GLOBALS['wpdb']->query(
			$GLOBALS['wpdb']->prepare(
				"UPDATE {$table}
				SET ip = '0.0.0.0',
				    other_ip = '',
				    username = '',
				    email = '',
				    fingerprint = ''" . ($has_vid_hash ? ', vid_hash = NULL' : '') . '
				WHERE ip = %s
				LIMIT %d',
				$ip_address,
				$number
			)
		);

		if ($updated > 0) {
			$items_removed = true; // Technically anonymized, not removed
			$messages[] = sprintf(
				/* translators: 1: anonymized record count, 2: IP address. */
				__('Anonymized %1$d record(s) with IP %2$s in SlimStat.', 'wp-slimstat'),
				$updated,
				$ip_address
			);
		}

		if ($items_removed) {
			\wp_slimstat::bump_data_version();
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
		// Events BEFORE pageviews. An event carries no email of its own — it can only be
		// attributed to a person through its parent pageview — so erasing the pageviews
		// first leaves the events unidentifiable and therefore unerasable. Same
		// children-before-parents rule the retention purge has to follow. (D1)
		$erasers['slimstat-events'] = [
			'eraser_friendly_name' => __('SlimStat Events', 'wp-slimstat'),
			'callback'             => [self::class, 'eraseEvents'],
		];

		$erasers['slimstat-pageviews'] = [
			'eraser_friendly_name' => __('SlimStat Pageviews', 'wp-slimstat'),
			'callback'             => [self::class, 'erasePageviews'],
		];

		return $erasers;
	}
}
