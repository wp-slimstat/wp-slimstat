<?php

// Let's define the main class with all the methods that we need
class wp_slimstat_view {

	public function __construct($user_filters = ''){
		global $wpdb, $table_prefix;

		// We use WP SlimStat tables to retrieve metrics
		$this->table_stats = $table_prefix . 'slim_stats';
		$this->table_countries = $table_prefix . 'slim_countries';
		$this->table_browsers = $table_prefix . 'slim_browsers';
		$this->table_screenres = $table_prefix . 'slim_screenres';
		$this->table_visits = $table_prefix . 'slim_visits';
		$this->table_outbound = $table_prefix . 'slim_outbound';

		// Start from...
		$this->starting_from = 0;

		// Limit results to...
		$this->limit_results = get_option('slimstat_rows_to_show', '20');

		// Calculate filters
		$this->filters_to_parse = array(
			'day' => 'integer',
			'month' => 'integer', 
			'year' => 'integer',
			'interval' => 'integer',
			'browser' => 'string',
			'version' => 'string',
			'css_version' => 'string',
			'country' => 'string',
			'domain' => 'string',
			'ip' => 'string',
			'language' => 'string',
			'platform' => 'string',
			'resource' => 'string',
			'referer' => 'string',
			'resolution' => 'string',
			'searchterms' => 'string'
		);

		$this->filters_parsed = array();
		
		foreach ($this->filters_to_parse as $a_filter_label => $a_filter_type){
			if(!empty($user_filters) && !empty($user_filters[$a_filter_label])){
				$f_value = ($a_filter_type == 'integer')?abs(intval($user_filters[$a_filter_label])):$wpdb->escape(htmlspecialchars(str_replace('\\', '', $user_filters[$a_filter_label])));
				$f_operator = !empty($user_filters[$a_filter_label.'-op'])?$wpdb->escape(htmlspecialchars(str_replace('\\', '', $user_filters[$a_filter_label.'-op']))):'equals';
				$this->filters_parsed[$a_filter_label] = array($f_value, $f_operator);
			}
			else if (!empty($_GET['filter']) && !empty($_GET['f_value']) && !empty($_GET['f_operator']) && $_GET['filter']==$a_filter_label){
				$f_value = ($a_filter_type == 'integer')?abs(intval($_GET['f_value'])):$wpdb->escape(htmlspecialchars(str_replace('\\', '', $_GET['f_value'])));
				$f_operator = $wpdb->escape(htmlspecialchars(str_replace('\\', '', $_GET['f_operator'])));
				$this->filters_parsed[$a_filter_label] = array($f_value, $f_operator);
			}
			else if(!empty($_GET[$a_filter_label])){
				$f_value = ($a_filter_type == 'integer')?abs(intval($_GET[$a_filter_label])):$wpdb->escape(htmlspecialchars(str_replace('\\', '', $_GET[$a_filter_label])));
				$f_operator = !empty($_GET[$a_filter_label.'-op'])?$wpdb->escape(htmlspecialchars(str_replace('\\', '', $_GET[$a_filter_label.'-op']))):'equals';
				$this->filters_parsed[$a_filter_label] = array($f_value, $f_operator);
			}
		}
	
		// Date filter
		$this->current_date = array();		
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
		$this->current_date_string = "{$this->current_date['y']}-{$this->current_date['m']}-{$this->current_date['d']}";

		$this->yesterday['d'] = date_i18n('d', strtotime("{$this->current_date['y']}-{$this->current_date['m']}-".($this->current_date['d'] - 1)) ); 
		$this->yesterday['m'] = date_i18n('m', strtotime("{$this->current_date['y']}-{$this->current_date['m']}-".($this->current_date['d'] - 1)) ); 
		$this->yesterday['y'] = date_i18n('Y', strtotime("{$this->current_date['y']}-{$this->current_date['m']}-".($this->current_date['d'] - 1)) ); 
		$this->yesterday_string = "{$this->yesterday['y']}-{$this->yesterday['m']}-{$this->yesterday['d']}";
	
		$this->previous_month['d'] = date_i18n('d', strtotime("{$this->current_date['y']}-".($this->current_date['m'] - 1)."-{$this->current_date['d']}") );
		$this->previous_month['m'] = date_i18n('m', strtotime("{$this->current_date['y']}-".($this->current_date['m'] - 1)."-{$this->current_date['d']}") );
		$this->previous_month['y'] = date_i18n('Y', strtotime("{$this->current_date['y']}-".($this->current_date['m'] - 1)."-{$this->current_date['d']}") );
		$this->previous_month_string = "{$this->previous_month['y']}-{$this->previous_month['m']}-{$this->previous_month['d']}";

		$this->filters_sql_from = array('browsers' => '', 'screenres' => '');
		$this->filters_sql_where = '';
		if (!empty($this->filters_parsed)){
			$this->filters_query = $filters_query;
			
			foreach($this->filters_parsed as $a_filter_label => $a_filter_details){
				// Skip filters on date
				if (($a_filter_label != 'day') && ($a_filter_label != 'month') && ($a_filter_label != 'year') && ($a_filter_label != 'interval')){
					
					// Filters on the IP address require a special treatment
					if ($a_filter_label == 'ip'){
						$a_filter_column = 'INET_NTOA(`ip`)';
					}
					else{
						$a_filter_column = "`$a_filter_label`";
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
						default:
							$this->filters_sql_where .= " AND $a_filter_column = '{$a_filter_details[0]}'";
					}
				}

				// Some columns are in separate tables, so we need to join these tables
				switch($a_filter_label){
					case 'browser':
					case 'platform':
					case 'version':
					case 'css_version':
						if (empty($this->filters_sql_from['browsers'])) $this->filters_sql_from['browsers'] = ", `$this->table_browsers` tb";
						$this->filters_sql_where .= " AND t1.`browser_id` = tb.`browser_id`";
						break;
					case 'resolution':
						if (empty($this->filters_sql_from['screenres'])) $this->filters_sql_from['screenres'] = ", `$this->table_screenres` tss";
						$this->filters_sql_where .= " AND t1.`screenres_id` = tss.`screenres_id`";
						break;
					default:
						break;
				}
			}
		}
		if (empty($this->day_interval)){
			if ($this->day_filter_active){
				$this->filters_date_sql_where = " AND (DATE_FORMAT(FROM_UNIXTIME(t1.`dt`), '%Y-%m-%d') = '$this->current_date_string')";
			}
			else{
				$this->filters_date_sql_where = " AND (DATE_FORMAT(FROM_UNIXTIME(t1.`dt`), '%Y-%m') = '{$this->current_date['y']}-{$this->current_date['m']}')";
			}
		}
		else{
			$this->filters_date_sql_where = " AND (DATE_FORMAT(FROM_UNIXTIME(t1.`dt`), '%Y-%m-%d') BETWEEN '$this->current_date_string' AND DATE_ADD('$this->current_date_string', INTERVAL $this->day_interval DAY ))";
		}
	}

	// Functions are declared in alphabetical order

	public function count_bots(){
		global $wpdb;

		$sql = "SELECT COUNT(`ip`)
				FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
				WHERE `visit_id` = 0 $this->filters_date_sql_where $this->filters_sql_where";
		return intval($wpdb->get_var($sql));
	}

	public function count_details_recent_visits(){
		global $wpdb;

		$sql = "SELECT COUNT(`ip`) count
				FROM `$this->table_stats` t1, `$this->table_browsers` tb {$this->filters_sql_from['screenres']}
				WHERE t1.`browser_id` = tb.`browser_id` AND t1.`visit_id` > 0 $this->filters_date_sql_where $this->filters_sql_where";
		return intval($wpdb->get_var($sql));
	}

	public function count_direct_visits(){
		global $wpdb;

		$sql = "SELECT COUNT(DISTINCT `id`) count
				FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
				WHERE `domain` = '' $this->filters_date_sql_where $this->filters_sql_where";
		return intval($wpdb->get_var($sql));
	}

	public function count_exit_pages(){
		global $wpdb;

		$sql = "SELECT COUNT(*) count
				FROM (
					SELECT `resource`, `visit_id`, `dt`
					FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
					WHERE `visit_id` > 0 AND `resource` <> '' AND resource <> '__l_s__' $this->filters_date_sql_where $this->filters_sql_where
					GROUP BY `visit_id`
					HAVING `dt` = MAX(`dt`)
				) AS ts";
		return intval($wpdb->get_var($sql));
	}

	public function count_new_visitors(){
		global $wpdb;

		$sql = "SELECT COUNT(*) FROM (
					SELECT `ip`
					FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
					WHERE 1=1 $this->filters_date_sql_where $this->filters_sql_where
					GROUP BY `ip`
					HAVING COUNT(`visit_id`) = 1)
				AS ts1";
		return intval($wpdb->get_var($sql));
	}

	public function count_pages_referred(){
		global $wpdb;

		$sql = "SELECT COUNT(DISTINCT `resource`) count
				FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
				WHERE `domain` <> '' $this->filters_date_sql_where $this->filters_sql_where";
		return intval($wpdb->get_var($sql));
	}

	public function count_plugin($_plugin_name = ''){
		global $wpdb;

		$sql = "SELECT COUNT(*) count
				FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
				WHERE `plugins` LIKE '%$_plugin_name%' AND `visit_id` > 0 $this->filters_date_sql_where $this->filters_sql_where";
		return intval($wpdb->get_var($sql));
	}
	
	public function count_raw_data(){
		global $wpdb;

		$sql = "SELECT COUNT(`ip`)
				FROM `$this->table_stats` t1
					LEFT JOIN `$this->table_browsers` tb ON t1.`browser_id` = tb.`browser_id`
					LEFT JOIN `$this->table_screenres` tss ON t1.`screenres_id` = tss.`screenres_id`
				WHERE 1=1 $this->filters_sql_where ".($this->custom_data_filter?$this->filters_date_sql_where:'');
		return intval($wpdb->get_var($sql));
	}
	
	public function count_recent($_field = 'id'){
		global $wpdb;

		$sql = "SELECT COUNT( DISTINCT `$_field`)
				FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
				WHERE t1.`$_field` <> '' AND  t1.`$_field` <> '__l_s__' $this->filters_sql_where $this->filters_date_sql_where";
		return intval($wpdb->get_var($sql));
	}
	
	public function count_recent_404_pages(){
		global $wpdb;

		$sql = "SELECT COUNT(`resource`)
				FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
				WHERE `resource` LIKE '[404]%' $this->filters_date_sql_where $this->filters_sql_where";
		return  intval($wpdb->get_var($sql));
	}
	
	public function count_recent_bouncing_pages(){
		global $wpdb;

		$sql = "SELECT COUNT(*) FROM (
					SELECT `resource`, LENGTH(`resource`) len
					FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
					WHERE `visit_id` <> 0 AND `resource` <> '__l_s__' AND `resource` <> '' $this->filters_sql_where $this->filters_date_sql_where
					GROUP BY `visit_id`
					HAVING COUNT(`visit_id`) = 1
				) as ts1";
		return intval($wpdb->get_var($sql));
	}

	public function count_recent_browsers(){
		global $wpdb;

		$sql = "SELECT COUNT(DISTINCT tb.`browser`, tb.`version`, tb.`css_version`)
				FROM `$this->table_stats` t1, `$this->table_browsers` tb {$this->filters_sql_from['screenres']}
				WHERE t1.`browser_id` = tb.`browser_id`
					AND tb.`platform` <> '' AND tb.`platform` <> '0'
					AND tb.`css_version` <> '' AND tb.`css_version` <> '0'
					$this->filters_sql_where $this->filters_date_sql_where";
		return intval($wpdb->get_var($sql));
	}

	public function count_referred_from_internal(){
		global $wpdb;

		$sql = "SELECT COUNT(DISTINCT `resource`) count
				FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
				WHERE `domain` = '{$_SERVER['SERVER_NAME']}' $this->filters_date_sql_where $this->filters_sql_where";
		return intval($wpdb->get_var($sql));
	}

	public function count_referers(){
		global $wpdb;

		$sql = "SELECT COUNT(*) count
				FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
				WHERE `referer` <> '' $this->filters_date_sql_where $this->filters_sql_where";
		return intval($wpdb->get_var($sql));
	}

	public function count_search_engines(){
		global $wpdb;

		$sql = "SELECT COUNT(DISTINCT `id`) count
				FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
				WHERE `searchterms` <> '' AND `domain` <> '{$_SERVER['SERVER_NAME']}' AND `domain` <> ''
					$this->filters_date_sql_where $this->filters_sql_where";
		return intval($wpdb->get_var($sql));
	}

	public function count_total_pageviews($_only_current_period = false){
		global $wpdb;

		$sql = "SELECT COUNT(*) count
				FROM `$this->table_stats` t1 ".
					($_only_current_period?$this->filters_sql_from['browsers'].$this->filters_sql_from['screenres']:'')."
				WHERE 1=1 ".($_only_current_period?$this->filters_date_sql_where.$this->filters_sql_where:'');
		return intval($wpdb->get_var($sql));
	}

	public function count_unique_ips(){
		global $wpdb;

		$sql = "SELECT COUNT(DISTINCT `ip`) count
				FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
				WHERE 1=1 $this->filters_date_sql_where $this->filters_sql_where";
		return intval($wpdb->get_var($sql));
	}

	public function count_unique_referers(){
		global $wpdb;

		$sql = "SELECT COUNT(DISTINCT `domain`) count
				FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
				WHERE `domain` <> '{$_SERVER['SERVER_NAME']}' AND `domain` <> '' $this->filters_date_sql_where $this->filters_sql_where";
		return intval($wpdb->get_var($sql));
	}

	public function get_average_pageviews_by_day(){
		if ($this->day_filter_active){			
			$sql = "SELECT DATE_FORMAT(FROM_UNIXTIME(`dt`), '%H') h, DATE_FORMAT(FROM_UNIXTIME(`dt`), '%d') d, AVG(ts1.count) data1, MAX(ts1.count) data2
					FROM (
						SELECT count(`ip`) count, `visit_id`, `dt`
						FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
						WHERE `visit_id` > 0 $this->filters_sql_where
						GROUP BY `visit_id`
					) AS ts1
					WHERE (DATE_FORMAT(FROM_UNIXTIME(`dt`), '%Y-%m-%d') = '$this->current_date_string'
						OR DATE_FORMAT(FROM_UNIXTIME(`dt`), '%Y-%m-%d') = '$this->yesterday_string')
						AND `visit_id` > 0
					GROUP BY h, d
					ORDER BY d ASC, h asc";
		}
		else{
			$time_constraints = " AND (DATE_FORMAT(FROM_UNIXTIME(`dt`), '%Y-%m') = '{$this->current_date['y']}-{$this->current_date['m']}'
				OR DATE_FORMAT(FROM_UNIXTIME(`dt`), '%Y-%m') = '{$this->previous_month['y']}-{$this->previous_month['m']}')";
				
			if (!empty($this->day_interval))
				$time_constraints = " AND (DATE_FORMAT(FROM_UNIXTIME(`dt`), '%Y-%m-%d') BETWEEN '$this->current_date_string' AND DATE_ADD('$this->current_date_string', INTERVAL $this->day_interval DAY )
				OR DATE_FORMAT(FROM_UNIXTIME(`dt`), '%Y-%m-%d') BETWEEN '$this->previous_month_string' AND DATE_ADD('$this->previous_month_string', INTERVAL $this->day_interval DAY ))";
				
			$sql = "SELECT DATE_FORMAT(FROM_UNIXTIME(`dt`), '%m') m, DATE_FORMAT(FROM_UNIXTIME(`dt`), '%d') d, AVG(ts1.count) data1, MAX(ts1.count) data2
					FROM (
						SELECT count(`ip`) count, `visit_id`, `dt`
						FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
						WHERE `visit_id` > 0 $time_constraints $this->filters_sql_where
						GROUP BY `visit_id`
					) AS ts1
					GROUP BY m, d
					ORDER BY m ASC, d asc";
		}
		return $this->_extract_data_for_graph($sql, 4, __('Avg Pageviews','wp-slimstat-view'), __('Longest visit','wp-slimstat-view'), 2);
	}

	public function get_browsers(){
		global $wpdb;

		$sql = "SELECT DISTINCT `browser`,`version`, COUNT(*) count
				FROM `$this->table_stats` t1, `$this->table_browsers` tb {$this->filters_sql_from['screenres']}
				WHERE t1.`browser_id` = tb.`browser_id` AND tb.`browser` <> '' $this->filters_date_sql_where $this->filters_sql_where
				GROUP BY `browser`, `version`
				ORDER BY count DESC, `browser` ASC
				LIMIT 0,$this->limit_results";
		return $wpdb->get_results($sql, ARRAY_A);
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
		return number_format($myTableSize, 2, ",", ".").' '.$suffix;
	}

	public function get_details_recent_visits(){
		global $wpdb;

		$sql = "SELECT INET_NTOA(`ip`) ip, `language`, `country`, `domain`, CONCAT(`domain`, SUBSTRING(`referer`,1,35)) domain_short, 
					`referer`, `resource`, `browser`, `visit_id`, `searchterms`, `platform`,
					DATE_FORMAT( FROM_UNIXTIME( `dt` ) , '%d/%m/%Y %H:%i' ) customdatetime
				FROM `$this->table_stats` t1, `$this->table_browsers` tb {$this->filters_sql_from['screenres']}
				WHERE t1.`browser_id` = tb.`browser_id` AND t1.`visit_id` > 0 $this->filters_date_sql_where $this->filters_sql_where
				ORDER BY `visit_id` DESC, `dt` ASC
				LIMIT $this->starting_from,$this->limit_results";
		return $wpdb->get_results($sql, ARRAY_A);
	}

	public function get_max_and_average_pages_per_visit(){
		global $wpdb;

		$sql = "SELECT AVG(ts1.count) avg, MAX(ts1.count) max FROM (
					SELECT count(`ip`) count, `visit_id`
					FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
					WHERE `visit_id` > 0 $this->filters_date_sql_where $this->filters_sql_where
					GROUP BY `visit_id`
				) AS ts1";

		$array_result = $wpdb->get_row($sql, ARRAY_A);
		$result->avg = sprintf("%01.2f", $array_result['avg']);
		$result->max = $array_result['max'];
		return $result;
	}

	public function get_other_referers(){
		global $wpdb;

		$sql = "SELECT `domain`, `referer`, COUNT(*) count
				FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
				WHERE `searchterms` = '' AND `domain` <> '{$_SERVER['SERVER_NAME']}' AND `domain` <> '' $this->filters_date_sql_where $this->filters_sql_where
				GROUP BY `domain`
				ORDER BY count DESC, `domain` ASC
				LIMIT 0,$this->limit_results";
		return $wpdb->get_results($sql, ARRAY_A);
	}

	public function get_pageviews_by_day(){
		if ($this->day_filter_active){
			$sql = "SELECT DATE_FORMAT(FROM_UNIXTIME(`dt`), '%H') h, DATE_FORMAT(FROM_UNIXTIME(`dt`), '%d') d, COUNT(`ip`) data1, COUNT(DISTINCT(`ip`)) data2
					FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
					WHERE (DATE_FORMAT(FROM_UNIXTIME(t1.`dt`), '%Y-%m-%d') = '$this->current_date_string'
						OR DATE_FORMAT(FROM_UNIXTIME(t1.`dt`), '%Y-%m-%d') = '$this->yesterday_string')
						$this->filters_sql_where
					GROUP BY h, d
					ORDER BY d ASC, h asc";
		}
		else{
			$time_constraints = "(DATE_FORMAT(FROM_UNIXTIME(t1.`dt`), '%Y-%m') = '{$this->current_date['y']}-{$this->current_date['m']}'
				OR DATE_FORMAT(FROM_UNIXTIME(t1.`dt`), '%Y-%m') = '{$this->previous_month['y']}-{$this->previous_month['m']}')";
			if (!empty($this->day_interval))
				$time_constraints = "(DATE_FORMAT(FROM_UNIXTIME(`dt`), '%Y-%m-%d') BETWEEN '$this->current_date_string' AND DATE_ADD('$this->current_date_string', INTERVAL $this->day_interval DAY )
				OR DATE_FORMAT(FROM_UNIXTIME(`dt`), '%Y-%m-%d') BETWEEN '$this->previous_month_string' AND DATE_ADD('$this->previous_month_string', INTERVAL $this->day_interval DAY ))";

			$sql = "SELECT DATE_FORMAT(FROM_UNIXTIME(`dt`), '%m') m, DATE_FORMAT(FROM_UNIXTIME(`dt`), '%d') d, COUNT(`ip`) data1, COUNT(DISTINCT(`ip`)) data2
					FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
					WHERE $time_constraints $this->filters_sql_where
					GROUP BY m, d
					ORDER BY m ASC,d ASC";
		}
		return $this->_extract_data_for_graph($sql, 1, __('Pageviews','wp-slimstat-view'), __('Unique IPs','wp-slimstat-view'), 0);
	}

	public function get_raw_data($_field = 'dt', $_direction = 'DESC'){
		global $wpdb;

		$sql = "SELECT INET_NTOA(`ip`) ip, `language`, `country`, CONCAT(`domain`, SUBSTRING(`referer`,1,25)) domain_short, CONCAT(`domain`, `referer`) domain,
					SUBSTRING(`searchterms`,1,60) searchterms, SUBSTRING(`resource`,1,80) resource, `browser`, `version`, `platform`, `plugins`,
					`resolution`, `colordepth`, DATE_FORMAT( FROM_UNIXTIME( `dt` ) , '%d/%m/%Y %H:%i' ) customdatetime
				FROM `$this->table_stats` t1
					LEFT JOIN `$this->table_browsers` tb ON t1.`browser_id` = tb.`browser_id`
					LEFT JOIN `$this->table_screenres` tss ON t1.`screenres_id` = tss.`screenres_id`
				WHERE 1=1 $this->filters_sql_where ".($this->custom_data_filter?$this->filters_date_sql_where:'')."
				ORDER BY `$_field` $_direction
				LIMIT $this->starting_from,50";
		return $wpdb->get_results($sql, ARRAY_A);
	}

	public function get_recent($_field = 'id', $_field2 = '', $_limit_lenght = 30){
		global $wpdb;

		$sql = "SELECT SUBSTRING(t1.`$_field`, 1, $_limit_lenght) short_string, t1.`$_field` long_string, LENGTH(t1.`$_field`) len,
				`searchterms`, `resource`, `country`, DATE_FORMAT( FROM_UNIXTIME( MAX(t1.`dt`) ), '%d/%m/%Y %H:%i' ) customdatetime ".(!empty($_field2)?", t1.`$_field2` $_field2 ":'')."
				FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
				WHERE t1.`$_field` <> '' AND  t1.`$_field` <> '__l_s__' $this->filters_sql_where $this->filters_date_sql_where
				GROUP BY short_string, long_string, len".(!empty($_field2)?", $_field2 ":'')."
				ORDER BY MAX(t1.`dt`) DESC
				LIMIT $this->starting_from,$this->limit_results";
		return $wpdb->get_results($sql, ARRAY_A);
	}

	public function get_recent_404_pages(){
		global $wpdb;

		$sql = "SELECT SUBSTRING(`resource`, 6, 30) short_string, `resource`, LENGTH(`resource`) len, INET_NTOA(`ip`) ip, `country`, `language`,
					CONCAT(`domain`, SUBSTRING(`referer`,1,25)) domain_short, CONCAT(`domain`, `referer`) domain,
					DATE_FORMAT( FROM_UNIXTIME( t1.`dt` ), '%d/%m/%Y %H:%i' ) customdatetime
				FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
				WHERE `resource` LIKE '[404]%' $this->filters_date_sql_where $this->filters_sql_where
				ORDER BY `dt` DESC
				LIMIT $this->starting_from,$this->limit_results";
		return $wpdb->get_results($sql, ARRAY_A);
	}
	
	public function get_recent_bouncing_pages(){
		global $wpdb;

		$sql = "SELECT SUBSTRING(`resource`, 1, 30) short_string, `resource`, LENGTH(`resource`) len,
					CONCAT(`domain`, SUBSTRING(`referer`,1,25)) domain_short, CONCAT(`domain`, `referer`) domain,
					DATE_FORMAT( FROM_UNIXTIME( MAX(t1.`dt`) ), '%d/%m/%Y %H:%i' ) customdatetime
				FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
				WHERE `visit_id` <> 0 AND `resource` <> '__l_s__' AND `resource` <> '' $this->filters_sql_where $this->filters_date_sql_where
				GROUP BY `visit_id`
				HAVING COUNT(`visit_id`) = 1
				ORDER BY MAX(`dt`) DESC, `resource` ASC
				LIMIT $this->starting_from,$this->limit_results";
		return $wpdb->get_results($sql, ARRAY_A);
	}

	public function get_recent_browsers(){
		global $wpdb;

		$sql = "SELECT SUBSTRING(tb.`browser`, 1, 23) as browser, tb.`version`, tb.`css_version`, t1.`resource`, t1.`country`,
					DATE_FORMAT( FROM_UNIXTIME( MAX(t1.`dt`) ), '%d/%m/%Y %H:%i' ) customdatetime
				FROM `$this->table_stats` t1, `$this->table_browsers` tb {$this->filters_sql_from['screenres']}
				WHERE t1.`browser_id` = tb.`browser_id`
					AND tb.`platform` <> '' AND tb.`platform` <> '0'
					AND tb.`css_version` <> '' AND tb.`css_version` <> '0'
					$this->filters_sql_where $this->filters_date_sql_where
				GROUP BY tb.`browser`, tb.`version`, tb.`css_version`
				ORDER BY MAX(`dt`) DESC
				LIMIT $this->starting_from,$this->limit_results";
		return $wpdb->get_results($sql, ARRAY_A);
	}
	
	public function get_recent_downloads(){
		global $wpdb;

		$sql = "SELECT SUBSTRING(`outbound_resource`, 1, 35) short_string, `outbound_resource`, LENGTH(`outbound_resource`) len
				FROM `$this->table_outbound` t1
				WHERE `type` = 1 $this->filters_date_sql_where
				ORDER BY `dt` DESC
				LIMIT 0,$this->limit_results";
		return $wpdb->get_results($sql, ARRAY_A);
	}

	public function get_recent_feeds(){
		global $wpdb;

		$sql = "SELECT SUBSTRING(`resource`, 1, 30) short_string, `resource`, LENGTH(`resource`) len
				FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
				WHERE (`resource` LIKE '%/feed' OR `resource` LIKE '%?feed=%' OR `resource` LIKE '%&feed=%')
					$this->filters_sql_where $this->filters_date_sql_where
				GROUP BY `resource`
				ORDER BY `dt` DESC
				LIMIT 0,$this->limit_results";
		return $wpdb->get_results($sql, ARRAY_A);
	}
	
	public function get_recent_internal_searches(){
		global $wpdb;

		$sql = "SELECT SUBSTRING(`searchterms`, 1, 30) short_string, `searchterms`, LENGTH(`searchterms`) len
				FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
				WHERE (`resource` = '__l_s__' OR `resource` = '') $this->filters_date_sql_where $this->filters_sql_where
				GROUP BY `searchterms`
				ORDER BY `dt` DESC
				LIMIT 0,$this->limit_results";
		return $wpdb->get_results($sql, ARRAY_A);
	}

	public function get_recent_keywords_pages(){
		global $wpdb;

		$sql = "SELECT SUBSTRING(`searchterms`, 1, 40) short_searchterms, `searchterms`, LENGTH(`searchterms`) len_searchterms,
						SUBSTRING(`resource`, 1, 40) short_resource, `resource`, LENGTH(`resource`) len_resource, 
						`domain`, `referer`, COUNT(*) count
				FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
				WHERE `searchterms` <> '' $this->filters_sql_where $this->filters_date_sql_where
				GROUP BY `searchterms`, `resource`, `domain`, `referer`
				ORDER BY `dt` DESC
				LIMIT 0,$this->limit_results";
		return $wpdb->get_results($sql, ARRAY_A);
	}

	public function get_recent_outbound(){
		global $wpdb;

		$sql = "SELECT SUBSTRING(CONCAT(t1.`outbound_domain`, t1.`outbound_resource`), 1, 35) short_outbound,
					CONCAT(t1.`outbound_domain`, t1.`outbound_resource`) long_outbound,
					LENGTH(CONCAT(t1.`outbound_domain`, t1.`outbound_resource`)) len_outbound,
					SUBSTRING(`resource`, 1, 35) short_resource, `resource`, LENGTH(`resource`) len_resource,
					t1.`outbound_domain`, t1.`outbound_resource`, t2.`resource`, t2.`ip`, t2.`searchterms`, t2.`country`, t1.`outbound_id`,
					DATE_FORMAT( FROM_UNIXTIME( t1.`dt` ) , '%H:%i' ) time_f, DATE_FORMAT( FROM_UNIXTIME( t1.`dt` ) , '%d/%m/%Y' ) date_f
				FROM `$this->table_outbound` t1, `$this->table_stats` t2
				WHERE t1.`id` = t2.`id` AND `type` = 0 $this->filters_date_sql_where
				ORDER BY t1.`dt` DESC
				LIMIT 0,$this->limit_results";
		return $wpdb->get_results($sql, ARRAY_A);
	}

	public function get_top($_field = 'id', $_field2 = '', $_limit_lenght = 30, $_only_current_month = false, $_limit_results = 0){
		global $wpdb;
		
		$limit_results = ($_limit_results==0)?$this->limit_results:$_limit_results;
		$sql = "SELECT DISTINCT SUBSTRING(`$_field`, 1, $_limit_lenght) short_string,`$_field` long_string, LENGTH(`$_field`) len, COUNT(*) count
				".(!empty($_field2)?", `$_field2` $_field2 ":'')."
				FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
				WHERE t1.`$_field` <> '' AND t1.`$_field` <> '__l_s__' ".($_only_current_month?$this->filters_date_sql_where:'')." $this->filters_sql_where
				GROUP BY long_string
				ORDER BY count DESC, `$_field` ASC
				LIMIT 0,$limit_results";
		return $wpdb->get_results($sql, ARRAY_A);
	}

	public function get_top_browsers_by_operating_system(){
		global $wpdb;

		$sql = "SELECT tb.`browser`, tb.`version`, tb.`platform`, COUNT(*) count
				FROM `$this->table_stats` t1, `$this->table_browsers` tb {$this->filters_sql_from['screenres']}
				WHERE t1.`browser_id` = tb.`browser_id` AND tb.`platform` <> '' AND tb.`platform` <> 'unknown' AND tb.`version` <> '' AND tb.`version` <> '0'
					$this->filters_sql_where $this->filters_date_sql_where
				GROUP BY tb.`browser`, tb.`version`, tb.`platform`
				ORDER BY count DESC, `browser` ASC
				LIMIT 0,$this->limit_results";
		return $wpdb->get_results($sql, ARRAY_A);
	}

	public function get_top_exit_pages(){
		global $wpdb;

		$sql = "SELECT SUBSTRING(ts.`resource`, 1, 50) short_string, ts.`resource`, LENGTH(ts.`resource`) len, COUNT(*) count
				FROM (
					SELECT `resource`, `visit_id`, `dt`
					FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
					WHERE `visit_id` > 0 AND `resource` <> '' AND resource <> '__l_s__' $this->filters_sql_where $this->filters_date_sql_where
					GROUP BY `visit_id`
					HAVING `dt` = MAX(`dt`)
				) AS ts
				GROUP BY ts.`resource`
				ORDER BY count DESC, ts.`resource` ASC
				LIMIT 0,$this->limit_results";
		return $wpdb->get_results($sql, ARRAY_A);
	}

	public function get_top_only_visits($_field = 'id', $_field2 = '', $_limit_lenght = 30){
		global $wpdb;

		$sql = "SELECT SUBSTRING(`$_field`, 1, $_limit_lenght) short_string,`$_field` long_string, LENGTH(`$_field`) len, COUNT(*) count
				".(!empty($_field2)?", `$_field2` $_field2 ":'')."
				FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
				WHERE `$_field` <> '' AND `visit_id` > 0 $this->filters_sql_where $this->filters_date_sql_where
				GROUP BY long_string
				ORDER BY count DESC, `$_field` ASC
				LIMIT 0,$this->limit_results";
		return $wpdb->get_results($sql, ARRAY_A);
	}

	public function get_top_operating_systems(){
		global $wpdb;

		$sql = "SELECT tb.`platform`, COUNT(*) count
				FROM `$this->table_stats` t1, `$this->table_browsers` tb {$this->filters_sql_from['screenres']}
				WHERE t1.`browser_id` = tb.`browser_id` AND tb.`platform` <> '' AND tb.`platform` <> 'unknown'
					$this->filters_sql_where $this->filters_date_sql_where
				GROUP BY tb.`platform`
				ORDER BY count DESC, `platform` ASC
				LIMIT 0,$this->limit_results";
		return $wpdb->get_results($sql, ARRAY_A);
	}

	public function get_top_screenres($_group_by_colordepth = false){
		global $wpdb;

		$sql = "SELECT tss.`resolution`, COUNT(*) count
				".(($_group_by_colordepth)?", tss.`colordepth`, tss.`antialias`":'')."
				FROM `$this->table_stats` t1, `$this->table_screenres` tss {$this->filters_sql_from['browsers']}
				WHERE t1.`screenres_id` = tss.`screenres_id` $this->filters_sql_where $this->filters_date_sql_where
				GROUP BY tss.`resolution` ".(($_group_by_colordepth)?", tss.`colordepth`, tss.`antialias`":'')."
				ORDER BY count DESC, `resolution` ASC
				LIMIT 0,$this->limit_results";
		return $wpdb->get_results($sql, ARRAY_A);
	}

	public function get_top_search_engines(){
		global $wpdb;

		$sql = "SELECT `domain`, COUNT(*) count
				FROM `$this->table_stats` t1
				{$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
				WHERE `searchterms` <> '' AND `domain` <> '{$_SERVER['SERVER_NAME']}' $this->filters_sql_where $this->filters_date_sql_where
				GROUP BY `domain`
				ORDER BY count DESC, `domain` ASC
				LIMIT 0,$this->limit_results";
		return $wpdb->get_results($sql, ARRAY_A);
	}

	public function get_traffic_sources_by_day(){
		if ($this->day_filter_active){
			$sql = "SELECT DATE_FORMAT(FROM_UNIXTIME(`dt`), '%H') h, DATE_FORMAT(FROM_UNIXTIME(`dt`), '%d') d, COUNT(`ip`) data1, COUNT(DISTINCT(`ip`)) data2
					FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
					WHERE (DATE_FORMAT(FROM_UNIXTIME(t1.`dt`), '%Y-%m-%d') = '$this->current_date_string'
						OR DATE_FORMAT(FROM_UNIXTIME(t1.`dt`), '%Y-%m-%d') = '$this->yesterday_string')
						AND `domain` <> '' AND `domain` <> '{$_SERVER['SERVER_NAME']}' $this->filters_sql_where
					GROUP BY h, d
					ORDER BY d ASC, h asc";
		}
		else{
			$time_constraints = " AND (DATE_FORMAT(FROM_UNIXTIME(t1.`dt`), '%Y-%m') = '{$this->current_date['y']}-{$this->current_date['m']}'
				OR DATE_FORMAT(FROM_UNIXTIME(t1.`dt`), '%Y-%m') = '{$this->previous_month['y']}-{$this->previous_month['m']}')";
			if (!empty($this->day_interval))
				$time_constraints = " AND (DATE_FORMAT(FROM_UNIXTIME(`dt`), '%Y-%m-%d') BETWEEN '$this->current_date_string' AND DATE_ADD('$this->current_date_string', INTERVAL $this->day_interval DAY )
				OR DATE_FORMAT(FROM_UNIXTIME(`dt`), '%Y-%m-%d') BETWEEN '$this->previous_month_string' AND DATE_ADD('$this->previous_month_string', INTERVAL $this->day_interval DAY ))";
	
			$sql = "SELECT DATE_FORMAT(FROM_UNIXTIME(`dt`), '%m') m, DATE_FORMAT(FROM_UNIXTIME(`dt`), '%d') d, COUNT(`ip`) data1, COUNT(DISTINCT(`ip`)) data2
					FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
					WHERE `domain` <> '' AND `domain` <> '{$_SERVER['SERVER_NAME']}' $time_constraints $this->filters_sql_where
					GROUP BY m, d
					ORDER BY m ASC,d ASC";
		}
		return $this->_extract_data_for_graph($sql, 3, __('Pageviews','wp-slimstat-view'), __('Unique IPs','wp-slimstat-view'), 0);
	}

	public function get_visits_by_day(){
		if ($this->day_filter_active){
			$sql = "SELECT DATE_FORMAT(FROM_UNIXTIME(`dt`), '%H') h, DATE_FORMAT(FROM_UNIXTIME(`dt`), '%d') d, COUNT(`ip`) data1, COUNT(DISTINCT(`ip`)) data2
					FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
					WHERE (DATE_FORMAT(FROM_UNIXTIME(t1.`dt`), '%Y-%m-%d') = '$this->current_date_string'
						OR DATE_FORMAT(FROM_UNIXTIME(t1.`dt`), '%Y-%m-%d') = '$this->yesterday_string')
						AND `visit_id` > 0 $this->filters_sql_where
					GROUP BY h, d
					ORDER BY d ASC, h asc";
		}
		else{
			$time_constraints = " AND (DATE_FORMAT(FROM_UNIXTIME(t1.`dt`), '%Y-%m') = '{$this->current_date['y']}-{$this->current_date['m']}'
				OR DATE_FORMAT(FROM_UNIXTIME(t1.`dt`), '%Y-%m') = '{$this->previous_month['y']}-{$this->previous_month['m']}')";
			if (!empty($this->day_interval))
				$time_constraints = " AND (DATE_FORMAT(FROM_UNIXTIME(`dt`), '%Y-%m-%d') BETWEEN '$this->current_date_string' AND DATE_ADD('$this->current_date_string', INTERVAL $this->day_interval DAY )
				OR DATE_FORMAT(FROM_UNIXTIME(`dt`), '%Y-%m-%d') BETWEEN '$this->previous_month_string' AND DATE_ADD('$this->previous_month_string', INTERVAL $this->day_interval DAY ))";

			$sql = "SELECT DATE_FORMAT(FROM_UNIXTIME(`dt`), '%m') m, DATE_FORMAT(FROM_UNIXTIME(`dt`), '%d') d, COUNT(`ip`) data1, COUNT(DISTINCT(`ip`)) data2
					FROM `$this->table_stats` t1 {$this->filters_sql_from['browsers']} {$this->filters_sql_from['screenres']}
					WHERE `visit_id` > 0 $time_constraints $this->filters_sql_where
					GROUP BY YEAR(FROM_UNIXTIME(`dt`)), m, d
					ORDER BY m ASC, d ASC";
		}
		return $this->_extract_data_for_graph($sql, 2, __('Visits','wp-slimstat-view'), __('Unique Visits','wp-slimstat-view'), 0);
	}

	private function _extract_data_for_graph($_sql, $_current_panel = 1, $_label_data1 = '', $_label_data2 = '', $_decimal_precision = 0){
		global $wpdb;

		// This SQL query has a standard format: grouped by day or hour and then data1 and data2 represent the information we want to extract
		$array_results = $wpdb->get_results($_sql, ARRAY_A);

		$array_current_period_data1 = $array_previous_period_data1 = $array_current_period_data2 = array();
		$current_non_zero_count = $previous_non_zero_count = 0;
		$current_period_xml_data1 = $current_period_xml_data2 = $previous_period_xml = $categories_xml = '';

		if (is_array($array_results) && !empty($array_results)){
			if ($this->day_filter_active){
				foreach($array_results as $a_result) {
					if($a_result['d'] == $this->current_date['d']) {
						$array_current_period_data1[intval($a_result['h'])] = $a_result['data1'];
						$array_current_period_data2[intval($a_result['h'])] = $a_result['data2'];
						if ($a_result['data1'] > 0) $current_non_zero_count++;
					}
					else {
						$array_previous_period_data1[intval($a_result['h'])] = $a_result['data1'];
						if ($a_result['data1'] > 0) $previous_non_zero_count++;
					}
				}
			}
			else{
				foreach($array_results as $a_result) {
					if($a_result['m'] == $this->current_date['m']) {
						$array_current_period_data1[intval($a_result['d'])] = $a_result['data1'];
						$array_current_period_data2[intval($a_result['d'])] = $a_result['data2'];
						if ($a_result['data1'] > 0) $current_non_zero_count++;
					}
					else {
						$array_previous_period_data1[intval($a_result['d'])] = $a_result['data1'];
						if ($a_result['data1'] > 0) $previous_non_zero_count++;
					}
				}
			}

			// Let's generate the XML for the flash chart
			if ($this->day_filter_active){

				for($i=0;$i<24;$i++) { // showing a hourly graph
					$categories_xml .= "<category name='$i'/>";
					$current_period_xml_data1 .= $this->_format_value($array_current_period_data1[$i]);
					$current_period_xml_data2 .= $this->_format_value($array_current_period_data2[$i]);
					$previous_period_xml .= $this->_format_value($array_previous_period_data1[$i]);
				}
			}
			else{
				// Days are clickable, so we need to carry the information about current filters
				$encoded_filters_query = urlencode(str_replace('interval=','xinterval=', $this->filters_query));

				for($i=1;$i<=31;$i++) { 
					$categories_xml .= "<category name='$i'/>";
					$current_period_xml_data1 .= $this->_format_value($array_current_period_data1[$i], "index.php%3Fpage=wp-slimstat/view/index.php%26slimpanel%3D$_current_panel%26day%3D$i%26month%3D{$this->current_date['m']}%26year%3D{$this->current_date['y']}$encoded_filters_query");
					$current_period_xml_data2 .= $this->_format_value($array_current_period_data2[$i]);
					$previous_period_xml .= $this->_format_value($array_previous_period_data1[$i], "index.php%3Fpage=wp-slimstat/view/index.php%26slimpanel%3D$_current_panel%26day%3D$i%26month%3D{$this->previous_month['m']}%26year%3D{$this->previous_month['y']}$encoded_filters_query");
				}
			}
		}

		$xml = "<graph canvasBorderThickness='0' yaxisminvalue='1' canvasBorderColor='ffffff' decimalPrecision='$_decimal_precision' divLineAlpha='20' formatNumberScale='0' lineThickness='2' showNames='1' showShadow='0' showValues='0' yAxisName='$_label_data1'>";
		$xml .= "<categories>$categories_xml</categories>";

		if ($this->day_filter_active){
			$xml .= "<dataset seriesname='$_label_data1";
			$xml .= " {$this->yesterday['d']}/{$this->yesterday['m']}/{$this->yesterday['y']}' color='00aaff' showValue='1'>$previous_period_xml</dataset>";
			$xml .= "<dataset seriesname='$_label_data1";
			$xml .= " {$this->current_date['d']}/{$this->current_date['m']}/{$this->current_date['y']}' color='0022cc' showValue='1' anchorSides='10'>$current_period_xml_data1</dataset>";
			$xml .= "<dataset seriesname='$_label_data2";
			$xml .= " {$this->current_date['d']}/{$this->current_date['m']}/{$this->current_date['y']}' color='bbbbbb' showValue='1' anchorSides='10'>$current_period_xml_data2</dataset>";
		}
		else{
			$xml .= "<dataset seriesname='$_label_data1";
			$xml .= " {$this->previous_month['m']}/{$this->previous_month['y']}' color='00aaff' showValue='1'>$previous_period_xml</dataset>";
			$xml .= "<dataset seriesname='$_label_data1";
			$xml .= " {$this->current_date['m']}/{$this->current_date['y']}' color='0022cc' showValue='1' anchorSides='10'>$current_period_xml_data1</dataset>";
			$xml .= "<dataset seriesname='$_label_data2";
			$xml .= " {$this->current_date['m']}/{$this->current_date['y']}' color='bbbbbb' showValue='1' anchorSides='10'>$current_period_xml_data2</dataset>";
		}
		$xml .= "</graph>";

		$result->xml = $xml;
		$result->current_data1 = $array_current_period_data1;
		$result->current_data2 = $array_current_period_data2;
		$result->previous_data1 = $array_previous_period_data1;
		$result->current_non_zero_count = $current_non_zero_count;
		$result->previous_non_zero_count = $previous_non_zero_count;

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