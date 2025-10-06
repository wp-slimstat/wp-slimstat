<?php

namespace SlimStat\Tracker;

use SlimStat\Utils\Query;

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
			$next_visit_id = Query::select('AUTO_INCREMENT')
                ->from('information_schema.TABLES')
                ->whereRaw("TABLE_SCHEMA = DATABASE()")
                ->where('TABLE_NAME', '=', $table)
                ->getVar();

			if ($next_visit_id === null || $next_visit_id <= 0) {
				$max_visit_id  = Query::select('COALESCE(MAX(visit_id), 0)')->from($table)->getVar();
				$next_visit_id = intval($max_visit_id) + 1;
			}
            
			if ($next_visit_id <= 0) {
				$next_visit_id = time();
			}
            
			$existing_visit_id = Query::select('visit_id')->from($table)->where('visit_id', '=', $next_visit_id)->getVar();

			if ($existing_visit_id !== null) {
				do {
					$next_visit_id++;
					$existing_visit_id = Query::select('visit_id')->from($table)->where('visit_id', '=', $next_visit_id)->getVar();
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
			Query::update($GLOBALS['wpdb']->prefix . 'slim_stats')
                ->set(['visit_id' => \wp_slimstat::$stat['visit_id']])
                ->where('id', '=', $identifier)
                ->where('visit_id', '=', 0)
                ->execute();
		}
        
		return ($is_new_session && ($forceAssign || 'on' == \wp_slimstat::$settings['javascript_mode']));
	}
}
