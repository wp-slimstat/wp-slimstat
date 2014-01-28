<?php

// Let's define the main class with all the methods that we need
class wp_slimstat_db {
	// Filters
	public static $filter_names = array();
	public static $filters_normalized = array();
	
	// Kept for backward compatibility, but not used -- TO BE REMOVED
	public static $filters = array();
	public static $timeframes = array();

	// Number and date formats
	public static $formats = array('decimal' => ',', 'thousand' => '.');
	
	// Filters as SQL clauses
	public static $sql_filters = array(
		'from' => array(
			'browsers' => '',
			'screenres' => '',
			'content_info' => '',
			'outbound' => '',
			'all_tables' => '',
			'all_other_tables' => ''
		),
		'where' => '',
		'where_time_range' => ''
	);

	/*
	 * Initializes the environment, sets the filters
	 */
	public static function init($_filters = ''){
		// Reset MySQL timezone settings, our dates and times are recorded using WP settings
		wp_slimstat::$wpdb->query("SET @@session.time_zone = '+00:00'");
		date_default_timezone_set('UTC');

		// Decimal and thousand separators
		if (wp_slimstat::$options['use_european_separators'] == 'no'){
			self::$formats['decimal'] = '.';
			self::$formats['thousand'] = ',';
		}

		// Use WordPress' settings for date and time format
		self::$formats['date_format'] = get_option('date_format', 'd-m-Y');
		self::$formats['time_format'] = get_option('time_format', 'd-m-Y');
		self::$formats['date_time_format'] = self::$formats['date_format'].' '.self::$formats['time_format'];

		// Filters are defined as: browser equals Chrome|country starts_with en
		if (!is_string($_filters) || empty($_filters)){
			$_filters = '';
		}

		// List of supported filters and their friendly names
		self::$filter_names = array(
			'no-filter-selected-1' => '&nbsp;',
			'browser' => __('Browser','wp-slimstat'),
			'country' => __('Country Code','wp-slimstat'),
			'ip' => __('IP Address','wp-slimstat'),
			'searchterms' => __('Search Terms','wp-slimstat'),
			'language' => __('Language Code','wp-slimstat'),
			'platform' => __('Operating System','wp-slimstat'),
			'resource' => __('Permalink','wp-slimstat'),
			'domain' => __('Domain','wp-slimstat'),
			'referer' => __('Referer','wp-slimstat'),
			'user' => __('Visitor\'s Name','wp-slimstat'),
			'no-filter-selected-2' => '&nbsp;',
			'no-filter-selected-3' => __('-- Advanced filters --','wp-slimstat'),
			'plugins' => __('Browser Capabilities','wp-slimstat'),
			'version' => __('Browser Version','wp-slimstat'),
			'type' => __('Browser Type','wp-slimstat'),
			'user_agent' => __('User Agent','wp-slimstat'),
			'colordepth' => __('Color Depth','wp-slimstat'),
			'css_version' => __('CSS Version','wp-slimstat'),
			'notes' => __('Pageview Attributes','wp-slimstat'),
			'outbound_resource' => __('Outbound Link','wp-slimstat'),
			'author' => __('Post Author','wp-slimstat'),
			'category' => __('Post Category ID','wp-slimstat'),
			'other_ip' => __('Originating IP','wp-slimstat'),
			'content_type' => __('Resource Content Type','wp-slimstat'),
			'content_id' => __('Resource ID','wp-slimstat'),
			'resolution' => __('Screen Resolution','wp-slimstat'),
			'visit_id' => __('Visit ID','wp-slimstat'),

			// The following filters will not be displayed in the dropdown
			'hour' => __('Hour','wp-slimstat'),
			'day' => __('Day','wp-slimstat'),
			'month' => __('Month','wp-slimstat'),
			'year' => __('Year','wp-slimstat'),
			'interval' => __('days','wp-slimstat'),

			'direction' => __('Order Direction','wp-slimstat'),
			'limit_results' => __('Limit Results','wp-slimstat'),
			'start_from' => __('Start From','wp-slimstat'),

			// Misc Filters
			'strtotime' => 0
		);

		self::reset_filters();
		self::$filters_normalized = apply_filters('slimstat_pre_filters_normalized', self::$filters_normalized);

		// Normalize the input (filters)
		if (!empty($_filters)){
			$matches = explode('|', $_filters);

			foreach($matches as $idx => $a_match){
				preg_match('/([^\s]+)\s([^\s]+)\s(.+)?/', urldecode($a_match), $a_filter);

				if (empty($a_filter) || !array_key_exists($a_filter[1], self::$filter_names) || strpos($a_filter[1], 'no-filter-selected') !== false){
					continue;
				}

				switch($a_filter[1]){
					case 'strtotime': // TODO - TO BE REMOVED - add support for strtotime to right side of expression
						$custom_date = strtotime($matches[3][$idx]);
						self::$filters_normalized['date']['day'] = date_i18n('j', $a_filter[3]);
						self::$filters_normalized['date']['month'] = date_i18n('n', $a_filter[3]);
						self::$filters_normalized['date']['year'] = date_i18n('Y', $a_filter[3]);
						break;
					case 'hour':
					case 'day':
					case 'month':
					case 'year':
					case 'interval':
						if (intval($a_filter[3]) != 0){
							self::$filters_normalized['date'][$a_filter[1]] = intval($a_filter[3]);
							self::$filters_normalized['selected'][$a_filter[1]] = true;
						}
						break;
					case 'direction':
					case 'limit_results':
					case 'start_from':
						self::$filters_normalized['misc'][$a_filter[1]] = str_replace('\\', '', htmlspecialchars_decode($a_filter[3]));
						break;
					default:
						self::$filters_normalized['columns'][$a_filter[1]] = array($a_filter[2], isset($a_filter[3])?str_replace('\\', '', htmlspecialchars_decode($a_filter[3])):'');
						break;
				}
			}
		}
		self::$filters_normalized = apply_filters('slimstat_post_filters_normalized', self::$filters_normalized);

		// Convert date filters into time ranges to be used in the SQL query
		self::$filters_normalized['time_ranges']['current_start'] = mktime(self::$filters_normalized['date']['hour'], 0, 0, self::$filters_normalized['date']['month'], self::$filters_normalized['date']['day'], self::$filters_normalized['date']['year']);
		
		if (!self::$filters_normalized['selected']['interval']){
			if (self::$filters_normalized['selected']['hour']){
				self::$filters_normalized['time_ranges']['current_end'] = self::$filters_normalized['time_ranges']['current_start'] + 3599;
				self::$filters_normalized['time_ranges']['type'] = 'H';
			}
			else if (self::$filters_normalized['selected']['day']){
				self::$filters_normalized['time_ranges']['current_end'] = self::$filters_normalized['time_ranges']['current_start'] + 86399;
				self::$filters_normalized['time_ranges']['type'] = 'd';
			}
			else if (self::$filters_normalized['selected']['year'] && !self::$filters_normalized['selected']['month']){
				self::$filters_normalized['time_ranges']['current_end'] = mktime(0, 0, 0, 1, 1, self::$filters_normalized['date']['year']+1)-1;
				self::$filters_normalized['time_ranges']['type'] = 'Y';
			}
			else{
				self::$filters_normalized['time_ranges']['current_end'] = strtotime(self::$filters_normalized['date']['year'].'-'.self::$filters_normalized['date']['month'].'-01 00:00 +1 month')-1;
				self::$filters_normalized['time_ranges']['type'] = 'm';
			}
		}
		else{
			self::$filters_normalized['time_ranges']['type'] = 'interval';
			if (self::$filters_normalized['date']['interval'] > 0){
				self::$filters_normalized['time_ranges']['current_end'] = strtotime(self::$filters_normalized['date']['year'].'-'.self::$filters_normalized['date']['month'].'-'.self::$filters_normalized['date']['day'].' 00:00:00 +'.self::$filters_normalized['date']['interval'].' days')-1;
			}
			else{
				// Swap boundaries, if interval is negative
				self::$filters_normalized['time_ranges']['current_end'] = mktime(23, 59, 59, self::$filters_normalized['date']['month'], self::$filters_normalized['date']['day'], self::$filters_normalized['date']['year']);
				self::$filters_normalized['time_ranges']['current_start'] = strtotime(self::$filters_normalized['date']['year'].'-'.self::$filters_normalized['date']['month'].'-'.self::$filters_normalized['date']['day'].' 00:00:00 '.(self::$filters_normalized['date']['interval']+1).' days');
			}
		}
		
		// If end is in the future, set it to now
		$now = date_i18n('U');
		if (self::$filters_normalized['time_ranges']['current_end'] > $now) self::$filters_normalized['time_ranges']['current_end'] = $now;

		// Now let's translate our filters into SQL clauses
		self::$sql_filters['where_time_range'] = ' AND (t1.dt BETWEEN '.self::$filters_normalized['time_ranges']['current_start'].' AND '.self::$filters_normalized['time_ranges']['current_end'].')';
		foreach (self::$filters_normalized['columns'] as $a_filter_column => $a_filter_data){
			$a_filter_empty = '0';

			// Some columns are in separate tables, so we need to join them
			switch (self::get_table_identifier($a_filter_column)){
				case 'tb.':
					self::$sql_filters['from']['browsers'] = "INNER JOIN {$GLOBALS['wpdb']->base_prefix}slim_browsers tb ON t1.browser_id = tb.browser_id";
					break;
				case 'tci.':
					self::$sql_filters['from']['content_info'] = "INNER JOIN {$GLOBALS['wpdb']->base_prefix}slim_content_info tci ON t1.content_info_id = tci.content_info_id";
					break;
				case 'tss.':
					self::$sql_filters['from']['screenres'] = "LEFT JOIN {$GLOBALS['wpdb']->base_prefix}slim_screenres tss ON t1.screenres_id = tss.screenres_id";
					break;
				case 'tob.':
					self::$sql_filters['from']['outbound'] = "LEFT JOIN {$GLOBALS['wpdb']->prefix}slim_outbound tob ON t1.id = tob.id";
					break;
				default:
			}

			// Some columns require a special treatment
			switch($a_filter_column){
				case 'ip':
				case 'other_ip':
					$a_filter_column = "INET_NTOA($a_filter_column)";
					$a_filter_empty = '0.0.0.0';
					break;
				default:
					$a_filter_column = self::get_table_identifier($a_filter_column).$a_filter_column;
					break;
			}

			switch ($a_filter_data[0]){
				case 'is_not_equal_to':
					self::$sql_filters['where'] .= $GLOBALS['wpdb']->prepare(" AND $a_filter_column <> %s", $a_filter_data[1]);
					break;
				case 'contains':
					self::$sql_filters['where'] .= $GLOBALS['wpdb']->prepare(" AND $a_filter_column LIKE %s", '%'.$a_filter_data[1].'%');
					break;
				case 'does_not_contain':
					self::$sql_filters['where'] .= $GLOBALS['wpdb']->prepare(" AND $a_filter_column NOT LIKE %s", '%'.$a_filter_data[1].'%');;
					break;
				case 'starts_with':
					self::$sql_filters['where'] .= $GLOBALS['wpdb']->prepare(" AND $a_filter_column LIKE %s", $a_filter_data[1].'%');
					break;
				case 'ends_with':
					self::$sql_filters['where'] .= $GLOBALS['wpdb']->prepare(" AND $a_filter_column LIKE %s", '%'.$a_filter_data[1]);
					break;
				case 'sounds_like':
					self::$sql_filters['where'] .= $GLOBALS['wpdb']->prepare(" AND SOUNDEX($a_filter_column) = SOUNDEX(%s)", $a_filter_data[1]);
					break;
				case 'is_empty':
					self::$sql_filters['where'] .= " AND ($a_filter_column = '' OR $a_filter_column = '$a_filter_empty')";
					break;
				case 'is_not_empty':
					self::$sql_filters['where'] .= " AND $a_filter_column <> '' AND $a_filter_column <> '$a_filter_empty'";
					break;
				case 'is_greater_than':
					self::$sql_filters['where'] .= $GLOBALS['wpdb']->prepare(" AND $a_filter_column > %s", $a_filter_data[1]);
					break;
				case 'is_less_than':
					self::$sql_filters['where'] .= $GLOBALS['wpdb']->prepare(" AND $a_filter_column < %s", $a_filter_data[1]);
					break;
				case 'matches':
					self::$sql_filters['where'] .= $GLOBALS['wpdb']->prepare(" AND $a_filter_column REGEXP %s", $a_filter_data[1]);
					break;
				case 'does_not_match':
					self::$sql_filters['where'] .= $GLOBALS['wpdb']->prepare(" AND $a_filter_column NOT REGEXP %s", $a_filter_data[1]);
					break;
				default:
					self::$sql_filters['where'] .= $GLOBALS['wpdb']->prepare(" AND $a_filter_column = %s", $a_filter_data[1]);
			}
		}
		self::$sql_filters['from']['all_other_tables'] = trim(self::$sql_filters['from']['browsers'].' '.self::$sql_filters['from']['screenres'].' '.self::$sql_filters['from']['content_info'].' '.self::$sql_filters['from']['outbound']);
		self::$sql_filters['from']['all_tables'] = "{$GLOBALS['wpdb']->prefix}slim_stats t1 ".self::$sql_filters['from']['all_other_tables'];
	}
	// end init

	/**
	 * Associates tables and their 'SQL aliases'
	 */
	public static function get_table_identifier($_field = 'id'){
		switch($_field){
			case 'browser':
			case 'version':
			case 'css_version':
			case 'type':
			case 'platform':
			case 'user_agent':
				return 'tb.';
				break;
			case 'resolution':
			case 'colordepth':
				return 'tss.';
				break;
			case 'author':
			case 'category':
			case 'content_type':
			case 'content_id':
				return 'tci.';
				break;
			case 'outbound_domain':
			case 'outbound_resource':
			case 'position':
				return 'tob.';
				break;
			default:
				return 't1.';
				break;
		}	
	}
	// end get_table_identifier

	// The following methods retrieve the information from the database

	public static function count_bouncing_pages(){
		return intval(wp_slimstat::$wpdb->get_var('
			SELECT COUNT(*) count
				FROM (
					SELECT t1.resource
					FROM '.self::$sql_filters['from']['all_tables'].' '.self::_add_filters_to_sql_from('tci.content_type').'
					WHERE t1.visit_id <> 0 AND t1.resource <> "__l_s__" AND t1.resource <> "" AND tci.content_type <> "404" '.self::$sql_filters['where'].' '.self::$sql_filters['where_time_range'].'
					GROUP BY visit_id
					HAVING COUNT(visit_id) = 1
				) as ts1'));
	}

	public static function count_exit_pages(){
		return intval(wp_slimstat::$wpdb->get_var('
			SELECT COUNT(*) count
				FROM (
					SELECT resource, visit_id, dt
					FROM '.self::$sql_filters['from']['all_tables'].'
					WHERE visit_id > 0 AND resource <> "" AND resource <> "__l_s__" '.self::$sql_filters['where'].' '.self::$sql_filters['where_time_range'].'
					GROUP BY visit_id
					HAVING dt = MAX(dt)
				) AS ts1'));
	}

	public static function count_records($_where_clause = '1=1', $_distinct_column = '*', $_use_filters = true, $_use_date_filters = true, $_join_tables = ''){
		$column = ($_distinct_column != '*')?"DISTINCT $_distinct_column":$_distinct_column;
		return intval(wp_slimstat::$wpdb->get_var("
			SELECT COUNT($column) count
			FROM {$GLOBALS['wpdb']->prefix}slim_stats t1 ".($_use_filters?self::$sql_filters['from']['all_other_tables']:'').' '.self::_add_filters_to_sql_from($_where_clause.$_join_tables).'
			WHERE '.(!empty($_where_clause)?$_where_clause:'1=1').' '.($_use_filters?self::$sql_filters['where']:'').' '.($_use_date_filters?self::$sql_filters['where_time_range']:'')));
	}

	public static function count_outbound(){
		return intval(wp_slimstat::$wpdb->get_var("
			SELECT COUNT(outbound_id) count
			FROM {$GLOBALS['wpdb']->prefix}slim_stats t1 INNER JOIN {$GLOBALS['wpdb']->prefix}slim_outbound tob ON t1.id = tob.id ".self::$sql_filters['from']['all_other_tables']."
			WHERE 1=1 ".self::$sql_filters['where'].' '.self::$sql_filters['where_time_range']));
	}

	public static function count_records_having($_where_clause = '1=1', $_column = 't1.ip', $_having_clause = ''){
		return intval(wp_slimstat::$wpdb->get_var("
			SELECT COUNT(*) FROM (
				SELECT $_column
				FROM ".self::$sql_filters['from']['all_tables'].' '.self::_add_filters_to_sql_from($_where_clause)."
				WHERE $_where_clause ".self::$sql_filters['where'].' '.self::$sql_filters['where_time_range']."
				GROUP BY $_column
				".(!empty($_having_clause)?"HAVING $_having_clause":'').')
			AS ts1'));
	}

	public static function get_data_size(){
		$suffix = 'KB';

		$sql = 'SHOW TABLE STATUS LIKE "'.$GLOBALS['wpdb']->prefix.'slim_stats"';
		$table_details = wp_slimstat::$wpdb->get_row($sql, 'ARRAY_A', 0);

		$table_size = ( $table_details['Data_length'] / 1024 ) + ( $table_details['Index_length'] / 1024 );

		if ($table_size > 1024){
			$table_size /= 1024;
			$suffix = 'MB';
		}
		return number_format($table_size, 2, self::$formats['decimal'], self::$formats['thousand']).' '.$suffix;
	}

	public static function get_max_and_average_pages_per_visit(){
		return wp_slimstat::$wpdb->get_row('
			SELECT AVG(ts1.count) avg, MAX(ts1.count) max FROM (
				SELECT count(ip) count, visit_id
				FROM '.self::$sql_filters['from']['all_tables'].'
				WHERE visit_id > 0 '.self::$sql_filters['where'].' '.self::$sql_filters['where_time_range'].'
				GROUP BY visit_id
			) AS ts1', ARRAY_A);
	}

	public static function get_oldest_visit($_where_clause = '1=1', $_use_filters = true){
		return wp_slimstat::$wpdb->get_var("
			SELECT t1.dt
			FROM {$GLOBALS['wpdb']->prefix}slim_stats t1 ".($_use_filters?self::$sql_filters['from']['all_other_tables']:'').' '.self::_add_filters_to_sql_from($_where_clause).'
			WHERE '.(!empty($_where_clause)?$_where_clause:'1=1').' '.($_use_filters?self::$sql_filters['where']:'').'
			ORDER BY dt ASC
			LIMIT 0,1');
	}

	public static function get_popular($_column = 't1.id', $_custom_where = '', $_more_columns = '', $_having_clause = '', $_as_column = ''){
		return wp_slimstat::$wpdb->get_results("
			SELECT $_column ".(!empty($_as_column)?'AS '.$_as_column:'')." $_more_columns, COUNT(*) count
			FROM ".self::$sql_filters['from']['all_tables'].' '.self::_add_filters_to_sql_from($_column.$_custom_where.$_more_columns).'
			WHERE '.(empty($_custom_where)?"$_column <> '' AND  $_column <> '__l_s__'":$_custom_where).' '.self::$sql_filters['where'].' '.self::$sql_filters['where_time_range']."
			GROUP BY $_column $_more_columns $_having_clause
			ORDER BY count ".self::$filters_normalized['misc']['direction']."
			LIMIT ".self::$filters_normalized['misc']['start_from'].', '.self::$filters_normalized['misc']['limit_results'], ARRAY_A);
	}

	public static function get_popular_outbound(){
		return wp_slimstat::$wpdb->get_results("
			SELECT tob.outbound_resource as resource, COUNT(*) count
			FROM {$GLOBALS['wpdb']->prefix}slim_stats t1 INNER JOIN {$GLOBALS['wpdb']->prefix}slim_outbound tob ON t1.id = tob.id ".self::$sql_filters['from']['browsers'].' '.self::$sql_filters['from']['screenres'].' '.self::$sql_filters['from']['content_info']."
			WHERE 1=1 ".self::$sql_filters['where'].' '.self::$sql_filters['where_time_range'].'
			GROUP BY tob.outbound_resource 
			ORDER BY count '.self::$filters_normalized['misc']['direction'].'
			LIMIT '.self::$filters_normalized['misc']['start_from'].', '.self::$filters_normalized['misc']['limit_results'], ARRAY_A);
	}

	public static function get_popular_complete($_column = 't1.id', $_custom_where = '', $_join_tables = '', $_having_clause = ''){
		return wp_slimstat::$wpdb->get_results("
			SELECT t1.*, ts1.maxid, ts1.count
			FROM (
				SELECT $_column, MAX(t1.id) maxid, COUNT(*) count
				FROM ".self::$sql_filters['from']['all_tables'].' '.self::_add_filters_to_sql_from($_column.$_custom_where).'
				WHERE '.(empty($_custom_where)?"$_column <> '' AND  $_column <> '__l_s__'":$_custom_where).' '.self::$sql_filters['where'].' '.self::$sql_filters['where_time_range']."
				GROUP BY $_column $_having_clause
			) AS ts1 JOIN {$GLOBALS['wpdb']->prefix}slim_stats t1 ON ts1.maxid = t1.id ".
			(!empty($_join_tables)?self::_add_filters_to_sql_from($_join_tables):'').
			'ORDER BY ts1.count '.self::$filters_normalized['misc']['direction'].'
			LIMIT '.self::$filters_normalized['misc']['start_from'].', '.self::$filters_normalized['misc']['limit_results'], ARRAY_A);
	}

	public static function get_recent($_column = 't1.id', $_custom_where = '', $_join_tables = '', $_having_clause = '', $_order_by = '', $_use_date_filters = true){
		if ($_column == 't1.id'){
			return wp_slimstat::$wpdb->get_results('
				SELECT t1.*'.(!empty($_join_tables)?', '.$_join_tables:'').'
				FROM '.self::$sql_filters['from']['all_tables'].' '.(!empty($_join_tables)?self::_add_filters_to_sql_from($_join_tables):'').'
				WHERE '.(empty($_custom_where)?"$_column <> 0 ":$_custom_where).' '.self::$sql_filters['where'].' '.($_use_date_filters?self::$sql_filters['where_time_range']:'').
				'ORDER BY '.(empty($_order_by)?'t1.dt '.self::$filters_normalized['misc']['direction']:$_order_by).'
				LIMIT '.self::$filters_normalized['misc']['start_from'].', '.self::$filters_normalized['misc']['limit_results'], ARRAY_A);
			
		}
		else{
			return wp_slimstat::$wpdb->get_results('
				SELECT t1.*, '.(!empty($_join_tables)?$_join_tables:'ts1.maxid')."
				FROM (
					SELECT $_column, MAX(t1.id) maxid
					FROM ".self::$sql_filters['from']['all_tables'].' '.self::_add_filters_to_sql_from($_column.$_custom_where).'
					WHERE '.(empty($_custom_where)?"$_column <> '' AND  $_column <> '__l_s__'":$_custom_where).' '.self::$sql_filters['where'].' '.($_use_date_filters?self::$sql_filters['where_time_range']:'')."
					GROUP BY $_column $_having_clause
				) AS ts1 INNER JOIN {$GLOBALS['wpdb']->prefix}slim_stats t1 ON ts1.maxid = t1.id ".
				(!empty($_join_tables)?self::_add_filters_to_sql_from($_join_tables):'').
				'ORDER BY '.(empty($_order_by)?'t1.dt '.self::$filters_normalized['misc']['direction']:$_order_by).'
				LIMIT '.self::$filters_normalized['misc']['start_from'].', '.self::$filters_normalized['misc']['limit_results'], ARRAY_A);
		}
	}

	public static function get_recent_outbound($_type = -1){
		return wp_slimstat::$wpdb->get_results("
			SELECT tob.outbound_id as visit_id, tob.outbound_domain, tob.outbound_resource as resource, tob.type, tob.notes, t1.ip, t1.other_ip, t1.user, 'local' as domain, t1.resource as referer, t1.country, tb.browser, tb.version, tb.platform, tob.dt
			FROM {$GLOBALS['wpdb']->prefix}slim_stats t1 INNER JOIN {$GLOBALS['wpdb']->prefix}slim_outbound tob ON tob.id = t1.id INNER JOIN {$GLOBALS['wpdb']->base_prefix}slim_browsers tb on t1.browser_id = tb.browser_id ".self::$sql_filters['from']['screenres'].' '.self::$sql_filters['from']['content_info'].'
			WHERE '.(($_type != -1)?"tob.type = $_type":'tob.type > 1').' '.self::$sql_filters['where'].' '.self::$sql_filters['where_time_range'].
			'ORDER BY tob.dt '.self::$filters_normalized['misc']['direction'].'
			LIMIT '.self::$filters_normalized['misc']['start_from'].', '.self::$filters_normalized['misc']['limit_results'], ARRAY_A);
	}

	public static function get_data_for_chart($_data1, $_data2, $_custom_where_clause = '', $_sql_from_where = ''){
		$previous = array('end' => self::$filters_normalized['time_ranges']['current_start'] - 1);
		$label_date_format = '';
		$output = array();

		// Each type has its own parameters
		switch (self::$filters_normalized['time_ranges']['type']){
			case 'H':
				$previous['start'] = self::$filters_normalized['time_ranges']['current_start'] - 3600;
				$label_date_format = get_option('time_format', 'g:i a');
				$group_by = array('HOUR', 'MINUTE', 'i');
				$values_in_interval = array(60, 60, 0); 
				break;
			case 'd':
				$previous['start'] = self::$filters_normalized['time_ranges']['current_start'] - 86400;
				$label_date_format = (self::$formats['decimal'] == '.')?'m/d':'d/m';
				$group_by = array('DAY', 'HOUR', 'G');
				$values_in_interval = array(24, 24, 0);
				break;
			case 'Y':
				$previous['start'] = mktime(0, 0, 0, 1, 1, self::$filters_normalized['date']['year']-1);
				$label_date_format = 'Y';
				$group_by = array('YEAR', 'MONTH', 'n');
				$values_in_interval = array(12, 12, 1);
				break;
			case 'interval':
				$group_by = array('MONTH', 'DAY', 'j');
				$values_in_interval = array(abs(self::$filters_normalized['date']['interval']), abs(self::$filters_normalized['date']['interval']), 0);
				break;
			default:
				$previous['start'] = mktime(0, 0, 0, self::$filters_normalized['date']['month']-1, 1, self::$filters_normalized['date']['year']);
				$label_date_format = 'm/Y';
				$group_by = array('MONTH', 'DAY', 'j');
				$values_in_interval = array(date('t', $previous['start']), date('t', self::$filters_normalized['time_ranges']['current_start']), 1);
				break;
		}
		

		// Custom intervals don't have a comparison chart ('previous' range)
		$time_range = self::$sql_filters['where_time_range'];
		if (!self::$filters_normalized['selected']['interval']){
			$time_range = 'AND (t1.dt BETWEEN '.$previous['start'].' AND '.$previous['end'].' OR t1.dt BETWEEN '.self::$filters_normalized['time_ranges']['current_start'].' AND '.self::$filters_normalized['time_ranges']['current_end'].')';
		}

		// Build the SQL query
		$sql = "SELECT DATE_FORMAT(FROM_UNIXTIME(dt), '%Y-%m-%d %H:%i') datestamp, $_data1 first_metric, $_data2 second_metric";

		// Panel 4 has a slightly different structure
		if(empty($_sql_from_where)){
			$sql .= '	FROM '.self::$sql_filters['from']['all_tables'].' '.self::_add_filters_to_sql_from($_data1.$_data2.$_custom_where_clause)."
						WHERE 1=1 $time_range ".self::$sql_filters['where'].' '.$_custom_where_clause;
		}
		else{
			$sql_no_placeholders = str_replace('[from_tables]', self::$sql_filters['from']['all_tables'].' '.self::_add_filters_to_sql_from($_data1.$_data2.$_custom_where_clause), $_sql_from_where);
			$sql_no_placeholders = str_replace('[where_clause]', '1=1 '.$time_range.' '.self::$sql_filters['where'].' '.$_custom_where_clause, $sql_no_placeholders);
			$sql .= $sql_no_placeholders;
		}
		$sql .= " GROUP BY {$group_by[0]}(FROM_UNIXTIME(dt)), {$group_by[1]}(FROM_UNIXTIME(dt))";

		// Get the data
		$results = wp_slimstat::$wpdb->get_results($sql, ARRAY_A);

		// Fill the output array
		$output['current']['label'] = '';
		if (!empty($label_date_format)){
			$output['current']['label'] = date_i18n($label_date_format, self::$filters_normalized['time_ranges']['current_start']);
			$output['previous']['label'] = date_i18n($label_date_format, $previous['start']);
		}

		$output['previous']['first_metric'] = array_fill($values_in_interval[2], $values_in_interval[0], 0);
		$output['previous']['second_metric'] = array_fill($values_in_interval[2], $values_in_interval[1], 0);

		for ($i = $values_in_interval[2]; $i < $values_in_interval[0]; $i++){
			// Do not include dates in the future
			if ((!self::$filters_normalized['selected']['interval'] || date_i18n('Ymd', wp_slimstat_db::$filters_normalized['time_ranges']['current_start'] + ( $i * 86400)) > date_i18n('Ymd')) && 
				(self::$filters_normalized['selected']['interval'] || $i > date_i18n($group_by[2]))){
				continue;
			}
			$output['current']['first_metric'][$i] = 0;
			$output['current']['second_metric'][$i] = 0;
		}

		// No data? No problem!
		if (!is_array($results) || empty($results)){
			return ($output);
		}

		// Rearrange the data and then format it for Flot
		foreach ($results as $i => $a_result){
			$unix_datestamp = strtotime($a_result['datestamp']);
			$index = (self::$filters_normalized['selected']['interval'])?floor(($unix_datestamp - wp_slimstat_db::$filters_normalized['time_ranges']['current_start'])/86400):date_i18n($group_by[2], $unix_datestamp);
			
			if (!self::$filters_normalized['selected']['interval'] && date_i18n(self::$filters_normalized['time_ranges']['type'], $unix_datestamp) == date_i18n(self::$filters_normalized['time_ranges']['type'], $previous['start'], true)){
				$output['previous']['first_metric'][$index] = $a_result['first_metric'];
				$output['previous']['second_metric'][$index] = $a_result['second_metric'];
			}
			if (self::$filters_normalized['selected']['interval'] || date_i18n(self::$filters_normalized['time_ranges']['type'], $unix_datestamp) == date_i18n(self::$filters_normalized['time_ranges']['type'], self::$filters_normalized['time_ranges']['current_start'], true)){
				$output['current']['first_metric'][$index] = $a_result['first_metric'];
				$output['current']['second_metric'][$index] = $a_result['second_metric'];
			}
		}

		//var_dump($output);
		return ($output);

/* 		BYE BYE OLD CODE!



		// Avoid PHP warnings in strict mode
		$result = array(
			'current' => array('non_zero_count' => 0, 'data1' => '', 'data2' => ''),
			'previous' => array('non_zero_count' => 0, 'data1' => '', 'data2' => ''),
			'max_yaxis' => 0,
			'ticks' => ''
		);
		$data = array();

		

		$data['count_offset'] = 0;
		if (!self::$filters_normalized['selected']['interval']){
			
			if (self::$filters_normalized['selected']['hour']){
				$sql = "SELECT DATE_FORMAT(FROM_UNIXTIME(dt), '%Y %m %d %H:%i') datestamp, $_data1 data1, $_data2 data2";
				$group_and_order = "GROUP BY DATE_FORMAT(FROM_UNIXTIME(dt), '%H'), DATE_FORMAT(FROM_UNIXTIME(dt), '%i') ORDER BY datestamp ASC";
				$data['end_value'] = 60;
				$data['count_offset'] = 1;
			}
			elseif (self::$filters_normalized['selected']['day']){
				$sql = "SELECT DATE_FORMAT(FROM_UNIXTIME(dt), '%Y %m %d %H:00') datestamp, $_data1 data1, $_data2 data2";
				$group_and_order = "GROUP BY DATE_FORMAT(FROM_UNIXTIME(dt), '%d'), DATE_FORMAT(FROM_UNIXTIME(dt), '%H') ORDER BY datestamp ASC";
				$data['end_value'] = 24;
				$data['count_offset'] = 1;
			}
			elseif (self::$filters_normalized['selected']['year'] && !self::$filters_normalized['selected']['month']){
				$sql = "SELECT DATE_FORMAT(FROM_UNIXTIME(dt), '%Y %m 01 00:00') datestamp, $_data1 data1, $_data2 data2";
				$group_and_order = "GROUP BY DATE_FORMAT(FROM_UNIXTIME(dt), '%Y'), DATE_FORMAT(FROM_UNIXTIME(dt), '%m') ORDER BY datestamp ASC";
				$data['end_value'] = 12;

			}
			else{
				$sql = "SELECT DATE_FORMAT(FROM_UNIXTIME(dt), '%Y %m %d 00:00') datestamp, $_data1 data1, $_data2 data2";
				$group_and_order = "GROUP BY DATE_FORMAT(FROM_UNIXTIME(dt), '%m'), DATE_FORMAT(FROM_UNIXTIME(dt), '%d') ORDER BY datestamp ASC";
				$data['end_value'] = 31;
			}
		}
		else{
			$time_range = self::$sql_filters['where_time_range'];
			$sql = "SELECT DATE_FORMAT(FROM_UNIXTIME(dt), '%Y %m %d 00:00') datestamp, $_data1 data1, $_data2 data2";
			$group_and_order = "GROUP BY DATE_FORMAT(FROM_UNIXTIME(dt), '%m'), DATE_FORMAT(FROM_UNIXTIME(dt), '%d') ORDER BY datestamp ASC";
			$data['end_value'] = abs(self::$filters_normalized['date']['interval']);
			$data['count_offset'] = -1; // skip ticks generation
		}

		// Panel 4 has a slightly different structure
		if(empty($_sql_from_where)){
			$sql .= '	FROM '.self::$sql_filters['from']['all_tables'].' '.self::_add_filters_to_sql_from($_data1.$_data2.$_custom_where_clause)."
						WHERE 1=1 $time_range ".self::$sql_filters['where'].' '.$_custom_where_clause;
		}
		else{
			$sql_no_placeholders = str_replace('[from_tables]', self::$sql_filters['from']['all_tables'].' '.self::_add_filters_to_sql_from($_data1.$_data2.$_custom_where_clause), $_sql_from_where);
			$sql_no_placeholders = str_replace('[where_clause]', $time_range.' '.self::$sql_filters['where'].' '.$_custom_where_clause, $sql_no_placeholders);
			$sql .= $sql_no_placeholders;
		}
		$sql .= ' '.$group_and_order;
		$array_results = wp_slimstat::$wpdb->get_results($sql, ARRAY_A);

		if (!is_array($array_results) || empty($array_results))
			$array_results = array_fill(0, $data['end_value']*2, array('datestamp' => 0, 'data1' => 0, 'data2' => 0));

		// Reorganize the data and then format it for Flot
		foreach ($array_results as $a_result){
			$data[0][$a_result['datestamp']] = $a_result['data1'];
			$data[1][$a_result['datestamp']] = $a_result['data2'];
		}

		$result['max_yaxis'] = max(max($data[0]), max($data[1]));
		$result['ticks'] = self::_generate_ticks($data['end_value'], $data['count_offset']);

		// Reverse the chart, if needed
		$k = ($GLOBALS['wp_locale']->text_direction == 'rtl')?1-$data['end_value']:0;

		for ($i=0;$i<$data['end_value'];$i++){
			$j = abs($k+$i);
			if (!self::$filters_normalized['selected']['interval']){
				if (self::$filters_normalized['selected']['hour']){
					$datestamp['timestamp_current'] = mktime(self::$filters_normalized['date']['hour'], $j, 0, self::$filters_normalized['date']['month'], self::$filters_normalized['date']['day'], self::$filters_normalized['date']['year']);
					$datestamp['timestamp_previous'] = mktime(self::$filters_normalized['date']['hour'] - 1, $j, 0, self::$filters_normalized['date']['month'], self::$filters_normalized['date']['day'], self::$filters_normalized['date']['year']);
					$datestamp['filter_current'] =  '';
					$datestamp['filter_previous'] =  '';
					//$datestamp['marking_signature'] = self::$filters_normalized['date']['year'].' '.self::$filters_normalized['date']['month'].' '.self::$filters_normalized['date']['day'].' '.self::$filters_normalized['date']['hour'].':'.sprintf('%02d', $j);
					$datestamp['group'] = 'h';
				}
				elseif (self::$filters_normalized['selected']['day']){
					$datestamp['timestamp_current'] = mktime($j, 0, 0, self::$filters_normalized['date']['month'], self::$filters_normalized['date']['day'], self::$filters_normalized['date']['year']);
					$datestamp['timestamp_previous'] = mktime($j, 0, 0, self::$filters_normalized['date']['month'], self::$filters_normalized['date']['day']-1, self::$filters_normalized['date']['year']);
					$datestamp['filter_current'] = ',"'.$_view_url.'&amp;fs%5Bhour%5D='.urlencode('equals '.$j).'&amp;fs%5Bday%5D='.urlencode('equals '.self::$filters_normalized['date']['day']).'&amp;fs%5Bmonth%5D='.urlencode('equals '.self::$filters_normalized['date']['month']).'&amp;fs%5Byear%5D='.urlencode('equals '.self::$filters_normalized['date']['year']).'"';
					$datestamp['filter_previous'] = ',"'.$_view_url.'&amp;fs%5Bhour%5D='.urlencode('equals '.$j).'&amp;fs%5Bday%5D='.urlencode('equals '.date_i18n('d', $datestamp['timestamp_previous'])).'&amp;fs%5Bmonth%5D='.urlencode('equals '.date_i18n('m', $datestamp['timestamp_previous'])).'&amp;fs%5Byear%5D='.urlencode('equals '.date_i18n('Y', $datestamp['timestamp_previous'])).'"';
					//$datestamp['marking_signature'] = self::$filters_normalized['date']['year'].' '.self::$filters_normalized['date']['month'].' '.self::$filters_normalized['date']['day'].' '.sprintf('%02d', $j);
					$datestamp['group'] = 'd';
				}
				elseif (self::$filters_normalized['selected']['year'] && !self::$filters_normalized['selected']['month']){
					$datestamp['timestamp_current'] = mktime(0, 0, 0, $j+1, 1, self::$filters_normalized['date']['year']);
					$datestamp['timestamp_previous'] = mktime(0, 0, 0, $j+1, 1, self::$filters_normalized['date']['year']-1);
					$datestamp['filter_current'] = ',"'.$_view_url.'&amp;fs%5Bmonth%5D='.urlencode('equals '.($j+1)).'&amp;fs%5Byear%5D='.urlencode('equals '.self::$filters_normalized['date']['year']).'"';
					$datestamp['filter_previous'] = ',"'.$_view_url.'&amp;fs%5Bmonth%5D='.urlencode('equals '.($j+1)).'&amp;fs%5Byear%5D='.urlencode('equals '.(self::$filters_normalized['date']['year']-1)).'"';
					//$datestamp['marking_signature'] = self::$filters_normalized['date']['year'].' '.sprintf('%02d', $j+1);
					$datestamp['group'] = 'Y';
				}
				else{
					$datestamp['timestamp_current'] = mktime(0, 0, 0, self::$filters_normalized['date']['month'], $j+1, self::$filters_normalized['date']['year']);
					$datestamp['timestamp_previous'] = mktime(0, 0, 0, self::$filters_normalized['date']['month']-1, $j+1, self::$filters_normalized['date']['year']);
					$datestamp['filter_current'] =  ',"'.$_view_url.'&amp;fs%5Bday%5D='.urlencode('equals '.($j+1)).'&amp;fs%5Bmonth%5D='.urlencode('equals '.self::$filters_normalized['date']['month']).'&amp;fs%5Byear%5D='.urlencode('equals '.self::$filters_normalized['date']['year']).'"';
					$datestamp['filter_previous'] =  ',"'.$_view_url.'&amp;fs%5Bday%5D='.urlencode('equals '.($j+1)).'&amp;fs%5Bmonth%5D='.urlencode('equals '.date_i18n('m', $datestamp['timestamp_previous'])).'&amp;fs%5Byear%5D='.urlencode('equals '.date_i18n('Y', $datestamp['timestamp_previous'])).'"';
					//$datestamp['marking_signature'] = self::$filters_normalized['date']['year'].' '.self::$filters_normalized['date']['month'].' '.sprintf('%02d', $j+1);
					$datestamp['group'] = 'm';
				}
			}
			else{
				$datestamp['timestamp_current'] = mktime(0, 0, 0, self::$filters_normalized['date']['month'], self::$filters_normalized['date']['day']+$j, self::$filters_normalized['date']['year']);
				$datestamp['timestamp_previous'] = mktime(0, 0, 0, self::$filters_normalized['date']['month']-1, self::$filters_normalized['date']['day']+$j, self::$filters_normalized['date']['year']);
				$datestamp['filter_current'] =  ',"'.$_view_url.'&amp;fs%5Bday%5D='.urlencode('equals '.(self::$filters_normalized['date']['day']+$j)).'&amp;fs%5Bmonth%5D='.urlencode('equals '.self::$filters_normalized['date']['month']).'&amp;fs%5Byear%5D='.urlencode('equals '.self::$filters_normalized['date']['year']).'"';
				$datestamp['filter_previous'] =  ',"'.$_view_url.'&amp;fs%5Bday%5D='.urlencode('equals '.(self::$filters_normalized['date']['day']+$j)).'&amp;fs%5Bmonth%5D='.urlencode('equals '.date_i18n('m', $datestamp['timestamp_previous'])).'&amp;fs%5Byear%5D='.urlencode('equals '.date_i18n('Y', $datestamp['timestamp_previous'])).'"';
				//$datestamp['marking_signature'] = self::$filters_normalized['date']['year'].' '.self::$filters_normalized['date']['month'].' '.sprintf('%02d', self::$filters_normalized['date']['day']+$j);
				$datestamp['group'] = 'm';
			}


			$datestamp['current'] = date_i18n('Y m d H:i', $datestamp['timestamp_current']);
			$datestamp['previous'] = date_i18n('Y m d H:i', $datestamp['timestamp_previous']);

			if (date_i18n($datestamp['group'], $datestamp['timestamp_current']) == date_i18n($datestamp['group'], self::$filters_normalized['time_ranges']['current_start'], true) || self::$filters_normalized['selected']['interval']){
				if (!empty($data[0][$datestamp['current']])){
					$result['current']['data1'] .= "[$i,{$data[0][$datestamp['current']]}{$datestamp['filter_current']}],";
					$result['current']['non_zero_count']++;
				}	
				elseif($datestamp['timestamp_current'] <= date_i18n('U')){
					$result['current']['data1'] .= "[$i,0],";
				}

				if (!empty($data[1][$datestamp['current']])){
					$result['current']['data2'] .= "[$i,{$data[1][$datestamp['current']]}{$datestamp['filter_current']}],";
				}
				elseif($datestamp['timestamp_current'] <= date_i18n('U')){
					$result['current']['data2'] .= "[$i,0],";
				}
			}

			if (date_i18n($datestamp['group'], $datestamp['timestamp_previous']) == date_i18n($datestamp['group'], self::$filters_normalized['time_ranges']['previous_start'], true) && !self::$filters_normalized['selected']['interval']){
				if (!empty($data[0][$datestamp['previous']])){
					$result['previous']['data1'] .= "[$i,{$data[0][$datestamp['previous']]}{$datestamp['filter_previous']}],";
					$result['previous']['non_zero_count']++;
				}
				elseif($datestamp['timestamp_previous'] <= date_i18n('U')){
					$result['previous']['data1'] .= "[$i,0],";
				}
				
				if (!empty($data[1][$datestamp['previous']])){
					$result['previous']['data2'] .= "[$i,{$data[1][$datestamp['previous']]}{$datestamp['filter_current']}],";
				}
				elseif($datestamp['timestamp_current'] <= date_i18n('U')){
					$result['previous']['data2'] .= "[$i,0],";
				}
			}
			
			if (self::$filters_normalized['selected']['interval']){
				$result['ticks'] .= "[$i, '".((self::$formats['decimal'] == '.')?date_i18n('m/d', $datestamp['timestamp_current']):date_i18n('d/m', $datestamp['timestamp_current']))."'],";
			}
		}

		date_default_timezone_set($reset_timezone);

		$result['current']['data1'] = substr($result['current']['data1'], 0, -1);
		$result['current']['data2'] = substr($result['current']['data2'], 0, -1);
		$result['previous']['data1'] = substr($result['previous']['data1'], 0, -1);
		$result['previous']['data2'] = substr($result['previous']['data2'], 0, -1);
		$result['ticks'] = substr($result['ticks'], 0, -1);

		return $result;
*/
	}

	public static function reset_filters(){
		self::$filters_normalized = array(
			'columns' => array(),
			'date' => array(
				'hour' => 0,
				'day' => 1,
				'month' => date_i18n('m'),
				'year' => date_i18n('Y'),
				'interval' => 0,
			),
			'selected' => array(
				'hour' => false,
				'day' => false,
				'month' => false,
				'year' => false,'interval' => false
			),
			'misc' => array(
				'direction' => 'desc',
				'limit_results' => wp_slimstat::$options['rows_to_show'],
				'start_from' => 0
			),
			'time_ranges' => array(
				'current_start' => mktime(0, 0, 0, date_i18n('m'), 1, date_i18n('Y')),
				'current_end' => strtotime(date_i18n('Y').'-'.date_i18n('m').'-01 00:00 +1 month')-1,
				'type' => 'month'
				//'current_label' => date_i18n('m/Y', mktime(0, 0, 0, date_i18n('m'), 1, date_i18n('Y'))),
				//'previous_start' => 0,
				//'previous_end' => 0,
				//'previous_label' => '',
			)
		);
		
		self::$sql_filters = array(
			'from' => array(
				'browsers' => '',
				'screenres' => '',
				'content_info' => '',
				'outbound' => '',
				'all_tables' => '',
				'all_other_tables' => ''
			),
			'where' => '',
			'where_time_range' => ''
		);
	}

	protected static function _add_filters_to_sql_from($_sql_tables = '', $_ignore_empty = false){
		$sql_from = '';
		if (($_ignore_empty || empty(self::$sql_filters['from']['browsers'])) && strpos($_sql_tables, 'tb.') !== false)
			$sql_from .= " INNER JOIN {$GLOBALS['wpdb']->base_prefix}slim_browsers tb ON t1.browser_id = tb.browser_id";

		if (($_ignore_empty || empty(self::$sql_filters['from']['content_info'])) && strpos($_sql_tables, 'tci.') !== false)
			$sql_from .=  " INNER JOIN {$GLOBALS['wpdb']->base_prefix}slim_content_info tci ON t1.content_info_id = tci.content_info_id";

		if (($_ignore_empty || empty(self::$sql_filters['from']['outbound'])) && strpos($_sql_tables, 'tob.') !== false)
			$sql_from .=  " LEFT JOIN {$GLOBALS['wpdb']->prefix}slim_outbound tob ON t1.id = tob.id";

		if (($_ignore_empty || empty(self::$sql_filters['from']['screenres'])) && strpos($_sql_tables, 'tss.') !== false)
			$sql_from .=  " LEFT JOIN {$GLOBALS['wpdb']->base_prefix}slim_screenres tss ON t1.screenres_id = tss.screenres_id";
		
		return $sql_from;
	}
/*
	protected static function _generate_ticks($_count = 0, $_offset = 0){
		$ticks = '';
		if ($_offset < 0) return $ticks;

		if ($GLOBALS['wp_locale']->text_direction == 'rtl'){
			for ($i = 0; $i < $_count; $i++){
				$ticks .= '['.$i.',"'.($_count - $i - $_offset).'"],';
			}
		}
		else{
			for ($i = 0; $i < $_count; $i++){
				$ticks .= '['.$i.',"'.($i - $_offset + 1).'"],';
			}
		}
		return $ticks;
	}
*/
}