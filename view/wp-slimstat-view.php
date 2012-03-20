<?php

// Let's define the main class with all the methods that we need
class wp_slimstat_view {

	public function __construct($user_filters = array()){
		global $wpdb;

		// We use three of tables to store data about visits
		$this->table_stats = $wpdb->prefix . 'slim_stats';
		$this->table_outbound = $wpdb->prefix . 'slim_outbound';

		// Some tables can be shared among the various installations (wordpress network)
		$this->table_browsers = $wpdb->base_prefix . 'slim_browsers';
		$this->table_screenres = $wpdb->base_prefix . 'slim_screenres';

		// Start from...
		$this->starting_from = 0;

		// Limit results to...
		$this->limit_results = empty($user_filters['limit_results'])?$GLOBALS['wp_slimstat']->options['rows_to_show']:intval($user_filters['limit_results']);

		// Date format
		$this->date_time_format = get_option('date_format', 'd-m-Y').' '.get_option('time_format', 'g:i a');

		// It looks like WP_PLUGIN_URL doesn't honor the HTTPS setting in wp-config.php
		$this->plugin_url = (is_ssl()?str_replace('http://', 'https://', WP_PLUGIN_URL):WP_PLUGIN_URL).'/wp-slimstat';

		// Base DOMAIN for this blog
		$this->blog_domain = get_bloginfo('url');
		$this->blog_domain = is_ssl()?str_replace('http://', 'https://', $this->blog_domain):$this->blog_domain;
		if (strpos(substr($this->blog_domain, 8), '/') > 0) $this->blog_domain = substr($this->blog_domain, 0, 8+strpos(substr($this->blog_domain, 8), '/'));

		// Calculate filters
		$this->filters_to_parse = array(
			'country' => array('string', 't1.'),
			'domain' => array('string', 't1.'),
			'ip' => array('string', 't1.'),
			'other_ip' => array('string', 't1.'),
			'user' => array('string', 't1.'),
			'visit_id' => array('string', 't1.'),
			'language' => array('string', 't1.'),
			'resource' => array('string', 't1.'),
			'referer' => array('string', 't1.'),
			'searchterms' => array('string', 't1.'),
			'browser' => array('string', 'tb.'),
			'version' => array('string', 'tb.'),
			'css_version' => array('string', 'tb.'),
			'type' => array('integer', 'tb.'),
			'platform' => array('string', 'tb.'),
			'resolution' => array('string', 'tss.'),
			'colordepth' => array('string', 'tss.'),
			'day' => array('integer', ''),
			'month' => array('integer', ''),
			'year' => array('integer', ''),
			'interval' => array('integer', ''),
			'author' => array('string', ''),
			'category-id' => array('integer', ''),
			'limit-results' => array('integer', '')
		);

		// Avoid warnings in strict mode
		$this->filters_parsed = array();
		$this->day_filter_active = false;
		$this->filters_query = '';
		$this->current_date = array();

		$this->direction = 'DESC';
		if (!empty($_GET['direction']) && $_GET['direction'] == 'ASC') $this->direction = 'ASC';

		// Decimal and thousands separators
		$this->decimal_separator = ','; $this->thousand_separator = '.';
		if ($GLOBALS['wp_slimstat']->options['use_european_separators'] == 'no') {
			$this->decimal_separator = '.'; $this->thousand_separator = ',';
		}

		foreach ($this->filters_to_parse as $a_filter_label => $a_filter_details){
			if (isset($user_filters[$a_filter_label.'-op'])){
				// Avoid PHP warnings
				if (empty($user_filters[$a_filter_label]))
					$f_value = '';
				else
					$f_value = $user_filters[$a_filter_label];
				$f_operator = $user_filters[$a_filter_label.'-op'];
			}
			// Data coming from the INPUT FORM
			elseif (isset($_GET['filter']) && $_GET['filter']==$a_filter_label){
				if (empty($_GET['f_value'])){
					if (in_array($a_filter_label, array('day','month','year','interval'))) continue;
					$f_value = '';
				}
				else
					$f_value = $_GET['f_value'];
				$f_operator = empty($_GET['f_operator'])?'equals':$_GET['f_operator'];
			}
			// Other filters previously set by the user
			elseif (isset($_GET[$a_filter_label.'-op']) || isset($_GET[$a_filter_label])){
				if (empty($_GET[$a_filter_label])){
					if (in_array($a_filter_label, array('day','month','year','interval'))) continue;
					$f_value = '';
				}
				else
					$f_value = $_GET[$a_filter_label];
				$f_operator = empty($_GET[$a_filter_label.'-op'])?'equals':$_GET[$a_filter_label.'-op'];
			}
			else continue;
			$f_value = ($a_filter_details[0] == 'integer')?abs(intval($f_value)):$wpdb->escape(str_replace('\\', '', htmlspecialchars_decode(urldecode($f_value))));
			$f_operator = $wpdb->escape(htmlspecialchars(str_replace('\\', '', $f_operator)));
			$this->filters_parsed[$a_filter_label] = array($f_value, $f_operator, $a_filter_details[1]);
			$this->filters_query .= "&$a_filter_label=$f_value&$a_filter_label-op=$f_operator";
		}

		// Date filter
		date_default_timezone_set('UTC');
		if (!empty($this->filters_parsed['day'][0])){
			$this->current_date['d'] = sprintf('%02d', $this->filters_parsed['day'][0]);
			if (empty($this->filters_parsed['interval'][0]))
				$this->day_filter_active = true;
			else
				$this->day_interval = abs($this->filters_parsed['interval'][0]);
		}
		else{
			$this->current_date['d'] = date_i18n('d');
		}

		if (!empty($this->filters_parsed['month'][0])){
			$this->current_date['m'] = sprintf('%02d', $this->filters_parsed['month'][0]);
		}
		else{
			$this->current_date['m'] = date_i18n('m');
		}

		if (!empty($this->filters_parsed['year'][0])){
			$this->current_date['y'] = sprintf('%04d', $this->filters_parsed['year'][0]);
		}
		else{
			$this->current_date['y'] = date_i18n('Y');
		}

		$this->current_date['h'] = date_i18n('H');
		$this->current_date_utime_start = strtotime("{$this->current_date['y']}/{$this->current_date['m']}/{$this->current_date['d']} 00:00");

		$this->yesterday['d'] = date_i18n('d', strtotime("{$this->current_date['y']}-{$this->current_date['m']}-".($this->current_date['d'] - 1)) );
		$this->yesterday['m'] = date_i18n('m', strtotime("{$this->current_date['y']}-{$this->current_date['m']}-".($this->current_date['d'] - 1)) );
		$this->yesterday['y'] = date_i18n('Y', strtotime("{$this->current_date['y']}-{$this->current_date['m']}-".($this->current_date['d'] - 1)) );
		$this->yesterday_utime_start = strtotime("{$this->yesterday['y']}/{$this->yesterday['m']}/{$this->yesterday['d']} 00:00");
		$this->yesterday_utime_end = $this->yesterday_utime_start + 86399;

		$this->previous_month['m'] = date_i18n('m', strtotime("{$this->current_date['y']}-".($this->current_date['m'] - 1)."-01") );
		$this->previous_month['y'] = date_i18n('Y', strtotime("{$this->current_date['y']}-".($this->current_date['m'] - 1)."-01") );
		$this->previous_month_utime_start = strtotime("{$this->previous_month['y']}/{$this->previous_month['m']}/01 00:00");
		$this->previous_month_utime_end = strtotime("{$this->previous_month['y']}/{$this->previous_month['m']}/01 +1 month")-1;

		$this->filters_sql_from = array('browsers' => '', 'screenres' => '');
		$this->filters_sql_where = '';
		if (!empty($this->filters_parsed)){
			foreach ($this->filters_parsed as $a_filter_label => $a_filter_details){
				// Skip filters on date and author
				if (($a_filter_label != 'day') && ($a_filter_label != 'month') && ($a_filter_label != 'year') && ($a_filter_label != 'interval') &&
					($a_filter_label != 'author') && ($a_filter_label != 'category-id')){

					// Filters on the IP address require a special treatment
					if ($a_filter_label == 'ip' || $a_filter_label == 'other_ip'){
						$a_filter_column = "INET_NTOA($a_filter_label)";
						$a_filter_empty = '0.0.0.0';
					}
					else{
						// $a_filter_details[2] is the table identifier (t1 = wp_slim_stats, tb = wp_slim_browsers, tss = wp_slim_screenres)
						$a_filter_column = "{$a_filter_details[2]}$a_filter_label";
						$a_filter_empty = '0';
					}

					switch ($a_filter_details[1]){
						case 'not equal to':
							$this->filters_sql_where .= " AND $a_filter_column <> '{$a_filter_details[0]}'";
							break;
						case 'contains':
							$this->filters_sql_where .= " AND $a_filter_column LIKE '%{$a_filter_details[0]}%'";
							break;
						case 'does not contain':
							$this->filters_sql_where .= " AND $a_filter_column NOT LIKE '%{$a_filter_details[0]}%'";
							break;
						case 'starts with':
							$this->filters_sql_where .= " AND $a_filter_column LIKE '{$a_filter_details[0]}%'";
							break;
						case 'ends with':
							$this->filters_sql_where .= " AND $a_filter_column LIKE '%{$a_filter_details[0]}'";
							break;
						case 'sounds like':
							$this->filters_sql_where .= " AND SOUNDEX($a_filter_column) = SOUNDEX('{$a_filter_details[0]}')";
							break;
						case 'is empty':
							$this->filters_sql_where .= " AND $a_filter_column = ''";
							break;
						case 'is not empty':
							$this->filters_sql_where .= " AND $a_filter_column <> '' AND $a_filter_column <> '$a_filter_empty'";
							break;
						default:
							$this->filters_sql_where .= " AND $a_filter_column = '{$a_filter_details[0]}'";
					}
				}

				// I know, this is not the best way of handling these two filters...
				if ($a_filter_label == 'author'){
					switch ($a_filter_details[1]){
						case 'not equal to':
							$user_filters_sql_where = "tu.user_login <> '{$a_filter_details[0]}'";
							break;
						case 'contains':
							$user_filters_sql_where = "tu.user_login LIKE '%{$a_filter_details[0]}%'";
							break;
						case 'does not contain':
							$user_filters_sql_where = "tu.user_login NOT LIKE '%{$a_filter_details[0]}%'";
							break;
						case 'starts with':
							$user_filters_sql_where = "tu.user_login LIKE '{$a_filter_details[0]}%'";
							break;
						case 'ends with':
							$user_filters_sql_where = "tu.user_login LIKE '%{$a_filter_details[0]}'";
							break;
						case 'sounds like':
							$user_filters_sql_where = "SOUNDEX(tu.user_login) = SOUNDEX('%{$a_filter_details[0]}%')";
							break;
						case 'is empty':
							$user_filters_sql_where = "tu.user_login = ''";
							break;
						case 'is not empty':
							$user_filters_sql_where = "tu.user_login <> ''";
							break;
						default:
							$user_filters_sql_where = " tu.user_login = '{$a_filter_details[0]}'";
					}

					$sql = "SELECT tp.ID
							FROM $wpdb->posts tp, $wpdb->users tu
							WHERE $user_filters_sql_where
								AND tp.post_author = tu.ID
								AND tp.post_status = 'publish'";
					$array_post_id_by_user = $wpdb->get_results($sql, ARRAY_A);
					if (count($array_post_id_by_user) > 0){
						$array_permalinks_by_user = array();
						$site_home_url = get_bloginfo('url');
						foreach ($array_post_id_by_user as $a_result){
							$array_permalinks_by_user[] = str_replace($site_home_url, '', get_permalink($a_result['ID']));
						}
						$this->filters_sql_where .= " AND t1.resource IN ('".implode("','", $array_permalinks_by_user)."')";
					}
					else
						$this->filters_sql_where .= " AND t1.resource IN ('[nothing found]')";
				}

				if ($a_filter_label == 'category-id'){
					$sql = "SELECT tr.object_id
							FROM $wpdb->term_relationships tr, $wpdb->term_taxonomy tt
							WHERE tt.term_id = '{$a_filter_details[0]}'
								AND tr.term_taxonomy_id = tt.term_taxonomy_id";
					$array_post_id_by_category = $wpdb->get_results($sql, ARRAY_A);
					if (count($array_post_id_by_category) > 0){
						$array_permalinks_by_category = array();
						$site_home_url = get_bloginfo('url');
						foreach ($array_post_id_by_category as $a_result){
							$array_permalinks_by_category[] = str_replace($site_home_url, '', get_permalink($a_result['object_id']));
						}
						$this->filters_sql_where .= " AND t1.resource IN ('".implode("','", $array_permalinks_by_category)."')";
					}
					else
						$this->filters_sql_where .= " AND t1.resource IN ('[nothing found]')";
				}

				// Some columns are in separate tables, so we need to join these tables
				switch ($a_filter_label){
					case 'browser':
					case 'platform':
					case 'version':
					case 'css_version':
					case 'type':
						$this->filters_sql_from['browsers'] = "INNER JOIN $this->table_browsers tb ON t1.browser_id = tb.browser_id";
						break;
					case 'resolution':
					case 'colordepth':
						$this->filters_sql_from['screenres'] = "INNER JOIN $this->table_screenres tss ON t1.screenres_id = tss.screenres_id";
						break;
					default:
						break;
				}
			}
		}
		if (empty($this->day_interval)){
			if ($this->day_filter_active){
				$this->current_date_utime_end = $this->current_date_utime_start + 86399;
				$this->filters_date_sql_where = " AND t1.dt BETWEEN '$this->current_date_utime_start' AND '$this->current_date_utime_end'";
			}
			else{
				$this->current_date_utime_start = strtotime("{$this->current_date['y']}/{$this->current_date['m']}/01 00:00");
				$this->current_date_utime_end = strtotime("{$this->current_date['y']}/{$this->current_date['m']}/01 +1 month")-1;
				$this->filters_date_sql_where = " AND t1.dt BETWEEN '$this->current_date_utime_start' AND '$this->current_date_utime_end'";
			}
		}
		else{
			$this->current_date_utime_end = strtotime("{$this->current_date['y']}/{$this->current_date['m']}/{$this->current_date['d']} +{$this->day_interval} days")-1;
			$this->filters_date_sql_where = " AND t1.dt BETWEEN '$this->current_date_utime_start' AND '$this->current_date_utime_end'";
		}
	}

	// Functions are declared in alphabetical order

	public function count_bouncing_pages(){
		global $wpdb;

		$sql = "SELECT COUNT(*) count
				FROM (
					SELECT resource
					FROM $this->table_stats t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
					WHERE visit_id <> 0 AND resource <> '__l_s__' AND resource <> '' $this->filters_sql_where $this->filters_date_sql_where
					GROUP BY visit_id
					HAVING COUNT(visit_id) = 1
				) as ts1";
		return intval($wpdb->get_var($sql));
	}

	public function count_exit_pages(){
		global $wpdb;

		$sql = "SELECT COUNT(*) count
				FROM (
					SELECT resource, visit_id, dt
					FROM $this->table_stats t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
					WHERE visit_id > 0 AND resource <> '' AND resource <> '__l_s__' $this->filters_sql_where $this->filters_date_sql_where
					GROUP BY visit_id
					HAVING dt = MAX(dt)
				) AS ts1";
		return intval($wpdb->get_var($sql));
	}

	public function count_records($_where_clause = '1=1', $_field = '*', $_use_filters = true, $_join_tables = ''){
		global $wpdb;

		$sql_from = '';
		if(strpos($_join_tables,'browsers')!==false && empty($this->filters_sql_from['browsers']))
			$sql_from .= " INNER JOIN $this->table_browsers tb ON t1.browser_id = tb.browser_id";

		if(strpos($_join_tables,'screenres')!==false && empty($this->filters_sql_from['screenres']))
			$sql_from .= " INNER JOIN $this->table_screenres tss ON t1.screenres_id = tss.screenres_id";

		$sql = "SELECT COUNT($_field) count
				FROM $this->table_stats t1 ".($_use_filters?"{$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}":'')." $sql_from
				WHERE $_where_clause ".($_use_filters?"$this->filters_sql_where $this->filters_date_sql_where":'');
		return intval($wpdb->get_var($sql));
	}

	public function count_records_having($_where_clause = '1=1', $_field = 'ip', $_having_clause = ''){
		global $wpdb;

		$sql = "SELECT COUNT(*) FROM (
					SELECT $_field
					FROM $this->table_stats t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
					WHERE $_where_clause $this->filters_sql_where $this->filters_date_sql_where
					GROUP BY $_field
					".(!empty($_having_clause)?"HAVING $_having_clause":'').")
				AS ts1";
		return intval($wpdb->get_var($sql));
	}

	public function get_data_size(){
		global $wpdb;

		$suffix = 'KB';

		$sql = "SHOW TABLE STATUS LIKE '$this->table_stats'";
		$myTableDetails = $wpdb->get_row($sql, 'ARRAY_A', 0);

		$myTableSize = ( $myTableDetails['Data_length'] / 1024 ) + ( $myTableDetails['Index_length'] / 1024 );

		if ($myTableSize > 1024){
			$myTableSize /= 1024;
			$suffix = 'MB';
		}
		return number_format($myTableSize, 2, $this->decimal_separator, $this->thousand_separator).' '.$suffix;
	}

	public function get_max_and_average_pages_per_visit(){
		global $wpdb;

		$sql = "SELECT AVG(ts1.count) avg, MAX(ts1.count) max FROM (
					SELECT count(ip) count, visit_id
					FROM $this->table_stats t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
					WHERE visit_id > 0 $this->filters_sql_where $this->filters_date_sql_where
					GROUP BY visit_id
				) AS ts1";
		return $wpdb->get_row($sql, ARRAY_A);
	}

	public function get_oldest_visit(){
		global $wpdb;

		$sql = "SELECT t1.dt
				FROM $this->table_stats t1
				ORDER BY t1.dt ASC
				LIMIT 0,1";
		return $wpdb->get_var($sql);
	}

	public function get_recent($_field = 'id', $_more_fields = '', $_custom_where = '', $_join_tables = '', $_having_clause = '', $_order_by = ''){
		global $wpdb;

		// Include the appropriate tables in the query
		$sql_from = $sql_internal_from = '';
		if(strpos($_join_tables,'browsers')!==false){
			if (empty($this->filters_sql_from['browsers'])) 
				$sql_internal_from .= " INNER JOIN $this->table_browsers tb ON t1.browser_id = tb.browser_id";
			$sql_from .= " INNER JOIN $this->table_browsers tb ON t1.browser_id = tb.browser_id";
		}

		if(strpos($_join_tables,'screenres')!==false){
			if (empty($this->filters_sql_from['screenres'])) 
				$sql_internal_from .= " INNER JOIN $this->table_screenres tss ON t1.screenres_id = tss.screenres_id";
			$sql_from .= " INNER JOIN $this->table_screenres tss ON t1.screenres_id = tss.screenres_id";
		}


		$fields = empty($_more_fields)?$_field:"$_field, $_more_fields";
		$order_by = empty($_order_by)?"t1.dt $this->direction":"$_order_by";
		$sql = "SELECT $fields, t1.dt
				FROM (
					SELECT $_field, MAX(t1.id) maxid
					FROM $this->table_stats t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']} $sql_internal_from
					WHERE ".(empty($_custom_where)?"$_field <> '' AND  $_field <> '__l_s__'":$_custom_where)." $this->filters_sql_where $this->filters_date_sql_where
					GROUP BY $_field $_having_clause
				) AS ts1 INNER JOIN $this->table_stats t1 ON ts1.maxid = t1.id $sql_from
				ORDER BY $order_by
				LIMIT $this->starting_from, $this->limit_results";
		return $wpdb->get_results($sql, ARRAY_A);
	}

	public function get_recent_outbound($_type = -1){
		global $wpdb;

		$where_clause = ($_type != -1)?"tob.type = $_type":'tob.type > 0';

		$sql = "SELECT tob.outbound_id, tob.outbound_domain, tob.outbound_resource, tob.type, tob.notes, t1.ip, t1.user, t1.domain, t1.resource, t1.referer, t1.country, tb.browser, tb.version, tb.platform, tob.dt
				FROM $this->table_outbound tob INNER JOIN $this->table_stats t1 ON tob.id = t1.id INNER JOIN $this->table_browsers tb on t1.browser_id = tb.browser_id
				WHERE $where_clause $this->filters_sql_where $this->filters_date_sql_where
				ORDER BY tob.dt $this->direction
				LIMIT $this->starting_from,$this->limit_results";
		return $wpdb->get_results($sql, ARRAY_A);
	}

	public function get_top($_field = 'id', $_more_fields = '', $_custom_where = '', $_join_tables = ''){
		global $wpdb;

		// Include the appropriate tables in the query
		$sql_from = $sql_internal_from = '';
		if(strpos($_join_tables,'browsers')!==false){
			if (empty($this->filters_sql_from['browsers'])) 
				$sql_internal_from .= " INNER JOIN $this->table_browsers tb ON t1.browser_id = tb.browser_id";
			$sql_from .= " INNER JOIN $this->table_browsers tb ON t1.browser_id = tb.browser_id";
		}

		if(strpos($_join_tables,'screenres')!==false){
			if (empty($this->filters_sql_from['screenres'])) 
				$sql_internal_from .= " INNER JOIN $this->table_screenres tss ON t1.screenres_id = tss.screenres_id";
			$sql_from .= " INNER JOIN $this->table_screenres tss ON t1.screenres_id = tss.screenres_id";
		}

		$fields = empty($_more_fields)?$_field:"$_field, $_more_fields";
		$sql = "SELECT $fields, t1.dt, ts1.count
				FROM (
					SELECT $_field, MAX(t1.id) id, COUNT(*) count
					FROM $this->table_stats t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']} $sql_internal_from
					WHERE ".(empty($_custom_where)?"$_field <> '' AND  $_field <> '__l_s__'":$_custom_where)." $this->filters_sql_where $this->filters_date_sql_where
					GROUP BY $_field
				) AS ts1 INNER JOIN $this->table_stats t1 ON ts1.id = t1.id $sql_from
				ORDER BY ts1.count $this->direction, $_field ASC
				LIMIT $this->starting_from, $this->limit_results";
		return $wpdb->get_results($sql, ARRAY_A);
	}

	public function extract_data_for_chart($_data1, $_data2, $_current_panel = 1, $_label_data1 = '', $_label_data2 = '', $_custom_where_clause = '', $_sql_from_where = '', $_join_tables = ''){
		global $wpdb, $wp_locale;

		// Avoid PHP warnings in strict mode
		$result->current_total1 = $result->current_total2 = $result->current_non_zero_count = $result->previous_total = $result->previous_non_zero_count = $result->today = $result->yesterday = $result->max_yaxis = 0;
		$result->current_data1 = $result->current_data2 = $result->previous_data = $result->ticks = '';
		$data1 = $data2 = array();

		if ($this->day_filter_active){
			// SQL query
			$select_fields = "DATE_FORMAT(FROM_UNIXTIME(dt), '%H') h, DATE_FORMAT(FROM_UNIXTIME(dt), '%d') d";
			$time_constraints = "(dt BETWEEN '$this->current_date_utime_start' AND '$this->current_date_utime_end' OR dt BETWEEN '$this->yesterday_utime_start' AND '$this->yesterday_utime_end') ";
			$group_and_order = " GROUP BY h, d ORDER BY d ASC, h asc";

			// Data parsing
			$filter_idx = 'd'; $group_idx = 'h';
			$data_start_value = 0; $data_end_value = 24;
			$result->min_max_ticks = ',min:0,max:23';
			$result->ticks = ($wp_locale->text_direction == 'rtl')?'[0,"23"],[1,"22"],[2,"21"],[3,"20"],[4,"19"],[5,"18"],[6,"17"],[7,"16"],[8,"15"],[9,"14"],[10,"13"],[11,"12"],[12,"11"],[13,"10"],[14,"9"],[15,"8"],[16,"7"],[17,"6"],[18,"5"],[19,"4"],[20,"3"],[21,"2"],[22,"1"],[23,"0"],':'[0,"00"],[1,"01"],[2,"02"],[3,"03"],[4,"04"],[5,"05"],[6,"06"],[7,"07"],[8,"08"],[9,"09"],[10,"10"],[11,"11"],[12,"12"],[13,"13"],[14,"14"],[15,"15"],[16,"16"],[17,"17"],[18,"18"],[19,"19"],[20,"20"],[21,"21"],[22,"22"],[23,"23"],';
			$label_date_format = get_option('date_format', 'd-m-Y');
		}
		else{
			// SQL query
			$select_fields = "DATE_FORMAT(FROM_UNIXTIME(dt), '%m') m, DATE_FORMAT(FROM_UNIXTIME(dt), '%d') d";
			$time_constraints = "(dt BETWEEN '$this->current_date_utime_start' AND '$this->current_date_utime_end'";
			if (empty($this->day_interval)) $time_constraints .= " OR dt BETWEEN '$this->previous_month_utime_start' AND '$this->previous_month_utime_end'";
			$time_constraints .= ')';
			$group_and_order = " GROUP BY m, d ORDER BY m ASC,d ASC";

			// Data parsing
			$filter_idx = 'm'; $group_idx = 'd';
			if (empty($this->day_interval)){
				$data_start_value = 0; $data_end_value = 31;
				$result->min_max_ticks = ',min:0,max:30';
				$result->ticks = ($wp_locale->text_direction == 'rtl')?'[0,"31"],[1,"30"],[2,"29"],[3,"28"],[4,"27"],[5,"26"],[6,"25"],[7,"24"],[8,"23"],[9,"22"],[10,"21"],[11,"20"],[12,"19"],[13,"18"],[14,"17"],[15,"16"],[16,"15"],[17,"14"],[18,"13"],[19,"12"],[20,"11"],[21,"10"],[22,"9"],[23,"8"],[24,"7"],[25,"6"],[26,"5"],[27,"4"],[28,"3"],[29,"2"],[30,"1"],':'[0,"1"],[1,"2"],[2,"3"],[3,"4"],[4,"5"],[5,"6"],[6,"7"],[7,"8"],[8,"9"],[9,"10"],[10,"11"],[11,"12"],[12,"13"],[13,"14"],[14,"15"],[15,"16"],[16,"17"],[17,"18"],[18,"19"],[19,"20"],[20,"21"],[21,"22"],[22,"23"],[23,"24"],[24,"25"],[25,"26"],[26,"27"],[27,"28"],[28,"29"],[29,"30"],[30,"31"],';
				$label_date_format = 'm/Y';
			}
			else{
				$data_start_value = 0; $data_end_value = $this->day_interval;
				$result->min_max_ticks = '';
				$label_date_format = '';
			}
		}

		// This SQL query has a standard format: grouped by day or hour and then data1 and data2 represent the information we want to extract
		$sql = "SELECT $select_fields, $_data1 data1, $_data2 data2";

		$sql_from = '';
		if(strpos($_join_tables,'browsers')!==false && empty($this->filters_sql_from['browsers']))
			$sql_from .= " INNER JOIN $this->table_browsers tb ON t1.browser_id = tb.browser_id";

		if(strpos($_join_tables,'screenres')!==false && empty($this->filters_sql_from['screenres']))
			$sql_from .= " INNER JOIN $this->table_screenres tss ON t1.screenres_id = tss.screenres_id";

		// Panel 4 has a slightly different structure
		if(empty($_sql_from_where)){
			$sql .= "	FROM $this->table_stats t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']} $sql_from
						WHERE $time_constraints $this->filters_sql_where $_custom_where_clause";
		}
		else{
			$sql_no_placeholders = str_replace('[from_tables]', "$this->table_stats t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']} $sql_from", $_sql_from_where);
			$sql_no_placeholders = str_replace('[where_clause]', "$time_constraints $this->filters_sql_where $_custom_where_clause", $sql_no_placeholders);
			$sql .= $sql_no_placeholders;
		}
		$sql .= $group_and_order;

		$array_results = $wpdb->get_results($sql, ARRAY_A);

		if (!is_array($array_results) || empty($array_results))
			return $result;

		// Double-pass: reorganize the data and then format it for Flot
		foreach ($array_results as $a_result){
			$data1[$a_result[$filter_idx].$a_result[$group_idx]] = $a_result['data1'];
			$data2[$a_result[$filter_idx].$a_result[$group_idx]] = $a_result['data2'];
			if ($a_result['data1'] > 0){
				if ($a_result[$filter_idx] == $this->current_date[$filter_idx]){
					$result->current_non_zero_count++;
				}
				else {
					$result->previous_non_zero_count++;
				}
			}
		}

		$result->max_yaxis = max(max($data1), max($data2));
		$filters_query_without_date = remove_query_arg(array('day','day-op','month','month-op','year','year-op','interval','interval-op'), $this->filters_query);
		if (!empty($filters_query_without_date) && strpos($filters_query_without_date, 0, 1) != '&') $filters_query_without_date = '&'.$filters_query_without_date;

		$k = ($wp_locale->text_direction == 'rtl')?$data_start_value-$data_end_value+1:0;
		for ($i=$data_start_value;$i<$data_end_value;$i++){
			$j = abs($k+$i);
			if ($this->day_filter_active){
				$timestamp_current = mktime($j, 0, 0, $this->current_date['m'], $this->current_date['d'], $this->current_date['y']);
				$hour_idx_current = date('H', $timestamp_current);
				$day_idx_current = date('d', $timestamp_current);
				$month_idx_current = date('m', $timestamp_current);
				$year_idx_current = date('Y', $timestamp_current);
				$date_idx_current = $day_idx_current.$hour_idx_current;
				$strtotime_current = strtotime("$year_idx_current-$month_idx_current-$day_idx_current $hour_idx_current:00:00");
				$current_filter_query = '';

				$timestamp_previous = mktime($j, 0, 0, $this->current_date['m'], $this->current_date['d']-1, $this->current_date['y']);
				$hour_idx_previous = date('H', $timestamp_previous);
				$day_idx_previous = date('d', $timestamp_previous);
				$month_idx_previous = date('m', $timestamp_previous);
				$year_idx_previous = date('Y', $timestamp_previous);
				$date_idx_previous = $day_idx_previous.$hour_idx_previous;
				$strtotime_previous = strtotime("$year_idx_previous-$month_idx_previous-$day_idx_previous $hour_idx_previous:00:00");
				$previous_filter_query = '';
			}
			else{
				$timestamp_current = mktime(0, 0, 0, $this->current_date['m'], (!empty($this->day_interval)?$this->current_date['d']:1)+$j, $this->current_date['y']);
				$day_idx_current = date('d', $timestamp_current);
				$month_idx_current = date('m', $timestamp_current);
				$year_idx_current = date('Y', $timestamp_current);
				$date_idx_current = $month_idx_current.$day_idx_current;

				$strtotime_current = strtotime("$year_idx_current-$month_idx_current-$day_idx_current 00:00:00");
				$current_filter_query = ",'$filters_query_without_date&slimpanel=$_current_panel&day=$day_idx_current&month=$month_idx_current&year=$year_idx_current'";

				$timestamp_previous = mktime(0, 0, 0, $this->current_date['m']-1, (!empty($this->day_interval)?$this->current_date['d']:1)+$j, $this->current_date['y']);
				$day_idx_previous = date('d', $timestamp_previous);
				$month_idx_previous = date('m', $timestamp_previous);
				$year_idx_previous = date('Y', $timestamp_previous);
				$date_idx_previous = $month_idx_previous.$day_idx_previous;
				$strtotime_previous = strtotime("$year_idx_previous-$month_idx_previous-$day_idx_previous 00:00:00");
				$previous_filter_query = ",'$filters_query_without_date&slimpanel=$_current_panel&day=$day_idx_previous&month=$month_idx_previous&year=$year_idx_previous'";

				if ($date_idx_current == "{$this->current_date['m']}{$this->current_date['d']}" && !empty($data1[$date_idx_current])) $result->today = $data1[$date_idx_current];
				if ($date_idx_current == "{$this->yesterday['m']}{$this->yesterday['d']}" && !empty($data1[$date_idx_current])) $result->yesterday = $data1[$date_idx_current];
			}

			// Format each group of data

			if (($j == $day_idx_current - 1) || $this->day_filter_active || !empty($this->day_interval)){
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

			if (($j == $day_idx_previous - 1) || $this->day_filter_active || !empty($this->day_interval)){
				if (empty($this->day_interval)){
					if (!empty($data1[$date_idx_previous])){
						$result->previous_data .= "[$i,{$data1[$date_idx_previous]}$previous_filter_query],";
						$result->previous_total += $data1[$date_idx_previous];
					}
					elseif($strtotime_previous <= date_i18n('U')){
						$result->previous_data .= "[$i,0],";
					}
				}
				else{
					$date_label = date('d/m', mktime(0, 0, 0, $this->current_date['m'], $this->current_date['d']+$i, $this->current_date['y']));
					$result->ticks .= "[$i, \"$date_label\"],";
				}
			}
		}

		if (!empty($result->current_data1)){
			$result->current_data1 = substr($result->current_data1, 0, -1);
			$result->current_data1_label = "$_label_data1".(!empty($label_date_format)?' '.date_i18n($label_date_format, mktime(0, 0, 0, $this->current_date['m'], $this->current_date['d'], $this->current_date['y'])):'');
		}
		if (!empty($result->current_data2)){
			$result->current_data2 = substr($result->current_data2, 0, -1);
			$result->current_data2_label = "$_label_data2".(!empty($label_date_format)?' '.date_i18n($label_date_format, mktime(0, 0, 0, $this->current_date['m'], $this->current_date['d'], $this->current_date['y'])):'');
		}
		if (!empty($result->previous_data)){
			$result->previous_data = substr($result->previous_data, 0, -1);
			$result->previous_data_label = "$_label_data1";
			if (!empty($label_date_format))
				if ($this->day_filter_active)
					$result->previous_data_label .= ' '.date_i18n($label_date_format, mktime(0, 0, 0, $this->yesterday['m'], $this->yesterday['d'], $this->yesterday['y']));
				else
					$result->previous_data_label .= ' '.date_i18n($label_date_format, mktime(0, 0, 0, $this->previous_month['m'], 1, $this->previous_month['y']));
		}
		if (!empty($result->ticks)){
			$result->ticks = substr($result->ticks, 0, -1);
		}
		return $result;
	}

	private function _format_value($_value = 0, $_link = ''){
		if ($_value == 0) return '<set/>';
		if (empty($_link)){
			return (intval($_value)==$_value)?"<set value='$_value'/>":sprintf("<set value='%01.2f'/>", $_value);
		}
		else{
			return (intval($_value)==$_value)?"<set value='$_value' link='$_link'/>":sprintf("<set value='%01.2f' link='%s'/>", $_value, $_link);
		}
	}
}