<?php

// Let's define the main class with all the methods that we need
class wp_slimstat_db {
	// Filters
	public static $filter_names = array();
	public static $filters_normalized = array();

	// Number and date formats
	public static $formats = array('decimal' => ',', 'thousand' => '.');

	// Filters as SQL clauses
	public static $sql_filters = array();

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

		// Filters are defined as: browser equals Chrome|country starts_with en
		if (!is_string($_filters) || empty($_filters)){
			$_filters = '';
		}

		// List of supported filters and their friendly names
		self::$filter_names = array(
			'no_filter_selected_1' => '&nbsp;',
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
			'page_performance' => __('Page Speed','wp-slimstat'),
			'no_filter_selected_2' => '&nbsp;',
			'no_filter_selected_3' => __('-- Advanced filters --','wp-slimstat'),
			'plugins' => __('Browser Capabilities','wp-slimstat'),
			'version' => __('Browser Version','wp-slimstat'),
			'type' => __('Browser Type','wp-slimstat'),
			'user_agent' => __('User Agent','wp-slimstat'),
			'colordepth' => __('Color Depth','wp-slimstat'),
			'css_version' => __('CSS Version','wp-slimstat'),
			'notes' => __('Pageview Attributes','wp-slimstat'),
			'server_latency' => __('Server Latency','wp-slimstat'),
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

		// Hook for the... filters
		$_filters = apply_filters('slimstat_db_pre_filters', $_filters);

		// Normalize the input (filters)
		self::$filters_normalized = self::parse_filters($_filters);

		// Hook for the array of normalized filters
		self::$filters_normalized = apply_filters('slimstat_db_filters_normalized', self::$filters_normalized, $_filters);

		if (empty(self::$filters_normalized['date']['interval'])){
			if (!empty(self::$filters_normalized['date']['hour'])){
				self::$filters_normalized['utime']['start'] = mktime(
					self::$filters_normalized['date']['hour'],
					0,
					0,
					!empty(self::$filters_normalized['date']['month'])?self::$filters_normalized['date']['month']:intval(date_i18n('n')),
					!empty(self::$filters_normalized['date']['day'])?self::$filters_normalized['date']['day']:intval(date_i18n('j')),
					!empty(self::$filters_normalized['date']['year'])?self::$filters_normalized['date']['year']:intval(date_i18n('Y'))
				);
				self::$filters_normalized['utime']['end'] = self::$filters_normalized['utime']['start'] + 3599;
				self::$filters_normalized['utime']['type'] = 'H';
			}
			else if (!empty(self::$filters_normalized['date']['day'])){
				self::$filters_normalized['utime']['start'] = mktime(
					0,
					0,
					0,
					!empty(self::$filters_normalized['date']['month'])?self::$filters_normalized['date']['month']:intval(date_i18n('n')),
					self::$filters_normalized['date']['day'],
					!empty(self::$filters_normalized['date']['year'])?self::$filters_normalized['date']['year']:intval(date_i18n('Y'))
				);
				self::$filters_normalized['utime']['end'] = self::$filters_normalized['utime']['start'] + 86399;
				self::$filters_normalized['utime']['type'] = 'd';
			}
			else if(!empty(self::$filters_normalized['date']['year']) && empty(self::$filters_normalized['date']['month'])){
				self::$filters_normalized['utime']['start'] = mktime(0, 0, 0, 1, 1, self::$filters_normalized['date']['year']);
				self::$filters_normalized['utime']['end'] = mktime(0, 0, 0, 1, 1, self::$filters_normalized['date']['year']+1)-1;
				self::$filters_normalized['utime']['type'] = 'Y';
			}
			else{
				self::$filters_normalized['utime']['start'] = mktime(
					0,
					0,
					0,
					!empty(self::$filters_normalized['date']['month'])?self::$filters_normalized['date']['month']:intval(date_i18n('n')),
					1,
					!empty(self::$filters_normalized['date']['year'])?self::$filters_normalized['date']['year']:intval(date_i18n('Y'))
				);

				self::$filters_normalized['utime']['end'] = strtotime(
					(!empty(self::$filters_normalized['date']['year'])?self::$filters_normalized['date']['year']:intval(date_i18n('Y'))).'-'.
					(!empty(self::$filters_normalized['date']['month'])?self::$filters_normalized['date']['month']:intval(date_i18n('n'))).
					'-01 00:00 +1 month UTC'
				)-1;
				self::$filters_normalized['utime']['type'] = 'm';
			}
		}
		else{
			self::$filters_normalized['utime']['type'] = 'interval';
			if (self::$filters_normalized['date']['interval'] > 0){
				self::$filters_normalized['utime']['start'] = mktime(
					0,
					0,
					0,
					!empty(self::$filters_normalized['date']['month'])?self::$filters_normalized['date']['month']:intval(date_i18n('n')),
					!empty(self::$filters_normalized['date']['day'])?self::$filters_normalized['date']['day']:intval(date_i18n('j')),
					!empty(self::$filters_normalized['date']['year'])?self::$filters_normalized['date']['year']:intval(date_i18n('Y'))
				);
				self::$filters_normalized['utime']['end'] = strtotime(
					(!empty(self::$filters_normalized['date']['year'])?self::$filters_normalized['date']['year']:intval(date_i18n('Y'))).'-'.
					(!empty(self::$filters_normalized['date']['month'])?self::$filters_normalized['date']['month']:intval(date_i18n('n'))).'-'.
					(!empty(self::$filters_normalized['date']['day'])?self::$filters_normalized['date']['day']:intval(date_i18n('j'))).' 00:00:00 +'.
					self::$filters_normalized['date']['interval'].' days UTC'
				)-1;
			}
			else{
				// Swap boundaries, if interval is negative
				self::$filters_normalized['utime']['start'] = strtotime(
					(!empty(self::$filters_normalized['date']['year'])?self::$filters_normalized['date']['year']:intval(date_i18n('Y'))).'-'.
					(!empty(self::$filters_normalized['date']['month'])?self::$filters_normalized['date']['month']:intval(date_i18n('n'))).'-'.
					(!empty(self::$filters_normalized['date']['day'])?self::$filters_normalized['date']['day']:intval(date_i18n('j'))).' 00:00:00 '.
					(self::$filters_normalized['date']['interval']+1).' days UTC'
				);
				self::$filters_normalized['utime']['end'] = mktime(
					23,
					59,
					59,
					!empty(self::$filters_normalized['date']['month'])?self::$filters_normalized['date']['month']:intval(date_i18n('n')),
					!empty(self::$filters_normalized['date']['day'])?self::$filters_normalized['date']['day']:intval(date_i18n('j')),
					!empty(self::$filters_normalized['date']['year'])?self::$filters_normalized['date']['year']:intval(date_i18n('Y'))
				);
			}
		}

		// If end is in the future, set it to now
		if (self::$filters_normalized['utime']['end'] > intval(date_i18n('U'))){
			self::$filters_normalized['utime']['end'] = intval(date_i18n('U'));
		}
		
		// If start is after end, set it to first of month
		if (self::$filters_normalized['utime']['start'] > self::$filters_normalized['utime']['end']){
			self::$filters_normalized['utime']['start'] = mktime(
				0,
				0,
				0,
				intval(date_i18n('n', self::$filters_normalized['utime']['end'])),
				1,
				intval(date_i18n('Y', self::$filters_normalized['utime']['end']))
			);
			self::$filters_normalized['date']['hour'] = self::$filters_normalized['date']['day'] = self::$filters_normalized['date']['month'] = self::$filters_normalized['date']['year'] = 0;
		}

		// Now let's translate our filters into SQL clauses
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
			'where_time_range' => ' AND (t1.dt BETWEEN '.self::$filters_normalized['utime']['start'].' AND '.self::$filters_normalized['utime']['end'].')'
		);

		foreach (self::$filters_normalized['columns'] as $a_filter_column => $a_filter_data){
			$a_filter_empty = '0';
			
			// Add-ons can set their own custom filters, which are ignored here
			if (strpos($a_filter_column, 'addon_') !== false){
				continue;
			}

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
					self::$sql_filters['from']['outbound'] = "LEFT JOIN {$GLOBALS['wpdb']->prefix}slim_outbound tob ON (t1.id = tob.id)";
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
				case 'between':
					$range = explode(',', $a_filter_data[1]);
					self::$sql_filters['where'] .= $GLOBALS['wpdb']->prepare(" AND $a_filter_column BETWEEN %s AND %s", $range[0], $range[1]);
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
		return intval(self::get_var('
			SELECT COUNT(*) counthits
				FROM (
					SELECT t1.resource
					FROM '.self::$sql_filters['from']['all_tables'].' '.self::_add_filters_to_sql_from('tci.content_type').'
					WHERE t1.visit_id <> 0 AND t1.resource <> "" AND tci.content_type <> "404" '.self::$sql_filters['where'].' '.self::$sql_filters['where_time_range'].'
					GROUP BY visit_id
					HAVING COUNT(visit_id) = 1
				) as ts1',
			'SUM(counthits) AS counthits'));
	}

	public static function count_exit_pages(){
		return intval(self::get_var('
			SELECT COUNT(*) counthits
				FROM (
					SELECT resource, visit_id, dt
					FROM '.self::$sql_filters['from']['all_tables'].'
					WHERE visit_id > 0 AND resource <> "" '.self::$sql_filters['where'].' '.self::$sql_filters['where_time_range'].'
					GROUP BY visit_id
					HAVING dt = MAX(dt)
				) AS ts1',
			'SUM(counthits) AS counthits'));
	}

	public static function count_records($_where_clause = '1=1', $_distinct_column = '*', $_use_filters = true, $_use_date_filters = true, $_join_tables = ''){
		$column = ($_distinct_column != '*')?"DISTINCT $_distinct_column":$_distinct_column;
		return intval(self::get_var("
			SELECT COUNT($column) counthits
			FROM {$GLOBALS['wpdb']->prefix}slim_stats t1 ".($_use_filters?self::$sql_filters['from']['all_other_tables']:'').' '.self::_add_filters_to_sql_from($_where_clause.$_join_tables).'
			WHERE '.(!empty($_where_clause)?$_where_clause:'1=1').' '.($_use_filters?self::$sql_filters['where']:'').' '.($_use_date_filters?self::$sql_filters['where_time_range']:''),
			'SUM(counthits) AS counthits'));
	}

	public static function count_outbound(){
		return intval(self::get_var("
			SELECT COUNT(outbound_id) counthits
			FROM {$GLOBALS['wpdb']->prefix}slim_stats t1 INNER JOIN {$GLOBALS['wpdb']->prefix}slim_outbound tob ON t1.id = tob.id ".self::$sql_filters['from']['all_other_tables']."
			WHERE 1=1 ".self::$sql_filters['where'].' '.self::$sql_filters['where_time_range'],
			'SUM(counthits) AS counthits'));
	}

	public static function count_records_having($_where_clause = '1=1', $_column = 't1.ip', $_having_clause = ''){
		return intval(self::get_var("
			SELECT COUNT(*) counthits FROM (
				SELECT $_column
				FROM ".self::$sql_filters['from']['all_tables'].' '.self::_add_filters_to_sql_from($_where_clause)."
				WHERE $_where_clause ".self::$sql_filters['where'].' '.self::$sql_filters['where_time_range']."
				GROUP BY $_column
				".(!empty($_having_clause)?"HAVING $_having_clause":'').')
			AS ts1',
			'SUM(counthits) AS counthits'));
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
		return self::get_results('
			SELECT AVG(ts1.counthits) AS avghits, MAX(ts1.counthits) AS maxhits FROM (
				SELECT count(ip) counthits, visit_id
				FROM '.self::$sql_filters['from']['all_tables'].'
				WHERE visit_id > 0 '.self::$sql_filters['where'].' '.self::$sql_filters['where_time_range'].'
				GROUP BY visit_id
			) AS ts1',
			'blog_id',
			'',
			'',
			'AVG(avghits) AS avghits, MAX(maxhits) AS maxhits');
	}

	public static function get_oldest_visit($_where_clause = '1=1', $_use_filters = true){
		return self::get_var("
			SELECT t1.dt
			FROM {$GLOBALS['wpdb']->prefix}slim_stats t1 ".($_use_filters?self::$sql_filters['from']['all_other_tables']:'').' '.self::_add_filters_to_sql_from($_where_clause).'
			WHERE '.(!empty($_where_clause)?$_where_clause:'1=1').' '.($_use_filters?self::$sql_filters['where']:'').'
			ORDER BY dt ASC
			LIMIT 0,1',
			'MIN(dt)');
	}

	public static function get_popular($_column = 't1.id', $_custom_where = '', $_more_columns = '', $_having_clause = '', $_as_column = ''){
		return self::get_results("
			SELECT $_column ".(!empty($_as_column)?'AS '.$_as_column:'')." $_more_columns, COUNT(*) counthits
			FROM ".self::$sql_filters['from']['all_tables'].' '.self::_add_filters_to_sql_from($_column.$_custom_where.$_more_columns).'
			WHERE '.(empty($_custom_where)?"$_column <> '' ":$_custom_where).' '.self::$sql_filters['where'].' '.self::$sql_filters['where_time_range']."
			GROUP BY $_column $_more_columns $_having_clause
			ORDER BY counthits ".self::$filters_normalized['misc']['direction']."
			LIMIT ".self::$filters_normalized['misc']['start_from'].', '.self::$filters_normalized['misc']['limit_results'], 
			(!empty($_as_column)?$_as_column:$_column).' '.$_more_columns.', blog_id',
			'counthits '.self::$filters_normalized['misc']['direction'],
			"$_column $_more_columns",
			'SUM(counthits) AS counthits');
	}

	public static function get_popular_outbound(){
		return self::get_results("
			SELECT tob.outbound_resource as resource, COUNT(*) counthits
			FROM {$GLOBALS['wpdb']->prefix}slim_stats t1 INNER JOIN {$GLOBALS['wpdb']->prefix}slim_outbound tob ON t1.id = tob.id ".self::$sql_filters['from']['browsers'].' '.self::$sql_filters['from']['screenres'].' '.self::$sql_filters['from']['content_info']."
			WHERE 1=1 ".self::$sql_filters['where'].' '.self::$sql_filters['where_time_range'].'
			GROUP BY tob.outbound_resource 
			ORDER BY counthits '.self::$filters_normalized['misc']['direction'].'
			LIMIT '.self::$filters_normalized['misc']['start_from'].', '.self::$filters_normalized['misc']['limit_results'],
			'blog_id, resource',
			'counthits '.self::$filters_normalized['misc']['direction'],
			'outbound_resource',
			'SUM(counthits) AS counthits');
	}

	/* public static function get_popular_complete($_column = 't1.id', $_custom_where = '', $_join_tables = '', $_having_clause = ''){
		return self::get_results("
			SELECT t1.ip, t1.other_ip, t1.user, t1.language, t1.country, t1.domain, t1.referer, t1.searchterms, t1.resource, t1.visit_id, ts1.maxid, ts1.counthits
			FROM (
				SELECT $_column, MAX(t1.id) maxid, COUNT(*) counthits
				FROM ".self::$sql_filters['from']['all_tables'].' '.self::_add_filters_to_sql_from($_column.$_custom_where).'
				WHERE '.(empty($_custom_where)?"$_column <> '' ":$_custom_where).' '.self::$sql_filters['where'].' '.self::$sql_filters['where_time_range']."
				GROUP BY $_column $_having_clause
			) AS ts1 JOIN {$GLOBALS['wpdb']->prefix}slim_stats t1 ON ts1.maxid = t1.id ".
			(!empty($_join_tables)?self::_add_filters_to_sql_from($_join_tables):'').'
			ORDER BY ts1.counthits '.self::$filters_normalized['misc']['direction'].'
			LIMIT '.self::$filters_normalized['misc']['start_from'].', '.self::$filters_normalized['misc']['limit_results'],
			'ip, other_ip, user, language, country, domain, referer, searchterms, resource, visit_id',
			'counthits '.self::$filters_normalized['misc']['direction'],
			'',
			'MAX(maxid), SUM(counthits)');
	}
	*/

	public static function get_recent($_column = 't1.id', $_custom_where = '', $_join_tables = '', $_having_clause = '', $_order_by = '', $_use_date_filters = true){
		if ($_column == 't1.id'){
			return self::get_results('
				SELECT t1.*'.(!empty($_join_tables)?', '.$_join_tables:'').'
				FROM '.self::$sql_filters['from']['all_tables'].' '.(!empty($_join_tables)?self::_add_filters_to_sql_from($_join_tables):'').'
				WHERE '.(empty($_custom_where)?"$_column <> 0 ":$_custom_where).' '.self::$sql_filters['where'].' '.($_use_date_filters?self::$sql_filters['where_time_range']:'').'
				ORDER BY '.(empty($_order_by)?'t1.dt '.self::$filters_normalized['misc']['direction']:$_order_by).'
				LIMIT '.self::$filters_normalized['misc']['start_from'].', '.self::$filters_normalized['misc']['limit_results'],
				'*',
				empty($_order_by)?'t1.dt '.self::$filters_normalized['misc']['direction']:$_order_by);
			
		}
		else{
			return self::get_results('
				SELECT t1.*, '.(!empty($_join_tables)?$_join_tables:'ts1.maxid')."
				FROM (
					SELECT $_column, MAX(t1.id) maxid
					FROM ".self::$sql_filters['from']['all_tables'].' '.self::_add_filters_to_sql_from($_column.$_custom_where).'
					WHERE '.(empty($_custom_where)?"$_column <> '' ":$_custom_where).' '.self::$sql_filters['where'].' '.($_use_date_filters?self::$sql_filters['where_time_range']:'')."
					GROUP BY $_column $_having_clause
				) AS ts1 INNER JOIN {$GLOBALS['wpdb']->prefix}slim_stats t1 ON ts1.maxid = t1.id ".
				(!empty($_join_tables)?self::_add_filters_to_sql_from($_join_tables):'').'
				ORDER BY '.(empty($_order_by)?'t1.dt '.self::$filters_normalized['misc']['direction']:$_order_by).'
				LIMIT '.self::$filters_normalized['misc']['start_from'].', '.self::$filters_normalized['misc']['limit_results'],
				't1.*, '.(!empty($_join_tables)?$_join_tables:'ts1.maxid'),
				empty($_order_by)?'t1.dt '.self::$filters_normalized['misc']['direction']:$_order_by);
		}
	}

	public static function get_recent_outbound($_type = -1){
		return self::get_results("
			SELECT tob.outbound_id as visit_id, tob.outbound_domain, tob.outbound_resource as resource, tob.type, tob.notes, t1.ip, t1.other_ip, t1.user, 'local' as domain, t1.resource as referer, t1.country, tb.browser, tb.version, tb.platform, tob.dt
			FROM {$GLOBALS['wpdb']->prefix}slim_stats t1 INNER JOIN {$GLOBALS['wpdb']->prefix}slim_outbound tob ON tob.id = t1.id INNER JOIN {$GLOBALS['wpdb']->base_prefix}slim_browsers tb on t1.browser_id = tb.browser_id ".self::$sql_filters['from']['screenres'].' '.self::$sql_filters['from']['content_info'].'
			WHERE '.(($_type != -1)?"tob.type = $_type":'tob.type > 1').' '.self::$sql_filters['where'].' '.self::$sql_filters['where_time_range'].'
			ORDER BY tob.dt '.self::$filters_normalized['misc']['direction'].'
			LIMIT '.self::$filters_normalized['misc']['start_from'].', '.self::$filters_normalized['misc']['limit_results'],
			'blog_id, visit_id, outbound_domain, resource, type, notes, ip, other_ip, user, domain, referer, country, browser, platform, dt',
			'dt '.self::$filters_normalized['misc']['direction']);
	}

	public static function get_data_for_chart($_data1, $_data2, $_custom_where_clause = '', $_sql_from_where = ''){
		$previous = array('end' => self::$filters_normalized['utime']['start'] - 1);
		$label_date_format = '';
		$output = array();

		// Each type has its own parameters
		switch (self::$filters_normalized['utime']['type']){
			case 'H':
				$previous['start'] = self::$filters_normalized['utime']['start'] - 3600;
				$label_date_format = get_option('time_format', 'g:i a');
				$group_by = array('HOUR', 'MINUTE', 'i');
				$values_in_interval = array(60, 60, 0); 
				break;
			case 'd':
				$previous['start'] = self::$filters_normalized['utime']['start'] - 86400;
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
				$previous['start'] = mktime(0, 0, 0, (!empty(self::$filters_normalized['date']['month'])?self::$filters_normalized['date']['month']:intval(date_i18n('n')))-1, 1, !empty(self::$filters_normalized['date']['year'])?self::$filters_normalized['date']['year']:intval(date_i18n('Y')));
				$label_date_format = 'm/Y';
				$group_by = array('MONTH', 'DAY', 'j');
				$values_in_interval = array(date('t', $previous['start']), date('t', self::$filters_normalized['utime']['start']), 1);
				break;
		}

		// Custom intervals don't have a comparison chart ('previous' range)
		$time_range = self::$sql_filters['where_time_range'];
		if (empty(self::$filters_normalized['date']['interval'])){
			$time_range = 'AND (t1.dt BETWEEN '.$previous['start'].' AND '.$previous['end'].' OR t1.dt BETWEEN '.self::$filters_normalized['utime']['start'].' AND '.self::$filters_normalized['utime']['end'].')';
		}

		// Build the SQL query
		$sql = "SELECT dt, DATE_FORMAT(FROM_UNIXTIME(dt), '%Y-%m-%d %H:%i') datestamp, $_data1 first_metric, $_data2 second_metric";

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

		$group_by_string = "{$group_by[0]}(FROM_UNIXTIME(dt)), {$group_by[1]}(FROM_UNIXTIME(dt))";
		$sql .= " GROUP BY $group_by_string";

		// Get the data
		$results = self::get_results($sql, 'blog_id, datestamp', '', $group_by_string, 'SUM(first_metric) AS first_metric, SUM(second_metric) AS second_metric');

		// Fill the output array
		$output['current']['label'] = '';
		if (!empty($label_date_format)){
			$output['current']['label'] = gmdate($label_date_format, self::$filters_normalized['utime']['start']);
			$output['previous']['label'] = gmdate($label_date_format, $previous['start']);
		}

		$output['previous']['first_metric'] = array_fill($values_in_interval[2], $values_in_interval[0], 0);
		$output['previous']['second_metric'] = array_fill($values_in_interval[2], $values_in_interval[0], 0);

		for ($i = $values_in_interval[2]; $i < $values_in_interval[0]; $i++){
			// Do not include dates in the future

			if ((empty(self::$filters_normalized['date']['interval']) || date('Ymd', wp_slimstat_db::$filters_normalized['utime']['start'] + ( $i * 86400)) > intval(date_i18n('Ymd'))) && 
				(!empty(self::$filters_normalized['date']['interval']) || $i > intval(date_i18n($group_by[2])))){
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
			$unix_datestamp = strtotime($a_result['datestamp'].' UTC');
			$index = (!empty(self::$filters_normalized['date']['interval']))?floor(($unix_datestamp - wp_slimstat_db::$filters_normalized['utime']['start'])/86400):gmdate($group_by[2], $unix_datestamp);
			
			if (empty(self::$filters_normalized['date']['interval']) && gmdate(self::$filters_normalized['utime']['type'], $unix_datestamp) == gmdate(self::$filters_normalized['utime']['type'], $previous['start'])){
				$output['previous']['first_metric'][$index] = $a_result['first_metric'];
				$output['previous']['second_metric'][$index] = $a_result['second_metric'];
			}
			if (!empty(self::$filters_normalized['date']['interval']) || gmdate(self::$filters_normalized['utime']['type'], $unix_datestamp) == gmdate(self::$filters_normalized['utime']['type'], self::$filters_normalized['utime']['start'])){
				$output['current']['first_metric'][$index] = $a_result['first_metric'];
				$output['current']['second_metric'][$index] = $a_result['second_metric'];
			}
		}

		return ($output);
	}

	public static function parse_filters($_filters = '', $_init_misc = true){
		$filters_normalized = array(
			'columns' => array(),
			'date' => array(),
			'misc' => $_init_misc?array(
				'direction' => 'desc',
				'limit_results' => wp_slimstat::$options['rows_to_show'],
				'start_from' => 0
			):array(),
			'utime' => array(
				'start' => 0,
				'end' => 0,
				'type' => 'm'
			)
		);

		if (!empty($_filters)){
			$matches = explode('&&&', $_filters);

			foreach($matches as $idx => $a_match){
				preg_match('/([^\s]+)\s([^\s]+)\s(.+)?/', urldecode($a_match), $a_filter);

				if ((empty($a_filter) || !array_key_exists($a_filter[1], self::$filter_names) || strpos($a_filter[1], 'no_filter') !== false) && strpos($a_filter[1], 'addon_') === false){
					continue;
				}

				switch($a_filter[1]){
					case 'strtotime':
						$custom_date = strtotime($a_filter[3].' UTC');
						$filters_normalized['date']['day'] = date('j', $custom_date);
						$filters_normalized['date']['month'] = date('n', $custom_date);
						$filters_normalized['date']['year'] = date('Y', $custom_date);
						break;
					case 'hour':
					case 'day':
					case 'month':
					case 'year':
						if (is_numeric($a_filter[3])){
							$filters_normalized['date'][$a_filter[1]] = intval($a_filter[3]);
						}
						else{
							// Try to apply strtotime to value
							switch($a_filter[1]){
								case 'hour':
									$filters_normalized['date'][$a_filter[1]] = date('H', strtotime($a_filter[3], intval(date_i18n('U'))));
									break;
								case 'day':
									$filters_normalized['date'][$a_filter[1]] = date('j', strtotime($a_filter[3], intval(date_i18n('U'))));
									break;
								case 'month':
									$filters_normalized['date'][$a_filter[1]] = date('n', strtotime($a_filter[3], intval(date_i18n('U'))));
									break;
								case 'year':
									$filters_normalized['date'][$a_filter[1]] = date('Y', strtotime($a_filter[3], intval(date_i18n('U'))));
									break;
								default:
									break;
							}
							if ($filters_normalized['date'][$a_filter[1]] === false){
								unset($filters_normalized['date'][$a_filter[1]]);
							}
						}
						break;
					case 'interval':
						$filters_normalized['date'][$a_filter[1]] = intval($a_filter[3]);
						break;
					case 'direction':
					case 'limit_results':
					case 'start_from':
						$filters_normalized['misc'][$a_filter[1]] = str_replace('\\', '', htmlspecialchars_decode($a_filter[3]));
						break;
					default:
						$filters_normalized['columns'][$a_filter[1]] = array($a_filter[2], isset($a_filter[3])?str_replace('\\', '', htmlspecialchars_decode($a_filter[3])):'');
						break;
				}
			}
		}

		return $filters_normalized;
	}
	
	public static function get_results($_sql = '', $_select_no_aggregate_values = '', $_order_by = '', $_group_by = '', $_aggregate_values_add = ''){
		$_sql = apply_filters('slimstat_get_results_sql', $_sql, $_select_no_aggregate_values, $_order_by, $_group_by, $_aggregate_values_add);

		if (wp_slimstat::$options['show_sql_debug'] == 'yes'){
			self::_show_debug($_sql);
		}

		return wp_slimstat::$wpdb->get_results($_sql, ARRAY_A);
	}
	
	public static function get_var($_sql = '', $_aggregate_value = ''){
		$_sql = apply_filters('slimstat_get_var_sql', $_sql, $_aggregate_value);

		if (wp_slimstat::$options['show_sql_debug'] == 'yes'){
			self::_show_debug($_sql);
		}

		return wp_slimstat::$wpdb->get_var($_sql);
	}

/*
	public static function datetime_offset_chart_filter($_index = 0){
		// Each type has its own parameters
		switch (self::$filters_normalized['utime']['type']){
			case 'H':
				return '';
				break;
			case 'd':
				return 'fs%hour%5D=equals+'.date('H', self::$filters_normalized['utime']['current_start'] + $_index * 3600);
				break;
			case 'Y':
				$previous['start'] = mktime(0, 0, 0, 1, 1, self::$filters_normalized['date']['year']-1);
				$label_date_format = 'Y';
				$group_by = array('YEAR', 'MONTH', 'n');
				$values_in_interval = array(12, 12, 1);
				break;
			default:
				return 'fs%day%5D=equals+'.date('d', self::$filters_normalized['utime']['current_start'] + $_index * 86400).;
				break;
		}

	}
*/

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

	protected static function _show_debug($_message = ''){
		echo "<p class='debug'>$_message</p>";
	}
}