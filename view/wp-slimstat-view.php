<?php

// Let's define the main class with all the methods that we need
class wp_slimstat_view {

	public function __construct($user_filters = ''){
		global $wpdb;

		// We use three of tables to store data about visits
		$this->table_stats = $wpdb->prefix . 'slim_stats';
		$this->table_visits = $wpdb->prefix . 'slim_visits';
		$this->table_outbound = $wpdb->prefix . 'slim_outbound';

		// Some tables can be shared among the various installations (wordpress network)
		$this->table_countries = $wpdb->base_prefix . 'slim_countries';
		$this->table_browsers = $wpdb->base_prefix . 'slim_browsers';
		$this->table_screenres = $wpdb->base_prefix . 'slim_screenres';

		// Start from...
		$this->starting_from = 0;

		// Limit results to...
		$this->limit_results = empty($user_filters['limit_results'])?get_option('slimstat_rows_to_show', '20'):intval($user_filters['limit_results']);

		// Date format
		$this->date_time_format = get_option('date_format', 'd-m-Y').' '.get_option('time_format', 'g:i a');

		// It looks like WP_PLUGIN_URL doesn't honor the HTTPS setting in wp-config.php
		$this->plugin_url = is_ssl()?str_replace('http://', 'https://', WP_PLUGIN_URL):WP_PLUGIN_URL;

		// Base DOMAIN for this blog
		$this->blog_domain = home_url();
		if (strpos(substr($this->blog_domain, 8), '/') > 0) $this->blog_domain = substr($this->blog_domain, 0, 8+strpos(substr($this->blog_domain, 8), '/'));

		// Calculate filters
		$this->filters_to_parse = array(
			'day' => 'integer',
			'month' => 'integer',
			'year' => 'integer',
			'interval' => 'integer',
			'browser' => 'string',
			'version' => 'string',
			'css_version' => 'string',
			'author' => 'string',
			'category-id' => 'integer',
			'country' => 'string',
			'domain' => 'string',
			'ip' => 'string',
			'user' => 'string',
			'visit_id' => 'string',
			'language' => 'string',
			'platform' => 'string',
			'resource' => 'string',
			'referer' => 'string',
			'resolution' => 'string',
			'searchterms' => 'string',
			'limit-results' => 'integer'
		);

		// Avoid warnings in strict mode
		$this->filters_parsed = array();
		$this->day_filter_active = false;
		$this->custom_data_filter = false;
		$this->filters_query = '';
		$this->current_date = array();

		$this->direction = 'DESC';
		if (!empty($_GET['direction']) && $_GET['direction'] == 'ASC') $this->direction = 'ASC';

		// Decimal and thousands separators
		$this->decimal_separator = ','; $this->thousand_separator = '.';
		if (get_option('slimstat_use_european_separators', 'yes') == 'no') {
			$this->decimal_separator = '.'; $this->thousand_separator = ',';
		}

		foreach ($this->filters_to_parse as $a_filter_label => $a_filter_type){
			if(!empty($user_filters) && !empty($user_filters[$a_filter_label])){
				$f_value = ($a_filter_type == 'integer')?abs(intval($user_filters[$a_filter_label])):$wpdb->escape(str_replace('\\', '', htmlspecialchars_decode(urldecode($user_filters[$a_filter_label]))));
				$f_operator = !empty($user_filters[$a_filter_label.'-op'])?$wpdb->escape(htmlspecialchars(str_replace('\\', '', $user_filters[$a_filter_label.'-op']))):'equals';
				$this->filters_parsed[$a_filter_label] = array($f_value, $f_operator);
			}
			else if (!empty($_GET['filter']) && (!empty($_GET['f_value']) || strpos($_GET['f_operator'], 'empty') > 0) && $_GET['filter']==$a_filter_label){
				$f_value = ($a_filter_type == 'integer')?abs(intval($_GET['f_value'])):(!empty($_GET['f_value'])?$wpdb->escape(str_replace('\\', '', htmlspecialchars_decode(urldecode($_GET['f_value'])))):'');
				$f_operator = !empty($_GET['f_operator'])?$wpdb->escape(htmlspecialchars(str_replace('\\', '', $_GET['f_operator']))):'equals';
				$this->filters_parsed[$a_filter_label] = array($f_value, $f_operator);
			}
			else if(!empty($_GET[$a_filter_label]) || (isset($_GET[$a_filter_label.'-op']) && strpos($_GET[$a_filter_label.'-op'], 'empty') > 0)){
				$f_value = ($a_filter_type == 'integer')?abs(intval($_GET[$a_filter_label])):(!empty($_GET[$a_filter_label])?$wpdb->escape(str_replace('\\', '', htmlspecialchars_decode(urldecode($_GET[$a_filter_label])))):'');
				$f_operator = !empty($_GET[$a_filter_label.'-op'])?$wpdb->escape(htmlspecialchars(str_replace('\\', '', $_GET[$a_filter_label.'-op']))):'equals';
				$this->filters_parsed[$a_filter_label] = array($f_value, $f_operator);
			}
		}

		// Date filter
		date_default_timezone_set('UTC');
		if (!empty($this->filters_parsed['day'][0])){
			$this->current_date['d'] = sprintf('%02d', $this->filters_parsed['day'][0]);
			if (empty($this->filters_parsed['interval'][0]))
				$this->day_filter_active = true;
			else
				$this->day_interval = $this->filters_parsed['interval'][0];
			$this->custom_data_filter = true;
		}
		else {
			$this->current_date['d'] = date_i18n('d');
		}
		if (!empty($this->filters_parsed['month'][0])){
			$this->current_date['m'] = sprintf('%02d', $this->filters_parsed['month'][0]);
			$this->custom_data_filter = true;
		}
		else {
			$this->current_date['m'] = date_i18n('m');
		}
		if (!empty($this->filters_parsed['year'][0])){
			$this->current_date['y'] = sprintf('%04d', $this->filters_parsed['year'][0]);
			$this->custom_data_filter = true;
		}
		else {
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
			if (!empty($filters_query))
				$this->filters_query = $filters_query;
			else
				$this->filters_query = '';

			foreach($this->filters_parsed as $a_filter_label => $a_filter_details){
				// Skip filters on date and author
				if (($a_filter_label != 'day') && ($a_filter_label != 'month') && ($a_filter_label != 'year') && ($a_filter_label != 'interval')
					&& ($a_filter_label != 'author') && ($a_filter_label != 'category-id')){

					// Filters on the IP address require a special treatment
					if ($a_filter_label == 'ip'){
						$a_filter_column = 'INET_NTOA(ip)';
					}
					else{
						$a_filter_column = "$a_filter_label";
					}


					switch($a_filter_details[1]){
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
							$this->filters_sql_where .= " AND $a_filter_column SOUNDS LIKE '%{$a_filter_details[0]}'";
							break;
						case 'is empty':
							$this->filters_sql_where .= " AND $a_filter_column = ''";
							break;
						case 'is not empty':
							$this->filters_sql_where .= " AND $a_filter_column <> ''";
							break;
						default:
							$this->filters_sql_where .= " AND $a_filter_column = '{$a_filter_details[0]}'";
					}
				}

				// I know, this is not the best way of handling these two filters...
				if ($a_filter_label == 'author'){
					$sql = "SELECT tp.ID
							FROM $wpdb->posts tp, $wpdb->users tu
							WHERE tu.user_login = '{$a_filter_details[0]}'
								AND tp.post_author = tu.ID
								AND tp.post_status = 'publish'";
					$array_post_id_by_user = $wpdb->get_results($sql, ARRAY_A);
					if (count($array_post_id_by_user) > 0){
						$array_permalinks_by_user = array();
						$site_home_url = get_bloginfo('url');
						foreach($array_post_id_by_user as $a_result){
							$array_permalinks_by_user[] = str_replace($site_home_url, '', get_permalink($a_result['ID']));
						}
						$this->filters_sql_where .= " AND resource IN ('".implode("','", $array_permalinks_by_user)."')";
					}
					else
						$this->filters_sql_where .= " AND resource IN ('[nothing found]')";
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
						foreach($array_post_id_by_category as $a_result){
							$array_permalinks_by_category[] = str_replace($site_home_url, '', get_permalink($a_result['object_id']));
						}
						$this->filters_sql_where .= " AND resource IN ('".implode("','", $array_permalinks_by_category)."')";
					}
					else
						$this->filters_sql_where .= " AND resource IN ('[nothing found]')";
				}

				// Some columns are in separate tables, so we need to join these tables
				switch($a_filter_label){
					case 'browser':
					case 'platform':
					case 'version':
					case 'css_version':
						$this->filters_sql_from['browsers'] = "INNER JOIN $this->table_browsers tb ON t1.browser_id = tb.browser_id";
						break;
					case 'resolution':
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
					WHERE visit_id > 0 AND resource <> '' AND resource <> '__l_s__' $this->filters_date_sql_where $this->filters_sql_where
					GROUP BY visit_id
					HAVING dt = MAX(dt)
				) AS ts1";
		return intval($wpdb->get_var($sql));
	}

	public function count_new_visitors(){
		global $wpdb;

		$sql = "SELECT COUNT(*) FROM (
					SELECT ip
					FROM $this->table_stats t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
					WHERE visit_id > 0 $this->filters_date_sql_where $this->filters_sql_where
					GROUP BY ip
					HAVING COUNT(visit_id) = 1)
				AS ts1";
		return intval($wpdb->get_var($sql));
	}

	public function count_records($_where_clause = '1=1', $_field = '*', $_only_current_period = true){
		global $wpdb;

		$sql = "SELECT COUNT($_field) count
				FROM `$this->table_stats` t1 ".
					($_only_current_period?$this->filters_sql_from['browsers'].' '.$this->filters_sql_from['screenres']:'')."
				WHERE $_where_clause ".($_only_current_period?$this->filters_date_sql_where.$this->filters_sql_where:'');
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
					WHERE visit_id > 0 $this->filters_date_sql_where $this->filters_sql_where
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
		$sql_from = '';
		if(strpos($_join_tables,'browsers')!==false)
			$sql_from .= " INNER JOIN $this->table_browsers tb ON t1.browser_id = tb.browser_id";

		if(strpos($_join_tables,'screenres')!==false)
			$sql_from = " INNER JOIN $this->table_screenres tss ON t1.screenres_id = tss.screenres_id";

		$fields = empty($_more_fields)?$_field:"$_field, $_more_fields";
		$order_by = empty($_order_by)?"t1.dt $this->direction":"$_order_by";
		$sql = "SELECT $fields, t1.dt
				FROM (
					SELECT $_field, MAX(t1.id) maxid
					FROM $this->table_stats t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
					WHERE ".(empty($_custom_where)?"$_field <> '' AND  $_field <> '__l_s__'":$_custom_where)." $this->filters_sql_where $this->filters_date_sql_where
					GROUP BY $_field $_having_clause
				) AS ts1 INNER JOIN $this->table_stats t1 ON ts1.maxid = t1.id $sql_from
				ORDER BY $order_by
				LIMIT $this->starting_from, $this->limit_results";
		return $wpdb->get_results($sql, ARRAY_A);
	}

	public function get_recent_outbound($_type = 0){
		global $wpdb;

		$sql = "SELECT tob.outbound_id, tob.outbound_domain, tob.outbound_resource, t1.ip, t1.user, t1.resource, t1.referer, t1.country, tob.dt
				FROM (
					SELECT tob.outbound_resource, MAX(tob.outbound_id) outbound_id
					FROM $this->table_outbound tob INNER JOIN $this->table_stats t1 ON t1.id = tob.id {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
					WHERE type = $_type $this->filters_sql_where $this->filters_date_sql_where
					GROUP BY tob.outbound_resource
				) AS ts1 INNER JOIN $this->table_outbound tob ON ts1.outbound_id = tob.outbound_id INNER JOIN $this->table_stats t1 ON tob.id = t1.id
				ORDER BY t1.dt $this->direction
				LIMIT $this->starting_from,$this->limit_results";
		return $wpdb->get_results($sql, ARRAY_A);
	}

	public function get_top($_field = 'id', $_more_fields = '', $_custom_where = '', $_join_tables = ''){
		global $wpdb;

		// Include the appropriate tables in the query
		$sql_from = '';
		$filters_sql_from_browsers = $this->filters_sql_from['browsers'];
		$filters_sql_from_screenres = $this->filters_sql_from['screenres'];
		if(strpos($_join_tables,'browsers')!==false)
			$sql_from .= $filters_sql_from_browsers = " INNER JOIN $this->table_browsers tb ON t1.browser_id = tb.browser_id";

		if(strpos($_join_tables,'screenres')!==false)
			$sql_from .= $filters_sql_from_screenres = " INNER JOIN $this->table_screenres tss ON t1.screenres_id = tss.screenres_id";

		$fields = empty($_more_fields)?$_field:"$_field, $_more_fields";
		$sql = "SELECT $fields, t1.dt, ts1.count
				FROM (
					SELECT $_field, MAX(t1.id) id, COUNT(*) count
					FROM $this->table_stats t1 $filters_sql_from_browsers $filters_sql_from_screenres
					WHERE ".(empty($_custom_where)?"$_field <> '' AND  $_field <> '__l_s__'":$_custom_where)." $this->filters_date_sql_where $this->filters_sql_where
					GROUP BY $_field
				) AS ts1 INNER JOIN $this->table_stats t1 ON ts1.id = t1.id $sql_from
				ORDER BY ts1.count $this->direction, $_field ASC
				LIMIT $this->starting_from, $this->limit_results";
		return $wpdb->get_results($sql, ARRAY_A);
	}

	public function extract_data_for_chart($_data1, $_data2, $_current_panel = 1, $_label_data1 = '', $_label_data2 = '', $_decimal_precision = 0, $_custom_where_clause = '', $_sql_from_where = ''){
		global $wpdb, $wp_locale;

		if ($this->day_filter_active){
			$select_fields = "DATE_FORMAT(FROM_UNIXTIME(dt), '%H') h, DATE_FORMAT(FROM_UNIXTIME(dt), '%d') d";
			$time_constraints = "(dt BETWEEN '$this->current_date_utime_start' AND '$this->current_date_utime_end' OR dt BETWEEN '$this->yesterday_utime_start' AND '$this->yesterday_utime_end') ";
			$group_and_order = " GROUP BY h, d ORDER BY d ASC, h asc";
		}
		else{
			$select_fields = "DATE_FORMAT(FROM_UNIXTIME(dt), '%m') m, DATE_FORMAT(FROM_UNIXTIME(dt), '%d') d";
			$time_constraints = "(dt BETWEEN '$this->current_date_utime_start' AND '$this->current_date_utime_end'";
			if (empty($this->day_interval)) $time_constraints .= " OR dt BETWEEN '$this->previous_month_utime_start' AND '$this->previous_month_utime_end'";
			$time_constraints .= ')';
			$group_and_order = " GROUP BY m, d ORDER BY m ASC,d ASC";
		}

		// This SQL query has a standard format: grouped by day or hour and then data1 and data2 represent the information we want to extract
		$sql = "SELECT $select_fields, $_data1 data1, $_data2 data2";

		// Panel 4 has a slightly different structure
		if(empty($_sql_from_where)){
			$sql .= "	FROM $this->table_stats t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
						WHERE $time_constraints $this->filters_sql_where $_custom_where_clause";
		}
		else{
			$sql_no_placeholders = str_replace('[from_tables]', "$this->table_stats t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}", $_sql_from_where);
			$sql_no_placeholders = str_replace('[where_clause]', "$time_constraints $this->filters_sql_where $_custom_where_clause", $sql_no_placeholders);
			$sql .= $sql_no_placeholders;
		}
		$sql .= $group_and_order;

		$array_results = $wpdb->get_results($sql, ARRAY_A);

		// Avoid PHP warnings in strict mode
		$current_period_xml_data1 = $current_period_xml_data2 = $previous_period_xml = $categories_xml = '';

		$result->xml = '';
		$result->current_data1 = array();
		$result->current_data2 = array();
		$result->previous_data1 = array();
		$result->current_non_zero_count = 0;
		$result->previous_non_zero_count = 0;

		if (!is_array($array_results) || empty($array_results)) return $result;

		// Reorganize the information retrieved
		if (empty($this->day_interval)){
			// Filter by day and group by hour if "day filter" is active, by month and day otherwise
			$filter_idx = ($this->day_filter_active)?'d':'m';
			$group_idx = ($this->day_filter_active)?'h':'d';

			foreach($array_results as $a_result){
				if($a_result[$filter_idx] == $this->current_date[$filter_idx]) {
					$result->current_data1[$a_result[$group_idx]] = $a_result['data1'];
					$result->current_data2[$a_result[$group_idx]] = $a_result['data2'];
					if ($a_result['data1'] > 0) $result->current_non_zero_count++;
				}
				else {
					$result->previous_data1[$a_result[$group_idx]] = $a_result['data1'];
					if ($a_result['data1'] > 0) $result->previous_non_zero_count++;
				}
			}
		} // if (empty($this->day_interval))
		else{
			foreach($array_results as $a_result){
				$result->current_data1[$a_result['m'].$a_result['d']] = $a_result['data1'];
				$result->current_data2[$a_result['m'].$a_result['d']] = $a_result['data2'];
				if ($a_result['data1'] > 0) $result->current_non_zero_count++;
			}
		}

		// Generate the XML for the flash chart
		if (empty($this->day_interval)){
			if ($this->day_filter_active){
				$data_start_value = 0; $data_end_value = 24;

				// Right-to-left 
				if ($wp_locale->text_direction == 'rtl'){
					$data_start_value = -23;
					$data_end_value = 1;
				}

				for($i=$data_start_value;$i<$data_end_value;$i++){ // showing a hourly graph
					$abs_i = abs($i);
					$padded_i = ($abs_i<10)?'0'.$abs_i:$abs_i;
					$categories_xml .= "<category name='$abs_i'/>";
					$current_period_xml_data1 .= !empty($result->current_data1[$padded_i])?$this->_format_value($result->current_data1[$padded_i]):'<set/>';
					$current_period_xml_data2 .= !empty($result->current_data2[$padded_i])?$this->_format_value($result->current_data2[$padded_i]):'<set/>';
					$previous_period_xml .= !empty($result->previous_data1[$padded_i])?$this->_format_value($result->previous_data1[$padded_i]):'<set/>';
				}
			}
			else{
				// Days are clickable, so we need to carry the information about current filters
				$encoded_filters_query = !empty($this->filters_query)?urlencode(str_replace('interval=','xinterval=', $this->filters_query)):'';
				$data_start_value = 1; $data_end_value = 32;

				// Right-to-left 
				if ($wp_locale->text_direction == 'rtl'){
					$data_start_value = -31;
					$data_end_value = 0;
				}

				for($i=$data_start_value;$i<$data_end_value;$i++){
					$abs_i = abs($i);
					$padded_i = ($abs_i<10)?'0'.$abs_i:$abs_i;
					$categories_xml .= "<category name='$abs_i'/>";
					$current_period_xml_data1 .= !empty($result->current_data1[$padded_i])?$this->_format_value($result->current_data1[$padded_i], "{$_SERVER['PHP_SELF']}%3Fpage=wp-slimstat%26slimpanel%3D$_current_panel%26day%3D$abs_i%26month%3D{$this->current_date['m']}%26year%3D{$this->current_date['y']}$encoded_filters_query"):'<set/>';
					$current_period_xml_data2 .= !empty($result->current_data2[$padded_i])?$this->_format_value($result->current_data2[$padded_i]):'<set/>';
					$previous_period_xml .= !empty($result->previous_data1[$padded_i])?$this->_format_value($result->previous_data1[$padded_i], "{$_SERVER['PHP_SELF']}%3Fpage=wp-slimstat%26slimpanel%3D$_current_panel%26day%3D$abs_i%26month%3D{$this->previous_month['m']}%26year%3D{$this->previous_month['y']}$encoded_filters_query"):'<set/>';
				}
			}
		}
		else{
			// Days are clickable, so we need to carry the information about current filters
			$encoded_filters_query = !empty($this->filters_query)?urlencode(str_replace('interval=','xinterval=', $this->filters_query)):'';
			$data_start_value = 0; $data_end_value = $this->day_interval;

			// Right-to-left 
			if ($wp_locale->text_direction == 'rtl'){
				$data_start_value = 1-$this->day_interval;
				$data_end_value = 1;
			}

			for($i=$data_start_value;$i<$data_end_value;$i++){
				$abs_i = abs($i);
				$day_in_interval = date('d', mktime(0,0,0,$this->current_date['m'],$this->current_date['d']+$abs_i, $this->current_date['y']));
				$month_in_interval = date('m', mktime(0,0,0,$this->current_date['m'],$this->current_date['d']+$abs_i, $this->current_date['y']));
				$year_in_interval = date('Y', mktime(0,0,0,$this->current_date['m'],$this->current_date['d']+$abs_i, $this->current_date['y']));
				if (strtotime("$year_in_interval-$month_in_interval-$day_in_interval") > date_i18n('U')) break;

				$categories_xml .= "<category name='$day_in_interval/$month_in_interval'/>";
				$current_period_xml_data1 .= !empty($result->current_data1[$month_in_interval.$day_in_interval])?$this->_format_value($result->current_data1[$month_in_interval.$day_in_interval], "{$_SERVER['PHP_SELF']}%3Fpage=wp-slimstat%26slimpanel%3D$_current_panel%26day%3D{$day_in_interval}%26month%3D{$month_in_interval}%26year%3D{$year_in_interval}$encoded_filters_query"):'<set/>';
				$current_period_xml_data2 .= !empty($result->current_data2[$month_in_interval.$day_in_interval])?$this->_format_value($result->current_data2[$month_in_interval.$day_in_interval]):'<set/>';
			}
		}

		$result->xml = "<graph canvasBorderThickness='0' yaxisminvalue='1' canvasBorderColor='ffffff' decimalPrecision='$_decimal_precision' divLineAlpha='20' formatNumberScale='0' lineThickness='2' showNames='1' showShadow='0' showValues='0' yAxisName='$_label_data1' decimalSeparator='$this->decimal_separator' thousandSeparator='$this->thousand_separator'>";
		$result->xml .= "<categories>$categories_xml</categories>";

		if ($this->day_filter_active){
			$result->xml .= "<dataset seriesname='$_label_data1 {$this->yesterday['d']}/{$this->yesterday['m']}/{$this->yesterday['y']}' color='00aaff' showValue='1'>$previous_period_xml</dataset>";
			$result->xml .= "<dataset seriesname='$_label_data1 {$this->current_date['d']}/{$this->current_date['m']}/{$this->current_date['y']}' color='0022cc' showValue='1' anchorSides='10'>$current_period_xml_data1</dataset>";
			$result->xml .= "<dataset seriesname='$_label_data2 {$this->current_date['d']}/{$this->current_date['m']}/{$this->current_date['y']}' color='bbbbbb' showValue='1' anchorSides='10'>$current_period_xml_data2</dataset>";
		}
		else{
			if (!empty($previous_period_xml)){
				$result->xml .= "<dataset seriesname='$_label_data1 {$this->previous_month['m']}/{$this->previous_month['y']}' color='00aaff' showValue='1'>$previous_period_xml</dataset>";
				$result->xml .= "<dataset seriesname='$_label_data1 {$this->current_date['m']}/{$this->current_date['y']}' color='0022cc' showValue='1' anchorSides='10'>$current_period_xml_data1</dataset>";
				$result->xml .= "<dataset seriesname='$_label_data2 {$this->current_date['m']}/{$this->current_date['y']}' color='bbbbbb' showValue='1' anchorSides='10'>$current_period_xml_data2</dataset>";
			}
			else{
				$result->xml .= "<dataset seriesname='$_label_data1' color='0022cc' showValue='1' anchorSides='10'>$current_period_xml_data1</dataset>";
				$result->xml .= "<dataset seriesname='$_label_data2' color='bbbbbb' showValue='1' anchorSides='10'>$current_period_xml_data2</dataset>";
			}
		}
		$result->xml .= '</graph>';

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
?>