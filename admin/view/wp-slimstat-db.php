<?php

// Let's define the main class with all the methods that we need
class wp_slimstat_db {
	// These tables contain the data about visits and visitors
	public static $table_stats = '';
	public static $table_outbound = '';

	// Lookup tables (shared among the various installations in a wordpress multiuser environment)
	public static $table_browsers = '';
	public static $table_screenres = '';
	public static $table_content_info = '';

	// Date ranges
	public static $current_date = array();
	public static $previous_date = array();
	public static $yesterday = array();
	public static $previous_month = array();
	public static $date_time_format = 'Y-m-d g:i a';
	public static $day_filter_active = false;

	// Number format
	public static $decimal_separator = ',';
	public static $thousand_separator = '.';

	// Filters
	public static $filters_parsed;
	public static $filters_date_sql_where = '';
	protected static $filters_sql_from = array('browsers' => '', 'screenres' => '', 'content_info' => '');
	protected static $filters_sql_where = '';

	public static function init($_filters_string = ''){
		self::$table_stats = $GLOBALS['wpdb']->prefix.'slim_stats';
		self::$table_outbound = $GLOBALS['wpdb']->prefix.'slim_outbound';
		self::$table_browsers = $GLOBALS['wpdb']->base_prefix.'slim_browsers';
		self::$table_screenres = $GLOBALS['wpdb']->base_prefix.'slim_screenres';
		self::$table_content_info = $GLOBALS['wpdb']->base_prefix.'slim_content_info';

		self::$date_time_format = get_option('date_format', 'd-m-Y').' '.get_option('time_format', 'g:i a');

		// Reset MySQL timezone settings, our dates and times are recorded using WP settings
		$GLOBALS['wpdb']->query("SET @@session.time_zone = '+00:00'");

		if (wp_slimstat::$options['use_european_separators'] == 'no'){
			self::$decimal_separator = '.';
			self::$thousand_separator = ',';
		}

		// Parse the string with all the filters
		if (!empty($_filters_string)){
			if (substr($_filters_string, -1) != '|') $_filters_string .= '|';
			preg_match_all('/([^\s|]+)\s([^\s|]+).((?:[^|]+)?)/', urldecode($_filters_string), $matches);

			self::$filters_parsed = array();
			foreach($matches[1] as $idx => $a_match){
				// Some filters need extra 'decoding'
				switch($a_match){
					case 'direction':
					case 'limit_results':
					case 'starting':
						self::$filters_parsed[$a_match] = $GLOBALS['wpdb']->escape(str_replace('\\', '', htmlspecialchars_decode($matches[3][$idx])));
						break;
					case 'strtotime':
						$custom_date = strtotime($matches[3][$idx]);
						self::$filters_parsed['day'] = array('equals', date_i18n('j', $custom_date));
						self::$filters_parsed['month'] = array('equals', date_i18n('n', $custom_date));
						self::$filters_parsed['year'] = array('equals', date_i18n('Y', $custom_date));
						break;
					case 'interval':
						self::$filters_parsed['day'] = array('equals', !isset(self::$filters_parsed['day'][1])?date_i18n('j'):intval(self::$filters_parsed['day'][1]));
						self::$filters_parsed['month'] = array('equals', !isset(self::$filters_parsed['month'][1])?date_i18n('n'):intval(self::$filters_parsed['month'][1]));
						self::$filters_parsed['year'] = array('equals', !isset(self::$filters_parsed['year'][1])?date_i18n('Y'):intval(self::$filters_parsed['year'][1]));
						if ($matches[3][$idx] == '-1')
							self::$filters_parsed['interval'] = array('equals', intval((strtotime('now')-strtotime(self::$filters_parsed['year'][1].'-'.self::$filters_parsed['month'][1].'-'.self::$filters_parsed['day'][1]))/86400));
						else
							self::$filters_parsed['interval'] = array('equals', intval($matches[3][$idx]));
						break;
					case 'browser':
					case 'country':
					case 'domain':
					case 'ip':
					case 'searchterms':
					case 'language':
					case 'platform':
					case 'resource':
					case 'referer':
					case 'user':
					case 'plugins':
					case 'version':
					case 'type':
					case 'colordepth':
					case 'css_version':
					case 'notes':
					case 'author':
					case 'category':
					case 'other_ip':
					case 'content_type':
					case 'resolution':
					case 'visit_id':
					case 'day':
					case 'month':
					case 'year':
						self::$filters_parsed[$a_match] = array($matches[2][$idx], isset($matches[3][$idx])?$GLOBALS['wpdb']->escape(str_replace('\\', '', htmlspecialchars_decode($matches[3][$idx]))):'');
						break;
					default:
				}
			}
		}

		// Default values for some filters
		self::$filters_parsed['direction'] = !empty(self::$filters_parsed['direction'])?((self::$filters_parsed['direction'] != 'asc' && self::$filters_parsed['direction'] != 'desc')?'descd':self::$filters_parsed['direction']):'desc';
		self::$filters_parsed['limit_results'] = empty(self::$filters_parsed['limit_results'])?wp_slimstat::$options['rows_to_show']:intval(self::$filters_parsed['limit_results']);

		// This filter is only used in the 'Right Now' panel
		self::$filters_parsed['starting'] = empty(self::$filters_parsed['starting'])?0:intval(self::$filters_parsed['starting']);

		// Date ranges
		date_default_timezone_set('UTC');
		
		// Today
		if (!empty(self::$filters_parsed['day'])){
			self::$current_date['d'] = sprintf('%02d', self::$filters_parsed['day'][1]);
			if (empty(self::$filters_parsed['interval'])) self::$day_filter_active = true;
		}
		else{
			self::$current_date['d'] = date_i18n('d');
		}
		self::$current_date['m'] = !empty(self::$filters_parsed['month'])?sprintf('%02d', self::$filters_parsed['month'][1]):date_i18n('m');
		self::$current_date['y'] = !empty(self::$filters_parsed['year'])?sprintf('%04d', self::$filters_parsed['year'][1]):date_i18n('Y');
		self::$current_date['h'] = date_i18n('H');
		self::$current_date['u'] = strtotime(self::$current_date['y'].'/'.self::$current_date['m'].'/'.self::$current_date['d'].' 00:00');

		// Yesterday
		self::$yesterday['d'] = date_i18n('d', strtotime(self::$current_date['y'].'-'.self::$current_date['m'].'-'.(self::$current_date['d'] - 1)));
		self::$yesterday['m'] = date_i18n('m', strtotime(self::$current_date['y'].'-'.self::$current_date['m'].'-'.(self::$current_date['d'] - 1)));
		self::$yesterday['y'] = date_i18n('Y', strtotime(self::$current_date['y'].'-'.self::$current_date['m'].'-'.(self::$current_date['d'] - 1)));

		// If user selected just the YEAR, select the entire period
		if (empty(self::$filters_parsed['day']) && empty(self::$filters_parsed['month']) && !empty(self::$filters_parsed['year'])){
			self::$current_date['m'] = '01';
			if (self::$current_date['y'] == date_i18n('Y')){
				self::$filters_parsed['interval'] = array('equals', intval((strtotime('now')-strtotime(self::$current_date['y'].'-01-01'))/86400));
			}
			else{
				self::$filters_parsed['interval'] = array('equals', 365+date('L', strtotime(self::$current_date['y'].'-01-01')));
			}
		}

		// Previous month
		self::$previous_month['m'] = date_i18n('m', strtotime(self::$current_date['y'].'-'.(self::$current_date['m'] - 1).'-01'));
		self::$previous_month['y'] = date_i18n('Y', strtotime(self::$current_date['y'].'-'.(self::$current_date['m'] - 1).'-01'));

		// SQL equivalents for given timeframes
		if (empty(self::$filters_parsed['interval'][1])){
			if (self::$day_filter_active){
				self::$current_date['utime_start'] = strtotime(self::$current_date['y'].'/'.self::$current_date['m'].'/'.self::$current_date['d'].' 00:00');
				self::$current_date['utime_end'] = self::$current_date['utime_start'] + 86399;
			}
			else{
				self::$current_date['utime_start'] = strtotime(self::$current_date['y'].'/'.self::$current_date['m'].'/01 00:00');
				self::$current_date['utime_end'] = strtotime(self::$current_date['y'].'/'.self::$current_date['m'].'/01 +1 month')-1;
			}
		}
		else{
			self::$current_date['utime_start'] = strtotime(self::$current_date['y'].'/'.self::$current_date['m'].'/'.self::$current_date['d'].' 00:00');
			self::$current_date['utime_end'] = strtotime(self::$current_date['y'].'/'.self::$current_date['m'].'/'.self::$current_date['d'].' +'.self::$filters_parsed['interval'][1].' days')-1;
		}
		self::$filters_date_sql_where = ' AND t1.dt BETWEEN '.self::$current_date['utime_start'].' AND '.self::$current_date['utime_end'];

		// Now let's translate these filters into pieces of SQL to be used later
		$filters_dropdown = array_diff_key(self::$filters_parsed, array('day' => 1, 'month' => 1, 'year' => 1, 'interval' => 0, 'direction' => 'asc', 'limit_results' => 20, 'starting' => 0));
		foreach ($filters_dropdown as $a_filter_label => $a_filter_details){
			$a_filter_column = self::get_table_identifier($a_filter_label).$a_filter_label;
			$a_filter_value = $a_filter_details[1];
			$a_filter_empty = '0';

			// Some filters require a special treatment
			switch($a_filter_label){
				case 'ip':
				case 'other_ip':
					$a_filter_column = "INET_NTOA($a_filter_label)";
					$a_filter_empty = '0.0.0.0';
					break;
				default:
			}

			switch ($a_filter_details[0]){
				case 'is_not_equal_to':
					self::$filters_sql_where .= " AND $a_filter_column <> '$a_filter_value'";
					break;
				case 'contains':
					self::$filters_sql_where .= " AND $a_filter_column LIKE '%$a_filter_value%'";
					break;
				case 'does_not_contain':
					self::$filters_sql_where .= " AND $a_filter_column NOT LIKE '%$a_filter_value%'";
					break;
				case 'starts_with':
					self::$filters_sql_where .= " AND $a_filter_column LIKE '$a_filter_value%'";
					break;
				case 'ends_with':
					self::$filters_sql_where .= " AND $a_filter_column LIKE '%$a_filter_value'";
					break;
				case 'sounds_like':
					self::$filters_sql_where .= " AND SOUNDEX($a_filter_column) = SOUNDEX('$a_filter_value')";
					break;
				case 'is_empty':
					self::$filters_sql_where .= " AND $a_filter_column = '' AND $a_filter_column <> '$a_filter_empty'";
					break;
				case 'is_not_empty':
					self::$filters_sql_where .= " AND $a_filter_column <> '' AND $a_filter_column <> '$a_filter_empty'";
					break;
				case 'is_greater_than':
					self::$filters_sql_where .= " AND $a_filter_column > '$a_filter_value'";
					break;
				case 'is_less_than':
					self::$filters_sql_where .= " AND $a_filter_column < '$a_filter_value'";
					break;
				default:
					self::$filters_sql_where .= " AND $a_filter_column = '$a_filter_value'";
			}

			// Some columns are in separate tables, so we need to join them
			switch (self::get_table_identifier($a_filter_label)){
				case 'tb.':
					self::$filters_sql_from['browsers'] = 'INNER JOIN '.self::$table_browsers.' tb ON t1.browser_id = tb.browser_id';
					break;
				case 'tss.':
					self::$filters_sql_from['screenres'] = 'INNER JOIN '.self::$table_screenres.' tss ON t1.screenres_id = tss.screenres_id';
					break;
				case 'tci.':
					self::$filters_sql_from['content_info'] = 'INNER JOIN '.self::$table_content_info.' tci ON t1.content_info_id = tci.content_info_id';
					break;
				default:
			}
		}
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
				return 'tb.';
				break;
			case 'resolution':
			case 'colordepth':
				return 'tss.';
				break;
			case 'author':
			case 'category':
			case 'content_type':
				return 'tci.';
				break;
			default:
				return 't1.';
				break;
		}	
	}
	// end get_table_identifier

	// The following methods retrieve the information from the database

	public static function count_bouncing_pages(){
		return intval($GLOBALS['wpdb']->get_var('
			SELECT COUNT(*) count
				FROM (
					SELECT resource
					FROM '.self::$table_stats.' t1 '.self::$filters_sql_from['browsers'].' '.self::$filters_sql_from['screenres'].' '.self::$filters_sql_from['content_info'].'
					WHERE visit_id <> 0 AND resource <> "__l_s__" AND resource <> "" '.self::$filters_sql_where.' '.self::$filters_date_sql_where.'
					GROUP BY visit_id
					HAVING COUNT(visit_id) = 1
				) as ts1'));
	}

	public static function count_exit_pages(){
		return intval($GLOBALS['wpdb']->get_var('
			SELECT COUNT(*) count
				FROM (
					SELECT resource, visit_id, dt
					FROM '.self::$table_stats.' t1 '.self::$filters_sql_from['browsers'].' '.self::$filters_sql_from['screenres'].' '.self::$filters_sql_from['content_info'].'
					WHERE visit_id > 0 AND resource <> "" AND resource <> "__l_s__" '.self::$filters_sql_where.' '.self::$filters_date_sql_where.'
					GROUP BY visit_id
					HAVING dt = MAX(dt)
				) AS ts1'));
	}

	public static function count_records($_where_clause = '1=1', $_distinct_column = '*', $_use_filters = true, $_join_tables = ''){
		// Include the appropriate tables in the query
		$sql_from = '';
		if (empty(self::$filters_sql_from['browsers']) && strpos($_where_clause, 'tb.') !== false)
			$sql_from .= ' INNER JOIN '.self::$table_browsers.' tb ON t1.browser_id = tb.browser_id';

		if (empty(self::$filters_sql_from['screenres']) && strpos($_where_clause, 'tss.') !== false)
			$sql_from .= ' INNER JOIN '.self::$table_screenres.' tss ON t1.screenres_id = tss.screenres_id';

		if (empty(self::$filters_sql_from['content_info']) && strpos($_where_clause, 'tci.') !== false)
			$sql_from .= ' INNER JOIN '.self::$table_content_info.' tci ON t1.content_info_id = tci.content_info_id';
			
		$column = ($_distinct_column != '*')?"DISTINCT $_distinct_column":$_distinct_column;

		return intval($GLOBALS['wpdb']->get_var("
			SELECT COUNT($column) count
				FROM ".self::$table_stats.' t1 '.($_use_filters?self::$filters_sql_from['browsers'].' '.self::$filters_sql_from['screenres'].' '.self::$filters_sql_from['content_info']:'')." $sql_from
				WHERE ".(!empty($_where_clause)?$_where_clause:'1=1').' '.($_use_filters?self::$filters_sql_where.' '.self::$filters_date_sql_where:'')));
	}

	public static function count_records_having($_where_clause = '1=1', $_column = 't1.ip', $_having_clause = ''){
		// Include the appropriate tables in the query
		$sql_from = '';
		if (empty(self::$filters_sql_from['browsers']) && strpos($_where_clause, 'tb.') !== false)
			$sql_from .= ' INNER JOIN '.self::$table_browsers.' tb ON t1.browser_id = tb.browser_id';

		if (empty(self::$filters_sql_from['screenres']) && strpos($_where_clause, 'tss.') !== false)
			$sql_from .= ' INNER JOIN '.self::$table_screenres.' tss ON t1.screenres_id = tss.screenres_id';

		if (empty(self::$filters_sql_from['content_info']) && strpos($_where_clause, 'tci.') !== false)
			$sql_from .= ' INNER JOIN '.self::$table_content_info.' tci ON t1.content_info_id = tci.content_info_id';

		return intval($GLOBALS['wpdb']->get_var("
			SELECT COUNT(*) FROM (
					SELECT $_column
					FROM ".self::$table_stats.' t1 '.self::$filters_sql_from['browsers'].' '.self::$filters_sql_from['screenres'].' '.self::$filters_sql_from['content_info']." $sql_from
					WHERE $_where_clause ".self::$filters_sql_where.' '.self::$filters_date_sql_where."
					GROUP BY $_column
					".(!empty($_having_clause)?"HAVING $_having_clause":'').')
				AS ts1'));
	}

	public static function get_data_size(){
		$suffix = 'KB';

		$sql = 'SHOW TABLE STATUS LIKE "'.self::$table_stats.'"';
		$myTableDetails = $GLOBALS['wpdb']->get_row($sql, 'ARRAY_A', 0);

		$myTableSize = ( $myTableDetails['Data_length'] / 1024 ) + ( $myTableDetails['Index_length'] / 1024 );

		if ($myTableSize > 1024){
			$myTableSize /= 1024;
			$suffix = 'MB';
		}
		return number_format($myTableSize, 2, self::$decimal_separator, self::$thousand_separator).' '.$suffix;
	}

	public static function get_max_and_average_pages_per_visit(){
		return $GLOBALS['wpdb']->get_row('
			SELECT AVG(ts1.count) avg, MAX(ts1.count) max FROM (
					SELECT count(ip) count, visit_id
					FROM '.self::$table_stats.' t1 '.self::$filters_sql_from['browsers'].' '.self::$filters_sql_from['screenres'].' '.self::$filters_sql_from['content_info'].'
					WHERE visit_id > 0 '.self::$filters_sql_where.' '.self::$filters_date_sql_where.'
					GROUP BY visit_id
				) AS ts1', ARRAY_A);
	}

	public static function get_oldest_visit(){
		return $GLOBALS['wpdb']->get_var('
			SELECT t1.dt
				FROM '.self::$table_stats.' t1
				ORDER BY t1.dt ASC
				LIMIT 0,1');
	}

	public static function get_recent($_column = 't1.id', $_custom_where = '', $_join_tables = '', $_having_clause = '', $_order_by = ''){
		// Include the appropriate tables in the query
		$sql_from = $sql_inner_from = '';
		if (strpos($_join_tables, 'tb.') !== false)
			$sql_from .= ' INNER JOIN '.self::$table_browsers.' tb ON t1.browser_id = tb.browser_id';
		if (empty(self::$filters_sql_from['browsers']) && (strpos($_column, 'tb.') !== false || strpos($_custom_where, 'tb.') !== false))
			$sql_inner_from .= ' INNER JOIN '.self::$table_browsers.' tb ON t1.browser_id = tb.browser_id';

		if(strpos($_join_tables, 'tss.') !== false)
			$sql_from .= ' INNER JOIN '.self::$table_screenres.' tss ON t1.screenres_id = tss.screenres_id';
		if (empty(self::$filters_sql_from['screenres']) && (strpos($_column, 'tss.') !== false || strpos($_custom_where, 'tss.') !== false))
			$sql_inner_from .=  ' INNER JOIN '.self::$table_screenres.' tss ON t1.screenres_id = tss.screenres_id';

		if(strpos($_join_tables,'tci.') !== false)
			$sql_from .= ' INNER JOIN '.self::$table_content_info.' tci ON t1.content_info_id = tci.content_info_id';
		if (empty(self::$filters_sql_from['content_info']) && strpos($_column.$_custom_where, 'tci.') !== false)
			$sql_inner_from .=  ' INNER JOIN '.self::$table_content_info.' tci ON t1.content_info_id = tci.content_info_id';

		return $GLOBALS['wpdb']->get_results('
			SELECT t1.*'.(!empty($_join_tables)?", $_join_tables":'')."
				FROM (
					SELECT $_column, MAX(t1.id) maxid
					FROM ".self::$table_stats.' t1 '.self::$filters_sql_from['browsers'].' '.self::$filters_sql_from['screenres'].' '.self::$filters_sql_from['content_info']." $sql_inner_from
					WHERE ".(empty($_custom_where)?"$_column <> '' AND  $_column <> '__l_s__'":$_custom_where).' '.self::$filters_sql_where.' '.self::$filters_date_sql_where."
					GROUP BY $_column $_having_clause
				) AS ts1 INNER JOIN ".self::$table_stats." t1 ON ts1.maxid = t1.id $sql_from
				ORDER BY ".(empty($_order_by)?'t1.dt '.self::$filters_parsed['direction']:$_order_by).'
				LIMIT '.self::$filters_parsed['starting'].', '.self::$filters_parsed['limit_results'], ARRAY_A);
	}

	public static function get_recent_outbound($_type = -1){
		return $GLOBALS['wpdb']->get_results('
			SELECT tob.outbound_id as visit_id, tob.outbound_domain, tob.outbound_resource as resource, tob.type, tob.notes, t1.ip, t1.other_ip, t1.user, "local" as domain, t1.resource as referer, t1.country, tb.browser, tb.version, tb.platform, tob.dt
				FROM  '.self::$table_stats.' t1 INNER JOIN '.self::$table_outbound.' tob ON tob.id = t1.id INNER JOIN '.self::$table_browsers.' tb on t1.browser_id = tb.browser_id '.self::$filters_sql_from['screenres'].' '.self::$filters_sql_from['content_info'].'
				WHERE '.(($_type != -1)?"tob.type = $_type":'tob.type > 0').' '.self::$filters_sql_where.' '.self::$filters_date_sql_where.'
				ORDER BY tob.dt '.self::$filters_parsed['direction'].'
				LIMIT '.self::$filters_parsed['starting'].','.self::$filters_parsed['limit_results'], ARRAY_A);
	}

	public static function get_popular_complete($_column = 't1.id', $_custom_where = '', $_join_tables = '', $_having_clause = ''){
		// Include the appropriate tables in the query
		$sql_from = $sql_inner_from = '';
		if (strpos($_join_tables, 'tb.') !== false)
			$sql_from .= ' INNER JOIN '.self::$table_browsers.' tb ON t1.browser_id = tb.browser_id';
		if (empty(self::$filters_sql_from['browsers']) && strpos($_column.$_custom_where, 'tb.') !== false)
			$sql_inner_from .= ' INNER JOIN '.self::$table_browsers.' tb ON t1.browser_id = tb.browser_id';

		if(strpos($_join_tables, 'tss.') !== false)
			$sql_from .= ' INNER JOIN '.self::$table_screenres.' tss ON t1.screenres_id = tss.screenres_id';
		if (empty(self::$filters_sql_from['screenres']) && strpos($_column.$_custom_where, 'tss.') !== false)
			$sql_inner_from .=  ' INNER JOIN '.self::$table_screenres.' tss ON t1.screenres_id = tss.screenres_id';

		if(strpos($_join_tables,'tci.') !== false)
			$sql_from .= ' INNER JOIN '.self::$table_content_info.' tci ON t1.content_info_id = tci.content_info_id';
		if (empty(self::$filters_sql_from['content_info']) && strpos($_column.$_custom_where, 'tci.') !== false)
			$sql_inner_from .=  ' INNER JOIN '.self::$table_content_info.' tci ON t1.content_info_id = tci.content_info_id';

		return $GLOBALS['wpdb']->get_results('
			SELECT t1.*'.(!empty($_join_tables)?", $_join_tables":'').", ts1.count
				FROM (
					SELECT $_column, MAX(t1.id) maxid, COUNT(*) count
					FROM ".self::$table_stats.' t1 '.self::$filters_sql_from['browsers'].' '.self::$filters_sql_from['screenres'].' '.self::$filters_sql_from['content_info']." $sql_inner_from
					WHERE ".(empty($_custom_where)?"$_column <> '' AND  $_column <> '__l_s__'":$_custom_where).' '.self::$filters_sql_where.' '.self::$filters_date_sql_where."
					GROUP BY $_column $_having_clause
				) AS ts1 JOIN ".self::$table_stats." t1 ON ts1.maxid = t1.id $sql_from
				ORDER BY ts1.count ".self::$filters_parsed['direction']."
				LIMIT ".self::$filters_parsed['starting'].', '.self::$filters_parsed['limit_results'], ARRAY_A);
	}

	public static function get_popular($_column = 't1.id', $_custom_where = '', $_more_columns = '', $_having_clause = ''){
		// Include the appropriate tables in the query
		$sql_from = '';
		if (empty(self::$filters_sql_from['browsers']) && strpos($_column.$_custom_where.$_more_columns, 'tb.') !== false)
			$sql_from .= ' INNER JOIN '.self::$table_browsers.' tb ON t1.browser_id = tb.browser_id';

		if (empty(self::$filters_sql_from['screenres']) && strpos($_column.$_custom_where.$_more_columns, 'tss.') !== false)
			$sql_from .=  ' INNER JOIN '.self::$table_screenres.' tss ON t1.screenres_id = tss.screenres_id';

		if (empty(self::$filters_sql_from['content_info']) && strpos($_column.$_custom_where.$_more_columns, 'tci.') !== false)
			$sql_from .=  ' INNER JOIN '.self::$table_content_info.' tci ON t1.content_info_id = tci.content_info_id';

		return $GLOBALS['wpdb']->get_results("
			SELECT $_column$_more_columns, COUNT(*) count
			FROM ".self::$table_stats.' t1 '.self::$filters_sql_from['browsers'].' '.self::$filters_sql_from['screenres'].' '.self::$filters_sql_from['content_info']." $sql_from
			WHERE ".(empty($_custom_where)?"$_column <> '' AND  $_column <> '__l_s__'":$_custom_where).' '.self::$filters_sql_where.' '.self::$filters_date_sql_where."
			GROUP BY $_column$_more_columns $_having_clause
			ORDER BY count ".self::$filters_parsed['direction']."
			LIMIT ".self::$filters_parsed['starting'].', '.self::$filters_parsed['limit_results'], ARRAY_A);
	}

	public static function extract_data_for_chart($_data1, $_data2, $_label_data1 = '', $_label_data2 = '', $_custom_where_clause = '', $_sql_from_where = ''){
		// Avoid PHP warnings in strict mode
		$result->current_total1 = $result->current_total2 = $result->current_non_zero_count = $result->previous_total = $result->previous_non_zero_count = $result->today = $result->yesterday = $result->max_yaxis = 0;
		$result->current_data1 = $result->current_data2 = $result->previous_data = $result->ticks = '';
		$data1 = $data2 = array();

		if (self::$day_filter_active){
			// SQL query
			$select_columns = "DATE_FORMAT(FROM_UNIXTIME(dt), '%H') h, DATE_FORMAT(FROM_UNIXTIME(dt), '%d') d";
			$time_constraints = '(dt BETWEEN '.self::$current_date['utime_start'].' AND '.self::$current_date['utime_end'].' OR dt BETWEEN '.(strtotime(self::$yesterday['y'].'/'.self::$yesterday['m'].'/'.self::$yesterday['d'].' 00:00')).' AND '.(strtotime(self::$yesterday['y'].'/'.self::$yesterday['m'].'/'.self::$yesterday['d'].' 00:00')+86399).') ';
			$group_and_order = " GROUP BY h, d ORDER BY d ASC, h asc";

			// Data parsing
			$filter_idx = 'd'; $group_idx = 'h';
			$data_start_value = 0; $data_end_value = 24;
			$result->min_max_ticks = ',min:0,max:23';
			$result->ticks = ($GLOBALS['wp_locale']->text_direction == 'rtl')?'[0,"23"],[1,"22"],[2,"21"],[3,"20"],[4,"19"],[5,"18"],[6,"17"],[7,"16"],[8,"15"],[9,"14"],[10,"13"],[11,"12"],[12,"11"],[13,"10"],[14,"9"],[15,"8"],[16,"7"],[17,"6"],[18,"5"],[19,"4"],[20,"3"],[21,"2"],[22,"1"],[23,"0"],':'[0,"00"],[1,"01"],[2,"02"],[3,"03"],[4,"04"],[5,"05"],[6,"06"],[7,"07"],[8,"08"],[9,"09"],[10,"10"],[11,"11"],[12,"12"],[13,"13"],[14,"14"],[15,"15"],[16,"16"],[17,"17"],[18,"18"],[19,"19"],[20,"20"],[21,"21"],[22,"22"],[23,"23"],';
			$label_date_format = get_option('date_format', 'd-m-Y');
		}
		else{
			// SQL query
			$select_columns = "DATE_FORMAT(FROM_UNIXTIME(dt), '%m') m, DATE_FORMAT(FROM_UNIXTIME(dt), '%d') d";
			$time_constraints = '(dt BETWEEN '.self::$current_date['utime_start'].' AND '.self::$current_date['utime_end'];
			if (empty(self::$filters_parsed['interval'][1])) $time_constraints .= ' OR dt BETWEEN '.(strtotime(self::$previous_month['y'].'/'.self::$previous_month['m'].'/01 00:00')).' AND '.(strtotime(self::$previous_month['y'].'/'.self::$previous_month['m'].'/01 +1 month')-1);
			$time_constraints .= ')';
			$group_and_order = " GROUP BY m, d ORDER BY m ASC,d ASC";

			// Data parsing
			$filter_idx = 'm'; $group_idx = 'd';
			if (empty(self::$filters_parsed['interval'][1])){
				$data_start_value = 0; $data_end_value = 31;
				$result->min_max_ticks = ',min:0,max:30';
				$result->ticks = ($GLOBALS['wp_locale']->text_direction == 'rtl')?'[0,"31"],[1,"30"],[2,"29"],[3,"28"],[4,"27"],[5,"26"],[6,"25"],[7,"24"],[8,"23"],[9,"22"],[10,"21"],[11,"20"],[12,"19"],[13,"18"],[14,"17"],[15,"16"],[16,"15"],[17,"14"],[18,"13"],[19,"12"],[20,"11"],[21,"10"],[22,"9"],[23,"8"],[24,"7"],[25,"6"],[26,"5"],[27,"4"],[28,"3"],[29,"2"],[30,"1"],':'[0,"1"],[1,"2"],[2,"3"],[3,"4"],[4,"5"],[5,"6"],[6,"7"],[7,"8"],[8,"9"],[9,"10"],[10,"11"],[11,"12"],[12,"13"],[13,"14"],[14,"15"],[15,"16"],[16,"17"],[17,"18"],[18,"19"],[19,"20"],[20,"21"],[21,"22"],[22,"23"],[23,"24"],[24,"25"],[25,"26"],[26,"27"],[27,"28"],[28,"29"],[29,"30"],[30,"31"],';
				$label_date_format = 'm/Y';
			}
			else{
				$data_start_value = 0; $data_end_value = self::$filters_parsed['interval'][1];
				$result->min_max_ticks = '';
				$label_date_format = '';
			}
		}

		// This SQL query has a standard format: grouped by day or hour and then data1 and data2 represent the information we want to extract
		$sql = "SELECT $select_columns, $_data1 data1, $_data2 data2";

		$sql_from = '';
		if (empty(self::$filters_sql_from['browsers']) && strpos($_data1.$_data2.$_custom_where_clause, 'tb.') !== false)
			$sql_from .= ' INNER JOIN '.self::$table_browsers.' tb ON t1.browser_id = tb.browser_id';

		if (empty(self::$filters_sql_from['screenres']) && strpos($_data1.$_data2.$_custom_where_clause, 'tss.') !== false)
			$sql_from .=  ' INNER JOIN '.self::$table_screenres.' tss ON t1.screenres_id = tss.screenres_id';

		if (empty(self::$filters_sql_from['content_info']) && strpos($_data1.$_data2.$_custom_where_clause, 'tci.') !== false)
			$sql_from .=  ' INNER JOIN '.self::$table_content_info.' tci ON t1.content_info_id = tci.content_info_id';

		// Panel 4 has a slightly different structure
		if(empty($_sql_from_where)){
			$sql .= '	FROM '.self::$table_stats.' t1 '.self::$filters_sql_from['browsers'].' '.self::$filters_sql_from['screenres'].' '.self::$filters_sql_from['content_info']." $sql_from
						WHERE $time_constraints ".self::$filters_sql_where.' '.$_custom_where_clause;
		}
		else{
			$sql_no_placeholders = str_replace('[from_tables]', self::$table_stats.' t1 '.self::$filters_sql_from['browsers'].' '.self::$filters_sql_from['screenres'].' '.self::$filters_sql_from['content_info']." $sql_from", $_sql_from_where);
			$sql_no_placeholders = str_replace('[where_clause]', $time_constraints.' '.self::$filters_sql_where.' '.$_custom_where_clause, $sql_no_placeholders);
			$sql .= $sql_no_placeholders;
		}
		$sql .= $group_and_order;

		$array_results = $GLOBALS['wpdb']->get_results($sql, ARRAY_A);

		if (!is_array($array_results) || empty($array_results))
			return $result;

		// Double-pass: reorganize the data and then format it for Flot
		foreach ($array_results as $a_result){
			$data1[$a_result[$filter_idx].$a_result[$group_idx]] = $a_result['data1'];
			$data2[$a_result[$filter_idx].$a_result[$group_idx]] = $a_result['data2'];
		}

		$result->max_yaxis = max(max($data1), max($data2));
		$k = ($GLOBALS['wp_locale']->text_direction == 'rtl')?$data_start_value-$data_end_value+1:0;
		for ($i=$data_start_value;$i<$data_end_value;$i++){
			$j = abs($k+$i);
			if (self::$day_filter_active){
				$timestamp_current = mktime($j, 0, 0, self::$current_date['m'], self::$current_date['d'], self::$current_date['y']);
				$hour_idx_current = date('H', $timestamp_current);
				$day_idx_current = date('d', $timestamp_current);
				$month_idx_current = date('m', $timestamp_current);
				$year_idx_current = date('Y', $timestamp_current);
				$date_idx_current = $day_idx_current.$hour_idx_current;
				$strtotime_current = strtotime("$year_idx_current-$month_idx_current-$day_idx_current $hour_idx_current:00:00");
				$current_filter_query = '';

				$timestamp_previous = mktime($j, 0, 0, self::$current_date['m'], self::$current_date['d']-1, self::$current_date['y']);
				$hour_idx_previous = date('H', $timestamp_previous);
				$day_idx_previous = date('d', $timestamp_previous);
				$month_idx_previous = date('m', $timestamp_previous);
				$year_idx_previous = date('Y', $timestamp_previous);
				$date_idx_previous = $day_idx_previous.$hour_idx_previous;
				$strtotime_previous = strtotime("$year_idx_previous-$month_idx_previous-$day_idx_previous $hour_idx_previous:00:00");
				$previous_filter_query = '';

				if ($date_idx_current == self::$current_date['m'].self::$current_date['d'] && !empty($data1[$date_idx_current])) $result->today = $data1[$date_idx_current];
				if ($date_idx_current == self::$yesterday['m'].self::$yesterday['d'] && !empty($data1[$date_idx_current])) $result->yesterday = $data1[$date_idx_current];
			}
			else{
				$timestamp_current = mktime(0, 0, 0, self::$current_date['m'], (!empty(self::$filters_parsed['interval'][1])?self::$current_date['d']:1)+$j, self::$current_date['y']);
				$day_idx_current = date('d', $timestamp_current);
				$month_idx_current = date('m', $timestamp_current);
				$year_idx_current = date('Y', $timestamp_current);
				$date_idx_current = $month_idx_current.$day_idx_current;

				$strtotime_current = strtotime("$year_idx_current-$month_idx_current-$day_idx_current 00:00:00");
				$current_filter_query = ',"'.wp_slimstat_boxes::replace_query_arg(array('day','month','year'), array($day_idx_current, $month_idx_current, $year_idx_current)).'&slimpanel='.wp_slimstat_boxes::$current_screen.'"';

				$timestamp_previous = mktime(0, 0, 0, self::$current_date['m']-1, (!empty(self::$filters_parsed['interval'][1])?self::$current_date['d']:1)+$j, self::$current_date['y']);
				$day_idx_previous = date('d', $timestamp_previous);
				$month_idx_previous = date('m', $timestamp_previous);
				$year_idx_previous = date('Y', $timestamp_previous);
				$date_idx_previous = $month_idx_previous.$day_idx_previous;
				$strtotime_previous = strtotime("$year_idx_previous-$month_idx_previous-$day_idx_previous 00:00:00");
				$previous_filter_query = ',"'.wp_slimstat_boxes::replace_query_arg(array('day','month','year'), array($day_idx_previous, $month_idx_previous, $year_idx_previous)).'&slimpanel='.wp_slimstat_boxes::$current_screen.'"';
			}

			// Format each group of data

			if (($j == $day_idx_current - 1) || self::$day_filter_active || !empty(self::$filters_parsed['interval'][1])){
				if (!empty($data1[$date_idx_current])){
					$result->current_data1 .= "[$i,{$data1[$date_idx_current]}$current_filter_query],";
					$result->current_total1 += $data1[$date_idx_current];
					$result->current_non_zero_count++;
				}	
				elseif($strtotime_current <= date_i18n('U')){
					$result->current_data1 .= "[$i,0],";
				}

				if (!empty($data2[$date_idx_current])){
					$result->current_data2 .= "[$i,{$data2[$date_idx_current]}$current_filter_query],";
					$result->current_total2 += $data2[$date_idx_current];
				}
				elseif($strtotime_current <= date_i18n('U')){
					$result->current_data2 .= "[$i,0],";
				}
			}

			if (($j == $day_idx_previous - 1) || self::$day_filter_active || !empty(self::$filters_parsed['interval'][1])){
				if (empty(self::$filters_parsed['interval'][1])){
					if (!empty($data1[$date_idx_previous])){
						$result->previous_data .= "[$i,{$data1[$date_idx_previous]}$previous_filter_query],";
						$result->previous_total += $data1[$date_idx_previous];
					}
					elseif($strtotime_previous <= date_i18n('U')){
						$result->previous_data .= "[$i,0],";
					}
				}
				else{
					$date_label = date('d/m', mktime(0, 0, 0, self::$current_date['m'], self::$current_date['d']+$i, self::$current_date['y']));
					$result->ticks .= "[$i, \"$date_label\"],";
				}
			}
		}

		if (!empty($result->current_data1)){
			$result->current_data1 = substr($result->current_data1, 0, -1);
			$result->current_data1_label = "$_label_data1".(!empty($label_date_format)?' '.date_i18n($label_date_format, mktime(0, 0, 0, self::$current_date['m'], self::$current_date['d'], self::$current_date['y'])):'');
		}
		if (!empty($result->current_data2)){
			$result->current_data2 = substr($result->current_data2, 0, -1);
			$result->current_data2_label = "$_label_data2".(!empty($label_date_format)?' '.date_i18n($label_date_format, mktime(0, 0, 0, self::$current_date['m'], self::$current_date['d'], self::$current_date['y'])):'');
		}
		if (!empty($result->previous_data)){
			$result->previous_data = substr($result->previous_data, 0, -1);
			$result->previous_data_label = "$_label_data1";
			if (!empty($label_date_format))
				if (self::$day_filter_active)
					$result->previous_data_label .= ' '.date_i18n($label_date_format, mktime(0, 0, 0, self::$yesterday['m'], self::$yesterday['d'], self::$yesterday['y']));
				else
					$result->previous_data_label .= ' '.date_i18n($label_date_format, mktime(0, 0, 0, self::$previous_month['m'], 1, self::$previous_month['y']));
		}
		if (!empty($result->ticks)){
			$result->ticks = substr($result->ticks, 0, -1);
		}
		return $result;
	}

	protected function _format_value($_value = 0, $_link = ''){
		if ($_value == 0) return '<set/>';
		if (empty($_link)){
			return (intval($_value)==$_value)?"<set value='$_value'/>":sprintf("<set value='%01.2f'/>", $_value);
		}
		else{
			return (intval($_value)==$_value)?"<set value='$_value' link='$_link'/>":sprintf("<set value='%01.2f' link='%s'/>", $_value, $_link);
		}
	}
}