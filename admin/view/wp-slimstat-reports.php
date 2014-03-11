<?php

class wp_slimstat_reports{
	// Hidden filters are not displayed to the user, but are applied to the reports
	public static $hidden_filters = array('hour' => 1, 'day' => 1, 'month' => 1, 'year' => 1, 'interval' => 1, 'direction' => 1, 'limit_results' => 1, 'start_from' => 1);

	public static $screen_names = array();
	public static $dropdown_filter_names = array();
	
	// Variables used to generate the HTML code for the metaboxes
	public static $current_tab = 1;
	public static $view_url = '';
	public static $meta_report_order_nonce = '';

	// Tab panels drag-and-drop functionality
	public static $all_reports_titles = array();
	public static $all_reports = '';
	public static $hidden_reports = array();
	
	// Shared descriptions
	public static $chart_tooltip = '';

	/**
	 * Initalizes class properties
	 */
	public static function init(){
		self::$screen_names = array(
			1 => __('Activity Log','wp-slimstat'),
			2 => __('Overview','wp-slimstat'),
			3 => __('Visitors','wp-slimstat'),
			4 => __('Content','wp-slimstat'),
			5 => __('Traffic Sources','wp-slimstat'),
			6 => __('World Map','wp-slimstat'),
			7 => __('Custom Reports','wp-slimstat')
		);

		self::$all_reports_titles = array(
			'slim_p1_01' => __('Pageviews (chart)','wp-slimstat'),
			'slim_p1_02' => __('About WP SlimStat','wp-slimstat'),
			'slim_p1_03' => __('At a Glance','wp-slimstat'),
			'slim_p1_04' => __('Currently Online','wp-slimstat'),
			'slim_p1_05' => __('Spy View','wp-slimstat'),
			'slim_p1_06' => __('Recent Search Terms','wp-slimstat'),
			'slim_p1_08' => __('Top Pages','wp-slimstat'),
			'slim_p1_10' => __('Top Traffic Sources','wp-slimstat'),
			'slim_p1_11' => __('Top Known Visitors','wp-slimstat'),
			'slim_p1_12' => __('Top Search Terms','wp-slimstat'),
			'slim_p1_13' => __('Top Countries','wp-slimstat'),
			'slim_p1_15' => __('Rankings','wp-slimstat'),
			'slim_p1_17' => __('Top Language Families','wp-slimstat'),
			'slim_p2_01' => __('Human Visits (chart)','wp-slimstat'),
			'slim_p2_02' => __('At a Glance','wp-slimstat'),
			'slim_p2_03' => __('Top Languages','wp-slimstat'),
			'slim_p2_04' => __('Top Browsers','wp-slimstat'),
			'slim_p2_05' => __('Top Service Providers','wp-slimstat'),
			'slim_p2_06' => __('Top Operating Systems','wp-slimstat'),
			'slim_p2_07' => __('Top Screen Resolutions','wp-slimstat'),
			'slim_p2_09' => __('Browser Capabilities','wp-slimstat'),
			'slim_p2_10' => __('Top Countries','wp-slimstat'),
			'slim_p2_12' => __('Visit Duration','wp-slimstat'),
			'slim_p2_13' => __('Recent Countries','wp-slimstat'),
			'slim_p2_14' => __('Recent Screen Resolutions','wp-slimstat'),
			'slim_p2_15' => __('Recent Operating Systems','wp-slimstat'),
			'slim_p2_16' => __('Recent Browsers','wp-slimstat'),
			'slim_p2_17' => __('Recent Languages','wp-slimstat'),
			'slim_p2_18' => __('Top Browser Families','wp-slimstat'),
			'slim_p2_19' => __('Top OS Families','wp-slimstat'),
			'slim_p2_20' => __('Recent Users','wp-slimstat'),
			'slim_p2_21' => __('Top Users','wp-slimstat'),
			'slim_p3_01' => __('Traffic Sources (chart)','wp-slimstat'),
			'slim_p3_02' => __('Summary','wp-slimstat'),
			'slim_p3_03' => __('Top Search Terms','wp-slimstat'),
			'slim_p3_04' => __('Top Countries','wp-slimstat'),
			'slim_p3_05' => __('Top Traffic Sources','wp-slimstat'),
			'slim_p3_06' => __('Top Referring Search Engines','wp-slimstat'),
			'slim_p3_08' => __('Spy View','wp-slimstat'),
			'slim_p3_09' => __('Recent Search Terms','wp-slimstat'),
			'slim_p3_10' => __('Recent Countries','wp-slimstat'),
			'slim_p3_11' => __('Top Landing Pages','wp-slimstat'),
			'slim_p4_01' => __('Average Pageviews per Visit (chart)','wp-slimstat'),
			'slim_p4_02' => __('Recent Posts','wp-slimstat'),
			'slim_p4_03' => __('Recent Bounce Pages','wp-slimstat'),
			'slim_p4_04' => __('Recent Feeds','wp-slimstat'),
			'slim_p4_05' => __('Recent Pages Not Found','wp-slimstat'),
			'slim_p4_06' => __('Recent Internal Searches','wp-slimstat'),
			'slim_p4_07' => __('Top Categories','wp-slimstat'),
			'slim_p4_08' => __('Recent Outbound Links','wp-slimstat'),
			'slim_p4_10' => __('Recent Events','wp-slimstat'),
			'slim_p4_11' => __('Top Posts','wp-slimstat'),
			'slim_p4_12' => __('Top Feeds','wp-slimstat'),
			'slim_p4_13' => __('Top Internal Searches','wp-slimstat'),
			'slim_p4_14' => __('Top Search Terms','wp-slimstat'),
			'slim_p4_15' => __('Recent Categories','wp-slimstat'),
			'slim_p4_16' => __('Top Pages Not Found','wp-slimstat'),
			'slim_p4_17' => __('Top Landing Pages','wp-slimstat'),
			'slim_p4_18' => __('Top Authors','wp-slimstat'),
			'slim_p4_19' => __('Top Tags','wp-slimstat'),
			'slim_p4_20' => __('Recent Downloads','wp-slimstat'),
			'slim_p4_21' => __('Top Outbound Links and Downloads','wp-slimstat'),
			'slim_p4_22' => __('Your Website','wp-slimstat'),
			'slim_p6_01' => __('World Map','wp-slimstat'),
			'slim_p7_02' => __('At A Glance','wp-slimstat')
		);

		
		if (!empty($_GET['page'])){
			self::$current_tab = intval(str_replace('wp-slim-view-', '', $_GET['page']));
		}
		else if (!empty($_POST['current_tab'])){
			self::$current_tab = intval($_POST['current_tab']);
		}
		self::$view_url = ((wp_slimstat::$options['use_separate_menu'] == 'yes')?'admin.php':'options.php').'?page=wp-slim-view-'.self::$current_tab;

		// TO BE REVIEWED AND CLEANED UP
		self::$meta_report_order_nonce = wp_create_nonce('meta-box-order');

		// Retrieve the order of this tab's panels and which ones are hidden
		$user = wp_get_current_user();
		$page_location = (wp_slimstat::$options['use_separate_menu'] == 'yes')?'slimstat':'admin';

		self::$all_reports_titles = apply_filters('slimstat_report_titles', self::$all_reports_titles);

		if (self::$current_tab != 1){
			self::$all_reports = get_user_option("meta-box-order_{$page_location}_page_wp-slim-view-".self::$current_tab, $user->ID);
			self::$all_reports = (self::$all_reports === false)?get_user_option("meta-box-order_{$page_location}_page_wp-slimstat", $user->ID):self::$all_reports; // backward compatible with old settings
		}
		self::$all_reports = (empty(self::$all_reports) || empty(self::$all_reports[0]))?array():explode(',', self::$all_reports[0]);

		$all_existing_reports = array(
			0 => array(),
			1 => array('slim_p7_02'),
			2 => array('slim_p1_01','slim_p1_02','slim_p1_03','slim_p1_04','slim_p1_11','slim_p1_12','slim_p1_05','slim_p1_08','slim_p1_10','slim_p1_13','slim_p1_15','slim_p1_17'),
			3 => array('slim_p2_01','slim_p2_02','slim_p2_03','slim_p2_04','slim_p2_06','slim_p2_05','slim_p2_07','slim_p2_09','slim_p2_10','slim_p2_12','slim_p2_13','slim_p2_14','slim_p2_15','slim_p2_16','slim_p2_17','slim_p2_18','slim_p2_19','slim_p2_20','slim_p2_21'),
			4 => array('slim_p4_01','slim_p4_22','slim_p1_06','slim_p4_07','slim_p4_02','slim_p4_03','slim_p4_05','slim_p4_04','slim_p4_06','slim_p4_08','slim_p4_12','slim_p4_13','slim_p4_14','slim_p4_15','slim_p4_16','slim_p4_17','slim_p4_18','slim_p4_11','slim_p4_10','slim_p4_19','slim_p4_20','slim_p4_21'),
			5 => array('slim_p3_01','slim_p3_02','slim_p3_03','slim_p3_04','slim_p3_06','slim_p3_05','slim_p3_08','slim_p3_10','slim_p3_09','slim_p3_11'),
			6 => array('slim_p6_01'),
			7 => array()
		);

		// Some boxes are hidden by default
		if (!empty($_GET['page']) && strpos($_GET['page'], 'wp-slim-view-') !== false){
			self::$hidden_reports = get_user_option("metaboxhidden_{$page_location}_page_wp-slim-view-".self::$current_tab, $user->ID);
			if (empty(self::$all_reports)){
				self::$all_reports = $all_existing_reports[self::$current_tab];
			}
			else{
				self::$all_reports = array_intersect(self::$all_reports, $all_existing_reports[self::$current_tab]);
			}
		}
		else{ // the script is being called from the dashboard widgets plugin
			self::$hidden_reports = get_user_option("metaboxhidden_{$page_location}", $user->ID);
		}
		
		// Default values
		if (self::$hidden_reports === false){
			switch(self::$current_tab){
				case 2:
					self::$hidden_reports = array('slim_p1_02', 'slim_p1_11', 'slim_p1_12', 'slim_p1_13','slim_p1_17');
					break;
				case 3:
					self::$hidden_reports = array('slim_p2_05', 'slim_p2_07', 'slim_p2_09', 'slim_p2_13', 'slim_p2_14', 'slim_p2_15', 'slim_p2_16', 'slim_p2_17', 'slim_p2_18', 'slim_p2_19', 'slim_p2_20', 'slim_p2_21');
					break;
				case 4:
					self::$hidden_reports = array('slim_p4_11', 'slim_p4_12', 'slim_p4_13', 'slim_p4_14', 'slim_p4_15', 'slim_p4_16', 'slim_p4_17');
					break;
				case 5:
					self::$hidden_reports = array('slim_p3_09', 'slim_p3_10');
					break;
				default:
					self::$hidden_reports = array();
			}
		}
		// END PART TO BE REVIEWED AND CLEANED UP

		// Filters use the following format: browser equals Firefox|country contains gb
		$filters = array();
		if (!empty($_REQUEST['fs']) && is_array($_REQUEST['fs'])){
			foreach($_REQUEST['fs'] as $a_request_filter_name => $a_request_filter_value){
				$filters[] = "$a_request_filter_name $a_request_filter_value";
			}
		}

		// Fields and drop downs 
		if (!empty($_POST['f']) && !empty($_POST['o'])){
			$filters[] = "{$_POST['f']} {$_POST['o']} ".(isset($_POST['v'])?$_POST['v']:'');
		}
		if (!empty($_POST['day'])){
			$filters[] = "day equals {$_POST['day']}";
		}
		if (!empty($_POST['month'])){
			$filters[] = "month equals {$_POST['month']}";
		}
		if (!empty($_POST['year'])){
			$filters[] = "year equals {$_POST['year']}";
		}
		if (!empty($_POST['interval'])){
			$filters[] = "interval equals {$_POST['interval']}";
		}

		// Hidden Filters
		if (wp_slimstat::$options['restrict_authors_view'] == 'yes' && !current_user_can('manage_options')){
			$filters[] = 'author equals '.$GLOBALS['current_user']->user_login;
			self::$hidden_filters['author'] = 1;
		}

		// Allow third-party add-ons to modify filters before they are used
		$filters = apply_filters('slimstat_modify_admin_filters', $filters);
		if (!empty($filters)){
			$filters = implode('&&&', $filters);
		}

		// Import and initialize the API to interact with the database
		include_once(WP_PLUGIN_DIR."/wp-slimstat/admin/view/wp-slimstat-db.php");
		wp_slimstat_db::init($filters);

		// Some of the filters supported by the API do not appear in the dropdown
 		self::$dropdown_filter_names = array_diff_key(wp_slimstat_db::$filter_names, array('hour' => 1, 'day' => 1, 'month' => 1, 'year' => 1, 'interval' => 1, 'direction' => 1, 'limit_results' => 1, 'start_from' => 1, 'strtotime' => 1));

		// Default text for the inline help associated to the chart
		self::$chart_tooltip = '<strong>'.__('Chart controls','wp-slimstat').'</strong><ul><li>'.__('Use your mouse wheel to zoom in and out','wp-slimstat').'</li><li>'.__('While zooming in, drag the chart to move to a different area','wp-slimstat').'</li><li>'.__('Double click on an empty region to reset the zoom level','wp-slimstat').'</li>';
		self::$chart_tooltip .= empty(wp_slimstat_db::$filters_normalized['date']['day'])?'<li>'.__('Click on a data point to display the activity chart for each hour of that day','wp-slimstat').'</li>':'';
	}
	// end init

	public static function fs_url($_filters = '', $_view_url = ''){
		$filtered_url = !empty($_view_url)?$_view_url:self::$view_url;

		// Backward compatibility
		if (is_array($_filters)){
			$flat_filters = array();
			foreach($_filters as $a_key => $a_filter_data){
				$flat_filters[] = "$a_key $a_filter_data";
			}
			$_filters = implode('&&&', $flat_filters);
		}

		// Columns
		$filters_normalized = wp_slimstat_db::parse_filters($_filters, false);
		if (!empty($filters_normalized['columns'])){
			foreach($filters_normalized['columns'] as $a_key => $a_filter){
				$filtered_url .= "&amp;fs%5B$a_key%5D=".urlencode($a_filter[0].' '.$a_filter[1]);
			}
		}

		// Date ranges
		if (!empty($filters_normalized['date'])){
			foreach($filters_normalized['date'] as $a_key => $a_filter){
				$filtered_url .= "&amp;fs%5B$a_key%5D=".urlencode('equals '.$a_filter);
			}
		}

		// Misc filters
		if (!empty($filters_normalized['misc'])){
			foreach($filters_normalized['misc'] as $a_key => $a_filter){
				$filtered_url .= "&amp;fs%5B$a_key%5D=".urlencode('equals '.$a_filter);
			}
		}

		return $filtered_url;
	}
	
	public static function get_search_terms_info($_searchterms = '', $_domain = '', $_referer = '', $_serp_only = false){
		$query_details = '';
		$search_terms_info = '';

		parse_str("daum=search?q&naver=search.naver?query&google=search?q&yahoo=search?p&bing=search?q&aol=search?query&lycos=web?q&ask=web?q&cnn=search/?query&about=?q&mamma=result.php?q&voila=S/voila?rdata&virgilio=ricerca?qs&baidu=s?wd&yandex=yandsearch?text&najdi=search.jsp?q&seznam=?q&onet=wyniki.html?qt&yam=Search/Web/DefaultCSA.aspx?k&pchome=/search/?q&kvasir=alle?q&arama.mynet=web/goal/1/?q&nova_rambler=search?query", $query_formats);
		preg_match("/(daum|naver|google|yahoo|bing|aol|lycos|ask|cnn|about|mamma|voila|virgilio|baidu|yandex|najdi|seznam|onet|szukacz|yam|pchome|kvasir|mynet|ekolay|rambler)./", $_domain, $matches);
		parse_str($_referer, $query_parse_str);

		if (!empty($query_parse_str['source']) && !$_serp_only) $query_details = __('src','wp-slimstat').": {$query_parse_str['source']}";
		if (!empty($query_parse_str['cd'])) $query_details = __('serp','wp-slimstat').": {$query_parse_str['cd']}";
		if (!empty($query_details)) $query_details = "($query_details)";

		if (!empty($_searchterms)){		
			if (!empty($matches) && !empty($query_formats[$matches[1]])){
				$search_terms_info = htmlentities($_searchterms, ENT_QUOTES, 'UTF-8').'<a class="slimstat-font-logout" target="_blank" title="'.htmlentities(__('Go to the corresponding search engine result page','wp-slimstat'), ENT_QUOTES, 'UTF-8').'" href="http://'.$_domain.'/'.$query_formats[$matches[1]].'='.urlencode($_searchterms).'"></a>';
			}
			else{
				$search_terms_info = htmlentities($_searchterms, ENT_QUOTES, 'UTF-8').'<a class="slimstat-font-logout" target="_blank" title="'.htmlentities(__('Go to the referring page','wp-slimstat'), ENT_QUOTES, 'UTF-8').'" href="'.$_referer.'"></a>';
			}
			$search_terms_info = "$search_terms_info $query_details";
		}
		return $search_terms_info;
	}

	/**
	 * Generate the HTML that lists all the filters currently used
	 */
	public static function get_filters_html($_filters_array = array()){
		$filters_html = '';

		// Don't display direction and limit results
		$filters_dropdown = array_diff_key($_filters_array, self::$hidden_filters);

		if (!empty($filters_dropdown)){
			foreach($filters_dropdown as $a_filter_label => $a_filter_details){
				if (!array_key_exists($a_filter_label, wp_slimstat_db::$filter_names) || strpos($a_filter_label, 'no_filter') !== false){
					continue;
				}

				$a_filter_value_no_slashes = htmlentities(str_replace('\\','', $a_filter_details[1]), ENT_QUOTES, 'UTF-8');
				$filters_html .= "<li>".wp_slimstat_db::$filter_names[$a_filter_label].' '.__(str_replace('_', ' ', $a_filter_details[0]),'wp-slimstat')." $a_filter_value_no_slashes <a class='slimstat-remove-filter slimstat-font-cancel' title='".htmlentities(__('Remove filter for','wp-slimstat'), ENT_QUOTES, 'UTF-8').' '.wp_slimstat_db::$filter_names[$a_filter_label]."' href='".self::fs_url("$a_filter_label equals ")."'></a></li>";
			}
		}
		if (!empty($filters_html)){
			$filters_html = "<ul class='slimstat-filter-list'>$filters_html</ul>";
		}
		if(count($filters_dropdown) > 1){
			$filters_html .= '<a href="'.self::fs_url().'" id="slimstat-remove-all-filters" class="button-secondary">'.__('Reset All','wp-slimstat').'</a>';
		}

		return ($filters_html != "<span class='filters-title'>".__('Current filters:','wp-slimstat').'</span> ')?$filters_html:'';
	}

	public static function report_header($_id = 'p0', $_postbox_class = 'normal', $_tooltip = '', $_title = ''){
		$header_buttons = '<a class="button-ajax refresh slimstat-font-spin3" title="'.__('Refresh','wp-slimstat').'" href="'.wp_slimstat_reports::fs_url().'"></a>';
		$header_buttons = apply_filters('slimstat_report_header_buttons', $header_buttons, $_id);
		$header_buttons = '<div class="slimstat-header-buttons">'.$header_buttons.'</div>';
		
		$header_tooltip = !empty($_tooltip)?"<i class='slimstat-tooltip-trigger corner'></i><span class='slimstat-tooltip-content'>$_tooltip</span>":'';

		echo "<div class='postbox {$_postbox_class}' id='$_id'".(in_array($_id, self::$hidden_reports)?' style="display:none"':'').">$header_buttons<h3 class='hndle'>".(!empty($_title)?$_title:self::$all_reports_titles[$_id])."$header_tooltip</h3><div class='inside' id='{$_id}_inside'>";
		if (wp_slimstat::$options['async_load'] == 'yes') echo '<p class="loading"></p>';
	}

	public static function report_footer(){
		echo '</div></div>';
	}

	public static function report_pagination($_id = 'p0', $_count_page_results = 0, $_count_all_results = 0){
		$endpoint = min($_count_all_results, wp_slimstat_db::$filters_normalized['misc']['start_from'] + wp_slimstat_db::$filters_normalized['misc']['limit_results']);
		$pagination_buttons = '';
		$direction_prev = is_rtl()?'right':'left';
		$direction_next = is_rtl()?'left':'right';

		if ($endpoint + wp_slimstat_db::$filters_normalized['misc']['limit_results'] < $_count_all_results && $_count_page_results > 0){
			$startpoint = $_count_all_results - $_count_all_results%wp_slimstat_db::$filters_normalized['misc']['limit_results'];
			if ($startpoint == $_count_all_results) $startpoint -= wp_slimstat_db::$filters_normalized['misc']['limit_results'];
			$pagination_buttons .= '<a class="button-ajax slimstat-font-angle-double-'.$direction_next.'" href="'.wp_slimstat_reports::fs_url('start_from equals '.$startpoint).'"></a> ';
		}
		if ($endpoint < $_count_all_results && $_count_page_results > 0){
			$startpoint = wp_slimstat_db::$filters_normalized['misc']['start_from'] + wp_slimstat_db::$filters_normalized['misc']['limit_results'];
			$pagination_buttons .= '<a class="button-ajax slimstat-font-angle-'.$direction_next.'" href="'.wp_slimstat_reports::fs_url('start_from equals '.$startpoint).'"></a> ';
		}
		if (wp_slimstat_db::$filters_normalized['misc']['start_from'] > 0){
			$startpoint = (wp_slimstat_db::$filters_normalized['misc']['start_from'] > wp_slimstat_db::$filters_normalized['misc']['limit_results'])?wp_slimstat_db::$filters_normalized['misc']['start_from']-wp_slimstat_db::$filters_normalized['misc']['limit_results']:0;
			$pagination_buttons .= '<a class="button-ajax slimstat-font-angle-'.$direction_prev.'" href="'.wp_slimstat_reports::fs_url('start_from equals '.$startpoint).'"></a> ';
		}
		if (wp_slimstat_db::$filters_normalized['misc']['start_from'] - wp_slimstat_db::$filters_normalized['misc']['limit_results'] > 0){
			$pagination_buttons .= '<a class="button-ajax slimstat-font-angle-double-'.$direction_prev.'" href="'.wp_slimstat_reports::fs_url('start_from equals 0').'"></a> ';
		}

		$pagination = '<p class="pagination">'.sprintf(__('Results %s - %s of %s', 'wp-slimstat'), number_format(wp_slimstat_db::$filters_normalized['misc']['start_from']+1, 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']), number_format($endpoint, 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']), number_format($_count_all_results, 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']));
		if (wp_slimstat::$options['refresh_interval'] > 0 && $_id == 'slim_p7_02'){
			$pagination .= ' &ndash; '.__('Refresh in','wp-slimstat').' <i class="refresh-timer"></i>';
		}
		$pagination .= $pagination_buttons.'</p>';

		echo $pagination;
	}
	
	/**
	 * Attempts to convert a permalink into a post title
	 */
	public static function get_resource_title($_resource = ''){
		if (wp_slimstat::$options['convert_resource_urls_to_titles'] == 'yes'){	
			$post_id = url_to_postid(strtok($_resource, '?'));
			if ($post_id > 0){
				return get_the_title($post_id);
			}
		}
		return htmlentities(urldecode($_resource), ENT_QUOTES, 'UTF-8');
	}
	
	public static function chart_title($_title = ''){
		if (!empty(wp_slimstat_db::$filters_normalized['date']['interval'])){
			return sprintf(__('Daily %s','wp-slimstat'), $_title);
		}
		else if (!empty(wp_slimstat_db::$filters_normalized['date']['hour'])){
			return sprintf(__('%s Minute by Minute','wp-slimstat'), $_title);
		}
		else if (!empty(wp_slimstat_db::$filters_normalized['date']['day'])){
			return sprintf(__('Hourly %s','wp-slimstat'), $_title);
		}
		else if (!empty(wp_slimstat_db::$filters_normalized['date']['year'])){
			return sprintf(__('Monthly %s','wp-slimstat'), $_title);
		}
		else{
			return sprintf(__('Daily %s','wp-slimstat'), $_title);
		}
	}
	
	public static function inline_help($_text = '', $_echo = true){
		$wrapped_text = "<i class='slimstat-tooltip-trigger corner'></i><span class='slimstat-tooltip-content'>$_text</span>";
		if ($_echo)
			echo $wrapped_text;
		else
			return $wrapped_text;
	}

	public static function show_results($_type = 'recent', $_id = 'p0', $_column = 'id', $_args = array()){
		// Initialize default values, if not specified
		$_args = array_merge(array('custom_where' => '', 'more_columns' => '', 'join_tables' => '', 'having_clause' => '', 'order_by' => '', 'total_for_percentage' => 0, 'as_column' => '', 'filter_op' => 'equals', 'use_date_filters' => true), $_args);
		$column = !empty($_args['as_column'])?$_column:wp_slimstat_db::get_table_identifier($_column).$_column;

		// Get ALL the results
		$temp_starting = wp_slimstat_db::$filters_normalized['misc']['start_from'];
		$temp_limit_results = wp_slimstat_db::$filters_normalized['misc']['limit_results'];
		wp_slimstat_db::$filters_normalized['misc']['start_from'] = 0;
		wp_slimstat_db::$filters_normalized['misc']['limit_results'] = 9999;

		//$count_all_results = wp_slimstat_db::count_records();
		switch($_type){
			case 'recent':
				$all_results = wp_slimstat_db::get_recent($column, $_args['custom_where'], $_args['join_tables'], $_args['having_clause'], $_args['order_by'], $_args['use_date_filters']);
				break;
			case 'popular':
				$all_results = wp_slimstat_db::get_popular($column, $_args['custom_where'], $_args['more_columns'], $_args['having_clause'], $_args['as_column']);
				break;
			case 'popular_complete':
				$all_results = wp_slimstat_db::get_popular_complete($column, $_args['custom_where'], $_args['join_tables'], $_args['having_clause']);
				break;
			case 'popular_outbound':
				$all_results = wp_slimstat_db::get_popular_outbound();
				break;
			default:
		}

		// Restore the filter
		wp_slimstat_db::$filters_normalized['misc']['start_from'] = $temp_starting;
		wp_slimstat_db::$filters_normalized['misc']['limit_results'] = $temp_limit_results;

		// Slice the array
		$results = array_slice($all_results, wp_slimstat_db::$filters_normalized['misc']['start_from'], wp_slimstat_db::$filters_normalized['misc']['limit_results']);

		$count_page_results = count($results);
		
		if ($count_page_results == 0){
			echo '<p class="nodata">'.__('No data to display','wp-slimstat').'</p>';
			return true;
		}

		// Sometimes we use aliases for columns
		if (!empty($_args['as_column'])){
			$_column = trim(str_replace('AS', '', $_args['as_column']));
		}

		self::report_pagination($_id, $count_page_results, count($all_results));
		$is_expanded = (wp_slimstat::$options['expand_details'] == 'yes')?' expanded':'';

		for($i=0;$i<$count_page_results;$i++){
			$row_details = $percentage = '';
			$element_pre_value = '';
			$element_value = $results[$i][$_column];

			// Convert the IP address
			if (!empty($results[$i]['ip'])) $results[$i]['ip'] = long2ip($results[$i]['ip']);

			// Some columns require a special pre-treatment
			switch ($_column){
				case 'browser':
					if (!empty($results[$i]['user_agent']) && wp_slimstat::$options['show_complete_user_agent_tooltip'] == 'yes') $element_pre_value = self::inline_help($results[$i]['user_agent'], false);
					$element_value = $results[$i]['browser'].((isset($results[$i]['version']) && intval($results[$i]['version']) != 0)?' '.$results[$i]['version']:'');
					break;
				case 'category':
					$row_details .= '<br>'.__('Category ID','wp-slimstat').": {$results[$i]['category']}";
					$cat_ids = explode(',', $results[$i]['category']);
					if (!empty($cat_ids)){
						$element_value = '';
						foreach ($cat_ids as $a_cat_id){
							if (empty($a_cat_id)) continue;
							$cat_name = get_cat_name($a_cat_id);
							if (empty($cat_name)) {
								$tag = get_term($a_cat_id, 'post_tag');
								if (!empty($tag->name)) $cat_name = $tag->name;
							}
							$element_value .= ', '.(!empty($cat_name)?$cat_name:$a_cat_id);
						}
						$element_value = substr($element_value, 2);
					}
					break;
				case 'country':
					$row_details .= '<br>'.__('Country Code','wp-slimstat').": {$results[$i]['country']}";
					$element_value = __('c-'.$results[$i]['country'], 'wp-slimstat');
					break;
				case 'ip':
					if (wp_slimstat::$options['convert_ip_addresses'] == 'yes'){
						$element_value = gethostbyaddr($results[$i]['ip']);
					}
					else{
						$element_value = $results[$i]['ip'];
					}
					break;
				case 'language':
					$row_details = '<br>'.__('Language Code','wp-slimstat').": {$results[$i]['language']}";
					$element_value = __('l-'.$results[$i]['language'], 'wp-slimstat');
					break;
				case 'platform':
					$row_details = '<br>'.__('OS Code','wp-slimstat').": {$results[$i]['platform']}";
					$element_value = __($results[$i]['platform'], 'wp-slimstat');
					break;
				case 'resource':
					$post_id = url_to_postid(strtok($results[$i]['resource'], '?'));
					if ($post_id > 0) $row_details = '<br>'.htmlentities($results[$i]['resource'], ENT_QUOTES, 'UTF-8');
					$element_value = self::get_resource_title($results[$i]['resource']);
					break;
				case 'searchterms':
					if ($_type == 'recent'){
						$row_details = '<br>'.__('Referrer','wp-slimstat').": {$results[$i]['domain']}";
						$element_value = self::get_search_terms_info($results[$i]['searchterms'], $results[$i]['domain'], $results[$i]['referer'], true);
					}
					else{
						$element_value = htmlentities($results[$i]['searchterms'], ENT_QUOTES, 'UTF-8');
					}
					break;
				case 'user':
					$element_value = $results[$i]['user'];
					if (wp_slimstat::$options['show_display_name'] == 'yes' && strpos($results[$i]['notes'], 'user:') !== false){
						$element_custom_value = get_user_by('login', $results[$i]['user']);
						if (is_object($element_custom_value)) $element_value = $element_custom_value->display_name;
					}
					break;
				default:
			}
			
			$element_value = "<a class='slimstat-filter-link' href='".self::fs_url($_column.' '.$_args['filter_op'].' '.$results[$i][$_column])."'>$element_value</a>";

			if ($_type == 'recent'){
				$row_details = date_i18n(wp_slimstat_db::$formats['date_time_format'], $results[$i]['dt'], true).$row_details;
			}
			else{
				$percentage = '<span>'.(($_args['total_for_percentage'] > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$_args['total_for_percentage'])), 2, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']):0).'%</span>';
				$row_details = __('Hits','wp-slimstat').': '.number_format($results[$i]['count'], 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']).$row_details;
			}

			// Some columns require a special post-treatment
			if ($_column == 'resource' && strpos($_args['custom_where'], '404') === false){
				$element_value = '<a target="_blank" class="slimstat-font-logout" title="'.__('Open this URL in a new window','wp-slimstat').'" href="'.htmlentities($results[$i]['resource'], ENT_QUOTES, 'UTF-8').'"></a> '.$element_value;
			}
			if ($_column == 'domain'){
				$element_url = htmlentities((strpos($results[$i]['referer'], '://') == false)?"http://{$results[$i]['domain']}{$results[$i]['referer']}":$results[$i]['referer'], ENT_QUOTES, 'UTF-8');
				$element_value = '<a target="_blank" class="slimstat-font-logout" title="'.__('Open this URL in a new window','wp-slimstat').'" href="'.$element_url.'"></a> '.$element_value;
			}
			if (!empty($results[$i]['ip']) && $_column != 'ip' && wp_slimstat::$options['convert_ip_addresses'] != 'yes'){
				$row_details .= '<br> IP: <a class="slimstat-filter-link" href="'.self::fs_url('ip equals '.$results[$i]['ip']).'">'.$results[$i]['ip'].'</a>'.(!empty($results[$i]['other_ip'])?' / '.long2ip($results[$i]['other_ip']):'').'<a title="WHOIS: '.$results[$i]['ip'].'" class="slimstat-font-location-1 whois" href="'.wp_slimstat::$options['ip_lookup_service'].$results[$i]['ip'].'"></a>';
			}
			if (!empty($row_details)){
				$row_details = "<b class='slimstat-row-details$is_expanded'>$row_details</b>";
			}

			echo "<p>$element_pre_value$element_value$percentage $row_details</p>";
		}
	}

	public static function show_chart($_id = 'p0', $_chart_data = array(), $_chart_labels = array()){
		/* $rtl_filler_current = $rtl_filler_previous = 0;
		if ($GLOBALS['wp_locale']->text_direction == 'rtl' && !wp_slimstat_db::$filters_normalized['selected']['day']){
			$rtl_filler_current = 31-((date_i18n('Ym') == wp_slimstat_db::$filters_normalized['date']['year'].wp_slimstat_db::$filters_normalized['date']['month'])?wp_slimstat_db::$filters_normalized['date']['day']:cal_days_in_month(CAL_GREGORIAN, wp_slimstat_db::$filters_normalized['date']['month'], wp_slimstat_db::$filters_normalized['date']['year']));
			$rtl_filler_previous = 31-cal_days_in_month(CAL_GREGORIAN, date_i18n('m', self::$filters_normalized['utime']['previous_start']), date_i18n('Y', self::$filters_normalized['utime']['previous_start']));
		} */ ?>
		<div id="chart-placeholder"></div><div id="chart-legend"></div>

		<script type="text/javascript">
			SlimStatAdmin.chart_data = [];

			<?php if (!empty($_chart_data['previous']['label'])): ?>
			SlimStatAdmin.chart_data.push({
				label: '<?php echo $_chart_labels[0].' '.$_chart_data['previous']['label'] ?>',
				data: [<?php 
					$tmp_serialize = array();
					$j = 0;
					foreach($_chart_data['previous']['first_metric'] as $a_value){
						$tmp_serialize[] = "[$j, $a_value]";
						$j++;
					}
					echo implode(',', $tmp_serialize); 
				?>],
				points: {
					show: true,
					symbol: function(ctx, x, y, radius, shadow){
						ctx.arc(x, y, 2, 0, Math.PI * 2, false)
					}
				}
			});
			SlimStatAdmin.chart_data.push({
				label: '<?php echo $_chart_labels[1].' '.$_chart_data['previous']['label'] ?>',
				data: [<?php 
					$tmp_serialize = array();
					$j = 0;
					foreach($_chart_data['previous']['second_metric'] as $a_value){
						$tmp_serialize[] = "[$j, $a_value]";
						$j++;
					}
					echo implode(',', $tmp_serialize); 
				?>],
				points: {
					show: true,
					symbol: function(ctx, x, y, radius, shadow){
						ctx.arc(x, y, 2, 0, Math.PI * 2, false)
					}
				}
			});
			<?php endif ?>

			SlimStatAdmin.chart_data.push({
				label: '<?php echo $_chart_labels[0].' '.$_chart_data['current']['label'] ?>',
				data: [<?php 
					$tmp_serialize = array();
					$j = 0;
					foreach($_chart_data['current']['first_metric'] as $a_value){
						$tmp_serialize[] = "[$j, $a_value]";
						$j++;
					}
					echo implode(',', $tmp_serialize); 
				?>],
				points: {
					show: true,
					symbol: function(ctx, x, y, radius, shadow){
						ctx.arc(x, y, 2, 0, Math.PI * 2, false)
					}
				}
			});
			SlimStatAdmin.chart_data.push({
				label: '<?php echo $_chart_labels[1].' '.$_chart_data['current']['label'] ?>',
				data: [<?php 
					$tmp_serialize = array();
					$j = 0;
					foreach($_chart_data['current']['second_metric'] as $a_value){
						$tmp_serialize[] = "[$j, $a_value]";
						$j++;
					}
					echo implode(',', $tmp_serialize); 
				?>],
				points: {
					show: true,
					symbol: function(ctx, x, y, radius, shadow){
						ctx.arc(x, y, 2, 0, Math.PI * 2, false)
					}
				}
			});

			SlimStatAdmin.ticks = [<?php
				$tmp_serialize = array();
				$max_ticks = max(count($_chart_data['current']['first_metric']), count($_chart_data['previous']['first_metric']));
				if (!empty(wp_slimstat_db::$filters_normalized['date']['interval'])){
					for ($i = 0; $i < $max_ticks; $i++){
						$tmp_serialize[] = "[$i,'".date('d/m', wp_slimstat_db::$filters_normalized['utime']['start'] + ( $i * 86400) )."']";
					}
				}
				else{
					$min_idx = min(array_keys($_chart_data['current']['first_metric']));
					for ($i = $min_idx; $i < $max_ticks+$min_idx; $i++){
						$tmp_serialize[] = '['.($i-$min_idx).',"'.$i.'"]';
					}
				}
				echo implode(',', $tmp_serialize); 
			?>];
			
			<?php /*
			if (typeof SlimStatAdmin == 'undefined') var SlimStatAdmin = {data:[],ticks:[],options:{}};
			SlimStatAdmin.chart_info.rtl_filler_current = <?php echo $rtl_filler_current ?>;
			SlimStatAdmin.chart_info.rtl_filler_previous = <?php echo $rtl_filler_previous ?>;
			
			SlimStatAdmin.chart_info.daily_chart = <?php echo ((!wp_slimstat_db::$filters_normalized['selected']['year'] || wp_slimstat_db::$filters_normalized['selected']['month']) && !wp_slimstat_db::$filters_normalized['selected']['day'] && !wp_slimstat_db::$filters_normalized['selected']['hour'])?'true':'false' ?>;
			SlimStatAdmin.chart_info.max_yaxis = <?php echo $_chart_data['max_yaxis'] ?>;
			SlimStatAdmin.chart_info.current_month = parseInt('<?php echo wp_slimstat_db::$filters_normalized['date']['month'] ?>');
			SlimStatAdmin.chart_info.current_year = parseInt('<?php echo wp_slimstat_db::$filters_normalized['date']['year'] ?>');
			
			// Data for the chart
			SlimStatAdmin.ticks = [<?php echo $_chart_data['ticks'] ?>];
			SlimStatAdmin.data = [];
			SlimStatAdmin.data.push({<?php echo !empty($_chart_data['previous']['data2'])?"label:'{$_chart_labels[1]} ".wp_slimstat_db::$filters_normalized['utime']['previous_label']."',data:[{$_chart_data['previous']['data2']}],points:{show:true,symbol:function(ctx, x, y, radius, shadow){ctx.arc(x, y, 2, 0, Math.PI * 2, false)}}":''; ?>});
			SlimStatAdmin.data.push({<?php echo !empty($_chart_data['previous']['data1'])?"label:'{$_chart_labels[0]} ".wp_slimstat_db::$filters_normalized['utime']['previous_label']."',data:[{$_chart_data['previous']['data1']}],points:{show:true,symbol:function(ctx, x, y, radius, shadow){ctx.arc(x, y, 2, 0, Math.PI * 2, false)}}":''; ?>});
			SlimStatAdmin.data.push({label:'<?php echo $_chart_labels[1].' '.wp_slimstat_db::$filters_normalized['utime']['current_label'] ?>',data:[<?php echo $_chart_data['current']['data2'] ?>],points:{show:true,symbol:function(ctx, x, y, radius, shadow){ctx.arc(x, y, 2, 0, Math.PI * 2, false)}}});
			SlimStatAdmin.data.push({label:'<?php echo $_chart_labels[0].' '.wp_slimstat_db::$filters_normalized['utime']['current_label'] ?>',data:[<?php echo $_chart_data['current']['data1'] ?>],points:{show:true,symbol:function(ctx, x, y, radius, shadow){ctx.arc(x, y, 2, 0, Math.PI * 2, false)}}});
			*/ ?>
		</script>
		<?php 
	}
	
	public static function show_spy_view($_id = 'p0', $_type = 'undefined'){
		$results = !is_int($_type)?wp_slimstat_db::get_recent('t1.id', '(t1.visit_id > 0 AND tb.type <> 1)', 'tb.*', '', 't1.visit_id DESC'):wp_slimstat_db::get_recent_outbound($_type);

		if (count($results) == 0){
			echo '<p class="nodata">'.__('No data to display','wp-slimstat').'</p>';
			return true;
		}

		$visit_id = 0;
		for($i=0;$i<count($results);$i++){
			$row_details = '';
			$results[$i]['ip'] = long2ip($results[$i]['ip']);

			$host_by_ip = $results[$i]['ip'];
			if (wp_slimstat::$options['convert_ip_addresses'] == 'yes'){
				$host_by_ip = gethostbyaddr($results[$i]['ip']);
				$host_by_ip .= ($host_by_ip != $results[$i]['ip'])?" ({$results[$i]['ip']})":'';
			}

			$results[$i]['dt'] = date_i18n(wp_slimstat_db::$formats['date_time_format'], $results[$i]['dt'], true);
			if (!empty($results[$i]['searchterms']) && empty($results[$i]['resource'])){
				$results[$i]['resource'] = __('Search for','wp-slimstat').': '.htmlentities($results[$i]['searchterms'], ENT_QUOTES, 'UTF-8');
			}
			if (!empty($results[$i]['resource']) && $_type == 0){
				$results[$i]['resource'] = '<a target="_blank" class="url" title="'.__('Open this URL in a new window','wp-slimstat').'" href="'.htmlentities($results[$i]['resource'], ENT_QUOTES, 'UTF-8').'"></a> '.self::get_resource_title($results[$i]['resource']);
			}

			if ($visit_id != $results[$i]['visit_id']){
				$highlight_row = !empty($results[$i]['searchterms'])?' is-search-engine':' is-direct';
				if (empty($results[$i]['user'])){
					$host_by_ip = "<a class='slimstat-filter-link' href='".self::fs_url('ip equals '.$results[$i]['ip'])."'>$host_by_ip</a>";
				}
				else{
					$display_user_name = $results[$i]['user'];
					if (wp_slimstat::$options['show_display_name'] == 'yes' && strpos($results[$i]['notes'], '[user:') !== false){
						$display_real_name = get_user_by('login', $results[$i]['user']);
						if (is_object($display_real_name)) $display_user_name = $display_real_name->display_name;
					}
					$host_by_ip = "<a class='slimstat-filter-link highlight-user' href='".self::fs_url('user equals '.$results[$i]['user'])."'>{$display_user_name}</a>";
					$highlight_row = (strpos( $results[$i]['notes'], '[user]') !== false)?' is-known-user':' is-known-visitor';
				}
				$host_by_ip = "<a class='slimstat-font-location-1 whois' href='".wp_slimstat::$options['ip_lookup_service']."{$results[$i]['ip']}' target='_blank' title='WHOIS: {$results[$i]['ip']}'></a> $host_by_ip";
				$results[$i]['country'] = "<a class='slimstat-filter-link inline-icon' href='".self::fs_url('country equals '.$results[$i]['country'])."'><img class='slimstat-tooltip-trigger' src='".plugins_url('/images/flags/'.$results[$i]['country'].'.png', dirname(__FILE__))."' width='16' height='16'/><span class='slimstat-tooltip-content'>".__('c-'.$results[$i]['country'],'wp-slimstat').'</span></a>';
				$results[$i]['other_ip'] = !empty($results[$i]['other_ip'])?" <a class='slimstat-filter-link' href='".self::fs_url('other_ip equals '.$results[$i]['other_ip'])."'>".long2ip($results[$i]['other_ip']).'</a>&nbsp;&nbsp;':'';
		
				echo "<p class='header$highlight_row'>{$results[$i]['country']} $host_by_ip <span class='date-and-other'><em>{$results[$i]['other_ip']} {$results[$i]['dt']}</em></span></p>";
				$visit_id = $results[$i]['visit_id'];
			}

			if (!empty($results[$i]['domain'])){
				if (!is_int($_type)){
					$element_url = htmlentities((strpos($results[$i]['referer'], '://') == false)?"http://{$results[$i]['domain']}{$results[$i]['referer']}":$results[$i]['referer'], ENT_QUOTES, 'UTF-8');
					$row_details = __('Source','wp-slimstat').": <a class='slimstat-filter-link' href='".self::fs_url('domain equals '.$results[$i]['domain'])."'>{$results[$i]['domain']}</a>";
					if (!empty($results[$i]['searchterms'])){
						$row_details .= "<br>".__('Keywords','wp-slimstat').": ";
						$row_details .= self::get_search_terms_info($results[$i]['searchterms'], $results[$i]['domain'], $results[$i]['referer'], true);
					}
				}
				else{
					$permalink = parse_url($results[$i]['referer']);
					$results[$i]['notes'] = str_replace('|ET:click', '', $results[$i]['notes']);
					$element_url = htmlentities((strpos($results[$i]['referer'], '://') === false)?home_url().$results[$i]['referer']:$results[$i]['referer'], ENT_QUOTES, 'UTF-8');
					$row_details = __('Source','wp-slimstat').": <a target=\"_blank\" class=\"url\" title=\"".__('Open this URL in a new window','wp-slimstat')."\" href=\"$element_url\"></a><a class=\"slimstat-filter-link\" title=\"".htmlentities(sprintf(__('Filter results where resource equals %s','wp-slimstat'), $permalink['path']), ENT_QUOTES, 'UTF-8')."\" href=\"".self::fs_url('resource equals '.$permalink['path'])."\">{$permalink['path']}</a>";
					$row_details .= !empty($results[$i]['notes'])?'<br><strong>Link Details</strong>: '.htmlentities($results[$i]['notes'], ENT_QUOTES, 'UTF-8'):'';
					$row_details .= ($_type == -1)?' <strong>Type</strong>: '.$results[$i]['type']:'';
				}
			}
			if (!empty($row_details)){
				$is_expanded = (wp_slimstat::$options['expand_details'] == 'yes')?' expanded':'';
				$row_details = "<b class='slimstat-row-details$is_expanded'>$row_details</b>";
			}
			echo "<p>{$results[$i]['resource']} $row_details</p>";
		}
	}
	
	public static function show_about_wpslimstat($_id = 'p0'){ ?>
		<p><?php _e('Total Pageviews', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_records('1=1', '*', false, '', false), 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php _e('DB Size', 'wp-slimstat') ?> <span><?php echo wp_slimstat_db::get_data_size() ?></span></p>
		<p><?php _e('Tracking Active', 'wp-slimstat') ?> <span><?php _e(ucfirst(wp_slimstat::$options['is_tracking']), 'wp-slimstat') ?></span></p>
		<p><?php _e('Javascript Mode', 'wp-slimstat') ?> <span><?php _e(ucfirst(wp_slimstat::$options['javascript_mode']), 'wp-slimstat') ?></span></p>
		<p><?php _e('Tracking Browser Caps', 'wp-slimstat') ?> <span><?php _e(ucfirst(wp_slimstat::$options['enable_javascript']), 'wp-slimstat') ?></span></p>
		<p><?php _e('Auto purge', 'wp-slimstat') ?> <span><?php echo (wp_slimstat::$options['auto_purge'] > 0)?wp_slimstat::$options['auto_purge'].' '.__('days','wp-slimstat'):__('No','wp-slimstat') ?></span></p>
		<p><?php _e('Oldest pageview', 'wp-slimstat') ?> <span><?php $dt = wp_slimstat_db::get_oldest_visit('1=1', false); echo ($dt == null)?__('No visits','wp-slimstat'):date_i18n(get_option('date_format'), $dt) ?></span></p>
		<p>Geo IP <span><?php echo date_i18n(get_option('date_format'), @filemtime(WP_PLUGIN_DIR.'/wp-slimstat/databases/maxmind.dat')) ?></span></p><?php
	}

	public static function show_overview_summary($_id = 'p0', $_current_pageviews = 0, $_chart_data = array()){
		$today_pageviews = wp_slimstat_db::count_records('t1.dt BETWEEN '.wp_slimstat_db::$filters_normalized['utime']['start'].' AND '.(wp_slimstat_db::$filters_normalized['utime']['start']+86399), '*', true, false);
		$yesterday_pageviews = wp_slimstat_db::count_records('t1.dt BETWEEN '.(wp_slimstat_db::$filters_normalized['utime']['start']-86400).' AND '.(wp_slimstat_db::$filters_normalized['utime']['start']-1), '*', true, false); 
		$count_non_zero = count(array_filter($_chart_data['current']['first_metric']));
		?>

		<p><?php self::inline_help(__('A request to load a single HTML file. WP SlimStat logs a "pageview" each time the tracking code is executed.','wp-slimstat'));
			_e('Pageviews', 'wp-slimstat'); ?> <span><?php echo number_format($_current_pageviews, 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(__('How many pages have been visited on average during the current period.','wp-slimstat'));
			_e('Average Pageviews', 'wp-slimstat') ?> <span><?php echo number_format(($count_non_zero > 0)?intval($_current_pageviews/$count_non_zero):0, 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(__('Visitors who landed on your site after searching for a keyword on Google, Yahoo, etc.','wp-slimstat'));
			_e('From Search Results', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_records('t1.searchterms <> ""'), 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(__('Used to differentiate between multiple requests to download a file from one internet address (IP) and requests originating from many distinct addresses','wp-slimstat'));
			_e('Unique IPs', 'wp-slimstat'); ?> <span><?php echo number_format(wp_slimstat_db::count_records('1=1', 't1.ip'), 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php _e('Last 5 minutes', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_records('t1.dt > '.(date_i18n('U')-300), '*', true, false), 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php _e('Last 30 minutes', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_records('t1.dt > '.(date_i18n('U')-1800), '*', true, false), 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php _e('Today', 'wp-slimstat'); ?> <span><?php echo number_format(wp_slimstat_db::count_records('t1.dt > '.(date_i18n('U', mktime(0, 0, 0, date_i18n('m'), date_i18n('d'), date_i18n('Y')))), '*', true, false), 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php _e('Yesterday', 'wp-slimstat'); ?> <span><?php echo number_format(wp_slimstat_db::count_records('t1.dt BETWEEN '.(date_i18n('U', mktime(0, 0, 0, date_i18n('m'), date_i18n('d')-1, date_i18n('Y')))).' AND '.(date_i18n('U', mktime(23, 59, 59, date_i18n('m'), date_i18n('d')-1, date_i18n('Y')))), '*', true, false), 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p><?php
	}
	
	public static function show_visitors_summary($_id = 'p0', $_total_human_hits = 0, $_total_human_visits = 0){
		$new_visitors = wp_slimstat_db::count_records_having('visit_id > 0', 'ip', 'COUNT(visit_id) = 1');
		$new_visitors_rate = ($_total_human_hits > 0)?sprintf("%01.2f", (100*$new_visitors/$_total_human_hits)):0;
		if (intval($new_visitors_rate) > 99) $new_visitors_rate = '100';
		$metrics_per_visit = wp_slimstat_db::get_max_and_average_pages_per_visit(); ?>
			<p><?php self::inline_help(__('A visit is a session of at most 30 minutes. Returning visitors are counted multiple times if they perform multiple visits.','wp-slimstat')) ?>
				<?php _e('Human visits', 'wp-slimstat') ?> <span><?php echo number_format($_total_human_visits, 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
			<p><?php self::inline_help(__('It includes only traffic generated by human visitors.','wp-slimstat')) ?>
				<?php _e('Unique IPs', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_records('t1.visit_id > 0 AND tb.type <> 1', 't1.ip'), 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
			<p><?php self::inline_help(__('Percentage of single-page visits, i.e. visits in which the person left your site from the entrance page.','wp-slimstat')) ?>
				<?php _e('Bounce rate', 'wp-slimstat') ?> <span><?php echo number_format($new_visitors_rate, 2, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?>%</span></p>
			<p><?php self::inline_help(__('Visitors who had previously left a comment on your blog.','wp-slimstat')) ?>
				<?php _e('Known visitors', 'wp-slimstat') ?> <span><?php echo wp_slimstat_db::count_records('t1.user <> ""', 't1.user') ?></span></p>
			<p><?php self::inline_help(__('Human users who visited your site only once.','wp-slimstat')) ?>
				<?php _e('New visitors', 'wp-slimstat') ?> <span><?php echo number_format($new_visitors, 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
			<p><?php _e('Bots', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_records('tb.type = 1'), 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
			<p><?php _e('Pages per visit', 'wp-slimstat') ?> <span><?php echo number_format($metrics_per_visit['avg'], 2, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
			<p><?php _e('Longest visit', 'wp-slimstat') ?> <span><?php echo number_format($metrics_per_visit['max'], 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']).' '.__('hits','wp-slimstat') ?></span></p><?php
	}

	public static function show_plugins($_id = 'p0', $_total_human_hits = 0){
		$wp_slim_plugins = array('flash', 'silverlight', 'acrobat', 'java', 'mediaplayer', 'director', 'real', 'quicktime');
		foreach($wp_slim_plugins as $i => $a_plugin){
			$count_results = wp_slimstat_db::count_records("t1.plugins LIKE '%{$a_plugin}%'");
			echo "<p title='".__('Hits','wp-slimstat').": $count_results'>".ucfirst($a_plugin).' <span>';
			echo ($_total_human_hits > 0)?number_format(sprintf("%01.2f", (100*$count_results/$_total_human_hits)), 2, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']):0;
			echo '%</span></p>';
		}
	}

	public static function show_visit_duration($_id = 'p0', $_total_human_visits = 0){
		$count = wp_slimstat_db::count_records_having('t1.visit_id > 0 AND tb.type <> 1', 'visit_id', 'max(t1.dt) - min(t1.dt) >= 0 AND max(t1.dt) - min(t1.dt) <= 30');
		$percentage = ($_total_human_visits > 0)?sprintf("%01.2f", (100*$count/$_total_human_visits)):0;
		$extra_info =  "title='".__('Hits','wp-slimstat').": {$count}'";
		$average_time = 30 * $count;
		echo "<p $extra_info>".__('0 - 30 seconds','wp-slimstat')." <span>$percentage%</span></p>";

		$count = wp_slimstat_db::count_records_having('t1.visit_id > 0 AND tb.type <> 1', 'visit_id', 'max(t1.dt) - min(t1.dt) > 30 AND max(t1.dt) - min(t1.dt) <= 60');
		$percentage = ($_total_human_visits > 0)?sprintf("%01.2f", (100*$count/$_total_human_visits)):0;
		$extra_info =  "title='".__('Hits','wp-slimstat').": {$count}'";
		$average_time += 60 * $count;
		echo "<p $extra_info>".__('31 - 60 seconds','wp-slimstat')." <span>$percentage%</span></p>";

		$count = wp_slimstat_db::count_records_having('t1.visit_id > 0 AND tb.type <> 1', 'visit_id', 'max(t1.dt) - min(t1.dt) > 60 AND max(t1.dt) - min(t1.dt) <= 180');
		$percentage = ($_total_human_visits > 0)?sprintf("%01.2f", (100*$count/$_total_human_visits)):0;
		$extra_info =  "title='".__('Hits','wp-slimstat').": {$count}'";
		$average_time += 180 * $count;
		echo "<p $extra_info>".__('1 - 3 minutes','wp-slimstat')." <span>$percentage%</span></p>";

		$count = wp_slimstat_db::count_records_having('t1.visit_id > 0 AND tb.type <> 1', 'visit_id', 'max(t1.dt) - min(t1.dt) > 180 AND max(t1.dt) - min(t1.dt) <= 300');
		$percentage = ($_total_human_visits > 0)?sprintf("%01.2f", (100*$count/$_total_human_visits)):0;
		$extra_info =  "title='".__('Hits','wp-slimstat').": {$count}'";
		$average_time += 300 * $count;
		echo "<p $extra_info>".__('3 - 5 minutes','wp-slimstat')." <span>$percentage%</span></p>";

		$count = wp_slimstat_db::count_records_having('t1.visit_id > 0 AND tb.type <> 1', 'visit_id', 'max(t1.dt) - min(t1.dt) > 300 AND max(t1.dt) - min(t1.dt) <= 420');
		$percentage = ($_total_human_visits > 0)?sprintf("%01.2f", (100*$count/$_total_human_visits)):0;
		$extra_info =  "title='".__('Hits','wp-slimstat').": {$count}'";
		$average_time += 420 * $count;
		echo "<p $extra_info>".__('5 - 7 minutes','wp-slimstat')." <span>$percentage%</span></p>";

		$count = wp_slimstat_db::count_records_having('t1.visit_id > 0 AND tb.type <> 1', 'visit_id', 'max(t1.dt) - min(t1.dt) > 420 AND max(t1.dt) - min(t1.dt) <= 600');
		$percentage = ($_total_human_visits > 0)?sprintf("%01.2f", (100*$count/$_total_human_visits)):0;
		$extra_info =  "title='".__('Hits','wp-slimstat').": {$count}'";
		$average_time += 600* $count;
		echo "<p $extra_info>".__('7 - 10 minutes','wp-slimstat')." <span>$percentage%</span></p>";

		$count = wp_slimstat_db::count_records_having('t1.visit_id > 0 AND tb.type <> 1', 'visit_id', 'max(t1.dt) - min(t1.dt) > 600');
		$percentage = ($_total_human_visits > 0)?sprintf("%01.2f", (100*$count/$_total_human_visits)):0;
		$extra_info =  "title='".__('Hits','wp-slimstat').": {$count}'";
		$average_time += 900 * $count;
		echo "<p $extra_info>".__('More than 10 minutes','wp-slimstat')." <span>$percentage%</span></p>";

		if ($_total_human_visits > 0){
			$average_time /= $_total_human_visits;
			$average_time = date('m:s', intval($average_time));
		}
		else{
			$average_time = '0:00';
		}
		echo '<p>'.__('Average time on site','wp-slimstat')." <span>$average_time </span></p>";
	}
	
	public static function show_traffic_sources_summary($_id = 'p0', $_current_pageviews = 0){
		$total_human_hits = wp_slimstat_db::count_records('t1.visit_id > 0 AND tb.type <> 1');
		$new_visitors = wp_slimstat_db::count_records_having('visit_id > 0', 'ip', 'COUNT(visit_id) = 1');
		$new_visitors_rate = ($total_human_hits > 0)?sprintf("%01.2f", (100*$new_visitors/$total_human_hits)):0;
		if (intval($new_visitors_rate) > 99) $new_visitors_rate = '100'; ?>		
		<p><?php self::inline_help(__('A request to load a single HTML file. WP SlimStat logs a "pageview" each time the tracking code is executed.','wp-slimstat')) ?>
			<?php _e('Pageviews', 'wp-slimstat') ?> <span><?php echo number_format($_current_pageviews, 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(__('A referrer (or referring site) is the site that a visitor previously visited before following a link to your site.','wp-slimstat')) ?>
			<?php _e('Unique Referrers', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_records("t1.domain <> '{$_SERVER['SERVER_NAME']}' AND t1.domain <> ''", 't1.domain'), 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(__("Visitors who visited the site by typing the URL directly into their browser. <em>Direct</em> can also refer to the visitors who clicked on the links from their bookmarks/favorites, untagged links within emails, or links from documents that don't include tracking variables.",'wp-slimstat')) ?>
			<?php _e('Direct Pageviews', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_records('t1.domain = ""', 't1.id'), 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(__("Visitors who came to your site via searches on Google or some other search engine.",'wp-slimstat')) ?>
			<?php _e('From a search result', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_records("t1.searchterms <> '' AND t1.domain <> '{$_SERVER['SERVER_NAME']}' AND t1.domain <> ''", 't1.id'), 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(__("The first page that a user views during a session. This is also known as the <em>entrance page</em>. For example, if they search for 'Brooklyn Office Space,' and they land on your home page, it gets counted (for that visit) as a landing page.",'wp-slimstat')) ?>
			<?php _e('Unique Landing Pages', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_records('t1.domain <> ""', 't1.resource'), 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(__("Number of single-page visits to your site over the selected period.",'wp-slimstat')) ?>
			<?php _e('Bounce Pages', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_bouncing_pages(), 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(__('Percentage of single-page visits, i.e. visits in which the person left your site from the entrance page.','wp-slimstat')) ?>
			<?php _e('New Visitors Rate', 'wp-slimstat') ?> <span><?php echo number_format($new_visitors_rate, 2, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?>%</span></p>
		<p><?php self::inline_help(__("Visitors who visited the site in the last 5 minutes coming from a search engine.",'wp-slimstat')) ?>
			<?php _e('Currently from search engines', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_records("t1.searchterms <> '' AND t1.domain <> '{$_SERVER['SERVER_NAME']}' AND t1.domain <> '' AND t1.dt > UNIX_TIMESTAMP()-300", 't1.id', false), 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p><?php
	}
	
	public static function show_rankings($_id = 'p0'){
		$options = array('timeout' => 1, 'headers' => array('Accept' => 'application/json'));
		$site_url_array = parse_url(home_url());
		
		// Check if we have a valied transient
		if (false === ($rankings = get_transient( 'slimstat_ranking_values' ))){
			$rankings = array('google_index' => 0, 'google_backlinks' => 0, 'facebook_likes' => 0, 'facebook_shares' => 0, 'facebook_clicks' => 0, 'alexa_world_rank' => 0, 'alexa_country_rank' => 0, 'alexa_popularity' => 0);

			// Google Index
			$response = @wp_remote_get('https://ajax.googleapis.com/ajax/services/search/web?v=1.0&q=site:'.$site_url_array['host'], $options);
			if (!is_wp_error($response) && isset($response['response']['code']) && ($response['response']['code'] == 200) && !empty($response['body'])){
				$response = @json_decode($response['body']);
				if (is_object($response) && !empty($response->responseData->cursor->resultCount)){
					$rankings['google_index'] = (int)$response->responseData->cursor->resultCount;
				}
			}
			
			// Google Backlinks
			$response = @wp_remote_get('https://ajax.googleapis.com/ajax/services/search/web?v=1.0&q=link:'.$site_url_array['host'], $options);
			if (!is_wp_error($response) && isset($response['response']['code']) && ($response['response']['code'] == 200) && !empty($response['body'])){
				$response = @json_decode($response['body']);
				if (is_object($response) && !empty($response->responseData->cursor->resultCount)){
					$rankings['google_backlinks'] = (int)$response->responseData->cursor->resultCount;
				}
			}
			
			// Facebook
			$options['headers']['Accept'] = 'text/xml';
			$response = @wp_remote_get("https://api.facebook.com/method/fql.query?query=select%20%20like_count,%20total_count,%20share_count,%20click_count%20from%20link_stat%20where%20url='".$site_url_array['host']."'", $options);
			if (!is_wp_error($response) && isset($response['response']['code']) && ($response['response']['code'] == 200) && !empty($response['body'])){
				$response = new SimpleXMLElement($response['body']);
				if (is_object($response) && is_object($response->link_stat) && !empty($response->link_stat->like_count)){
					$rankings['facebook_likes'] = (int)$response->link_stat->like_count;
					$rankings['facebook_shares'] = (int)$response->link_stat->share_count;
					$rankings['facebook_clicks'] = (int)$response->link_stat->click_count;
				}
			}

			// Alexa
			$response = @wp_remote_get("http://data.alexa.com/data?cli=10&dat=snbamz&url=".$site_url_array['host'], $options);
			if (!is_wp_error($response) && isset($response['response']['code']) && ($response['response']['code'] == 200) && !empty($response['body'])){
				$response = new SimpleXMLElement($response['body']);
				if (is_object($response->SD[1]) && is_object($response->SD[1]->POPULARITY)){
					if ($response->SD[1]->POPULARITY && $response->SD[1]->POPULARITY->attributes()){
						$attributes = $response->SD[1]->POPULARITY->attributes();
						$rankings['alexa_popularity'] = (int)$attributes['TEXT'];
					}

					if ($response->SD[1]->REACH && $response->SD[1]->REACH->attributes()){
						$attributes = $response->SD[1]->REACH->attributes();
						$rankings['alexa_world_rank'] = (int)$attributes['RANK'];
					}

					if ($response->SD[1]->COUNTRY && $response->SD[1]->COUNTRY->attributes()){
						$attributes = $response->SD[1]->COUNTRY->attributes();
						$rankings['alexa_country_rank'] = (int)$attributes['RANK'];
					}
				}
			}

			// Store rankings as transients for 12 hours
			set_transient('slimstat_ranking_values', $rankings, 43200);
		}
		?>
		
		<p><?php self::inline_help(__("Number of pages in your site included in Google's index.",'wp-slimstat')) ?>
			<?php _e('Google Index', 'wp-slimstat') ?> <span><?php echo number_format($rankings['google_index'], 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(__("Number of pages, according to Google, that link back to your site.",'wp-slimstat')) ?>
			<?php _e('Google Backlinks', 'wp-slimstat') ?> <span><?php echo number_format($rankings['google_backlinks'], 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(__("How many times the Facebook Like button has been approximately clicked on your site.",'wp-slimstat')) ?>
			<?php _e('Facebook Likes', 'wp-slimstat') ?> <span><?php echo number_format($rankings['facebook_likes'], 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(__("How many times your site has been shared by someone on the social network.",'wp-slimstat')) ?>
			<?php _e('Facebook Shares', 'wp-slimstat') ?> <span><?php echo number_format($rankings['facebook_shares'], 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(__("How many times links to your website have been clicked on Facebook.",'wp-slimstat')) ?>
			<?php _e('Facebook Clicks', 'wp-slimstat') ?> <span><?php echo number_format($rankings['facebook_clicks'], 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(__("Alexa is a subsidiary company of Amazon.com which provides commercial web traffic data.",'wp-slimstat')) ?>
			<?php _e('Alexa World Rank', 'wp-slimstat') ?> <span><?php echo number_format($rankings['alexa_world_rank'], 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php _e('Alexa Country Rank', 'wp-slimstat') ?> <span><?php echo number_format($rankings['alexa_country_rank'], 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php _e('Alexa Popularity', 'wp-slimstat') ?> <span><?php echo number_format($rankings['alexa_popularity'], 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p><?php
	}

	public static function show_world_map($_id = 'p0'){
		wp_slimstat_db::$filters_normalized['misc']['limit_results'] = 9999;
		$countries = wp_slimstat_db::get_popular('t1.country');
		$total_count = wp_slimstat_db::count_records('1=1', '*');
		$data_areas = array('xx'=>'{id:"XX",balloonText:"'.__('c-xx','wp-slimstat').': 0",value:0,color:"#ededed"}','af'=>'{id:"AF",balloonText:"'.__('c-af','wp-slimstat').': 0",value:0,color:"#ededed"}','ax'=>'{id:"AX",balloonText:"'.__('c-ax','wp-slimstat').': 0",value:0,color:"#ededed"}','al'=>'{id:"AL",balloonText:"'.__('c-al','wp-slimstat').': 0",value:0,color:"#ededed"}','dz'=>'{id:"DZ",balloonText:"'.__('c-dz','wp-slimstat').': 0",value:0,color:"#ededed"}','ad'=>'{id:"AD",balloonText:"'.__('c-ad','wp-slimstat').': 0",value:0,color:"#ededed"}','ao'=>'{id:"AO",balloonText:"'.__('c-ao','wp-slimstat').': 0",value:0,color:"#ededed"}','ai'=>'{id:"AI",balloonText:"'.__('c-ai','wp-slimstat').': 0",value:0,color:"#ededed"}','ag'=>'{id:"AG",balloonText:"'.__('c-ag','wp-slimstat').': 0",value:0,color:"#ededed"}','ar'=>'{id:"AR",balloonText:"'.__('c-ar','wp-slimstat').': 0",value:0,color:"#ededed"}','am'=>'{id:"AM",balloonText:"'.__('c-am','wp-slimstat').': 0",value:0,color:"#ededed"}','aw'=>'{id:"AW",balloonText:"'.__('c-aw','wp-slimstat').': 0",value:0,color:"#ededed"}','au'=>'{id:"AU",balloonText:"'.__('c-au','wp-slimstat').': 0",value:0,color:"#ededed"}','at'=>'{id:"AT",balloonText:"'.__('c-at','wp-slimstat').': 0",value:0,color:"#ededed"}','az'=>'{id:"AZ",balloonText:"'.__('c-az','wp-slimstat').': 0",value:0,color:"#ededed"}','bs'=>'{id:"BS",balloonText:"'.__('c-bs','wp-slimstat').': 0",value:0,color:"#ededed"}','bh'=>'{id:"BH",balloonText:"'.__('c-bh','wp-slimstat').': 0",value:0,color:"#ededed"}','bd'=>'{id:"BD",balloonText:"'.__('c-bd','wp-slimstat').': 0",value:0,color:"#ededed"}','bb'=>'{id:"BB",balloonText:"'.__('c-bb','wp-slimstat').': 0",value:0,color:"#ededed"}','by'=>'{id:"BY",balloonText:"'.__('c-by','wp-slimstat').': 0",value:0,color:"#ededed"}','be'=>'{id:"BE",balloonText:"'.__('c-be','wp-slimstat').': 0",value:0,color:"#ededed"}','bz'=>'{id:"BZ",balloonText:"'.__('c-bz','wp-slimstat').': 0",value:0,color:"#ededed"}','bj'=>'{id:"BJ",balloonText:"'.__('c-bj','wp-slimstat').': 0",value:0,color:"#ededed"}','bm'=>'{id:"BM",balloonText:"'.__('c-bm','wp-slimstat').': 0",value:0,color:"#ededed"}','bt'=>'{id:"BT",balloonText:"'.__('c-bt','wp-slimstat').': 0",value:0,color:"#ededed"}','bo'=>'{id:"BO",balloonText:"'.__('c-bo','wp-slimstat').': 0",value:0,color:"#ededed"}','ba'=>'{id:"BA",balloonText:"'.__('c-ba','wp-slimstat').': 0",value:0,color:"#ededed"}','bw'=>'{id:"BW",balloonText:"'.__('c-bw','wp-slimstat').': 0",value:0,color:"#ededed"}','br'=>'{id:"BR",balloonText:"'.__('c-br','wp-slimstat').': 0",value:0,color:"#ededed"}','bn'=>'{id:"BN",balloonText:"'.__('c-bn','wp-slimstat').': 0",value:0,color:"#ededed"}','bg'=>'{id:"BG",balloonText:"'.__('c-bg','wp-slimstat').': 0",value:0,color:"#ededed"}','bf'=>'{id:"BF",balloonText:"'.__('c-bf','wp-slimstat').': 0",value:0,color:"#ededed"}','bi'=>'{id:"BI",balloonText:"'.__('c-bi','wp-slimstat').': 0",value:0,color:"#ededed"}','kh'=>'{id:"KH",balloonText:"'.__('c-kh','wp-slimstat').': 0",value:0,color:"#ededed"}','cm'=>'{id:"CM",balloonText:"'.__('c-cm','wp-slimstat').': 0",value:0,color:"#ededed"}','ca'=>'{id:"CA",balloonText:"'.__('c-ca','wp-slimstat').': 0",value:0,color:"#ededed"}','cv'=>'{id:"CV",balloonText:"'.__('c-cv','wp-slimstat').': 0",value:0,color:"#ededed"}','ky'=>'{id:"KY",balloonText:"'.__('c-ky','wp-slimstat').': 0",value:0,color:"#ededed"}','cf'=>'{id:"CF",balloonText:"'.__('c-cf','wp-slimstat').': 0",value:0,color:"#ededed"}','td'=>'{id:"TD",balloonText:"'.__('c-td','wp-slimstat').': 0",value:0,color:"#ededed"}','cl'=>'{id:"CL",balloonText:"'.__('c-cl','wp-slimstat').': 0",value:0,color:"#ededed"}','cn'=>'{id:"CN",balloonText:"'.__('c-cn','wp-slimstat').': 0",value:0,color:"#ededed"}','co'=>'{id:"CO",balloonText:"'.__('c-co','wp-slimstat').': 0",value:0,color:"#ededed"}','km'=>'{id:"KM",balloonText:"'.__('c-km','wp-slimstat').': 0",value:0,color:"#ededed"}','cg'=>'{id:"CG",balloonText:"'.__('c-cg','wp-slimstat').': 0",value:0,color:"#ededed"}','cd'=>'{id:"CD",balloonText:"'.__('c-cd','wp-slimstat').': 0",value:0,color:"#ededed"}','cr'=>'{id:"CR",balloonText:"'.__('c-cr','wp-slimstat').': 0",value:0,color:"#ededed"}','ci'=>'{id:"CI",balloonText:"'.__('c-ci','wp-slimstat').': 0",value:0,color:"#ededed"}','hr'=>'{id:"HR",balloonText:"'.__('c-hr','wp-slimstat').': 0",value:0,color:"#ededed"}','cu'=>'{id:"CU",balloonText:"'.__('c-cu','wp-slimstat').': 0",value:0,color:"#ededed"}','cy'=>'{id:"CY",balloonText:"'.__('c-cy','wp-slimstat').': 0",value:0,color:"#ededed"}','cz'=>'{id:"CZ",balloonText:"'.__('c-cz','wp-slimstat').': 0",value:0,color:"#ededed"}','dk'=>'{id:"DK",balloonText:"'.__('c-dk','wp-slimstat').': 0",value:0,color:"#ededed"}','dj'=>'{id:"DJ",balloonText:"'.__('c-dj','wp-slimstat').': 0",value:0,color:"#ededed"}','dm'=>'{id:"DM",balloonText:"'.__('c-dm','wp-slimstat').': 0",value:0,color:"#ededed"}','do'=>'{id:"DO",balloonText:"'.__('c-do','wp-slimstat').': 0",value:0,color:"#ededed"}','ec'=>'{id:"EC",balloonText:"'.__('c-ec','wp-slimstat').': 0",value:0,color:"#ededed"}','eg'=>'{id:"EG",balloonText:"'.__('c-eg','wp-slimstat').': 0",value:0,color:"#ededed"}','sv'=>'{id:"SV",balloonText:"'.__('c-sv','wp-slimstat').': 0",value:0,color:"#ededed"}','gq'=>'{id:"GQ",balloonText:"'.__('c-gq','wp-slimstat').': 0",value:0,color:"#ededed"}','er'=>'{id:"ER",balloonText:"'.__('c-er','wp-slimstat').': 0",value:0,color:"#ededed"}','ee'=>'{id:"EE",balloonText:"'.__('c-ee','wp-slimstat').': 0",value:0,color:"#ededed"}','et'=>'{id:"ET",balloonText:"'.__('c-et','wp-slimstat').': 0",value:0,color:"#ededed"}','fo'=>'{id:"FO",balloonText:"'.__('c-fo','wp-slimstat').': 0",value:0,color:"#ededed"}','fk'=>'{id:"FK",balloonText:"'.__('c-fk','wp-slimstat').': 0",value:0,color:"#ededed"}','fj'=>'{id:"FJ",balloonText:"'.__('c-fj','wp-slimstat').': 0",value:0,color:"#ededed"}','fi'=>'{id:"FI",balloonText:"'.__('c-fi','wp-slimstat').': 0",value:0,color:"#ededed"}','fr'=>'{id:"FR",balloonText:"'.__('c-fr','wp-slimstat').': 0",value:0,color:"#ededed"}','gf'=>'{id:"GF",balloonText:"'.__('c-gf','wp-slimstat').': 0",value:0,color:"#ededed"}','ga'=>'{id:"GA",balloonText:"'.__('c-ga','wp-slimstat').': 0",value:0,color:"#ededed"}','gm'=>'{id:"GM",balloonText:"'.__('c-gm','wp-slimstat').': 0",value:0,color:"#ededed"}','ge'=>'{id:"GE",balloonText:"'.__('c-ge','wp-slimstat').': 0",value:0,color:"#ededed"}','de'=>'{id:"DE",balloonText:"'.__('c-de','wp-slimstat').': 0",value:0,color:"#ededed"}','gh'=>'{id:"GH",balloonText:"'.__('c-gh','wp-slimstat').': 0",value:0,color:"#ededed"}','gr'=>'{id:"GR",balloonText:"'.__('c-gr','wp-slimstat').': 0",value:0,color:"#ededed"}','gl'=>'{id:"GL",balloonText:"'.__('c-gl','wp-slimstat').': 0",value:0,color:"#ededed"}','gd'=>'{id:"GD",balloonText:"'.__('c-gd','wp-slimstat').': 0",value:0,color:"#ededed"}','gp'=>'{id:"GP",balloonText:"'.__('c-gp','wp-slimstat').': 0",value:0,color:"#ededed"}','gt'=>'{id:"GT",balloonText:"'.__('c-gt','wp-slimstat').': 0",value:0,color:"#ededed"}','gn'=>'{id:"GN",balloonText:"'.__('c-gn','wp-slimstat').': 0",value:0,color:"#ededed"}','gw'=>'{id:"GW",balloonText:"'.__('c-gw','wp-slimstat').': 0",value:0,color:"#ededed"}','gy'=>'{id:"GY",balloonText:"'.__('c-gy','wp-slimstat').': 0",value:0,color:"#ededed"}','ht'=>'{id:"HT",balloonText:"'.__('c-ht','wp-slimstat').': 0",value:0,color:"#ededed"}','hn'=>'{id:"HN",balloonText:"'.__('c-hn','wp-slimstat').': 0",value:0,color:"#ededed"}','hk'=>'{id:"HK",balloonText:"'.__('c-hk','wp-slimstat').': 0",value:0,color:"#ededed"}','hu'=>'{id:"HU",balloonText:"'.__('c-hu','wp-slimstat').': 0",value:0,color:"#ededed"}','is'=>'{id:"IS",balloonText:"'.__('c-is','wp-slimstat').': 0",value:0,color:"#ededed"}','in'=>'{id:"IN",balloonText:"'.__('c-in','wp-slimstat').': 0",value:0,color:"#ededed"}','id'=>'{id:"ID",balloonText:"'.__('c-id','wp-slimstat').': 0",value:0,color:"#ededed"}','ir'=>'{id:"IR",balloonText:"'.__('c-ir','wp-slimstat').': 0",value:0,color:"#ededed"}','iq'=>'{id:"IQ",balloonText:"'.__('c-iq','wp-slimstat').': 0",value:0,color:"#ededed"}','ie'=>'{id:"IE",balloonText:"'.__('c-ie','wp-slimstat').': 0",value:0,color:"#ededed"}','il'=>'{id:"IL",balloonText:"'.__('c-il','wp-slimstat').': 0",value:0,color:"#ededed"}','it'=>'{id:"IT",balloonText:"'.__('c-it','wp-slimstat').': 0",value:0,color:"#ededed"}','jm'=>'{id:"JM",balloonText:"'.__('c-jm','wp-slimstat').': 0",value:0,color:"#ededed"}','jp'=>'{id:"JP",balloonText:"'.__('c-jp','wp-slimstat').': 0",value:0,color:"#ededed"}','jo'=>'{id:"JO",balloonText:"'.__('c-jo','wp-slimstat').': 0",value:0,color:"#ededed"}','kz'=>'{id:"KZ",balloonText:"'.__('c-kz','wp-slimstat').': 0",value:0,color:"#ededed"}','ke'=>'{id:"KE",balloonText:"'.__('c-ke','wp-slimstat').': 0",value:0,color:"#ededed"}','nr'=>'{id:"NR",balloonText:"'.__('c-nr','wp-slimstat').': 0",value:0,color:"#ededed"}','kp'=>'{id:"KP",balloonText:"'.__('c-kp','wp-slimstat').': 0",value:0,color:"#ededed"}','kr'=>'{id:"KR",balloonText:"'.__('c-kr','wp-slimstat').': 0",value:0,color:"#ededed"}','kv'=>'{id:"KV",balloonText:"'.__('c-kv','wp-slimstat').': 0",value:0,color:"#ededed"}','kw'=>'{id:"KW",balloonText:"'.__('c-kw','wp-slimstat').': 0",value:0,color:"#ededed"}','kg'=>'{id:"KG",balloonText:"'.__('c-kg','wp-slimstat').': 0",value:0,color:"#ededed"}','la'=>'{id:"LA",balloonText:"'.__('c-la','wp-slimstat').': 0",value:0,color:"#ededed"}','lv'=>'{id:"LV",balloonText:"'.__('c-lv','wp-slimstat').': 0",value:0,color:"#ededed"}','lb'=>'{id:"LB",balloonText:"'.__('c-lb','wp-slimstat').': 0",value:0,color:"#ededed"}','ls'=>'{id:"LS",balloonText:"'.__('c-ls','wp-slimstat').': 0",value:0,color:"#ededed"}','lr'=>'{id:"LR",balloonText:"'.__('c-lr','wp-slimstat').': 0",value:0,color:"#ededed"}','ly'=>'{id:"LY",balloonText:"'.__('c-ly','wp-slimstat').': 0",value:0,color:"#ededed"}','li'=>'{id:"LI",balloonText:"'.__('c-li','wp-slimstat').': 0",value:0,color:"#ededed"}','lt'=>'{id:"LT",balloonText:"'.__('c-lt','wp-slimstat').': 0",value:0,color:"#ededed"}','lu'=>'{id:"LU",balloonText:"'.__('c-lu','wp-slimstat').': 0",value:0,color:"#ededed"}','mk'=>'{id:"MK",balloonText:"'.__('c-mk','wp-slimstat').': 0",value:0,color:"#ededed"}','mg'=>'{id:"MG",balloonText:"'.__('c-mg','wp-slimstat').': 0",value:0,color:"#ededed"}','mw'=>'{id:"MW",balloonText:"'.__('c-mw','wp-slimstat').': 0",value:0,color:"#ededed"}','my'=>'{id:"MY",balloonText:"'.__('c-my','wp-slimstat').': 0",value:0,color:"#ededed"}','ml'=>'{id:"ML",balloonText:"'.__('c-ml','wp-slimstat').': 0",value:0,color:"#ededed"}','mt'=>'{id:"MT",balloonText:"'.__('c-mt','wp-slimstat').': 0",value:0,color:"#ededed"}','mq'=>'{id:"MQ",balloonText:"'.__('c-mq','wp-slimstat').': 0",value:0,color:"#ededed"}','mr'=>'{id:"MR",balloonText:"'.__('c-mr','wp-slimstat').': 0",value:0,color:"#ededed"}','mu'=>'{id:"MU",balloonText:"'.__('c-mu','wp-slimstat').': 0",value:0,color:"#ededed"}','mx'=>'{id:"MX",balloonText:"'.__('c-mx','wp-slimstat').': 0",value:0,color:"#ededed"}','md'=>'{id:"MD",balloonText:"'.__('c-md','wp-slimstat').': 0",value:0,color:"#ededed"}','mn'=>'{id:"MN",balloonText:"'.__('c-mn','wp-slimstat').': 0",value:0,color:"#ededed"}','me'=>'{id:"ME",balloonText:"'.__('c-me','wp-slimstat').': 0",value:0,color:"#ededed"}','ms'=>'{id:"MS",balloonText:"'.__('c-ms','wp-slimstat').': 0",value:0,color:"#ededed"}','ma'=>'{id:"MA",balloonText:"'.__('c-ma','wp-slimstat').': 0",value:0,color:"#ededed"}','mz'=>'{id:"MZ",balloonText:"'.__('c-mz','wp-slimstat').': 0",value:0,color:"#ededed"}','mm'=>'{id:"MM",balloonText:"'.__('c-mm','wp-slimstat').': 0",value:0,color:"#ededed"}','na'=>'{id:"NA",balloonText:"'.__('c-na','wp-slimstat').': 0",value:0,color:"#ededed"}','np'=>'{id:"NP",balloonText:"'.__('c-np','wp-slimstat').': 0",value:0,color:"#ededed"}','nl'=>'{id:"NL",balloonText:"'.__('c-nl','wp-slimstat').': 0",value:0,color:"#ededed"}','nc'=>'{id:"NC",balloonText:"'.__('c-nc','wp-slimstat').': 0",value:0,color:"#ededed"}','nz'=>'{id:"NZ",balloonText:"'.__('c-nz','wp-slimstat').': 0",value:0,color:"#ededed"}','ni'=>'{id:"NI",balloonText:"'.__('c-ni','wp-slimstat').': 0",value:0,color:"#ededed"}','ne'=>'{id:"NE",balloonText:"'.__('c-ne','wp-slimstat').': 0",value:0,color:"#ededed"}','ng'=>'{id:"NG",balloonText:"'.__('c-ng','wp-slimstat').': 0",value:0,color:"#ededed"}','no'=>'{id:"NO",balloonText:"'.__('c-no','wp-slimstat').': 0",value:0,color:"#ededed"}','om'=>'{id:"OM",balloonText:"'.__('c-om','wp-slimstat').': 0",value:0,color:"#ededed"}','pk'=>'{id:"PK",balloonText:"'.__('c-pk','wp-slimstat').': 0",value:0,color:"#ededed"}','pw'=>'{id:"PW",balloonText:"'.__('c-pw','wp-slimstat').': 0",value:0,color:"#ededed"}','ps'=>'{id:"PS",balloonText:"'.__('c-ps','wp-slimstat').': 0",value:0,color:"#ededed"}','pa'=>'{id:"PA",balloonText:"'.__('c-pa','wp-slimstat').': 0",value:0,color:"#ededed"}','pg'=>'{id:"PG",balloonText:"'.__('c-pg','wp-slimstat').': 0",value:0,color:"#ededed"}','py'=>'{id:"PY",balloonText:"'.__('c-py','wp-slimstat').': 0",value:0,color:"#ededed"}','pe'=>'{id:"PE",balloonText:"'.__('c-pe','wp-slimstat').': 0",value:0,color:"#ededed"}','ph'=>'{id:"PH",balloonText:"'.__('c-ph','wp-slimstat').': 0",value:0,color:"#ededed"}','pl'=>'{id:"PL",balloonText:"'.__('c-pl','wp-slimstat').': 0",value:0,color:"#ededed"}','pt'=>'{id:"PT",balloonText:"'.__('c-pt','wp-slimstat').': 0",value:0,color:"#ededed"}','pr'=>'{id:"PR",balloonText:"'.__('c-pr','wp-slimstat').': 0",value:0,color:"#ededed"}','qa'=>'{id:"QA",balloonText:"'.__('c-qa','wp-slimstat').': 0",value:0,color:"#ededed"}','re'=>'{id:"RE",balloonText:"'.__('c-re','wp-slimstat').': 0",value:0,color:"#ededed"}','ro'=>'{id:"RO",balloonText:"'.__('c-ro','wp-slimstat').': 0",value:0,color:"#ededed"}','ru'=>'{id:"RU",balloonText:"'.__('c-ru','wp-slimstat').': 0",value:0,color:"#ededed"}','rw'=>'{id:"RW",balloonText:"'.__('c-rw','wp-slimstat').': 0",value:0,color:"#ededed"}','kn'=>'{id:"KN",balloonText:"'.__('c-kn','wp-slimstat').': 0",value:0,color:"#ededed"}','lc'=>'{id:"LC",balloonText:"'.__('c-lc','wp-slimstat').': 0",value:0,color:"#ededed"}','mf'=>'{id:"MF",balloonText:"'.__('c-mf','wp-slimstat').': 0",value:0,color:"#ededed"}','vc'=>'{id:"VC",balloonText:"'.__('c-vc','wp-slimstat').': 0",value:0,color:"#ededed"}','ws'=>'{id:"WS",balloonText:"'.__('c-ws','wp-slimstat').': 0",value:0,color:"#ededed"}','st'=>'{id:"ST",balloonText:"'.__('c-st','wp-slimstat').': 0",value:0,color:"#ededed"}','sa'=>'{id:"SA",balloonText:"'.__('c-sa','wp-slimstat').': 0",value:0,color:"#ededed"}','sn'=>'{id:"SN",balloonText:"'.__('c-sn','wp-slimstat').': 0",value:0,color:"#ededed"}','rs'=>'{id:"RS",balloonText:"'.__('c-rs','wp-slimstat').': 0",value:0,color:"#ededed"}','sl'=>'{id:"SL",balloonText:"'.__('c-sl','wp-slimstat').': 0",value:0,color:"#ededed"}','sg'=>'{id:"SG",balloonText:"'.__('c-sg','wp-slimstat').': 0",value:0,color:"#ededed"}','sk'=>'{id:"SK",balloonText:"'.__('c-sk','wp-slimstat').': 0",value:0,color:"#ededed"}','si'=>'{id:"SI",balloonText:"'.__('c-si','wp-slimstat').': 0",value:0,color:"#ededed"}','sb'=>'{id:"SB",balloonText:"'.__('c-sb','wp-slimstat').': 0",value:0,color:"#ededed"}','so'=>'{id:"SO",balloonText:"'.__('c-so','wp-slimstat').': 0",value:0,color:"#ededed"}','za'=>'{id:"ZA",balloonText:"'.__('c-za','wp-slimstat').': 0",value:0,color:"#ededed"}','gs'=>'{id:"GS",balloonText:"'.__('c-gs','wp-slimstat').': 0",value:0,color:"#ededed"}','es'=>'{id:"ES",balloonText:"'.__('c-es','wp-slimstat').': 0",value:0,color:"#ededed"}','lk'=>'{id:"LK",balloonText:"'.__('c-lk','wp-slimstat').': 0",value:0,color:"#ededed"}','sc'=>'{id:"SC",balloonText:"'.__('c-sc','wp-slimstat').': 0",value:0,color:"#ededed"}','sd'=>'{id:"SD",balloonText:"'.__('c-sd','wp-slimstat').': 0",value:0,color:"#ededed"}','ss'=>'{id:"SS",balloonText:"'.__('c-ss','wp-slimstat').': 0",value:0,color:"#ededed"}','sr'=>'{id:"SR",balloonText:"'.__('c-sr','wp-slimstat').': 0",value:0,color:"#ededed"}','sj'=>'{id:"SJ",balloonText:"'.__('c-sj','wp-slimstat').': 0",value:0,color:"#ededed"}','sz'=>'{id:"SZ",balloonText:"'.__('c-sz','wp-slimstat').': 0",value:0,color:"#ededed"}','se'=>'{id:"SE",balloonText:"'.__('c-se','wp-slimstat').': 0",value:0,color:"#ededed"}','ch'=>'{id:"CH",balloonText:"'.__('c-ch','wp-slimstat').': 0",value:0,color:"#ededed"}','sy'=>'{id:"SY",balloonText:"'.__('c-sy','wp-slimstat').': 0",value:0,color:"#ededed"}','tw'=>'{id:"TW",balloonText:"'.__('c-tw','wp-slimstat').': 0",value:0,color:"#ededed"}','tj'=>'{id:"TJ",balloonText:"'.__('c-tj','wp-slimstat').': 0",value:0,color:"#ededed"}','tz'=>'{id:"TZ",balloonText:"'.__('c-tz','wp-slimstat').': 0",value:0,color:"#ededed"}','th'=>'{id:"TH",balloonText:"'.__('c-th','wp-slimstat').': 0",value:0,color:"#ededed"}','tl'=>'{id:"TL",balloonText:"'.__('c-tl','wp-slimstat').': 0",value:0,color:"#ededed"}','tg'=>'{id:"TG",balloonText:"'.__('c-tg','wp-slimstat').': 0",value:0,color:"#ededed"}','to'=>'{id:"TO",balloonText:"'.__('c-to','wp-slimstat').': 0",value:0,color:"#ededed"}','tt'=>'{id:"TT",balloonText:"'.__('c-tt','wp-slimstat').': 0",value:0,color:"#ededed"}','tn'=>'{id:"TN",balloonText:"'.__('c-tn','wp-slimstat').': 0",value:0,color:"#ededed"}','tr'=>'{id:"TR",balloonText:"'.__('c-tr','wp-slimstat').': 0",value:0,color:"#ededed"}','tm'=>'{id:"TM",balloonText:"'.__('c-tm','wp-slimstat').': 0",value:0,color:"#ededed"}','tc'=>'{id:"TC",balloonText:"'.__('c-tc','wp-slimstat').': 0",value:0,color:"#ededed"}','ug'=>'{id:"UG",balloonText:"'.__('c-ug','wp-slimstat').': 0",value:0,color:"#ededed"}','ua'=>'{id:"UA",balloonText:"'.__('c-ua','wp-slimstat').': 0",value:0,color:"#ededed"}','ae'=>'{id:"AE",balloonText:"'.__('c-ae','wp-slimstat').': 0",value:0,color:"#ededed"}','gb'=>'{id:"GB",balloonText:"'.__('c-gb','wp-slimstat').': 0",value:0,color:"#ededed"}','us'=>'{id:"US",balloonText:"'.__('c-us','wp-slimstat').': 0",value:0,color:"#ededed"}','uy'=>'{id:"UY",balloonText:"'.__('c-uy','wp-slimstat').': 0",value:0,color:"#ededed"}','uz'=>'{id:"UZ",balloonText:"'.__('c-uz','wp-slimstat').': 0",value:0,color:"#ededed"}','vu'=>'{id:"VU",balloonText:"'.__('c-vu','wp-slimstat').': 0",value:0,color:"#ededed"}','ve'=>'{id:"VE",balloonText:"'.__('c-ve','wp-slimstat').': 0",value:0,color:"#ededed"}','vn'=>'{id:"VN",balloonText:"'.__('c-vn','wp-slimstat').': 0",value:0,color:"#ededed"}','vg'=>'{id:"VG",balloonText:"'.__('c-vg','wp-slimstat').': 0",value:0,color:"#ededed"}','vi'=>'{id:"VI",balloonText:"'.__('c-vi','wp-slimstat').': 0",value:0,color:"#ededed"}','eh'=>'{id:"EH",balloonText:"'.__('c-eh','wp-slimstat').': 0",value:0,color:"#ededed"}','ye'=>'{id:"YE",balloonText:"'.__('c-ye','wp-slimstat').': 0",value:0,color:"#ededed"}','zm'=>'{id:"ZM",balloonText:"'.__('c-zm','wp-slimstat').': 0",value:0,color:"#ededed"}','zw'=>'{id:"ZW",balloonText:"'.__('c-zw','wp-slimstat').': 0",value:0,color:"#ededed"}','gg'=>'{id:"GG",balloonText:"'.__('c-gg','wp-slimstat').': 0",value:0,color:"#ededed"}','je'=>'{id:"JE",balloonText:"'.__('c-je','wp-slimstat').': 0",value:0,color:"#ededed"}','im'=>'{id:"IM",balloonText:"'.__('c-im','wp-slimstat').': 0",value:0,color:"#ededed"}','mv'=>'{id:"MV",balloonText:"'.__('c-mv','wp-slimstat').': 0",value:0,color:"#ededed"}');
		$countries_not_represented = array( __('c-eu','wp-slimstat') );
		$max = 0;

		foreach($countries as $a_country){
			if (!array_key_exists($a_country['country'], $data_areas)) continue;

			$percentage = sprintf("%01.2f", (100*$a_country['count']/$total_count));
			$percentage_format = ($total_count > 0)?number_format($percentage, 2, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']):0;
			$balloon_text = __('c-'.$a_country['country'], 'wp-slimstat').': '.$percentage_format.'% ('.number_format($a_country['count'], 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']).')';
			$data_areas[$a_country['country']] = '{id:"'.strtoupper($a_country['country']).'",balloonText:"'.$balloon_text.'",value:'.$percentage.'}';

			if ($percentage > $max){
				$max = $percentage;
			}
		}
		?>

		<script src="<?php echo plugins_url('/js/ammap/ammap.js', dirname(__FILE__)) ?>" type="text/javascript"></script>
		<script src="<?php echo plugins_url('/js/ammap/world.js', dirname(__FILE__)) ?>" type="text/javascript"></script>
		<script type="text/javascript">
		//AmCharts.ready(function(){
			var dataProvider = {
				mapVar: AmCharts.maps.worldLow,
				getAreasFromMap:true,
				areas:[<?php echo implode(',', $data_areas) ?>]
			}; 

			// Create AmMap object
			var map = new AmCharts.AmMap();
			
			<?php if ($max != 0): ?>
			var legend = new AmCharts.ValueLegend();
			legend.height = 20;
			legend.minValue = "0.01";
			legend.maxValue = "<?php echo $max ?>%";
			legend.right = 20;
			legend.showAsGradient = true;
			legend.width = 300;
			map.valueLegend = legend;
			<?php endif; ?>

			// Configuration
			map.areasSettings = {
				autoZoom: true,
				color: "#9dff98",
				colorSolid: "#fa8a50",
				outlineColor: "#888888",
				selectedColor: "#ffb739"
			};
			map.backgroundAlpha = .9;
			map.backgroundColor = "#7adafd";
			map.backgroundZoomsToTop = true;
			map.balloon.color = "#000000";
			map.colorSteps = 5;
			map.mouseWheelZoomEnabled = true;
			map.pathToImages = "<?php echo plugins_url('/js/ammap/images/', dirname(__FILE__)) ?>";
			
			
			// Init Data
			map.dataProvider = dataProvider;

			// Display Map
			map.write("slim_p6_01_inside");
		//});
		</script><?php
	}
	
	public static function show_your_blog($_id = 'p0'){
		//if (false === ($your_content = get_transient( 'slimstat_your_content' ))){
			$your_content = array();
			$your_content['content_items'] = $GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type != 'revision' AND post_status != 'auto-draft'");
			$your_content['posts'] = $GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type = 'post'");
			$your_content['comments'] = $GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->comments}");
			$your_content['pingbacks'] = $GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->comments} WHERE comment_type = 'pingback'");
			$your_content['trackbacks'] = $GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->comments} WHERE comment_type = 'trackback'");
			$your_content['longest_post_id'] = $GLOBALS['wpdb']->get_var("SELECT ID FROM {$GLOBALS['wpdb']->posts} WHERE post_status = 'publish' ORDER BY LENGTH(post_content) DESC LIMIT 0,1");
			$your_content['oldest_post_timestamp'] = $GLOBALS['wpdb']->get_var("SELECT UNIX_TIMESTAMP(post_date) FROM {$GLOBALS['wpdb']->posts} WHERE post_status = 'publish' ORDER BY post_date ASC LIMIT 0,1");
			$your_content['longest_comment_id'] = $GLOBALS['wpdb']->get_var("SELECT comment_ID FROM {$GLOBALS['wpdb']->comments}");
			$your_content['avg_comments_per_post'] = !empty($your_content['posts'])?$your_content['comments']/$your_content['posts']:0;

			$days_in_interval = floor((date_i18n('U')-$your_content['oldest_post_timestamp'])/86400);
			$your_content['avg_posts_per_day'] = ($days_in_interval > 0)?$your_content['posts']/$days_in_interval:$your_content['posts'];

			// Store values as transients for 30 minutes
			set_transient('slimstat_your_content', $your_content, 1800);
		//}
		
		?>
		
		<p><?php self::inline_help(__("This value includes not only posts, but also custom post types, regardless of their status",'wp-slimstat')) ?>
			<?php _e('Content Items', 'wp-slimstat') ?> <span><?php echo number_format($your_content['content_items'], 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php _e('Total Comments', 'wp-slimstat') ?> <span><?php echo number_format($your_content['comments'], 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php _e('Pingbacks', 'wp-slimstat') ?> <span><?php echo number_format($your_content['pingbacks'], 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php _e('Trackbacks', 'wp-slimstat') ?> <span><?php echo number_format($your_content['trackbacks'], 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php _e('Longest Post (ID)', 'wp-slimstat') ?> <span><?php echo '<a href="post.php?action=edit&post='.$your_content['longest_post_id'].'">'.$your_content['longest_post_id'].'</a>' ?></span></p>
		<p><?php _e('Longest Comment (ID)', 'wp-slimstat') ?> <span><?php echo '<a href="comment.php?action=editcomment&c='.$your_content['longest_comment_id'].'">'.$your_content['longest_comment_id'].'</a>' ?></span></p>
		<p><?php _e('Avg Comments Per Post', 'wp-slimstat') ?> <span><?php echo number_format($your_content['avg_comments_per_post'], 2, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php _e('Avg Posts Per Day', 'wp-slimstat') ?> <span><?php echo number_format($your_content['avg_posts_per_day'], 2, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p><?php
	}

	public static function show_report_wrapper($_report_id = 'p0'){
		$is_ajax = false;
		if (!empty($_POST['report_id'])){
			// Let's make sure the request is coming from the right place
			check_ajax_referer('meta-box-order', 'security');
			$_report_id = $_POST['report_id'];
			$is_ajax = true;
		}

		if (!$is_ajax && (in_array($_report_id, self::$hidden_reports) || wp_slimstat::$options['async_load'] == 'yes')) return; 
		
		// Some boxes need extra information
		if (in_array($_report_id, array('slim_p1_03', 'slim_p1_08', 'slim_p1_13', 'slim_p1_17', 'slim_p2_03', 'slim_p2_04', 'slim_p2_05', 'slim_p2_06', 'slim_p2_18', 'slim_p2_19', 'slim_p2_10', 'slim_p3_02', 'slim_p3_04'))){
			$current_pageviews = wp_slimstat_db::count_records();
		}

		switch($_report_id){
			case 'slim_p1_01':
			case 'slim_p1_03':
				$chart_data = wp_slimstat_db::get_data_for_chart('COUNT(t1.ip)', 'COUNT(DISTINCT(t1.ip))');
				$chart_labels = array(__('Pageviews','wp-slimstat'), __('Unique IPs','wp-slimstat'));
				break;
			case 'slim_p2_01':
				$chart_data = wp_slimstat_db::get_data_for_chart('COUNT(DISTINCT t1.visit_id)', 'COUNT(DISTINCT t1.ip)', 'AND (tb.type = 0 OR tb.type = 2)');
				$chart_labels = array(__('Visits','wp-slimstat'), __('Unique IPs','wp-slimstat'));
				break;
			case 'slim_p3_01':
				$chart_data = wp_slimstat_db::get_data_for_chart('COUNT(DISTINCT(`domain`))', 'COUNT(DISTINCT(ip))', "AND domain <> '' AND domain <> '{$_SERVER['SERVER_NAME']}'");
				$chart_labels = array(__('Domains','wp-slimstat'), __('Unique IPs','wp-slimstat'));
				break;
			case 'slim_p4_01':
				$sql_from_where = " FROM (SELECT t1.visit_id, count(t1.ip) count, MAX(t1.dt) dt FROM [from_tables] WHERE [where_clause] GROUP BY t1.visit_id) AS ts1";
				$chart_data = wp_slimstat_db::get_data_for_chart('ROUND(AVG(ts1.count),2)', 'MAX(ts1.count)', 'AND t1.visit_id > 0', $sql_from_where);
				$chart_labels = array(__('Avg Pageviews','wp-slimstat'), __('Longest visit','wp-slimstat'));
				break;
			default:
		}

		switch($_report_id){
			case 'slim_p1_01':
			case 'slim_p2_01':
			case 'slim_p3_01':
			case 'slim_p4_01':
				self::show_chart($_report_id, $chart_data, $chart_labels);
				break;
			case 'slim_p1_02':
				self::show_about_wpslimstat($_report_id);
				break;
			case 'slim_p1_03':
				self::show_overview_summary($_report_id, $current_pageviews, $chart_data);
				break;
			case 'slim_p1_04':
				self::show_results('recent', $_report_id, 'user', array('custom_where' => 't1.user <> "" AND t1.dt > '.(date_i18n('U')-300), 'use_date_filters' => false));
				break;
			case 'slim_p1_05':
			case 'slim_p3_08':
				self::show_spy_view($_report_id);
				break;
			case 'slim_p1_06':
			case 'slim_p3_09':
				self::show_results('recent', $_report_id, 'searchterms');
				break;
			case 'slim_p1_08':
				self::show_results('popular', $_report_id, 'SUBSTRING_INDEX(t1.resource, "?", 1)', array('total_for_percentage' => $current_pageviews, 'as_column' => 'resource', 'filter_op' => 'contains'));
				break;
			case 'slim_p1_10':
			case 'slim_p3_05':
				$self_domain = parse_url(site_url());
				$self_domain = $self_domain['host'];
				self::show_results('popular_complete', $_report_id, 'domain', array('total_for_percentage' => wp_slimstat_db::count_records('t1.referer <> ""'), 'custom_where' => 't1.domain <> "'.$self_domain.'" AND t1.domain <> ""'));
				break;
			case 'slim_p1_11':
				self::show_results('popular_complete', $_report_id, 'user', array('total_for_percentage' => wp_slimstat_db::count_records('t1.user <> ""')));
				break;
			case 'slim_p1_12':
			case 'slim_p3_03':
			case 'slim_p4_14':
				self::show_results('popular', $_report_id, 'searchterms', array('total_for_percentage' => wp_slimstat_db::count_records('t1.searchterms <> ""')));
				break;
			case 'slim_p1_13':
			case 'slim_p2_10':
			case 'slim_p3_04':
				self::show_results('popular', $_report_id, 'country', array('total_for_percentage' => $current_pageviews));
				break;
			case 'slim_p1_15':
				self::show_rankings($_report_id);
				break;
			case 'slim_p1_17':
				self::show_results('popular', $_report_id, 'SUBSTRING(t1.language, 1, 2)', array('total_for_percentage' => $current_pageviews, 'as_column' => 'language', 'filter_op' => 'contains'));
				break;
			case 'slim_p2_02':
				self::show_visitors_summary($_report_id,  wp_slimstat_db::count_records_having('visit_id > 0', 'ip'), wp_slimstat_db::count_records('t1.visit_id > 0 AND tb.type <> 1', 'visit_id'));
				break;
			case 'slim_p2_03':
				self::show_results('popular', $_report_id, 'language', array('total_for_percentage' => $current_pageviews));
				break;
			case 'slim_p2_04':
				self::show_results('popular', $_report_id, 'browser', array('total_for_percentage' => $current_pageviews, 'more_columns' => ',tb.version'.((wp_slimstat::$options['show_complete_user_agent_tooltip']=='yes')?',tb.user_agent':'')));
				break;
			case 'slim_p2_05':
				self::show_results('popular', $_report_id, 'ip', array('total_for_percentage' =>$current_pageviews));
				break;
			case 'slim_p2_06':
				self::show_results('popular', $_report_id, 'platform', array('total_for_percentage' => $current_pageviews));
				break;
			case 'slim_p2_07':
				self::show_results('popular', $_report_id, 'resolution', array('total_for_percentage' => wp_slimstat_db::count_records('tss.resolution <> ""')));
				break;
			case 'slim_p2_09':
				self::show_plugins($_report_id, wp_slimstat_db::count_records('t1.visit_id > 0 AND tb.type <> 1'));
				break;
			case 'slim_p2_12':
				self::show_visit_duration($_report_id, wp_slimstat_db::count_records('visit_id > 0 AND tb.type <> 1', 'visit_id'));
				break;
			case 'slim_p2_13':
			case 'slim_p3_10':
				self::show_results('recent', $_report_id, 'country');
				break;
			case 'slim_p2_14':
				self::show_results('recent', $_report_id, 'resolution', array('join_tables' => 'tss.*'));
				break;
			case 'slim_p2_15':
				self::show_results('recent', $_report_id, 'platform', array('join_tables' => 'tb.*'));
				break;
			case 'slim_p2_16':
				self::show_results('recent', $_report_id, 'browser', array('join_tables' => 'tb.*'));
				break;
			case 'slim_p2_17':
				self::show_results('recent', $_report_id, 'language');
				break;
			case 'slim_p2_18':
				self::show_results('popular', $_report_id, 'browser', array('total_for_percentage' => $current_pageviews));
				break;
			case 'slim_p2_19':
				self::show_results('popular', $_report_id, 'CONCAT("p-", SUBSTRING(tb.platform, 1, 3))', array('total_for_percentage' => $current_pageviews, 'as_column' => 'platform'));
				break;
			case 'slim_p2_20':
				self::show_results('recent', $_report_id, 'user', array('custom_where' => 'notes LIKE "%[user:%"'));
				break;
			case 'slim_p2_21':
				self::show_results('popular_complete', $_report_id, 'user', array('total_for_percentage' => wp_slimstat_db::count_records('notes LIKE "%[user:%"'), 'custom_where' => 'notes LIKE "%[user:%"'));
				break;
			case 'slim_p3_02':
				self::show_traffic_sources_summary($_report_id, $current_pageviews);
				break;
			case 'slim_p3_06':
				self::show_results('popular_complete', $_report_id, 'domain', array('total_for_percentage' => wp_slimstat_db::count_records("t1.searchterms <> '' AND t1.domain <> '{$_SERVER['SERVER_NAME']}' AND t1.domain <> ''", 't1.id'), 'custom_where' => "t1.searchterms <> '' AND t1.domain <> '{$_SERVER['SERVER_NAME']}'"));
				break;
			case 'slim_p3_11':
			case 'slim_p4_17':
				self::show_results('popular', $_report_id, 'resource', array('total_for_percentage' => wp_slimstat_db::count_records('t1.domain <> ""'), 'custom_where' => 't1.domain <> ""'));
				break;
			case 'slim_p4_02':
				self::show_results('recent', $_report_id, 'resource', array('custom_where' => 'tci.content_type = "post"'));
				break;
			case 'slim_p4_03':
				self::show_results('recent', $_report_id, 'resource', array('custom_where' => 'tci.content_type <> "404"', 'having_clause' => 'HAVING COUNT(visit_id) = 1'));
				break;
			case 'slim_p4_04':
				self::show_results('recent', $_report_id, 'resource', array('custom_where' => '(t1.resource LIKE "%/feed%" OR t1.resource LIKE "%?feed=%" OR t1.resource LIKE "%&feed=%" OR tci.content_type LIKE "%feed%")'));
				break;
			case 'slim_p4_05':
				self::show_results('recent', $_report_id, 'resource', array('custom_where' => '(t1.resource LIKE "[404]%" OR tci.content_type LIKE "%404%")'));
				break;
			case 'slim_p4_06':
				self::show_results('recent', $_report_id, 'searchterms', array('custom_where' => '(t1.resource = "__l_s__" OR t1.resource = "" OR tci.content_type LIKE "%search%")'));
				break;
			case 'slim_p4_07':
				self::show_results('popular', $_report_id, 'category', array('total_for_percentage' => wp_slimstat_db::count_records('(tci.content_type LIKE "%category%")'), 'custom_where' => '(tci.content_type LIKE "%category%")'));
				break;
			case 'slim_p4_08':
				self::show_spy_view($_report_id, 0);
				break;
			case 'slim_p4_10':
				self::show_spy_view($_report_id, -1);
				break;
			case 'slim_p4_11':
				self::show_results('popular', $_report_id, 'resource', array('total_for_percentage' => wp_slimstat_db::count_records('tci.content_type = "post"'), 'custom_where' => 'tci.content_type = "post"'));
				break;
			case 'slim_p4_12':
				self::show_results('popular', $_report_id, 'resource', array('total_for_percentage' => wp_slimstat_db::count_records('(t1.resource LIKE "%/feed%" OR t1.resource LIKE "%?feed=%" OR t1.resource LIKE "%&feed=%" OR tci.content_type LIKE "%feed%")'), 'custom_where' => '(t1.resource LIKE "%/feed%" OR t1.resource LIKE "%?feed=%" OR t1.resource LIKE "%&feed=%" OR tci.content_type LIKE "%feed%")'));
				break;
			case 'slim_p4_13':
				self::show_results('popular', $_report_id, 'searchterms', array('total_for_percentage' => wp_slimstat_db::count_records('(t1.resource = "__l_s__" OR t1.resource = "" OR tci.content_type LIKE "%search%")'), 'custom_where' => '(t1.resource = "__l_s__" OR t1.resource = "" OR tci.content_type LIKE "%search%")'));
				break;
			case 'slim_p4_15':
				self::show_results('recent', $_report_id, 'resource', array('custom_where' => '(tci.content_type = "category" OR tci.content_type = "tag")', 'join_tables' => 'tci.*'));
				break;
			case 'slim_p4_16':
				self::show_results('popular', $_report_id, 'resource', array('total_for_percentage' => wp_slimstat_db::count_records('(t1.resource LIKE "[404]%" OR tci.content_type LIKE "%404%")'), 'custom_where' => '(t1.resource LIKE "[404]%" OR tci.content_type LIKE "%404%")'));
				break;
			case 'slim_p4_18':
				self::show_results('popular', $_report_id, 'author', array('total_for_percentage' => wp_slimstat_db::count_records('tci.author <> ""')));
				break;
			case 'slim_p4_19':
				self::show_results('popular', $_report_id, 'category', array('total_for_percentage' => wp_slimstat_db::count_records('(tci.content_type LIKE "%tag%")'), 'custom_where' => '(tci.content_type LIKE "%tag%")', 'more_columns' => ',tci.category'));
				break;
			case 'slim_p4_20':
				self::show_spy_view($_report_id, 1);
				break;
			case 'slim_p4_21':
				self::show_results('popular_outbound', $_report_id, 'resource', array('total_for_percentage' => wp_slimstat_db::count_outbound()));
				break;
			case 'slim_p4_22':
				self::show_your_blog($_report_id);
				break;
			case 'slim_p6_01':
				self::show_world_map($_report_id);
				break;
			case 'slim_p7_02':
				$using_screenres = wp_slimstat_admin::check_screenres();
				include_once(WP_PLUGIN_DIR."/wp-slimstat/admin/view/right-now.php");
				break;
			default:
		}
		if (!empty($_POST['report_id'])) die();
	}
}