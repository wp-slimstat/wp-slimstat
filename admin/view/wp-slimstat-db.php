<?php

// Let's define the main class with all the methods that we need
class wp_slimstat_db {
	// Date ranges
	public static $timeframes = array('current_day' => array('hour_selected' => false, 'day_selected' => false, 'month_selected' => false, 'year_selected' => false));
	
	// Number format
	public static $formats = array('decimal' => ',', 'thousand' => '.');

	// Filters
	public static $filters = array();

	public static function init($_filters_string = '', $_system_filters_string = ''){
		// Reset MySQL timezone settings, our dates and times are recorded using WP settings
		$GLOBALS['wpdb']->query("SET @@session.time_zone = '+00:00'");
		date_default_timezone_set('UTC');

		// Reset filters
		self::$filters = array(
			'parsed' => array('direction' => array('equals', 'desc'), 'limit_results' => array('equals', 20), 'starting' => array('equals', 0)),
			'date_sql_where' => '',
			'sql_from' => array('browsers' => '', 'screenres' => '', 'content_info' => '', 'outbound' => ''),
			'sql_where' => ''
		);

		// Decimal and thousand separators
		if (wp_slimstat::$options['use_european_separators'] == 'no'){
			self::$formats['decimal'] = '.';
			self::$formats['thousand'] = ',';	
		}

		// Use WordPress' settings for date and time format
		self::$formats['date_time_format'] = get_option('date_format', 'd-m-Y').' '.get_option('time_format', 'g:i a');

		// Parse all the filters
		if (!empty($_filters_string)) self::_init_filters($_filters_string);
		if (!empty($_system_filters_string)) self::_init_filters($_system_filters_string);

		// self::$filters['parsed']['direction'] = !empty(self::$filters['parsed']['direction'])?((self::$filters['parsed']['direction'] != 'asc' && self::$filters['parsed']['direction'] != 'desc')?'desc':self::$filters['parsed']['direction']):'desc';
		self::$filters['parsed']['limit_results'] = array('equals', empty(self::$filters['parsed']['limit_results'][1])?wp_slimstat::$options['rows_to_show']:intval(self::$filters['parsed']['limit_results'][1]));
		
		// Current day: specified in the filters, or today otherwise
		if (!empty(self::$filters['parsed']['hour'])){
			self::$timeframes['current_day']['h'] = sprintf('%02d', self::$filters['parsed']['hour'][1]);
			self::$timeframes['current_day']['hour_selected'] = true;
		}
		else{
			self::$timeframes['current_day']['h'] = date_i18n('H');
		}
		if (!empty(self::$filters['parsed']['day'])){
			self::$timeframes['current_day']['d'] = sprintf('%02d', self::$filters['parsed']['day'][1]);
			self::$timeframes['current_day']['day_selected'] = true;
		}
		else{
			self::$timeframes['current_day']['d'] = date_i18n('d');
			if (isset(self::$filters['parsed']['interval'])) unset(self::$filters['parsed']['interval']);
		}
		if (!empty(self::$filters['parsed']['month'])){
			self::$timeframes['current_day']['m'] = sprintf('%02d', self::$filters['parsed']['month'][1]);
			self::$timeframes['current_day']['month_selected'] = true;
		}
		else{
			self::$timeframes['current_day']['m'] = date_i18n('m');
		}
		if (!empty(self::$filters['parsed']['year'])){
			self::$timeframes['current_day']['y'] = sprintf('%04d', self::$filters['parsed']['year'][1]);
			self::$timeframes['current_day']['year_selected'] = true;
		}
		else{
			self::$timeframes['current_day']['y'] = date_i18n('Y');
		}
		self::$timeframes['current_day']['utime'] = mktime(0, 0, 0, self::$timeframes['current_day']['m'], self::$timeframes['current_day']['d'], self::$timeframes['current_day']['y']);

		// Previous day
		self::$timeframes['previous_day']['utime'] = self::$timeframes['current_day']['utime'] - 86400;
		self::$timeframes['previous_day']['d'] = date_i18n('d', self::$timeframes['previous_day']['utime']);
		self::$timeframes['previous_day']['m'] = date_i18n('m', self::$timeframes['previous_day']['utime']);
		self::$timeframes['previous_day']['y'] = date_i18n('Y', self::$timeframes['previous_day']['utime']);

		// Previous month
		self::$timeframes['previous_month']['utime'] = mktime(0, 0, 0, self::$timeframes['current_day']['m'] - 1, 1, self::$timeframes['current_day']['y']);
		self::$timeframes['previous_month']['m'] = date_i18n('m', self::$timeframes['previous_month']['utime']);
		self::$timeframes['previous_month']['y'] = date_i18n('Y', self::$timeframes['previous_month']['utime']);

		// SQL timeframes
		if (empty(self::$filters['parsed']['interval'][1])){
			if (self::$timeframes['current_day']['hour_selected']){
				self::$timeframes['current_utime_start'] = mktime(self::$timeframes['current_day']['h'], 0, 0, self::$timeframes['current_day']['m'], self::$timeframes['current_day']['d'], self::$timeframes['current_day']['y']);
				self::$timeframes['current_utime_end'] = self::$timeframes['current_utime_start'] + 3599;
				self::$timeframes['previous_utime_start'] = self::$timeframes['current_utime_start'] - 3600;
				self::$timeframes['previous_utime_end'] = self::$timeframes['current_utime_start'] - 1;
				self::$timeframes['label_current'] = date_i18n(get_option('time_format', 'g:i a'), self::$timeframes['current_utime_start']);
				self::$timeframes['label_previous'] = date_i18n(get_option('time_format', 'g:i a'), self::$timeframes['previous_utime_start']);
			}
			elseif (self::$timeframes['current_day']['day_selected']){
				self::$timeframes['current_utime_start'] = self::$timeframes['current_day']['utime'];
				self::$timeframes['current_utime_end'] = self::$timeframes['current_day']['utime'] + 86399;
				self::$timeframes['previous_utime_start'] = self::$timeframes['previous_day']['utime'];
				self::$timeframes['previous_utime_end'] = self::$timeframes['previous_day']['utime'] + 86399;
				if (self::$formats['decimal'] == '.'){
					self::$timeframes['label_current'] = date_i18n('m/d', self::$timeframes['current_utime_start']);
					self::$timeframes['label_previous'] = date_i18n('m/d',self::$timeframes['previous_utime_start']);
				}
				else{
					self::$timeframes['label_current'] = date_i18n('d/m', self::$timeframes['current_utime_start']);
					self::$timeframes['label_previous'] = date_i18n('d/m', self::$timeframes['previous_utime_start']);
				}
			}
			elseif (self::$timeframes['current_day']['year_selected'] && !self::$timeframes['current_day']['month_selected']){
				self::$timeframes['current_utime_start'] = mktime(0, 0, 0, 1, 1, self::$timeframes['current_day']['y']);
				self::$timeframes['current_utime_end'] = strtotime(self::$timeframes['current_day']['y'].'-01-01 00:00 +1 year')-1;
				self::$timeframes['previous_utime_start'] = mktime(0, 0, 0, 1, 1, self::$timeframes['current_day']['y'] - 1);
				self::$timeframes['previous_utime_end'] = strtotime((self::$timeframes['current_day']['y']-1).'-01-01 00:00 +1 year')-1;
				self::$timeframes['label_current'] = date_i18n('Y', self::$timeframes['current_utime_start']);
				self::$timeframes['label_previous'] = date_i18n('Y', self::$timeframes['previous_utime_start']);
			}
			else{
				self::$timeframes['current_utime_start'] = mktime(0, 0, 0, self::$timeframes['current_day']['m'], 1, self::$timeframes['current_day']['y']);
				self::$timeframes['current_utime_end'] = strtotime(self::$timeframes['current_day']['y'].'-'.self::$timeframes['current_day']['m'].'-01 00:00 +1 month')-1;
				self::$timeframes['previous_utime_start'] = mktime(0, 0, 0, self::$timeframes['current_day']['m'] - 1, 1, self::$timeframes['current_day']['y']);
				self::$timeframes['previous_utime_end'] = strtotime(self::$timeframes['current_day']['y'].'-'.(self::$timeframes['current_day']['m'] - 1).'-01 00:00 +1 month')-1;
				self::$timeframes['label_current'] = date_i18n('m/Y', self::$timeframes['current_utime_start']);
				self::$timeframes['label_previous'] = date_i18n('m/Y', self::$timeframes['previous_utime_start']);
			}
		}
		else{
			self::$timeframes['current_utime_start'] = self::$timeframes['current_day']['utime'];
			self::$timeframes['current_utime_end'] = strtotime(self::$timeframes['current_day']['y'].'-'.self::$timeframes['current_day']['m'].'-'.self::$timeframes['current_day']['d'].' 00:00 +'.self::$filters['parsed']['interval'][1].' days')-1;
			self::$timeframes['previous_utime_start'] = mktime(0, 0, 0, self::$timeframes['current_day']['m'] - 1, self::$timeframes['current_day']['d'], self::$timeframes['current_day']['y']);
			self::$timeframes['previous_utime_end'] = strtotime(self::$timeframes['current_day']['y'].'-'.(self::$timeframes['current_day']['m'] - 1).'-'.self::$timeframes['current_day']['d'].' 00:00 +'.self::$filters['parsed']['interval'][1].' days')-1;
			self::$timeframes['label_current'] = '';
			self::$timeframes['label_previous'] = '';
		}
		self::$filters['date_sql_where'] = ' AND t1.dt BETWEEN '.self::$timeframes['current_utime_start'].' AND '.self::$timeframes['current_utime_end'];

		// Now let's translate these filters into pieces of SQL to be used later
		$filters_dropdown = array_diff_key(self::$filters['parsed'], array('hour' => 1, 'day' => 1, 'month' => 1, 'year' => 1, 'interval' => 0, 'direction' => 1, 'limit_results' => 1, 'starting' => 1));
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
					self::$filters['sql_where'] .= " AND $a_filter_column <> '$a_filter_value'";
					break;
				case 'contains':
					self::$filters['sql_where'] .= " AND $a_filter_column LIKE '%$a_filter_value%'";
					break;
				case 'does_not_contain':
					self::$filters['sql_where'] .= " AND $a_filter_column NOT LIKE '%$a_filter_value%'";
					break;
				case 'starts_with':
					self::$filters['sql_where'] .= " AND $a_filter_column LIKE '$a_filter_value%'";
					break;
				case 'ends_with':
					self::$filters['sql_where'] .= " AND $a_filter_column LIKE '%$a_filter_value'";
					break;
				case 'sounds_like':
					self::$filters['sql_where'] .= " AND SOUNDEX($a_filter_column) = SOUNDEX('$a_filter_value')";
					break;
				case 'is_empty':
					self::$filters['sql_where'] .= " AND $a_filter_column = '' AND $a_filter_column <> '$a_filter_empty'";
					break;
				case 'is_not_empty':
					self::$filters['sql_where'] .= " AND $a_filter_column <> '' AND $a_filter_column <> '$a_filter_empty'";
					break;
				case 'is_greater_than':
					self::$filters['sql_where'] .= " AND $a_filter_column > '$a_filter_value'";
					break;
				case 'is_less_than':
					self::$filters['sql_where'] .= " AND $a_filter_column < '$a_filter_value'";
					break;
				default:
					self::$filters['sql_where'] .= " AND $a_filter_column = '$a_filter_value'";
			}

			// Some columns are in separate tables, so we need to join them
			switch (self::get_table_identifier($a_filter_label)){
				case 'tb.':
					self::$filters['sql_from']['browsers'] = 'INNER JOIN '.$GLOBALS['wpdb']->base_prefix.'slim_browsers tb ON t1.browser_id = tb.browser_id';
					break;
				case 'tss.':
					self::$filters['sql_from']['screenres'] = 'LEFT JOIN '.$GLOBALS['wpdb']->base_prefix.'slim_screenres tss ON t1.screenres_id = tss.screenres_id';
					break;
				case 'tci.':
					self::$filters['sql_from']['content_info'] = 'INNER JOIN '.$GLOBALS['wpdb']->base_prefix.'slim_content_info tci ON t1.content_info_id = tci.content_info_id';
					break;
				case 'to.':
					self::$filters['sql_from']['outbound'] = 'INNER JOIN '.$GLOBALS['wpdb']->prefix.'slim_outbound to ON t1.id = to.id';
					break;
				default:
			}
		}
		self::$filters['sql_from']['all_others'] = trim(self::$filters['sql_from']['browsers'].' '.self::$filters['sql_from']['screenres'].' '.self::$filters['sql_from']['content_info'].' '.self::$filters['sql_from']['outbound']);
		self::$filters['sql_from']['all'] = $GLOBALS['wpdb']->prefix.'slim_stats t1 '.self::$filters['sql_from']['all_others'];
	}
	// end init

	/**
	 * Associates tables and their 'SQL aliases'
	 */
	public static function get_table_identifier($_field = 'id', $_as_column = ''){
		if (!empty($_as_column)) return '';

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
					FROM '.self::$filters['sql_from']['all'].'
					WHERE visit_id <> 0 AND resource <> "__l_s__" AND resource <> "" '.self::$filters['sql_where'].' '.self::$filters['date_sql_where'].'
					GROUP BY visit_id
					HAVING COUNT(visit_id) = 1
				) as ts1'));
	}

	public static function count_exit_pages(){
		return intval($GLOBALS['wpdb']->get_var('
			SELECT COUNT(*) count
				FROM (
					SELECT resource, visit_id, dt
					FROM '.self::$filters['sql_from']['all'].'
					WHERE visit_id > 0 AND resource <> "" AND resource <> "__l_s__" '.self::$filters['sql_where'].' '.self::$filters['date_sql_where'].'
					GROUP BY visit_id
					HAVING dt = MAX(dt)
				) AS ts1'));
	}

	public static function count_records($_where_clause = '1=1', $_distinct_column = '*', $_use_filters = true, $_use_date_filters = true){
		$column = ($_distinct_column != '*')?"DISTINCT $_distinct_column":$_distinct_column;
		return intval($GLOBALS['wpdb']->get_var("
			SELECT COUNT($column) count
			FROM ".$GLOBALS['wpdb']->prefix.'slim_stats t1 '.($_use_filters?self::$filters['sql_from']['all_others']:'').' '.self::_add_filters_to_sql_from($_where_clause).'
			WHERE '.(!empty($_where_clause)?$_where_clause:'1=1').' '.($_use_filters?self::$filters['sql_where']:'').' '.($_use_date_filters?self::$filters['date_sql_where']:'')));
	}

	public static function count_records_having($_where_clause = '1=1', $_column = 't1.ip', $_having_clause = ''){
		return intval($GLOBALS['wpdb']->get_var("
			SELECT COUNT(*) FROM (
				SELECT $_column
				FROM ".self::$filters['sql_from']['all'].' '.self::_add_filters_to_sql_from($_where_clause)."
				WHERE $_where_clause ".self::$filters['sql_where'].' '.self::$filters['date_sql_where']."
				GROUP BY $_column
				".(!empty($_having_clause)?"HAVING $_having_clause":'').')
			AS ts1'));
	}

	public static function get_data_size(){
		$suffix = 'KB';

		$sql = 'SHOW TABLE STATUS LIKE "'.$GLOBALS['wpdb']->prefix.'slim_stats"';
		$myTableDetails = $GLOBALS['wpdb']->get_row($sql, 'ARRAY_A', 0);

		$myTableSize = ( $myTableDetails['Data_length'] / 1024 ) + ( $myTableDetails['Index_length'] / 1024 );

		if ($myTableSize > 1024){
			$myTableSize /= 1024;
			$suffix = 'MB';
		}
		return number_format($myTableSize, 2, self::$formats['decimal'], self::$formats['thousand']).' '.$suffix;
	}

	public static function get_max_and_average_pages_per_visit(){
		return $GLOBALS['wpdb']->get_row('
			SELECT AVG(ts1.count) avg, MAX(ts1.count) max FROM (
				SELECT count(ip) count, visit_id
				FROM '.self::$filters['sql_from']['all'].'
				WHERE visit_id > 0 '.self::$filters['sql_where'].' '.self::$filters['date_sql_where'].'
				GROUP BY visit_id
			) AS ts1', ARRAY_A);
	}

	public static function get_oldest_visit($_order = 'ASC'){
		return $GLOBALS['wpdb']->get_var('
			SELECT t1.dt
			FROM '.$GLOBALS['wpdb']->prefix."slim_stats t1
			ORDER BY t1.dt $_order
			LIMIT 0,1");
	}

	public static function get_recent($_column = 't1.id', $_custom_where = '', $_join_tables = '', $_having_clause = '', $_order_by = ''){
		$other_tables = self::_add_filters_to_sql_from($_column.$_custom_where);
		if ($_column == 't1.id'){
			return $GLOBALS['wpdb']->get_results('
				SELECT t1.*'.(!empty($_join_tables)?', '.$_join_tables:'').'
				FROM '.self::$filters['sql_from']['all'].' '.(!empty($_join_tables)?self::_add_filters_to_sql_from($_join_tables):'').'
				WHERE '.(empty($_custom_where)?"$_column <> 0 ":$_custom_where).' '.self::$filters['sql_where'].' '.self::$filters['date_sql_where'].'
				ORDER BY '.(empty($_order_by)?'t1.dt '.self::$filters['parsed']['direction'][1]:$_order_by).'
				LIMIT '.self::$filters['parsed']['starting'][1].', '.self::$filters['parsed']['limit_results'][1], ARRAY_A);
		}
		else{
			return $GLOBALS['wpdb']->get_results('
				SELECT t1.*, '.(!empty($_join_tables)?$_join_tables:'ts1.*')."
				FROM (
					SELECT $_column, MAX(t1.id) maxid
					FROM ".self::$filters['sql_from']['all'].' '.self::_add_filters_to_sql_from($_column.$_custom_where).'
					WHERE '.(empty($_custom_where)?"$_column <> '' AND  $_column <> '__l_s__'":$_custom_where).' '.self::$filters['sql_where'].' '.self::$filters['date_sql_where']."
					GROUP BY $_column $_having_clause
				) AS ts1 INNER JOIN ".$GLOBALS['wpdb']->prefix.'slim_stats t1 ON ts1.maxid = t1.id '.
				(!empty($_join_tables)?self::_add_filters_to_sql_from($_join_tables):'').'
				ORDER BY '.(empty($_order_by)?'t1.dt '.self::$filters['parsed']['direction'][1]:$_order_by).'
				LIMIT '.self::$filters['parsed']['starting'][1].', '.self::$filters['parsed']['limit_results'][1], ARRAY_A);
		}
	}

	public static function get_recent_outbound($_type = -1){
		return $GLOBALS['wpdb']->get_results('
			SELECT tob.outbound_id as visit_id, tob.outbound_domain, tob.outbound_resource as resource, tob.type, tob.notes, t1.ip, t1.other_ip, t1.user, "local" as domain, t1.resource as referer, t1.country, tb.browser, tb.version, tb.platform, tob.dt
			FROM  '.$GLOBALS['wpdb']->prefix.'slim_stats t1 INNER JOIN '.$GLOBALS['wpdb']->prefix.'slim_outbound tob ON tob.id = t1.id INNER JOIN '.$GLOBALS['wpdb']->base_prefix.'slim_browsers tb on t1.browser_id = tb.browser_id '.self::$filters['sql_from']['screenres'].' '.self::$filters['sql_from']['content_info'].'
			WHERE '.(($_type != -1)?"tob.type = $_type":'tob.type > 0').' '.self::$filters['sql_where'].' '.self::$filters['date_sql_where'].'
			ORDER BY tob.dt '.self::$filters['parsed']['direction'][1].'
			LIMIT '.self::$filters['parsed']['starting'][1].','.self::$filters['parsed']['limit_results'][1], ARRAY_A);
	}

	public static function get_popular_complete($_column = 't1.id', $_custom_where = '', $_join_tables = '', $_having_clause = ''){
		return $GLOBALS['wpdb']->get_results("
			SELECT t1.*, ts1.*, ts1.count
			FROM (
				SELECT $_column, MAX(t1.id) maxid, COUNT(*) count
				FROM ".self::$filters['sql_from']['all'].' '.self::_add_filters_to_sql_from($_column.$_custom_where).'
				WHERE '.(empty($_custom_where)?"$_column <> '' AND  $_column <> '__l_s__'":$_custom_where).' '.self::$filters['sql_where'].' '.self::$filters['date_sql_where']."
				GROUP BY $_column $_having_clause
			) AS ts1 JOIN ".$GLOBALS['wpdb']->prefix.'slim_stats t1 ON ts1.maxid = t1.id '.
			(!empty($_join_tables)?self::_add_filters_to_sql_from($_join_tables):'').'
			ORDER BY ts1.count '.self::$filters['parsed']['direction'][1]."
			LIMIT ".self::$filters['parsed']['starting'][1].', '.self::$filters['parsed']['limit_results'][1], ARRAY_A);
	}

	public static function get_popular($_column = 't1.id', $_custom_where = '', $_more_columns = '', $_having_clause = '', $_as_column = ''){
		return $GLOBALS['wpdb']->get_results("
			SELECT $_column$_as_column$_more_columns, COUNT(*) count
			FROM ".self::$filters['sql_from']['all'].' '.self::_add_filters_to_sql_from($_column.$_custom_where.$_more_columns).'
			WHERE '.(empty($_custom_where)?"$_column <> '' AND  $_column <> '__l_s__'":$_custom_where).' '.self::$filters['sql_where'].' '.self::$filters['date_sql_where']."
			GROUP BY $_column$_more_columns $_having_clause
			ORDER BY count ".self::$filters['parsed']['direction'][1]."
			LIMIT ".self::$filters['parsed']['starting'][1].', '.self::$filters['parsed']['limit_results'][1], ARRAY_A);
	}

	public static function extract_data_for_chart($_data1, $_data2, $_custom_where_clause = '', $_sql_from_where = ''){
		// Avoid PHP warnings in strict mode
		$result = array(
			'current' => array('non_zero_count' => 0, 'data1' => '', 'data2' => '', 'total1' => 0, 'total2' => 0),
			'previous' => array('non_zero_count' => 0, 'data' => '', 'total' => 0),
			'max_yaxis' => 0,
			'ticks' => '', 'markings' => ''
		);
		$data = array();

		$reset_timezone = date_default_timezone_get();
		date_default_timezone_set('UTC');

		$time_constraints = '(dt BETWEEN '.self::$timeframes['current_utime_start'].' AND '.self::$timeframes['current_utime_end'].' OR dt BETWEEN '.self::$timeframes['previous_utime_start'].' AND '.self::$timeframes['previous_utime_end'].')';
		$data['count_offset'] = 0;
		if (empty(self::$filters['parsed']['interval'][1])){
			if (self::$timeframes['current_day']['hour_selected']){
				$sql = "SELECT DATE_FORMAT(FROM_UNIXTIME(dt), '%Y %m %d %H:%i') datestamp, $_data1 data1, $_data2 data2";
				$group_and_order = "GROUP BY DATE_FORMAT(FROM_UNIXTIME(dt), '%H'), DATE_FORMAT(FROM_UNIXTIME(dt), '%i') ORDER BY datestamp ASC";
				$data['end_value'] = 60;
				$data['count_offset'] = 1;
			}
			elseif (self::$timeframes['current_day']['day_selected']){
				$sql = "SELECT DATE_FORMAT(FROM_UNIXTIME(dt), '%Y %m %d %H:00') datestamp, $_data1 data1, $_data2 data2";
				$group_and_order = "GROUP BY DATE_FORMAT(FROM_UNIXTIME(dt), '%d'), DATE_FORMAT(FROM_UNIXTIME(dt), '%H') ORDER BY datestamp ASC";
				$data['end_value'] = 24;
				$data['count_offset'] = 1;
			}
			elseif (self::$timeframes['current_day']['year_selected'] && !self::$timeframes['current_day']['month_selected']){
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
			$sql = "SELECT DATE_FORMAT(FROM_UNIXTIME(dt), '%Y %m %d 00:00') datestamp, $_data1 data1, $_data2 data2";
			$group_and_order = "GROUP BY DATE_FORMAT(FROM_UNIXTIME(dt), '%m'), DATE_FORMAT(FROM_UNIXTIME(dt), '%d') ORDER BY datestamp ASC";
			$data['end_value'] = self::$filters['parsed']['interval'][1];
			$data['count_offset'] = -1; // skip ticks generation
		}

		// Panel 4 has a slightly different structure
		if(empty($_sql_from_where)){
			$sql .= '	FROM '.self::$filters['sql_from']['all'].' '.self::_add_filters_to_sql_from($_data1.$_data2.$_custom_where_clause)."
						WHERE $time_constraints ".self::$filters['sql_where'].' '.$_custom_where_clause;
		}
		else{
			$sql_no_placeholders = str_replace('[from_tables]', self::$filters['sql_from']['all'].' '.self::_add_filters_to_sql_from($_data1.$_data2.$_custom_where_clause), $_sql_from_where);
			$sql_no_placeholders = str_replace('[where_clause]', $time_constraints.' '.self::$filters['sql_where'].' '.$_custom_where_clause, $sql_no_placeholders);
			$sql .= $sql_no_placeholders;
		}
		$sql .= ' '.$group_and_order;

		$array_results = $GLOBALS['wpdb']->get_results($sql, ARRAY_A);

		if (!is_array($array_results) || empty($array_results))
			$array_results = array_fill(0, $data['end_value']*2, array('datestamp' => 0, 'data1' => 0, 'data2' => 0, ));

		// Reorganize the data and then format it for Flot
		foreach ($array_results as $a_result){
			$data[0][$a_result['datestamp']] = $a_result['data1'];
			$data[1][$a_result['datestamp']] = $a_result['data2'];
		}

		$result['max_yaxis'] = max(max($data[0]), max($data[1]));
		$result['ticks'] = self::_generate_ticks($data['end_value'], $data['count_offset']);

		$markings = '';

		// Reverse the chart, if needed
		$k = ($GLOBALS['wp_locale']->text_direction == 'rtl')?1-$data['end_value']:0;

		for ($i=0;$i<$data['end_value'];$i++){
			$j = abs($k+$i);
			if (empty(self::$filters['parsed']['interval'][1])){
				if (self::$timeframes['current_day']['hour_selected']){
					$datestamp['timestamp_current'] = mktime(self::$timeframes['current_day']['h'], $j, 0, self::$timeframes['current_day']['m'], self::$timeframes['current_day']['d'], self::$timeframes['current_day']['y']);
					$datestamp['timestamp_previous'] = mktime(self::$timeframes['current_day']['h'] - 1, $j, 0, self::$timeframes['current_day']['m'], self::$timeframes['current_day']['d'], self::$timeframes['current_day']['y']);
					$datestamp['filter_current'] =  '';
					$datestamp['filter_previous'] =  '';
					$datestamp['marking_signature'] = self::$timeframes['current_day']['y'].' '.self::$timeframes['current_day']['m'].' '.self::$timeframes['current_day']['d'].' '.self::$timeframes['current_day']['h'].':'.sprintf('%02d', $j);
					$datestamp['group'] = 'h';
				}
				elseif (self::$timeframes['current_day']['day_selected']){
					$datestamp['timestamp_current'] = mktime($j, 0, 0, self::$timeframes['current_day']['m'], self::$timeframes['current_day']['d'], self::$timeframes['current_day']['y']);
					$datestamp['timestamp_previous'] = mktime($j, 0, 0, self::$timeframes['current_day']['m'], self::$timeframes['current_day']['d']-1, self::$timeframes['current_day']['y']);
					$datestamp['filter_current'] = ',"'.wp_slimstat_boxes::fs_url(array('hour','day','month','year'), array($j, self::$timeframes['current_day']['d'], self::$timeframes['current_day']['m'], self::$timeframes['current_day']['y'])).'"';
					$datestamp['filter_previous'] = ',"'.wp_slimstat_boxes::fs_url(array('hour','day','month','year'), array($j, date_i18n('d', $datestamp['timestamp_previous']), date_i18n('m', $datestamp['timestamp_previous']), date_i18n('Y', $datestamp['timestamp_previous']))).'"';
					$datestamp['marking_signature'] = self::$timeframes['current_day']['y'].' '.self::$timeframes['current_day']['m'].' '.self::$timeframes['current_day']['d'].' '.sprintf('%02d', $j);
					$datestamp['group'] = 'd';
				}
				elseif (self::$timeframes['current_day']['year_selected'] && !self::$timeframes['current_day']['month_selected']){
					$datestamp['timestamp_current'] = mktime(0, 0, 0, $j+1, 1, self::$timeframes['current_day']['y']);
					$datestamp['timestamp_previous'] = mktime(0, 0, 0, $j+1, 1, self::$timeframes['current_day']['y']-1);
					$datestamp['filter_current'] = ',"'.wp_slimstat_boxes::fs_url(array('month','year'), array($j+1, self::$timeframes['current_day']['y'])).'"';
					$datestamp['filter_previous'] = ',"'.wp_slimstat_boxes::fs_url(array('month','year'), array($j+1, self::$timeframes['current_day']['y']-1)).'"';
					$datestamp['marking_signature'] = self::$timeframes['current_day']['y'].' '.sprintf('%02d', $j+1);
					$datestamp['group'] = 'Y';
				}
				else{
					$datestamp['timestamp_current'] = mktime(0, 0, 0, self::$timeframes['current_day']['m'], $j+1, self::$timeframes['current_day']['y']);
					$datestamp['timestamp_previous'] = mktime(0, 0, 0, self::$timeframes['current_day']['m']-1, $j+1, self::$timeframes['current_day']['y']);
					$datestamp['filter_current'] =  ',"'.wp_slimstat_boxes::fs_url(array('day','month','year'), array($j+1, self::$timeframes['current_day']['m'], self::$timeframes['current_day']['y'])).'"';
					$datestamp['filter_previous'] =  ',"'.wp_slimstat_boxes::fs_url(array('day','month','year'), array($j+1, date_i18n('m', $datestamp['timestamp_previous']), date_i18n('Y', $datestamp['timestamp_previous']))).'"';
					$datestamp['marking_signature'] = self::$timeframes['current_day']['y'].' '.self::$timeframes['current_day']['m'].' '.sprintf('%02d', $j+1);
					$datestamp['group'] = 'm';
				}
			}
			else{
				$datestamp['timestamp_current'] = mktime(0, 0, 0, self::$timeframes['current_day']['m'], self::$timeframes['current_day']['d']+$j, self::$timeframes['current_day']['y']);
				$datestamp['timestamp_previous'] = mktime(0, 0, 0, self::$timeframes['current_day']['m']-1, self::$timeframes['current_day']['d']+$j, self::$timeframes['current_day']['y']);
				$datestamp['filter_current'] =  ',"'.wp_slimstat_boxes::fs_url(array('day','month','year'), array(self::$timeframes['current_day']['d']+$j, self::$timeframes['current_day']['m'], self::$timeframes['current_day']['y'])).'"';
				$datestamp['filter_previous'] =  ',"'.wp_slimstat_boxes::fs_url(array('day','month','year'), array(self::$timeframes['current_day']['d']+$j, date_i18n('m', $datestamp['timestamp_previous']), date_i18n('Y', $datestamp['timestamp_previous']))).'"';
				$datestamp['marking_signature'] = self::$timeframes['current_day']['y'].' '.self::$timeframes['current_day']['m'].' '.sprintf('%02d', self::$timeframes['current_day']['d']+$j);
				$datestamp['group'] = 'm';
			}

			$datestamp['current'] = date_i18n('Y m d H:i', $datestamp['timestamp_current']);
			$datestamp['previous'] = date_i18n('Y m d H:i', $datestamp['timestamp_previous']);

			if (date_i18n($datestamp['group'], $datestamp['timestamp_current']) == date_i18n($datestamp['group'], self::$timeframes['current_utime_start'], true) || !empty(self::$filters['parsed']['interval'][1])){
				if (!empty($data[0][$datestamp['current']])){
					$result['current']['data1'] .= "[$i,{$data[0][$datestamp['current']]}{$datestamp['filter_current']}],";
					$result['current']['total1'] += $data[0][$datestamp['current']];
					$result['current']['non_zero_count']++;
				}	
				elseif($datestamp['timestamp_current'] <= date_i18n('U')){
					$result['current']['data1'] .= "[$i,0],";
				}

				if (!empty($data[1][$datestamp['current']])){
					$result['current']['data2'] .= "[$i,{$data[1][$datestamp['current']]}{$datestamp['filter_current']}],";
					$result['current']['total2'] += $data[1][$datestamp['current']];
				}
				elseif($datestamp['timestamp_current'] <= date_i18n('U')){
					$result['current']['data2'] .= "[$i,0],";
				}
			}

			if (date_i18n($datestamp['group'], $datestamp['timestamp_previous']) == date_i18n($datestamp['group'], self::$timeframes['previous_utime_start'], true) && empty(self::$filters['parsed']['interval'][1])){
				if (!empty($data[0][$datestamp['previous']])){
					$result['previous']['data'] .= "[$i,{$data[0][$datestamp['previous']]}{$datestamp['filter_previous']}],";
					$result['previous']['total'] += $data[0][$datestamp['previous']];
				}
				elseif($datestamp['timestamp_previous'] <= date_i18n('U')){
					$result['previous']['data'] .= "[$i,0],";
				}
			}
			
			if (!empty(self::$filters['parsed']['interval'][1])){
				$result['ticks'] .= "[$i, '".((self::$formats['decimal'] == '.')?date_i18n('m/d', $datestamp['timestamp_current']):date_i18n('d/m', $datestamp['timestamp_current']))."'],";
			}
			
			if (!empty(wp_slimstat::$options['markings'])){
				preg_match_all("/{$datestamp['marking_signature']}[^\=]*\=([^,]+)/", wp_slimstat::$options['markings'], $matches);
				if (!empty($matches[1])){
					$current_marking_description = '';
					foreach($matches[1] as $a_description){
						$current_marking_description .= trim($a_description).', ';
					}
					$current_marking_description = substr($current_marking_description, 0, -2);
					$result['markings'] .= "[$i,SlimStatAdmin.options['max_yaxis']+1,'$current_marking_description'],";
				}
			}
		}

		date_default_timezone_set($reset_timezone);

		$result['current']['data1'] = substr($result['current']['data1'], 0, -1);
		$result['current']['data2'] = substr($result['current']['data2'], 0, -1);
		$result['previous']['data'] = substr($result['previous']['data'], 0, -1);
		$result['ticks'] = substr($result['ticks'], 0, -1);
		$result['markings'] = substr($result['markings'], 0, -1);

		return $result;
	}

	protected function _init_filters($_filters_string = ''){
		if (substr($_filters_string, -1) != '|') $_filters_string .= '|';
			preg_match_all('/([^\s|]+)\s([^\s|]+).((?:[^|]+)?)/', urldecode($_filters_string), $matches);

		foreach($matches[1] as $idx => $a_match){
			// Some filters need extra 'decoding'
			switch($a_match){
				case 'strtotime': // TODO - TO BE REMOVED - add support for strtotime to right side of expression
					$custom_date = strtotime($matches[3][$idx]);
					self::$filters['parsed']['day'] = array('equals', date_i18n('j', $custom_date));
					self::$filters['parsed']['month'] = array('equals', date_i18n('n', $custom_date));
					self::$filters['parsed']['year'] = array('equals', date_i18n('Y', $custom_date));
					break;
				case 'interval':
					if (intval($matches[3][$idx]) > 0) self::$filters['parsed']['interval'] = array('equals', intval($matches[3][$idx]));
					break;
				case 'direction':
				case 'limit_results':
				case 'starting':
				case 'browser':
				case 'country':
				case 'ip':
				case 'searchterms':
				case 'language':
				case 'platform':
				case 'resource':
				case 'domain':
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
				case 'hour':
				case 'day':
				case 'month':
				case 'year':
					self::$filters['parsed'][$a_match] = array(isset($matches[2][$idx])?$matches[2][$idx]:'equals', isset($matches[3][$idx])?$GLOBALS['wpdb']->escape(str_replace('\\', '', htmlspecialchars_decode($matches[3][$idx]))):'');
					break;
				default:
			}
		}
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
	
	protected function _add_filters_to_sql_from($_sql_tables = '', $_ignore_empty = false){
		$sql_from = '';
		if (($_ignore_empty || empty(self::$filters['sql_from']['browsers'])) && strpos($_sql_tables, 'tb.') !== false)
			$sql_from .= ' INNER JOIN '.$GLOBALS['wpdb']->base_prefix.'slim_browsers tb ON t1.browser_id = tb.browser_id';

		if (($_ignore_empty || empty(self::$filters['sql_from']['content_info'])) && strpos($_sql_tables, 'tci.') !== false)
			$sql_from .=  ' INNER JOIN '.$GLOBALS['wpdb']->base_prefix.'slim_content_info tci ON t1.content_info_id = tci.content_info_id';

		if (($_ignore_empty || empty(self::$filters['sql_from']['outbound'])) && strpos($_sql_tables, 'to.') !== false)
			$sql_from .=  ' INNER JOIN '.$GLOBALS['wpdb']->prefix.'slim_outbound to ON t1.id = to.id';

		if (($_ignore_empty || empty(self::$filters['sql_from']['screenres'])) && strpos($_sql_tables, 'tss.') !== false)
			$sql_from .=  ' LEFT JOIN '.$GLOBALS['wpdb']->base_prefix.'slim_screenres tss ON t1.screenres_id = tss.screenres_id';
		
		return $sql_from;
	}
	
	protected function _generate_ticks($_count = 0, $_offset = 0){
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
}