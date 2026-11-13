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
 * email address through WordPress Privacy Tools. The IP helper is for verified
 * administrator requests; WordPress itself verifies requests by email only.
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
		$db = \wp_slimstat::$wpdb ?? $GLOBALS['wpdb'];
		$items_removed = false;
		$messages = [];
		$done = true;
		$tables = [$GLOBALS['wpdb']->prefix . 'slim_stats'];
		$archive = $GLOBALS['wpdb']->prefix . 'slim_stats_archive';
		$exists = $db->get_var($db->prepare('SHOW TABLES LIKE %s', $db->esc_like($archive)));
		$failed = '' !== (string) $db->last_error;
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
			$failed = $failed || '' !== (string) $db->last_error;
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
		$db = \wp_slimstat::$wpdb ?? $GLOBALS['wpdb'];
		$prefix = $GLOBALS['wpdb']->prefix;
		$items_removed = false;
		$messages = [];
		$done = true;
		$suffixes = [''];
		$archive = $prefix . 'slim_events_archive';
		$exists = $db->get_var($db->prepare('SHOW TABLES LIKE %s', $db->esc_like($archive)));
		$failed = '' !== (string) $db->last_error;
		if ($exists) {
			$suffixes[] = '_archive';
		}

		// Live and archived IDs can belong to different people after an ID reset.
		foreach ($suffixes as $suffix) {
			$stats_table = $prefix . 'slim_stats' . $suffix;
			$events_table = $prefix . 'slim_events' . $suffix;
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
	 * @return array|\WP_Error WordPress Privacy API formatted erase response
	 */
	public static function anonymizeByIp($ip_address, $page = 1)
	{
        if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
            return new \WP_Error('slimstat_erasure_failed', __('SlimStat could not finish erasing the data. Please try again.', 'wp-slimstat'));
        }
		if (\SlimStat\Ecommerce\Integration::ready()) {
			$commerce = \SlimStat\Ecommerce\Integration::erase($ip_address, $page, 'ip');
			if (!$commerce['done']) {
				return $commerce;
			}
		}
        $db = \wp_slimstat::$wpdb ?? $GLOBALS['wpdb'];
        $prefix = $GLOBALS['wpdb']->prefix;
        $tables = [$prefix . 'slim_stats'];
        $archive = $prefix . 'slim_stats_archive';
        $exists = $db->get_var($db->prepare('SHOW TABLES LIKE %s', $db->esc_like($archive)));
        $failed = '' !== (string) $db->last_error;
        if ($exists) { $tables[] = $archive; }
        $items_removed = false;
        $done = true;
        $messages = [];
        foreach ($tables as $table) {
            // Older archives may predate identity columns or store IPv4 as an integer.
            // Read the actual schema so a missing optional field cannot disable erasure.
            $columns = $db->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
            $query_error = (string) $db->last_error;
            if ('' !== $query_error || !$columns) { $failed = true; break; }
            $types = array_column($columns, 'Type', 'Field');
            if (!isset($types['ip'])) { $failed = true; break; }
            $anonymous = preg_match('/int/i', $types['ip']) ? '0' : '0.0.0.0';
            $set = ['ip = %s'];
            $personal = ['CAST(ip AS CHAR) <> %s'];
            foreach (['other_ip', 'username', 'email', 'fingerprint'] as $column) {
                if (isset($types[$column])) {
                    $empty = preg_match('/int/i', $types[$column]) ? '0' : "''";
                    $set[] = "{$column} = {$empty}";
                    $personal[] = "COALESCE({$column}, {$empty}) <> {$empty}";
                }
            }
            if (isset($types['vid_hash'])) {
                $set[] = 'vid_hash = NULL';
                $personal[] = 'vid_hash IS NOT NULL';
            }
            $ips = [(string) $ip_address];
            if (filter_var($ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ips[] = sprintf('%u', ip2long($ip_address));
            }
            $where = 'CAST(ip AS CHAR) IN (' . implode(',', array_fill(0, count($ips), '%s')) . ') AND (' . implode(' OR ', $personal) . ')';
            $params = array_merge($ips, [$anonymous]);
            $updated = $db->query($db->prepare("UPDATE {$table} SET " . implode(', ', $set) . " WHERE {$where} LIMIT 500", array_merge([$anonymous], $params)));
            if (false === $updated) { $failed = true; break; }
            if ($updated > 0) {
                $items_removed = true;
                \wp_slimstat::bump_data_version();
                $messages[] = sprintf(
                    /* translators: 1: anonymized record count, 2: IP address. */
                    __('Anonymized %1$d record(s) with IP %2$s in SlimStat.', 'wp-slimstat'),
                    $updated, $ip_address
                );
            }
            $remaining = $db->get_var($db->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}", $params));
            $query_error = (string) $db->last_error;
            $failed = $failed || '' !== $query_error;
            $done = $done && 0 === (int) $remaining;
        }
        if ($failed) {
            return new \WP_Error('slimstat_erasure_failed', __('SlimStat could not finish erasing the data. Please try again.', 'wp-slimstat'));
        }
        return ['items_removed' => $items_removed, 'items_retained' => false, 'messages' => $messages, 'done' => $done];
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
