<?php

namespace SlimStat\Tracker;

class Session
{
	public static function ensureVisitId($forceAssign = false)
	{
		$is_new_session = true;
		$identifier     = 0;
		if (isset($_COOKIE['slimstat_tracking_code'])) {
			$identifier = Utils::getValueWithoutChecksum($_COOKIE['slimstat_tracking_code']);
			if (false === $identifier) {
				return false;
			}
			$is_new_session = (false !== strpos($identifier, 'id'));
			$identifier     = intval($identifier);
		}
		if ($is_new_session && ($forceAssign || 'on' == \wp_slimstat::$settings['javascript_mode'])) {
			if (empty(\wp_slimstat::$settings['session_duration'])) {
				\wp_slimstat::$settings['session_duration'] = 1800;
			}
			$table         = $GLOBALS['wpdb']->prefix . 'slim_stats';
			$next_visit_id = \wp_slimstat::$wpdb->get_var(
				"SELECT AUTO_INCREMENT FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = '{$table}'"
			);
			if ($next_visit_id === null || $next_visit_id <= 0) {
				$max_visit_id  = \wp_slimstat::$wpdb->get_var("SELECT COALESCE(MAX(visit_id), 0) FROM {$table}");
				$next_visit_id = intval($max_visit_id) + 1;
			}
			if ($next_visit_id <= 0) {
				$next_visit_id = time();
			}
			$existing_visit_id = \wp_slimstat::$wpdb->get_var(
				\wp_slimstat::$wpdb->prepare("SELECT visit_id FROM {$table} WHERE visit_id = %d", $next_visit_id)
			);
			if ($existing_visit_id !== null) {
				do {
					$next_visit_id++;
					$existing_visit_id = \wp_slimstat::$wpdb->get_var(
						\wp_slimstat::$wpdb->prepare("SELECT visit_id FROM {$table} WHERE visit_id = %d", $next_visit_id)
					);
				} while ($existing_visit_id !== null);
			}
			\wp_slimstat::$stat['visit_id'] = intval($next_visit_id);
			$set_cookie                     = apply_filters('slimstat_set_visit_cookie', (!empty(\wp_slimstat::$settings['set_tracker_cookie']) && 'on' == \wp_slimstat::$settings['set_tracker_cookie']));
			if ($set_cookie) {
				@setcookie('slimstat_tracking_code', Utils::getValueWithChecksum(\wp_slimstat::$stat['visit_id']), ['expires' => time() + \wp_slimstat::$settings['session_duration'], 'path' => COOKIEPATH]);
			}
		} elseif ($identifier > 0) {
			\wp_slimstat::$stat['visit_id'] = $identifier;
		}
		if ($is_new_session && $identifier > 0) {
			\wp_slimstat::$wpdb->query(\wp_slimstat::$wpdb->prepare(
				"UPDATE {$GLOBALS['wpdb' ]->prefix}slim_stats SET visit_id = %d WHERE id = %d AND visit_id = 0",
				\wp_slimstat::$stat['visit_id'],
				$identifier
			));
		}
		return ($is_new_session && ($forceAssign || 'on' == \wp_slimstat::$settings['javascript_mode']));
	}
}
