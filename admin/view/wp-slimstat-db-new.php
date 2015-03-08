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
		// Decimal and thousand separators
		if (wp_slimstat::$options['use_european_separators'] == 'no'){
			self::$formats['decimal'] = '.';
			self::$formats['thousand'] = ',';
		}

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
			'minute' => __('Minute','wp-slimstat'),
			'hour' => __('Hour','wp-slimstat'),
			'day' => __('Day','wp-slimstat'),
			'month' => __('Month','wp-slimstat'),
			'year' => __('Year','wp-slimstat'),
			'interval_direction' => __('+/-','wp-slimstat'),
			'interval' => __('days','wp-slimstat'),
			'interval_hours' => __('hours','wp-slimstat'),
			'interval_minutes' => __('minutes','wp-slimstat'),

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

		// Temporarily disable any filter on date_i18n
		$date_i18n_filters = array();
		if (!empty($GLOBALS['wp_filter']['date_i18n'])){
			$date_i18n_filters = $GLOBALS['wp_filter']['date_i18n'];
			remove_all_filters('date_i18n');
		}

		// Date and time ranges
		if (empty(self::$filters_normalized['date']['interval']) && empty(self::$filters_normalized['date']['interval_hours']) && empty(self::$filters_normalized['date']['interval_minutes'])){
			if (!empty(self::$filters_normalized['date']['minute'])){
				self::$filters_normalized['utime']['start'] = mktime(
					!empty(self::$filters_normalized['date']['hour'])?self::$filters_normalized['date']['hour']:0,
					self::$filters_normalized['date']['minute'],
					0,
					!empty(self::$filters_normalized['date']['month'])?self::$filters_normalized['date']['month']:date_i18n('n'),
					!empty(self::$filters_normalized['date']['day'])?self::$filters_normalized['date']['day']:date_i18n('j'),
					!empty(self::$filters_normalized['date']['year'])?self::$filters_normalized['date']['year']:date_i18n('Y')
				);
				self::$filters_normalized['utime']['end'] = self::$filters_normalized['utime']['start'] + 60;
				self::$filters_normalized['utime']['type'] = 'H';
			}
			else if (!empty(self::$filters_normalized['date']['hour'])){
				self::$filters_normalized['utime']['start'] = mktime(
					self::$filters_normalized['date']['hour'],
					0,
					0,
					!empty(self::$filters_normalized['date']['month'])?self::$filters_normalized['date']['month']:date_i18n('n'),
					!empty(self::$filters_normalized['date']['day'])?self::$filters_normalized['date']['day']:date_i18n('j'),
					!empty(self::$filters_normalized['date']['year'])?self::$filters_normalized['date']['year']:date_i18n('Y')
				);
				self::$filters_normalized['utime']['end'] = self::$filters_normalized['utime']['start'] + 3599;
				self::$filters_normalized['utime']['type'] = 'H';
			}
			else if (!empty(self::$filters_normalized['date']['day'])){
				self::$filters_normalized['utime']['start'] = mktime(
					0,
					0,
					0,
					!empty(self::$filters_normalized['date']['month'])?self::$filters_normalized['date']['month']:date_i18n('n'),
					self::$filters_normalized['date']['day'],
					!empty(self::$filters_normalized['date']['year'])?self::$filters_normalized['date']['year']:date_i18n('Y')
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
					!empty(self::$filters_normalized['date']['month'])?self::$filters_normalized['date']['month']:date_i18n('n'),
					1,
					!empty(self::$filters_normalized['date']['year'])?self::$filters_normalized['date']['year']:date_i18n('Y')
				);

				self::$filters_normalized['utime']['end'] = strtotime(
					(!empty(self::$filters_normalized['date']['year'])?self::$filters_normalized['date']['year']:date_i18n('Y')).'-'.
					(!empty(self::$filters_normalized['date']['month'])?self::$filters_normalized['date']['month']:date_i18n('n')).
					'-01 00:00 +1 month UTC'
				)-1;
				self::$filters_normalized['utime']['type'] = 'm';
			}
		}
		else{ // An interval was specified
			self::$filters_normalized['utime']['type'] = 'interval';

			self::$filters_normalized['utime']['start'] = mktime(
				!empty(self::$filters_normalized['date']['hour'])?self::$filters_normalized['date']['hour']:0,
				!empty(self::$filters_normalized['date']['minute'])?self::$filters_normalized['date']['minute']:0,
				0,
				!empty(self::$filters_normalized['date']['month'])?self::$filters_normalized['date']['month']:date_i18n('n'),
				!empty(self::$filters_normalized['date']['day'])?self::$filters_normalized['date']['day']:date_i18n('j'),
				!empty(self::$filters_normalized['date']['year'])?self::$filters_normalized['date']['year']:date_i18n('Y')
			);

			$sign = (self::$filters_normalized['date']['interval_direction'] == 'plus')?'+':'-';

			self::$filters_normalized['utime']['end'] = self::$filters_normalized['utime']['start'] + intval($sign.(
					(!empty(self::$filters_normalized['date']['interval'])?intval(self::$filters_normalized['date']['interval'] + 1):0) * 86400 + 
					(!empty(self::$filters_normalized['date']['interval_hours'])?intval(self::$filters_normalized['date']['interval_hours']):0) * 3600 +
					(!empty(self::$filters_normalized['date']['interval_minutes'])?intval(self::$filters_normalized['date']['interval_minutes']):0) * 60
				)) - 1;

			// Swap boundaries if we're going back
			if (self::$filters_normalized['date']['interval_direction'] == 'minus'){
				list(self::$filters_normalized['utime']['start'], self::$filters_normalized['utime']['end']) = array(self::$filters_normalized['utime']['end'] + 86401, self::$filters_normalized['utime']['start'] + 86399);
			}
		}

		// If end is in the future, set it to now
		if (self::$filters_normalized['utime']['end'] > date_i18n('U')){
			self::$filters_normalized['utime']['end'] = date_i18n('U');
		}
		
		// If start is after end, set it to first of month
		if (self::$filters_normalized['utime']['start'] > self::$filters_normalized['utime']['end']){
			self::$filters_normalized['utime']['start'] = mktime(
				0,
				0,
				0,
				date_i18n('n', self::$filters_normalized['utime']['end']),
				1,
				date_i18n('Y', self::$filters_normalized['utime']['end'])
			);
			self::$filters_normalized['date']['hour'] = self::$filters_normalized['date']['day'] = self::$filters_normalized['date']['month'] = self::$filters_normalized['date']['year'] = 0;
		}
		
		// Restore filters on date_i18n
		foreach ($date_i18n_filters as $i18n_priority => $i18n_func_list) {
			foreach ($i18n_func_list as $func_name => $func_args) {
				add_filter('date_i8n', $func_args['function'], $i18n_priority, $func_args['accepted_args']);
			}
		}
		
		// Now let's translate our filters into SQL clauses
		self::$sql_filters = array(
			'table_info' => array(
				'tb' => array('slim_browsers', 'browser_id'),
				'tci' => array('slim_content_info', 'content_info_id'),
				'tob' => array('slim_outbound', 'outbound_id'),
				'tss' => array('slim_screenres', 'screenres_id'),
			),

			'from' => array(
				't1' => "{$GLOBALS['wpdb']->prefix}slim_stats t1",

				'all' => '',
				'all_others' => ''
			),

			'where' => array(
				't1' => '',
				'tb' => array('browser_id' => ''),
				'tci' => array('content_info_id' => ''),
				'tob' => array('outbound_id' => ''),
				'tss' => array('screenres_id' => ''),

				'all' => '',
				'time_range' => ' AND (t1.dt BETWEEN '.self::$filters_normalized['utime']['start'].' AND '.self::$filters_normalized['utime']['end'].')'
			),
			
			'id' => array(
				'tb' => '',
				'tci' => '',
				'tob' => '',
				'tss' => ''
			)
		);

		foreach (self::$filters_normalized['columns'] as $a_filter_column => $a_filter_data){
			// Add-ons can set their own custom filters, which are ignored here
			if (strpos($a_filter_column, 'addon_') !== false){
				continue;
			}

			$filter_empty = '0';

			// Table this column belongs to
			$table_alias = self::get_table_alias($a_filter_column);

			// Some columns require a special treatment
			switch($a_filter_column){
				case 'ip':
				case 'other_ip':
					$a_filter_column = "INET_NTOA($a_filter_column)";
					$filter_empty = '0.0.0.0';
					break;
				default:
					$a_filter_column = $table_alias.'.'.$a_filter_column;
					break;
			}

			switch ($a_filter_data[0]){
				case 'is_not_equal_to':
					self::$sql_filters['where'][$table_alias][$a_filter_column] = $GLOBALS['wpdb']->prepare("$a_filter_column <> %s", $a_filter_data[1]);
					break;
				case 'contains':
					self::$sql_filters['where'][$table_alias][$a_filter_column] = $GLOBALS['wpdb']->prepare("$a_filter_column LIKE %s", '%'.$a_filter_data[1].'%');
					break;
				case 'includes_in_set':
					self::$sql_filters['where'][$table_alias][$a_filter_column] = $GLOBALS['wpdb']->prepare("FIND_IN_SET(%s, $a_filter_column) > 0", $a_filter_data[1]);
					break;
				case 'does_not_contain':
					self::$sql_filters['where'][$table_alias][$a_filter_column] = $GLOBALS['wpdb']->prepare("$a_filter_column NOT LIKE %s", '%'.$a_filter_data[1].'%');;
					break;
				case 'starts_with':
					self::$sql_filters['where'][$table_alias][$a_filter_column] = $GLOBALS['wpdb']->prepare("$a_filter_column LIKE %s", $a_filter_data[1].'%');
					break;
				case 'ends_with':
					self::$sql_filters['where'][$table_alias][$a_filter_column] = $GLOBALS['wpdb']->prepare("$a_filter_column LIKE %s", '%'.$a_filter_data[1]);
					break;
				case 'sounds_like':
					self::$sql_filters['where'][$table_alias][$a_filter_column] = $GLOBALS['wpdb']->prepare("SOUNDEX($a_filter_column) = SOUNDEX(%s)", $a_filter_data[1]);
					break;
				case 'is_empty':
					self::$sql_filters['where'][$table_alias][$a_filter_column] = "($a_filter_column = '' OR $a_filter_column = '$filter_empty')";
					break;
				case 'is_not_empty':
					self::$sql_filters['where'][$table_alias][$a_filter_column] = "($a_filter_column <> '' AND $a_filter_column <> '$filter_empty')";
					break;
				case 'is_greater_than':
					self::$sql_filters['where'][$table_alias][$a_filter_column] = $GLOBALS['wpdb']->prepare("$a_filter_column > %d", $a_filter_data[1]);
					break;
				case 'is_less_than':
					self::$sql_filters['where'][$table_alias][$a_filter_column] = $GLOBALS['wpdb']->prepare("$a_filter_column < %d", $a_filter_data[1]);
					break;
				case 'between':
					$range = explode(',', $a_filter_data[1]);
					self::$sql_filters['where'][$table_alias][$a_filter_column] = $GLOBALS['wpdb']->prepare("$a_filter_column BETWEEN %d AND %d", $range[0], $range[1]);
					break;
				case 'matches':
					self::$sql_filters['where'][$table_alias][$a_filter_column] = $GLOBALS['wpdb']->prepare("$a_filter_column REGEXP %s", $a_filter_data[1]);
					break;
				case 'does_not_match':
					self::$sql_filters['where'][$table_alias][$a_filter_column] = $GLOBALS['wpdb']->prepare("$a_filter_column NOT REGEXP %s", $a_filter_data[1]);
					break;
				default:
					self::$sql_filters['where'][$table_alias][$a_filter_column] = $GLOBALS['wpdb']->prepare("$a_filter_column = %s", $a_filter_data[1]);
			}
		}

		// Get the IDs from the lookup tables
		foreach (array_keys(self::$sql_filters['id']) as $a_table_alias){
			if (!empty(self::$sql_filters['where'][$a_table_alias])){
				self::$sql_filters['id'][$a_table_alias] = self::get_results('
					SELECT *
					FROM '.$GLOBALS['wpdb']->base_prefix.self::$sql_filters['table_info'][$a_table_alias][0].' '.$a_table_alias.'
					WHERE '.implode(' AND ', self::$sql_filters['where'][$a_table_alias]));

				if (!empty(self::$sql_filters['id'][$a_table_alias])){
					$table_ids = array();
					foreach (self::$sql_filters['id'][$a_table_alias] as $a_result){
						$table_ids[] = $a_result[self::$sql_filters['table_info'][$a_table_alias][1]];
					}
					self::$sql_filters['where'][$a_table_alias][self::$sql_filters['table_info'][$a_table_alias][1]] = 't1.'.self::$sql_filters['table_info'][$a_table_alias][1].' IN ('.implode(',', $table_ids).')';
				}
			}
		}

		// self::$sql_filters['from']['all_others'] = trim(self::$sql_filters['from']['tb'].' '.self::$sql_filters['from']['tci'].' '.self::$sql_filters['from']['tob'].' '.self::$sql_filters['from']['tss']);
		// self::$sql_filters['from']['all'] = "{$GLOBALS['wpdb']->prefix}slim_stats t1 ".self::$sql_filters['from']['all_others'];

		self::$sql_filters['where']['all'] = trim(
			self::$sql_filters['where']['t1'].' '.
			self::$sql_filters['where']['tb']['browser_id'].' '.
			self::$sql_filters['where']['tci']['content_info_id'].' '.
			self::$sql_filters['where']['tob']['outbound_id'].' '.
			self::$sql_filters['where']['tss']['screenres_id']);
	}
	// end init

	// The following methods retrieve the information from the database

	public static function count_bouncing_pages(){
		return intval(self::get_var('
			SELECT COUNT(*) counthits
				FROM (
					SELECT t1.resource
					FROM '.self::$sql_filters['from']['all'].' '.self::_add_filters_to_sql_from('tci.content_type').'
					WHERE t1.visit_id <> 0 AND t1.resource <> "" AND tci.content_type <> "404" '.self::$sql_filters['where']['all'].' '.self::$sql_filters['where']['time_range'].'
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
					FROM '.self::$sql_filters['from']['all'].'
					WHERE visit_id > 0 AND resource <> "" '.self::$sql_filters['where']['all'].' '.self::$sql_filters['where']['time_range'].'
					GROUP BY visit_id
					HAVING dt = MAX(dt)
				) AS ts1',
			'SUM(counthits) AS counthits'));
	}

	public static function count_records($_where_clause = '1=1', $_distinct_column = '*', $_use_filters = true, $_use_date_filters = true, $_join_tables = ''){
		$column = ($_distinct_column != '*')?"DISTINCT $_distinct_column":$_distinct_column;
		return intval(self::get_var("
			SELECT COUNT($column) counthits
			FROM {$GLOBALS['wpdb']->prefix}slim_stats t1 ".($_use_filters?self::$sql_filters['from']['all_others']:'').' '.self::_add_filters_to_sql_from($_where_clause.$_join_tables).'
			WHERE '.(!empty($_where_clause)?$_where_clause:'1=1').' '.($_use_filters?self::$sql_filters['where']['all']:'').' '.($_use_date_filters?self::$sql_filters['where']['time_range']:''),
			'SUM(counthits) AS counthits'));
	}

	public static function count_outbound(){
		return intval(self::get_var("
			SELECT COUNT(outbound_id) counthits
			FROM {$GLOBALS['wpdb']->prefix}slim_stats t1 INNER JOIN {$GLOBALS['wpdb']->prefix}slim_outbound tob ON t1.id = tob.id ".self::$sql_filters['from']['all_others']."
			WHERE 1=1 ".self::$sql_filters['where']['all'].' '.self::$sql_filters['where']['time_range'],
			'SUM(counthits) AS counthits'));
	}

	public static function count_records_having($_where_clause = '1=1', $_column = 't1.ip', $_having_clause = ''){
		return intval(self::get_var("
			SELECT COUNT(*) counthits FROM (
				SELECT $_column
				FROM ".self::$sql_filters['from']['all'].' '.self::_add_filters_to_sql_from($_where_clause)."
				WHERE $_where_clause ".self::$sql_filters['where']['all'].' '.self::$sql_filters['where']['time_range']."
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
				FROM '.self::$sql_filters['from']['all'].'
				WHERE visit_id > 0 '.self::$sql_filters['where']['all'].' '.self::$sql_filters['where']['time_range'].'
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
			FROM {$GLOBALS['wpdb']->prefix}slim_stats t1 ".($_use_filters?self::$sql_filters['from']['all_others']:'').' '.self::_add_filters_to_sql_from($_where_clause).'
			WHERE '.(!empty($_where_clause)?$_where_clause:'1=1').' '.($_use_filters?self::$sql_filters['where']['all']:'').'
			ORDER BY dt ASC
			LIMIT 0,1',
			'MIN(dt)');
	}

	public static function get_popular($_column = 't1.id', $_custom_where = '', $_more_columns = '', $_having_clause = '', $_as_column = ''){
		return self::get_results("
			SELECT $_column ".(!empty($_as_column)?'AS '.$_as_column:'')." $_more_columns, COUNT(*) counthits
			FROM ".self::$sql_filters['from']['all'].' '.self::_add_filters_to_sql_from($_column.$_custom_where.$_more_columns).'
			WHERE '.(empty($_custom_where)?"$_column <> '' ":$_custom_where).' '.self::$sql_filters['where']['all'].' '.self::$sql_filters['where']['time_range']."
			GROUP BY $_column $_having_clause
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
			FROM {$GLOBALS['wpdb']->prefix}slim_stats t1 INNER JOIN {$GLOBALS['wpdb']->prefix}slim_outbound tob ON t1.id = tob.id ".self::$sql_filters['from']['tb'].' '.self::$sql_filters['from']['tss'].' '.self::$sql_filters['from']['tci']."
			WHERE (tob.type = 0 OR tob.type = 1) ".self::$sql_filters['where']['all'].' '.self::$sql_filters['where']['time_range'].'
			GROUP BY tob.outbound_resource 
			ORDER BY counthits '.self::$filters_normalized['misc']['direction'].'
			LIMIT '.self::$filters_normalized['misc']['start_from'].', '.self::$filters_normalized['misc']['limit_results'],
			'blog_id, resource',
			'counthits '.self::$filters_normalized['misc']['direction'],
			'outbound_resource',
			'SUM(counthits) AS counthits');
	}

	public static function get_popular_complete($_column = 't1.id', $_custom_where = '', $_join_tables = '', $_having_clause = '', $_outer_select_column = '', $_max_min = 'MAX'){
		$column_for_select = empty($_outer_select_column)?$_column:$_outer_select_column;
		return self::get_results("
			SELECT $column_for_select, ts1.maxid, COUNT(*) counthits
			FROM (
				SELECT $_column, $_max_min(t1.id) maxid
				FROM ".self::$sql_filters['from']['all'].' '.self::_add_filters_to_sql_from($_column.$_custom_where).'
				WHERE '.(empty($_custom_where)?"$_column <> '' ":$_custom_where).' '.self::$sql_filters['where']['all'].' '.self::$sql_filters['where']['time_range']."
				GROUP BY $_column $_having_clause
			) AS ts1 JOIN {$GLOBALS['wpdb']->prefix}slim_stats t1 ON ts1.maxid = t1.id ".
			(!empty($_join_tables)?self::_add_filters_to_sql_from($_join_tables):'')."
			GROUP BY $column_for_select
			ORDER BY counthits ".self::$filters_normalized['misc']['direction'].'
			LIMIT '.self::$filters_normalized['misc']['start_from'].', '.self::$filters_normalized['misc']['limit_results'],
			$column_for_select,
			'counthits '.self::$filters_normalized['misc']['direction'],
			$column_for_select,
			'MAX(maxid), SUM(counthits)');
	}

	public static function get_recent($_column = 't1.id', $_custom_where = '', $_more_columns = '', $_having_clause = '', $_order_by = '', $_use_date_filters = true){
		if ($_column == 't1.id'){
			return self::get_results('
				SELECT t1.*'.(!empty($_more_columns)?', '.$_more_columns:'').'
				FROM '.self::$sql_filters['from']['t1'].'
				WHERE '.(empty($_custom_where)?"$_column <> 0 ":$_custom_where).' '.self::$sql_filters['where']['all'].' '.($_use_date_filters?self::$sql_filters['where']['time_range']:'').'
				ORDER BY '.(empty($_order_by)?'t1.dt '.self::$filters_normalized['misc']['direction']:$_order_by).'
				LIMIT '.self::$filters_normalized['misc']['start_from'].', '.self::$filters_normalized['misc']['limit_results'],
				'*',
				empty($_order_by)?'t1.dt '.self::$filters_normalized['misc']['direction']:$_order_by);
			
		}
		else{
			$table_alias = self::get_table_alias($_column);
			if ($table_alias != 't1'){
				$group_by = self::$sql_filters['table_info'][$table_alias][1];
			}

			$where_ids = self::_add_ids_to_sql_where($_custom_where, $_column);
			// WHERE '.(empty($_custom_where)?"$_column <> '' ":$_custom_where).' '.self::$sql_filters['where']['all'].' '.($_use_date_filters?self::$sql_filters['where']['time_range']:'')."
			
			
			return self::get_results('
				SELECT t1.*
				FROM (
					SELECT MAX(t1.id) maxid
					FROM '.self::$sql_filters['from']['t1'].'
					WHERE '.$where_ids.' '.($_use_date_filters?self::$sql_filters['where']['time_range']:'').'
					GROUP BY '.$group_by.' '.
					$_having_clause.'
				) AS ts1 INNER JOIN '.$GLOBALS['wpdb']->prefix.'slim_stats t1 ON ts1.maxid = t1.id '.
				(!empty($_more_columns)?self::_add_filters_to_sql_from($_more_columns):'').'
				ORDER BY '.(empty($_order_by)?'t1.dt '.self::$filters_normalized['misc']['direction']:$_order_by).'
				LIMIT '.self::$filters_normalized['misc']['start_from'].', '.self::$filters_normalized['misc']['limit_results'],
				't1.*, '.(!empty($_more_columns)?$_more_columns:'ts1.maxid'),
				empty($_order_by)?'t1.dt '.self::$filters_normalized['misc']['direction']:$_order_by);
		}
	}

	public static function get_recent_outbound($_type = -1){
		return self::get_results("
			SELECT tob.outbound_id as visit_id, tob.outbound_domain, tob.outbound_resource as resource, tob.type, tob.notes, t1.ip, t1.other_ip, t1.user, 'local' as domain, t1.resource as referer, t1.country, tb.browser, tb.version, tb.platform, tob.dt
			FROM {$GLOBALS['wpdb']->prefix}slim_stats t1 INNER JOIN {$GLOBALS['wpdb']->prefix}slim_outbound tob ON tob.id = t1.id INNER JOIN {$GLOBALS['wpdb']->base_prefix}slim_browsers tb on t1.browser_id = tb.browser_id ".self::$sql_filters['from']['tci'].' '.self::$sql_filters['from']['tss'].'
			WHERE '.(($_type != -1)?"tob.type = $_type":'tob.type > 1').' '.self::$sql_filters['where']['all'].' '.self::$sql_filters['where']['time_range'].'
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
				$label_date_format = wp_slimstat::$options['time_format'];
				$group_by = array('HOUR', 'MINUTE', 'i');
				$values_in_interval = array(59, 59, 0, 60); 
				break;
			case 'd':
				$previous['start'] = self::$filters_normalized['utime']['start'] - 86400;
				$label_date_format = (self::$formats['decimal'] == '.')?'m/d':'d/m';
				$group_by = array('DAY', 'HOUR', 'G');
				$values_in_interval = array(23, 23, 0, 3600);
				break;
			case 'Y':
				$previous['start'] = mktime(0, 0, 0, 1, 1, self::$filters_normalized['date']['year']-1);
				$label_date_format = 'Y';
				$group_by = array('YEAR', 'MONTH', 'n');
				$values_in_interval = array(12, 12, 1, 2678400);
				break;
			case 'interval':
				$group_by = array('MONTH', 'DAY', 'j');
				$values_in_interval = array(abs(self::$filters_normalized['date']['interval']), abs(self::$filters_normalized['date']['interval']), 0, 86400);
				break;
			default:
				$previous['start'] = mktime(0, 0, 0, (!empty(self::$filters_normalized['date']['month'])?self::$filters_normalized['date']['month']:date_i18n('n'))-1, 1, !empty(self::$filters_normalized['date']['year'])?self::$filters_normalized['date']['year']:date_i18n('Y'));
				$label_date_format = 'm/Y';
				$group_by = array('MONTH', 'DAY', 'j');
				$values_in_interval = array(date('t', $previous['start']), date('t', self::$filters_normalized['utime']['start']), 1, 86400);
				break;
		}

		// Custom intervals don't have a comparison chart ('previous' range)
		$time_range = self::$sql_filters['where']['time_range'];
		if (empty(self::$filters_normalized['date']['interval'])){
			$time_range = 'AND (t1.dt BETWEEN '.$previous['start'].' AND '.$previous['end'].' OR t1.dt BETWEEN '.self::$filters_normalized['utime']['start'].' AND '.self::$filters_normalized['utime']['end'].')';
		}

		// Build the SQL query
		$sql = "SELECT t1.dt, $_data1 first_metric, $_data2 second_metric";

		// Panel 4 has a slightly different structure
		if(empty($_sql_from_where)){
			$sql .= '	FROM '.self::$sql_filters['from']['all'].' '.self::_add_filters_to_sql_from($_data1.$_data2.$_custom_where_clause)."
						WHERE 1=1 $time_range ".self::$sql_filters['where']['all'].' '.$_custom_where_clause;
		}
		else{
			$sql_no_placeholders = str_replace('[from_tables]', self::$sql_filters['from']['all'].' '.self::_add_filters_to_sql_from($_data1.$_data2.$_custom_where_clause), $_sql_from_where);
			$sql_no_placeholders = str_replace('[where_clause]', '1=1 '.$time_range.' '.self::$sql_filters['where']['all'].' '.$_custom_where_clause, $sql_no_placeholders);
			$sql .= $sql_no_placeholders;
		}

		$group_by_string = "{$group_by[0]}(CONVERT_TZ(FROM_UNIXTIME(t1.dt), @@session.time_zone, '+00:00')), {$group_by[1]}(CONVERT_TZ(FROM_UNIXTIME(t1.dt), @@session.time_zone, '+00:00'))";
		$sql .= " GROUP BY $group_by_string";

		// Get the data
		$results = self::get_results($sql, 'blog_id', '', $group_by_string, 'SUM(first_metric) AS first_metric, SUM(second_metric) AS second_metric');

		// Fill the output array
		$output['current']['label'] = '';
		if (!empty($label_date_format)){
			$output['current']['label'] = gmdate($label_date_format, self::$filters_normalized['utime']['start']);
			$output['previous']['label'] = gmdate($label_date_format, $previous['start']);
		}

		$output['previous']['first_metric'] = array_fill($values_in_interval[2], $values_in_interval[0], 0);
		$output['previous']['second_metric'] = array_fill($values_in_interval[2], $values_in_interval[0], 0);


		$today_limit = floatval(date_i18n('Ymd.Hi'));
		for ($i = $values_in_interval[2]; $i <= $values_in_interval[1]; $i++){
			// Do not include dates in the future
			
			$floatval = floatval(date('Ymd.Hi', wp_slimstat_db::$filters_normalized['utime']['start'] + ( ($i - $values_in_interval[2]) * $values_in_interval[3])));
			
			if ($floatval > $today_limit){
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
			$index = (!empty(self::$filters_normalized['date']['interval']))?floor(($a_result['dt'] - wp_slimstat_db::$filters_normalized['utime']['start'])/86400):gmdate($group_by[2], $a_result['dt']);

			if (empty(self::$filters_normalized['date']['interval']) && gmdate(self::$filters_normalized['utime']['type'], $a_result['dt']) == gmdate(self::$filters_normalized['utime']['type'], $previous['start'])){
				$output['previous']['first_metric'][$index] = $a_result['first_metric'];
				$output['previous']['second_metric'][$index] = $a_result['second_metric'];
			}
			if (!empty(self::$filters_normalized['date']['interval']) || gmdate(self::$filters_normalized['utime']['type'], $a_result['dt']) == gmdate(self::$filters_normalized['utime']['type'], self::$filters_normalized['utime']['start'])){
				$output['current']['first_metric'][$index] = $a_result['first_metric'];
				$output['current']['second_metric'][$index] = $a_result['second_metric'];
			}
		}

		return ($output);
	}

	public static function parse_filters($_filters = '', $_init_misc = true){
		$filters_normalized = array(
			'columns' => array(),
			'date' => array(
				'interval_direction' => '',
				'is_past' => false
			),
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

				if (empty($a_filter) || ((!array_key_exists($a_filter[1], self::$filter_names) || strpos($a_filter[1], 'no_filter') !== false) && strpos($a_filter[1], 'addon_') === false)){
					continue;
				}

				switch($a_filter[1]){
					case 'strtotime':
						$custom_date = strtotime($a_filter[3].' UTC');
						$filters_normalized['date']['day'] = date('j', $custom_date);
						$filters_normalized['date']['month'] = date('n', $custom_date);
						$filters_normalized['date']['year'] = date('Y', $custom_date);
						break;
					case 'minute':
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
								case 'minute':
									$filters_normalized['date']['minute'] = date('i', strtotime($a_filter[3], date_i18n('U')));
									$filters_normalized['date']['is_past'] = true;
									break;
								case 'hour':
									$filters_normalized['date']['hour'] = date('H', strtotime($a_filter[3], date_i18n('U')));
									$filters_normalized['date']['is_past'] = true;
									break;
								case 'day':
									$filters_normalized['date']['day'] = date('j', strtotime($a_filter[3], date_i18n('U')));
									break;
								case 'month':
									$filters_normalized['date']['month'] = date('n', strtotime($a_filter[3], date_i18n('U')));
									break;
								case 'year':
									$filters_normalized['date']['year'] = date('Y', strtotime($a_filter[3], date_i18n('U')));
									break;
								default:
									break;
							}
							
							if ($filters_normalized['date'][$a_filter[1]] === false){
								unset($filters_normalized['date'][$a_filter[1]]);
							}
						}

						switch($a_filter[1]){
							case 'day':
								if ($filters_normalized['date']['day'] != date_i18n('j')){
									$filters_normalized['date']['is_past'] = true;
								}
								break;
							case 'month':
								if ($filters_normalized['date']['month'] != date_i18n('n')){
									$filters_normalized['date']['is_past'] = true;
								}
								break;
							case 'year':
								if ($filters_normalized['date']['year'] != date_i18n('Y')){
									$filters_normalized['date']['is_past'] = true;
								}
								break;
							default:
								break;
						}
						
						break;
					case 'interval':
					case 'interval_hours':
					case 'interval_minutes':
						$intval_filter = intval($a_filter[3]);
						$filters_normalized['date'][$a_filter[1]] = abs($intval_filter);
						if ($intval_filter < 0){
							$filters_normalized['date']['interval_direction'] = 'minus';
						}
						break;
					case 'interval_direction':
						$filters_normalized['date'][$a_filter[1]] = in_array($a_filter[3], array('plus', 'minus'))?$a_filter[3]:'plus';
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

	/**
	 * Associates tables and their 'SQL aliases'
	 */
	public static function get_table_alias($_field = 'id'){
		switch($_field){
			case 'browser_id':
			case 'browser':
			case 'version':
			case 'css_version':
			case 'type':
			case 'platform':
			case 'user_agent':
				return 'tb';
				break;
			case 'content_info_id':
			case 'author':
			case 'category':
			case 'content_type':
			case 'content_id':
				return 'tci';
				break;
			case 'outbound_id':
			case 'outbound_domain':
			case 'outbound_resource':
			case 'position':
				return 'tob';
				break;
			case 'screenres_id':
			case 'resolution':
			case 'colordepth':
			case 'antialias':
				return 'tss';
				break;
			default:
				return 't1';
				break;
		}	
	}
	// end get_table_alias

	public static function get_col($_sql = ''){
		$_sql = apply_filters('slimstat_get_col_sql', $_sql);

		if (wp_slimstat::$options['show_sql_debug'] == 'yes'){
			self::_show_debug($_sql);
		}

		return wp_slimstat::$wpdb->get_col($_sql);
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

	protected static function _add_filters_to_sql_from($_sql_tables = '', $_ignore_empty = false){
		$sql_from = '';
		if (($_ignore_empty || empty(self::$sql_filters['from']['tb'])) && strpos($_sql_tables, 'tb.') !== false)
			$sql_from .= " INNER JOIN {$GLOBALS['wpdb']->base_prefix}slim_browsers tb ON t1.browser_id = tb.browser_id";

		if (($_ignore_empty || empty(self::$sql_filters['from']['tci'])) && strpos($_sql_tables, 'tci.') !== false)
			$sql_from .=  " INNER JOIN {$GLOBALS['wpdb']->base_prefix}slim_content_info tci ON t1.content_info_id = tci.content_info_id";

		if (($_ignore_empty || empty(self::$sql_filters['from']['tob'])) && strpos($_sql_tables, 'tob.') !== false)
			$sql_from .=  " LEFT JOIN {$GLOBALS['wpdb']->prefix}slim_outbound tob ON t1.id = tob.id";

		if (($_ignore_empty || empty(self::$sql_filters['from']['tss'])) && strpos($_sql_tables, 'tss.') !== false)
			$sql_from .=  " LEFT JOIN {$GLOBALS['wpdb']->base_prefix}slim_screenres tss ON t1.screenres_id = tss.screenres_id";
		
		return $sql_from;
	}

	protected static function _get_lookup_rows($_where = array(), $_group_by_column = ''){
		foreach ($_custom_where as $a_table_column => $a_filter_column){
			$table_alias = self::get_table_alias($a_table_column);

			$where_ids = self::get_results('
				SELECT *
				FROM '.$GLOBALS['wpdb']->base_prefix.self::$sql_filters['table_info'][$a_table_alias][0].' '.$a_table_alias.'
				WHERE 1=1'.self::$sql_filters['where'][$a_table_alias]);
			
			if (!empty(self::$sql_filters['id'][$a_table_alias])){
				$table_ids = array();
				foreach (self::$sql_filters['id'][$a_table_alias] as $a_result){
					$table_ids[] = $a_result[self::$sql_filters['table_info'][$a_table_alias][1]];
				}
				self::$sql_filters['where'][$a_table_alias] = ' AND t1.'.self::$sql_filters['table_info'][$a_table_alias][1].' IN ('.implode(',', $table_ids).')';
			}
		}
	}

	protected static function _show_debug($_message = ''){
		echo "<p class='debug'>$_message</p>";
	}
}