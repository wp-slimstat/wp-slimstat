<?php

class wp_slimstat_reports{
	public static $filters = array();
	public static $system_filters = array();

	// Variables used to generate the HTML code for the metaboxes
	public static $current_tab = 1;
	public static $current_tab_url = '';
	public static $plugin_url = '';
	public static $home_url = '';
	public static $ip_lookup_url = 'http://www.infosniper.net/?ip_address=';
	public static $meta_report_order_nonce = '';
	public static $translations = array();

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
		// If a localization does not exist, use English
		if (!isset($l10n['wp-slimstat'])){
			load_textdomain('wp-slimstat', WP_PLUGIN_DIR .'/wp-slimstat/admin/lang/wp-slimstat-en_US.mo');
		}

		if (!empty($_GET['page'])) self::$current_tab = intval(str_replace('wp-slim-view-', '', $_GET['page']));
		if (!empty(wp_slimstat_admin::$view_url)) self::$current_tab_url = wp_slimstat_admin::$view_url.self::$current_tab;
		if (!empty(wp_slimstat::$options['ip_lookup_service'])) self::$ip_lookup_url = wp_slimstat::$options['ip_lookup_service'];
		self::$plugin_url = plugins_url('', dirname(__FILE__));
		self::$home_url = home_url();
		self::$meta_report_order_nonce = wp_create_nonce('meta-box-order');
		self::$translations = array(
			'browser' => strtolower(__('Browser','wp-slimstat')),
			'country' => strtolower(__('Country Code','wp-slimstat')),
			'ip' => strtolower(__('IP','wp-slimstat')),
			'searchterms' => strtolower(__('Search Terms','wp-slimstat')),
			'language' => strtolower(__('Language Code','wp-slimstat')),
			'platform' => strtolower(__('Operating System','wp-slimstat')),
			'resource' => strtolower(__('Permalink','wp-slimstat')),
			'domain' => strtolower(__('Domain','wp-slimstat')),
			'referer' => strtolower(__('Referer','wp-slimstat')),
			'user' => strtolower(__('Visitor\'s Name','wp-slimstat')),
			'plugins' => strtolower(__('Browser Capabilities','wp-slimstat')),
			'version' => strtolower(__('Browser Version','wp-slimstat')),
			'type' => strtolower(__('Browser Type','wp-slimstat')),
			'user_agent' => strtolower(__('User Agent','wp-slimstat')),
			'colordepth' => strtolower(__('Color Depth','wp-slimstat')),
			'css_version' => strtolower(__('CSS Version','wp-slimstat')),
			'notes' => strtolower(__('Pageview Attributes','wp-slimstat')),
			'outbound_resource' => strtolower(__('Outbound Link','wp-slimstat')),
			'author' => strtolower(__('Post Author','wp-slimstat')),
			'category' => strtolower(__('Post Category ID','wp-slimstat')),
			'other_ip' => strtolower(__('Originating IP','wp-slimstat')),
			'content_type' => strtolower(__('Resource Content Type','wp-slimstat')),
			'content_id' => strtolower(__('Resource ID','wp-slimstat')),
			'resolution' => strtolower(__('Screen Resolution','wp-slimstat')),
			'visit_id' => strtolower(__('Visit ID','wp-slimstat')),
			'hour' => strtolower(__('Hour','wp-slimstat')),
			'day' => strtolower(__('Day','wp-slimstat')),
			'month' => strtolower(__('Month','wp-slimstat')),
			'year' => strtolower(__('Year','wp-slimstat')),
			'interval' => strtolower(__('days','wp-slimstat'))
		);

		// Retrieve the order of this tab's panels and which ones are hidden
		$user = wp_get_current_user();
		$page_location = (wp_slimstat::$options['use_separate_menu'] == 'yes')?'slimstat':'admin';

		self::$all_reports_titles = array(
			'slim_p1_01' => __('Pageviews (chart)','wp-slimstat'),
			'slim_p1_02' => __('About WP SlimStat','wp-slimstat'),
			'slim_p1_03' => __('Summary','wp-slimstat'),
			'slim_p1_04' => __('Recent Known Visitors','wp-slimstat'),
			'slim_p1_05' => __('Spy View','wp-slimstat'),
			'slim_p1_06' => __('Recent Search Terms','wp-slimstat'),
			'slim_p1_07' => __('Top Languages - Just Visitors','wp-slimstat'),
			'slim_p1_08' => __('Top Pages','wp-slimstat'),
			'slim_p1_09' => __('Recent Countries','wp-slimstat'),
			'slim_p1_10' => __('Top Traffic Sources','wp-slimstat'),
			'slim_p1_11' => __('Top Known Visitors','wp-slimstat'),
			'slim_p1_12' => __('Top Search Terms','wp-slimstat'),
			'slim_p1_13' => __('Top Countries','wp-slimstat'),
			'slim_p1_14' => __('Top Downloads','wp-slimstat'),
			'slim_p2_01' => __('Human Visits (chart)','wp-slimstat'),
			'slim_p2_02' => __('Summary','wp-slimstat'),
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
			'slim_p4_05' => __('Recent 404 URLs','wp-slimstat'),
			'slim_p4_06' => __('Recent Internal Searches','wp-slimstat'),
			'slim_p4_07' => __('Top Categories','wp-slimstat'),
			'slim_p4_08' => __('Recent Outbound Links','wp-slimstat'),
			'slim_p4_10' => __('Recent Events','wp-slimstat'),
			'slim_p4_11' => __('Top Posts','wp-slimstat'),
			'slim_p4_12' => __('Top Feeds','wp-slimstat'),
			'slim_p4_13' => __('Top Internal Searches','wp-slimstat'),
			'slim_p4_14' => __('Top Search Terms','wp-slimstat'),
			'slim_p4_15' => __('Recent Categories','wp-slimstat'),
			'slim_p4_16' => __('Top 404 URLs','wp-slimstat'),
			'slim_p4_17' => __('Top Landing Pages','wp-slimstat'),
			'slim_p4_18' => __('Top Authors','wp-slimstat'),
			'slim_p4_19' => __('Top Tags','wp-slimstat'),
			'slim_p4_20' => __('Recent Downloads','wp-slimstat'),
			'slim_p7_02' => __('Right Now','wp-slimstat')
		);

		if (self::$current_tab != 1){
			self::$all_reports = get_user_option("meta-box-order_{$page_location}_page_wp-slim-view-".self::$current_tab, $user->ID);
			self::$all_reports = (self::$all_reports === false)?get_user_option("meta-box-order_{$page_location}_page_wp-slimstat", $user->ID):self::$all_reports; // backward compatible with old settings
		}
		self::$all_reports = (empty(self::$all_reports) || empty(self::$all_reports[0]))?array():explode(',', self::$all_reports[0]);

		// Use default values, if the corresponding option hasn't been initialized
		$old_ids = array_intersect(array('p1_01','p2_01','p3_01','p4_01'), self::$all_reports);

		if (empty(self::$all_reports) || !empty($old_ids)){
			switch(self::$current_tab){
				case 2:
					self::$all_reports = array('slim_p1_01','slim_p1_02','slim_p1_03','slim_p1_04','slim_p1_05','slim_p1_06','slim_p1_07','slim_p1_08','slim_p1_09','slim_p1_10','slim_p1_11','slim_p1_12','slim_p1_13','slim_p1_14');
					break;
				case 3:
					self::$all_reports = array('slim_p2_01','slim_p2_02','slim_p2_03','slim_p2_04','slim_p2_05','slim_p2_06','slim_p2_07','slim_p2_09','slim_p2_10','slim_p2_12','slim_p2_13','slim_p2_14','slim_p2_15','slim_p2_16','slim_p2_17','slim_p2_18','slim_p2_19','slim_p2_20','slim_p2_21');
					break;
				case 4:
					self::$all_reports = array('slim_p4_01','slim_p4_07','slim_p4_02','slim_p4_03','slim_p4_05','slim_p4_04','slim_p4_06','slim_p4_08','slim_p4_12','slim_p4_13','slim_p4_14','slim_p4_15','slim_p4_16','slim_p4_17','slim_p4_18','slim_p4_11','slim_p4_10','slim_p4_19','slim_p4_20');
					break;
				case 5:
					self::$all_reports = array('slim_p3_01','slim_p3_02','slim_p3_03','slim_p3_04','slim_p3_06','slim_p3_05','slim_p3_08','slim_p3_10','slim_p3_09','slim_p3_11');
					break;
				default:
			}
		}

		// Some boxes are hidden by default
		if (!empty($_GET['page']) && strpos($_GET['page'], 'wp-slim-view-') !== false)
			self::$hidden_reports = get_user_option("metaboxhidden_{$page_location}_page_wp-slim-view-".self::$current_tab, $user->ID);
		else // the script is being called from the dashboard widgets plugin
			self::$hidden_reports = get_user_option("metaboxhidden_{$page_location}", $user->ID);
		
		self::$hidden_reports = (self::$hidden_reports === false)?array():self::$hidden_reports;
		
		// Default values
		$old_ids = array_intersect(array('p1_01','p2_01','p3_01','p4_01'), self::$hidden_reports);
		if (self::$hidden_reports === false || !empty($old_ids)){
			switch(self::$current_tab){
				case 2:
					self::$hidden_reports = array('slim_p1_11', 'slim_p1_12', 'slim_p1_13', 'slim_p1_14');
					break;
				case 3:
					self::$hidden_reports= array('slim_p2_13', 'slim_p2_14', 'slim_p2_15', 'slim_p2_16', 'slim_p2_17');
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

		// Filters use the following format: browser equals Firefox|country contains gb
		if (!empty($_REQUEST['fs']) && is_array($_REQUEST['fs'])) self::$filters = $_REQUEST['fs'];
		if (!empty($_POST['f']) && !empty($_POST['o'])) self::$filters[$_POST['f']] = $_POST['o'].' '.(!isset($_POST['v'])?'':$_POST['v']);

		if (!empty($_POST['day'])) self::$filters['day'] = "equals {$_POST['day']}";
		if (!empty($_POST['month'])) self::$filters['month'] = "equals {$_POST['month']}";
		if (!empty($_POST['year'])) self::$filters['year'] = "equals {$_POST['year']}";
		if (!empty($_POST['interval']) && intval($_POST['interval']) != 0) self::$filters['interval'] = "equals {$_POST['interval']}";

		// The 'starting' filter only applies to the 'Right Now' screen
		if (self::$current_tab != 1) self::$filters['starting'] = '';

		// System filters are used to restrict access to the stats based on some settings
		if (wp_slimstat::$options['restrict_authors_view'] == 'yes' && !current_user_can('manage_options')) self::$system_filters['author'] = 'contains '.$GLOBALS['current_user']->user_login;
		
		// Allow third-party add-ons to modify filters before they are used
		self::$filters = apply_filters('slimstat_modify_admin_filters', self::$filters);
		self::$system_filters = apply_filters('slimstat_modify_admin_system_filters', self::$system_filters);

		// Import and initialize the API to interact with the database
		include_once(WP_PLUGIN_DIR."/wp-slimstat/admin/view/wp-slimstat-db.php");
		wp_slimstat_db::init(self::$filters, self::$system_filters);

		// Default text for the inline help associated to the chart
		self::$chart_tooltip = '<strong>'.htmlentities(__('Chart controls','wp-slimstat'), ENT_QUOTES, 'UTF-8').'</strong><ul><li>'.htmlentities(__('Use your mouse wheel to zoom in and out','wp-slimstat'), ENT_QUOTES, 'UTF-8').'</li><li>'.htmlentities(__('While zooming in, drag the chart to move to a different area','wp-slimstat'), ENT_QUOTES, 'UTF-8').'</li><li>'.htmlentities(__('Double click on an empty region to reset the zoom level','wp-slimstat'), ENT_QUOTES, 'UTF-8').'</li>';
		self::$chart_tooltip .= (!wp_slimstat_db::$timeframes['current_day']['day_selected'])?'<li>'.htmlentities(__('Click on a data point to display the activity chart for each hour of that day','wp-slimstat'), ENT_QUOTES, 'UTF-8').'</li>':'';
	}
	// end init

	public static function fs_url($_keys = array(), $_url = 'none', $_all = false){
		$filtered_url = ($_url == 'none')?self::$current_tab_url:$_url;

		if (!is_array($_keys)){
			if (!empty($_keys))
				$_keys = array($_keys => '');
			else
				$_keys = array();
		}
		
		if ($_all) $_keys = self::$filters;

		foreach($_keys as $a_key => $a_filter){
			if ($a_key == 'no-filter-selected-1') continue;
			//if (isset($_keys[$a_key])){
				//if (!empty($_keys[$a_key])) 
					$filtered_url .= "&amp;fs%5B$a_key%5D=".urlencode($_keys[$a_key]);
			//}
			//else{
				//$filtered_url .= "&amp;fs%5B$a_key%5D=".urlencode(self::$filters[$a_key]);
			//}
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
				$search_terms_info = '<a class="url" target="_blank" title="'.htmlentities(__('Go to the corresponding search engine result page','wp-slimstat'), ENT_QUOTES, 'UTF-8').'" href="http://'.$_domain.'/'.$query_formats[$matches[1]].'='.urlencode($_searchterms).'"></a> '.htmlentities($_searchterms, ENT_QUOTES, 'UTF-8');
			}
			else{
				$search_terms_info = '<a class="url" target="_blank" title="'.htmlentities(__('Go to the referring page','wp-slimstat'), ENT_QUOTES, 'UTF-8').'" href="'.$_referer.'"></a> '.htmlentities($_searchterms, ENT_QUOTES, 'UTF-8');
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
		$filters_dropdown = array_diff_key($_filters_array, array('direction' => 'asc', 'limit_results' => 20, 'starting' => 0));

		if (!empty($filters_dropdown)){
			$filters_html = "<span class='filters-title'>";
			if(count($filters_dropdown) > 1){
				$filters_html .= "<a class='remove-filter' title='".__('Remove all filters','wp-slimstat')."' style='margin-right:5px' href='".self::$current_tab_url."'></a>";
			}
			$filters_html .= __('Current filters:','wp-slimstat').'</span> ';
			foreach($filters_dropdown as $a_filter_label => $a_filter_details){
				$a_filter_value_no_slashes = htmlentities(str_replace('\\','', $a_filter_details[1]), ENT_QUOTES, 'UTF-8');
				$filters_html .= "<span class='filter-item'><a class='remove-filter' title='".htmlentities(__('Remove filter for','wp-slimstat'), ENT_QUOTES, 'UTF-8').' '.self::$translations[$a_filter_label]."' href='".self::fs_url($a_filter_label)."'></a> <code>".self::$translations[$a_filter_label].' '.__(str_replace('_', ' ', $a_filter_details[0]),'wp-slimstat')." $a_filter_value_no_slashes</code> </span> ";
			}
		}
		
		return ($filters_html != "<span class='filters-title'>".__('Current filters:','wp-slimstat').'</span> ')?$filters_html:'';
	}

	public static function report_header($_id = 'p0', $_tooltip = '', $_postbox_class = '', $_more = false, $_inside_class = '', $_title = ''){
		if (!empty($_postbox_class)) $_postbox_class .= ' ';
		
		$header_buttons = '<span class="box-refresh box-header-button" title="'.__('Refresh','wp-slimstat').'"></span>';
		if (!empty($_tooltip)) $header_buttons .= "<span class='box-help box-header-button' title='$_tooltip'></span>";
		$header_buttons = apply_filters('slimstat_report_header_buttons', $header_buttons, $_id);

		echo "<div class='postbox {$_postbox_class}slimstat' id='$_id'".(in_array($_id, self::$hidden_reports)?' style="display:none"':'').">$header_buttons<h3 class='hndle'>".(!empty($_title)?$_title:self::$all_reports_titles[$_id])."</h3><div class='inside $_inside_class'>";
		if (wp_slimstat::$options['async_load'] == 'yes') echo '<p class="loading"></p>';
	}

	public static function report_footer(){
		echo '</div></div>';
	}
	
	/**
	 * Attempts to convert a permalink into a post title
	 */
	public static function get_resource_title($_resource = ''){
		$post_id = url_to_postid(strtok($_resource, '?'));
		
		if ($post_id > 0){
			return get_the_title($post_id);
		}
		return htmlentities(urldecode($_resource), ENT_QUOTES, 'UTF-8');
	}
	
	public static function chart_title($_title = ''){
		if (wp_slimstat_db::$timeframes['current_day']['hour_selected']){
			return sprintf(__('%s Minute by Minute','wp-slimstat'), $_title);
		}
		elseif (wp_slimstat_db::$timeframes['current_day']['day_selected']){
			return sprintf(__('Hourly %s','wp-slimstat'), $_title);
		}
		elseif (wp_slimstat_db::$timeframes['current_day']['year_selected'] && !wp_slimstat_db::$timeframes['current_day']['month_selected']){
			return sprintf(__('Monthly %s','wp-slimstat'), $_title);
		}
		else{
			return sprintf(__('Daily %s','wp-slimstat'), $_title);
		}
	}
	
	public static function inline_help($_text = '', $_echo = true){
		$wrapped_text = "<span class='inline-help' title='$_text'></span>";
		if ($_echo)
			echo $wrapped_text;
		else
			return $wrapped_text;
	}

	public static function show_results($_type = 'recent', $_id = 'p0', $_column = 'id', $_args = array()){
		if ($_id != 'p0' && (in_array($_id, self::$hidden_reports) || wp_slimstat::$options['async_load'] == 'yes')) return;

		// Initialize default values, if not specified
		$_args = array_merge(array('custom_where' => '', 'more_columns' => '', 'join_tables' => '', 'having_clause' => '', 'order_by' => '', 'total_for_percentage' => 0, 'as_column' => '', 'filter_op' => 'equals'), $_args);

		$column = !empty($_args['as_column'])?$_column:wp_slimstat_db::get_table_identifier($_column).$_column;

		switch($_type){
			case 'recent':
				$results = wp_slimstat_db::get_recent($column, $_args['custom_where'], $_args['join_tables'], $_args['having_clause'], $_args['order_by']);
				break;
			case 'popular':
				$results = wp_slimstat_db::get_popular($column, $_args['custom_where'], $_args['more_columns'], $_args['having_clause'], $_args['as_column']);
				break;
			case 'popular_complete':
				$results = wp_slimstat_db::get_popular_complete($column, $_args['custom_where'], $_args['join_tables'], $_args['having_clause']);
				break;
			default:
		}

		if (count($results) == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat').'</p>';

		// Sometimes we use aliases for columns
		if (!empty($_args['as_column'])) $_column = trim(str_replace('AS', '', $_args['as_column']));

		for($i=0;$i<count($results);$i++){
			$element_title = $percentage = '';
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
					$element_title .= '<br>'.__('Category ID','wp-slimstat').": {$results[$i]['category']}";
					$cat_ids = explode(',', $results[$i]['category']);
					if (!empty($cat_ids)){
						$element_value = '';
						foreach ($cat_ids as $a_cat_id){
							$cat_name = get_cat_name($a_cat_id);
							if (empty($cat_name)) {
								$tag = get_term($a_cat_id, 'post_tag');
								if (!empty($tag)) $cat_name = $tag->name;
							}
							$element_value .= ', '.(!empty($cat_name)?$cat_name:$a_cat_id);
						}
						$element_value = substr($element_value, 2);
					}
					break;
				case 'country':
					$element_title .= '<br>'.__('Country Code','wp-slimstat').": {$results[$i]['country']}";
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
					$element_title = '<br>'.__('Language Code','wp-slimstat').": {$results[$i]['language']}";
					$element_value = __('l-'.$results[$i]['language'], 'wp-slimstat');
					break;
				case 'platform':
					$element_title = '<br>'.__('OS Code','wp-slimstat').": {$results[$i]['platform']}";
					$element_value = __($results[$i]['platform'], 'wp-slimstat');
					break;
				case 'resource':
					$post_id = url_to_postid(strtok($results[$i]['resource'], '?'));
					if ($post_id > 0) $element_title = '<br>'.htmlentities($results[$i]['resource'], ENT_QUOTES, 'UTF-8');
					$element_value = self::get_resource_title($results[$i]['resource']);
					break;
				case 'searchterms':
					if ($_type == 'recent'){
						$element_title = '<br>'.__('Referrer','wp-slimstat').": {$results[$i]['domain']}";
						$element_value = self::get_search_terms_info($results[$i]['searchterms'], $results[$i]['domain'], $results[$i]['referer'], true);
					}
					else{
						$element_value = htmlentities($results[$i]['searchterms'], ENT_QUOTES, 'UTF-8');
					}
					break;
				case 'user':
					$element_value = $results[$i]['user'];
					if (wp_slimstat::$options['show_display_name'] == 'yes' && strpos($results[$i]['notes'], '[user:') !== false){
						$element_custom_value = get_user_by('login', $results[$i]['user']);
						if (is_object($element_custom_value)) $element_value = $element_custom_value->display_name;
					}
					break;
				default:
			}
			
			$element_value = "<a class='slimstat-filter-link' title=\"".htmlentities(sprintf(__('Filter results where %s %s %s','wp-slimstat'), __(self::$translations[$_column],'wp-slimstat'), __($_args['filter_op'],'wp-slimstat'), $results[$i][$_column]), ENT_QUOTES, 'UTF-8')."\" href='".self::fs_url(array($_column => $_args['filter_op'].' '.$results[$i][$_column]))."'>$element_value</a>";

			if ($_type == 'recent'){
				$element_title = date_i18n(wp_slimstat_db::$formats['date_time_format'], $results[$i]['dt'], true).$element_title;
			}
			else{
				$percentage = '<span>'.(($_args['total_for_percentage'] > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$_args['total_for_percentage'])), 2, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']):0).'%</span>';
				$element_title = __('Hits','wp-slimstat').': '.number_format($results[$i]['count'], 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']).$element_title;
			}

			// Some columns require a special post-treatment
			if ($_column == 'resource' && strpos($_args['custom_where'], '404') === false){
				$element_value = '<a target="_blank" class="url" title="'.__('Open this URL in a new window','wp-slimstat').'" href="'.$results[$i]['resource'].'"></a>'.$element_value;
			}
			if ($_column == 'domain'){
				$element_url = htmlentities((strpos($results[$i]['referer'], '://') == false)?"http://{$results[$i]['domain']}{$results[$i]['referer']}":$results[$i]['referer'], ENT_QUOTES, 'UTF-8');
				$element_value = '<a target="_blank" class="url" title="'.__('Open this URL in a new window','wp-slimstat').'" href="'.$element_url.'"></a>'.$element_value;
			}
			if (!empty($results[$i]['ip']))
				$element_title .= '<br><a title="WHOIS: '.$results[$i]['ip'].'" class="whois" href="'.self::$ip_lookup_url.$results[$i]['ip'].'"></a> IP: <a class="slimstat-filter-link" title="'.htmlentities(sprintf(__('Filter results where IP equals %s','wp-slimstat'), $results[$i]['ip']), ENT_QUOTES, 'UTF-8').'" href="'.self::fs_url(array('ip' => 'equals '.$results[$i]['ip'])).'">'.$results[$i]['ip'].'</a>'.(!empty($results[$i]['other_ip'])?' / '.long2ip($results[$i]['other_ip']):'');

			echo "<p title='$element_title'>$element_pre_value$element_value$percentage</p>";
		}
	}

	public static function show_chart($_id = 'p0', $_chart_data = array(), $_chart_labels = array()){
		if ($_id != 'p0' && (in_array($_id, self::$hidden_reports) || wp_slimstat::$options['async_load'] == 'yes')) return;
		$rtl_filler_current = $rtl_filler_previous = 0;
		if ($GLOBALS['wp_locale']->text_direction == 'rtl' && !wp_slimstat_db::$timeframes['current_day']['day_selected']){
			$rtl_filler_current = 31-((date_i18n('Ym') == wp_slimstat_db::$timeframes['current_day']['y'].wp_slimstat_db::$timeframes['current_day']['m'])?wp_slimstat_db::$timeframes['current_day']['d']:cal_days_in_month(CAL_GREGORIAN, wp_slimstat_db::$timeframes['current_day']['m'], wp_slimstat_db::$timeframes['current_day']['y']));
			$rtl_filler_previous = 31-cal_days_in_month(CAL_GREGORIAN, wp_slimstat_db::$timeframes['previous_month']['m'], wp_slimstat_db::$timeframes['previous_month']['y']);
		} ?>
		<div id="chart-placeholder"></div><div id="chart-legend"></div>
		<script type="text/javascript">
			if (typeof SlimStatAdmin == 'undefined') var SlimStatAdmin = {data:[],ticks:[],options:{}};
			SlimStatAdmin.options.rtl_filler_current = <?php echo $rtl_filler_current ?>;
			SlimStatAdmin.options.rtl_filler_previous = <?php echo $rtl_filler_previous ?>;
			SlimStatAdmin.options.interval = <?php echo !empty(wp_slimstat_db::$filters['parsed']['interval'])?wp_slimstat_db::$filters['parsed']['interval'][1]:0 ?>;
			SlimStatAdmin.options.daily_chart = <?php echo ((!wp_slimstat_db::$timeframes['current_day']['year_selected'] || wp_slimstat_db::$timeframes['current_day']['month_selected']) && !wp_slimstat_db::$timeframes['current_day']['day_selected'] && !wp_slimstat_db::$timeframes['current_day']['hour_selected'])?'true':'false' ?>;
			SlimStatAdmin.options.max_yaxis = <?php echo $_chart_data['max_yaxis'] ?>;
			SlimStatAdmin.options.current_month = parseInt('<?php echo wp_slimstat_db::$timeframes['current_day']['m'] ?>');
			SlimStatAdmin.options.current_year = parseInt('<?php echo wp_slimstat_db::$timeframes['current_day']['y'] ?>');
			
			// Data for the chart
			SlimStatAdmin.ticks = [<?php echo $_chart_data['ticks'] ?>];
			SlimStatAdmin.data = [];
			SlimStatAdmin.data.push({<?php echo !empty($_chart_data['markings'])?"data:[{$_chart_data['markings']}],bars:{show:true,radius:1,barWidth:0.2,lineWidth:1,align:'center',fill:true,fillColor:'#bbff44',},lines:{show:false}":''; ?>});
			SlimStatAdmin.data.push({<?php echo !empty($_chart_data['previous']['data2'])?"label:'{$_chart_labels[1]} ".wp_slimstat_db::$timeframes['label_previous']."',data:[{$_chart_data['previous']['data2']}],points:{show:true,symbol:function(ctx, x, y, radius, shadow){ctx.arc(x, y, 2, 0, Math.PI * 2, false)}}":''; ?>});
			SlimStatAdmin.data.push({<?php echo !empty($_chart_data['previous']['data1'])?"label:'{$_chart_labels[0]} ".wp_slimstat_db::$timeframes['label_previous']."',data:[{$_chart_data['previous']['data1']}],points:{show:true,symbol:function(ctx, x, y, radius, shadow){ctx.arc(x, y, 2, 0, Math.PI * 2, false)}}":''; ?>});
			SlimStatAdmin.data.push({label:'<?php echo $_chart_labels[1].' '.wp_slimstat_db::$timeframes['label_current'] ?>',data:[<?php echo $_chart_data['current']['data2'] ?>],points:{show:true,symbol:function(ctx, x, y, radius, shadow){ctx.arc(x, y, 2, 0, Math.PI * 2, false)}}});
			SlimStatAdmin.data.push({label:'<?php echo $_chart_labels[0].' '.wp_slimstat_db::$timeframes['label_current'] ?>',data:[<?php echo $_chart_data['current']['data1'] ?>],points:{show:true,symbol:function(ctx, x, y, radius, shadow){ctx.arc(x, y, 2, 0, Math.PI * 2, false)}}});
		</script>
		<?php 
	}
	
	public static function show_spy_view($_id = 'p0', $_type = 'undefined'){
		if ($_id != 'p0' && (in_array($_id, self::$hidden_reports) || wp_slimstat::$options['async_load'] == 'yes')) return;

		$results = !is_int($_type)?wp_slimstat_db::get_recent('t1.id', '(t1.visit_id > 0 AND tb.type <> 1)', 'tb.*', '', 't1.visit_id DESC'):wp_slimstat_db::get_recent_outbound($_type);

		if (count($results) == 0) echo '<p class="nodata">'.__('No data to display','wp-slimstat').'</p>';

		$visit_id = 0;
		for($i=0;$i<count($results);$i++){
			$element_title = '';
			$results[$i]['ip'] = long2ip($results[$i]['ip']);

			if (wp_slimstat::$options['convert_ip_addresses'] == 'yes'){
				$host_by_ip = gethostbyaddr($results[$i]['ip']);
				$host_by_ip .= ($host_by_ip != $results[$i]['ip'])?" ({$results[$i]['ip']})":'';
			}
			else{
				$host_by_ip = $results[$i]['ip'];
			}
			$results[$i]['dt'] = date_i18n(wp_slimstat_db::$formats['date_time_format'], $results[$i]['dt'], true);
			if (!empty($results[$i]['searchterms']) && empty($results[$i]['resource'])){
				$results[$i]['resource'] = __('Search for','wp-slimstat').': '.htmlentities($results[$i]['searchterms'], ENT_QUOTES, 'UTF-8');
			}
			if (!empty($results[$i]['resource']) && $_type == 0){
				$results[$i]['resource'] = '<a target="_blank" class="url" title="'.__('Open this URL in a new window','wp-slimstat').'" href="'.$results[$i]['resource'].'"></a> '.self::get_resource_title($results[$i]['resource']);
			}

			if ($visit_id != $results[$i]['visit_id']){
				$highlight_row = !empty($results[$i]['searchterms'])?' is-search-engine':' is-direct';
				if (empty($results[$i]['user'])){
					$host_by_ip = "<a class='slimstat-filter-link' href='".self::fs_url(array('ip' => 'equals '.$results[$i]['ip']))."'>$host_by_ip</a>";
				}
				else{
					$display_user_name = $results[$i]['user'];
					if (wp_slimstat::$options['show_display_name'] == 'yes' && strpos($results[$i]['notes'], '[user:') !== false){
						$display_real_name = get_user_by('login', $results[$i]['user']);
						if (is_object($display_real_name)) $display_user_name = $display_real_name->display_name;
					}
					$host_by_ip = "<a class='slimstat-filter-link' class='highlight-user' href='".self::fs_url(array('user' => 'equals '.$results[$i]['user']))."'>{$display_user_name}</a>";
					$highlight_row = (strpos( $results[$i]['notes'], '[user]') !== false)?' is-known-user':' is-known-visitor';
				}
				$host_by_ip = "<a class='whois img-inline-help' href='".self::$ip_lookup_url."{$results[$i]['ip']}' target='_blank' title='WHOIS: {$results[$i]['ip']}'></a> $host_by_ip";
				$results[$i]['country'] = "<a class='slimstat-filter-link image first' href='".self::fs_url(array('country' => 'equals '.$results[$i]['country']))."'><img class='img-inline-help' src='".wp_slimstat_reports::$plugin_url."/images/flags/{$results[$i]['country']}.png' title='".__('Country','wp-slimstat').': '.__('c-'.$results[$i]['country'],'wp-slimstat')."' width='16' height='16'/></a>";
				$results[$i]['other_ip'] = !empty($results[$i]['other_ip'])?" <a class='slimstat-filter-link' href='".self::fs_url(array('other_ip' => 'equals '.$results[$i]['other_ip']))."'>".long2ip($results[$i]['other_ip']).'</a>&nbsp;&nbsp;':'';
		
				echo "<p class='header$highlight_row'>{$results[$i]['country']} $host_by_ip <span class='date-and-other'><em>{$results[$i]['other_ip']} {$results[$i]['dt']}</em></span></p>";
				$visit_id = $results[$i]['visit_id'];
			}

			if (!empty($results[$i]['domain'])){
				if (!is_int($_type)){
					$element_url = htmlentities((strpos($results[$i]['referer'], '://') == false)?"http://{$results[$i]['domain']}{$results[$i]['referer']}":$results[$i]['referer'], ENT_QUOTES, 'UTF-8');
					$element_title = " title='".__('Source','wp-slimstat').": <a target=\"_blank\" class=\"url\" title=\"".__('Open this URL in a new window','wp-slimstat')."\" href=\"$element_url\"></a><a class=\"slimstat-filter-link\" title=\"".sprintf(__('Filter results where domain equals %s','wp-slimstat'), $results[$i]['domain'])."\" href=\"".self::fs_url(array('domain' => 'equals '.$results[$i]['domain']))."\">{$results[$i]['domain']}</a>";
					if (!empty($results[$i]['searchterms'])){
						$element_title .= "<br>".__('Keywords','wp-slimstat').": ";
						$element_title .= "<a class=\"slimstat-filter-link\" title=\"".sprintf(__('Filter results where searchterm equals %s','wp-slimstat'), htmlentities($results[$i]['searchterms'], ENT_QUOTES, 'UTF-8'))."\" ";
						$element_title .= "href=\"".self::fs_url(array('searchterms' => 'equals '.$results[$i]['searchterms']))."\">";
						$element_title .= htmlentities(self::get_search_terms_info($results[$i]['searchterms'], $results[$i]['domain'], $results[$i]['referer'], true), ENT_QUOTES, 'UTF-8');
						$element_title .= "</a>'";
					}
					else{
						$element_title .= "'";
					}
				}
				else{
					$permalink = parse_url($results[$i]['referer']);
					$results[$i]['notes'] = str_replace('|ET:click', '', $results[$i]['notes']);
					$element_url = htmlentities((strpos($results[$i]['referer'], '://') === false)?self::$home_url.$results[$i]['referer']:$results[$i]['referer'], ENT_QUOTES, 'UTF-8');
					$element_title = " title='".__('Source','wp-slimstat').": <a target=\"_blank\" class=\"url\" title=\"".__('Open this URL in a new window','wp-slimstat')."\" href=\"$element_url\"></a><a class=\"slimstat-filter-link\" title=\"".htmlentities(sprintf(__('Filter results where resource equals %s','wp-slimstat'), $permalink['path']), ENT_QUOTES, 'UTF-8')."\" href=\"".self::fs_url(array('resource' => 'equals '.$permalink['path']))."\">{$permalink['path']}</a>";
					$element_title .= !empty($results[$i]['notes'])?'<br><strong>Link Details</strong>: '.htmlentities($results[$i]['notes'], ENT_QUOTES, 'UTF-8'):'';
					$element_title .= ($_type == -1)?' <strong>Type</strong>: '.$results[$i]['type']:'';
					$element_title .= "'";
				}
			}
			echo "<p$element_title>{$results[$i]['resource']}</p>";
		}
	}
	
	public static function show_about_wpslimstat($_id = 'p0'){ 
		if ($_id != 'p0' && (in_array($_id, self::$hidden_reports) || wp_slimstat::$options['async_load'] == 'yes')) return; ?>
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
		if ($_id != 'p0' && (in_array($_id, self::$hidden_reports) || wp_slimstat::$options['async_load'] == 'yes')) return;

		$reset_timezone = date_default_timezone_get();
		date_default_timezone_set('UTC');

		$temp_filters_date_sql_where = wp_slimstat_db::$filters['date_sql_where'];
		wp_slimstat_db::$filters['date_sql_where'] = ''; // override date filters
		$today_pageviews = wp_slimstat_db::count_records('t1.dt BETWEEN '.wp_slimstat_db::$timeframes['current_day']['utime'].' AND '.(wp_slimstat_db::$timeframes['current_day']['utime']+86399));
		$yesterday_pageviews = wp_slimstat_db::count_records('t1.dt BETWEEN '.(wp_slimstat_db::$timeframes['previous_day']['utime']).' AND '.(wp_slimstat_db::$timeframes['previous_day']['utime']+86399));
		wp_slimstat_db::$filters['date_sql_where'] = $temp_filters_date_sql_where; ?>
			<p><?php self::inline_help(htmlentities(__('A request to load a single HTML file. WP SlimStat logs a "pageview" each time the tracking code is executed.','wp-slimstat'), ENT_QUOTES, 'UTF-8'));
				_e('Pageviews', 'wp-slimstat'); echo ' '.wp_slimstat_db::$timeframes['label_current']; ?> <span><?php echo number_format($_current_pageviews, 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
			<p><?php self::inline_help(htmlentities(__('This counter is based on any user activity in the last 5 minutes.','wp-slimstat'), ENT_QUOTES, 'UTF-8'));
				_e('Last 5 minutes', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_records('t1.dt > '.(date_i18n('U')-300), '*', true, '', false), 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
			<p><?php self::inline_help(htmlentities(__('This counter is based on any user activity in the last 30 minutes.','wp-slimstat'), ENT_QUOTES, 'UTF-8'));
				_e('Last 30 minutes', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_records('t1.dt > '.(date_i18n('U')-1800), '*', true, '', false), 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
			<p><a class="slimstat-filter-link" title="<?php _e('Filter results where date equals today','wp-slimstat') ?>" href="<?php echo self::fs_url(array('day' => 'equals '.date_i18n('d'))) ?>"><?php _e('Today', 'wp-slimstat'); ?></a> <span><?php echo number_format(wp_slimstat_db::count_records('t1.dt > '.(date_i18n('U', mktime(0, 0, 0, date_i18n('m'), date_i18n('d'), date_i18n('Y')))), '*', true, '', false), 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
			<p><a class="slimstat-filter-link" title="<?php _e('Filter results where date equals yesterday','wp-slimstat') ?>" href="<?php echo self::fs_url(array('day' => 'equals '.date_i18n('d',mktime(0, 0, 0, date_i18n('m'), date_i18n('d')-1, date_i18n('Y'))))) ?>"><?php _e('Yesterday', 'wp-slimstat'); ?></a> <span><?php echo number_format(wp_slimstat_db::count_records('t1.dt BETWEEN '.(date_i18n('U', mktime(0, 0, 0, date_i18n('m'), date_i18n('d')-1, date_i18n('Y')))).' AND '.(date_i18n('U', mktime(23, 59, 59, date_i18n('m'), date_i18n('d')-1, date_i18n('Y')))), '*', true, '', false), 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
			<p><?php self::inline_help(htmlentities(__('How many pages have been visited on average during the current period.','wp-slimstat'), ENT_QUOTES, 'UTF-8'));
				_e('Avg Pageviews', 'wp-slimstat') ?> <span><?php echo number_format(($_chart_data['current']['non_zero_count'] > 0)?intval($_current_pageviews/$_chart_data['current']['non_zero_count']):0, 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
			<p><?php self::inline_help(htmlentities(__('Visitors who landed on your site after searching for a keyword on Google, Yahoo, etc.','wp-slimstat'), ENT_QUOTES, 'UTF-8'));
				_e('From Search Results', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_records('t1.searchterms <> ""'), 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
			<p><?php self::inline_help(htmlentities(__('Used to differentiate between multiple requests to download a file from one internet address (IP) and requests originating from many distinct addresses','wp-slimstat'), ENT_QUOTES, 'UTF-8'));
				_e('Unique IPs', 'wp-slimstat'); ?> <span><?php echo number_format(wp_slimstat_db::count_records('1=1', 't1.ip'), 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p><?php
		date_default_timezone_set($reset_timezone);
	}
	
	public static function show_visitors_summary($_id = 'p0', $_total_human_hits = 0, $_total_human_visits = 0){
		if ($_id != 'p0' && (in_array($_id, self::$hidden_reports) || wp_slimstat::$options['async_load'] == 'yes')) return;

		$new_visitors = wp_slimstat_db::count_records_having('visit_id > 0', 'ip', 'COUNT(visit_id) = 1');
		$new_visitors_rate = ($_total_human_hits > 0)?sprintf("%01.2f", (100*$new_visitors/$_total_human_hits)):0;
		if (intval($new_visitors_rate) > 99) $new_visitors_rate = '100';
		$metrics_per_visit = wp_slimstat_db::get_max_and_average_pages_per_visit(); ?>
			<p><?php self::inline_help(htmlentities(__('A visit is a session of at most 30 minutes. Returning visitors are counted multiple times if they perform multiple visits.','wp-slimstat'), ENT_QUOTES, 'UTF-8')) ?>
				<?php _e('Human visits', 'wp-slimstat') ?> <span><?php echo number_format($_total_human_visits, 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
			<p><?php self::inline_help(htmlentities(__('This number includes <strong>human visits</strong> only.','wp-slimstat'), ENT_QUOTES, 'UTF-8')) ?>
				<?php _e('Unique IPs', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_records('t1.visit_id > 0 AND tb.type <> 1', 't1.ip'), 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
			<p><?php self::inline_help(htmlentities(__('Percentage of single-page visits, i.e. visits in which the person left your site from the entrance page.','wp-slimstat'), ENT_QUOTES, 'UTF-8')) ?>
				<?php _e('Bounce rate', 'wp-slimstat') ?> <span><?php echo number_format($new_visitors_rate, 2, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?>%</span></p>
			<p><?php self::inline_help(htmlentities(__('Visitors who had previously left a comment on your blog.','wp-slimstat'), ENT_QUOTES, 'UTF-8')) ?>
				<?php _e('Known visitors', 'wp-slimstat') ?> <span><?php echo wp_slimstat_db::count_records('t1.user <> ""', 't1.user') ?></span></p>
			<p><?php self::inline_help(htmlentities(__('Human users who visited your site only once.','wp-slimstat'), ENT_QUOTES, 'UTF-8')) ?>
				<?php _e('New visitors', 'wp-slimstat') ?> <span><?php echo number_format($new_visitors, 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
			<p><?php _e('Bots', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_records('tb.type = 1'), 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
			<p><?php _e('Pages per visit', 'wp-slimstat') ?> <span><?php echo number_format($metrics_per_visit['avg'], 2, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
			<p><?php _e('Longest visit', 'wp-slimstat') ?> <span><?php echo number_format($metrics_per_visit['max'], 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']).' '.__('hits','wp-slimstat') ?></span></p><?php
	}

	public static function show_plugins($_id = 'p0', $_total_human_hits = 0){
		if ($_id != 'p0' && (in_array($_id, self::$hidden_reports) || wp_slimstat::$options['async_load'] == 'yes')) return;

		$wp_slim_plugins = array('flash', 'silverlight', 'acrobat', 'java', 'mediaplayer', 'director', 'real', 'quicktime');
		foreach($wp_slim_plugins as $i => $a_plugin){
			$count_results = wp_slimstat_db::count_records("t1.plugins LIKE '%{$a_plugin}%'");
			echo "<p title='".__('Hits','wp-slimstat').": $count_results'>".ucfirst($a_plugin).' <span>';
			echo ($_total_human_hits > 0)?number_format(sprintf("%01.2f", (100*$count_results/$_total_human_hits)), 2, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']):0;
			echo '%</span></p>';
		}
	}
	
	public static function show_visit_duration($_id = 'p0', $_total_human_visits = 0){
		if ($_id != 'p0' && (in_array($_id, self::$hidden_reports) || wp_slimstat::$options['async_load'] == 'yes')) return;

		$count = wp_slimstat_db::count_records_having('t1.visit_id > 0 AND tb.type <> 1', 'visit_id', 'max(t1.dt) - min(t1.dt) >= 0 AND max(t1.dt) - min(t1.dt) <= 30');
		$percentage = ($_total_human_visits > 0)?sprintf("%01.2f", (100*$count/$_total_human_visits)):0;
		$extra_info =  "title='".__('Hits','wp-slimstat').": {$count}'";
		echo "<p $extra_info>".__('0 - 30 seconds','wp-slimstat')." <span>$percentage%</span></p>";

		$count = wp_slimstat_db::count_records_having('t1.visit_id > 0 AND tb.type <> 1', 'visit_id', 'max(t1.dt) - min(t1.dt) > 30 AND max(t1.dt) - min(t1.dt) <= 60');
		$percentage = ($_total_human_visits > 0)?sprintf("%01.2f", (100*$count/$_total_human_visits)):0;
		$extra_info =  "title='".__('Hits','wp-slimstat').": {$count}'";
		echo "<p $extra_info>".__('31 - 60 seconds','wp-slimstat')." <span>$percentage%</span></p>";

		$count = wp_slimstat_db::count_records_having('t1.visit_id > 0 AND tb.type <> 1', 'visit_id', 'max(t1.dt) - min(t1.dt) > 60 AND max(t1.dt) - min(t1.dt) <= 180');
		$percentage = ($_total_human_visits > 0)?sprintf("%01.2f", (100*$count/$_total_human_visits)):0;
		$extra_info =  "title='".__('Hits','wp-slimstat').": {$count}'";
		echo "<p $extra_info>".__('1 - 3 minutes','wp-slimstat')." <span>$percentage%</span></p>";

		$count = wp_slimstat_db::count_records_having('t1.visit_id > 0 AND tb.type <> 1', 'visit_id', 'max(t1.dt) - min(t1.dt) > 180 AND max(t1.dt) - min(t1.dt) <= 300');
		$percentage = ($_total_human_visits > 0)?sprintf("%01.2f", (100*$count/$_total_human_visits)):0;
		$extra_info =  "title='".__('Hits','wp-slimstat').": {$count}'";
		echo "<p $extra_info>".__('3 - 5 minutes','wp-slimstat')." <span>$percentage%</span></p>";

		$count = wp_slimstat_db::count_records_having('t1.visit_id > 0 AND tb.type <> 1', 'visit_id', 'max(t1.dt) - min(t1.dt) > 300 AND max(t1.dt) - min(t1.dt) <= 420');
		$percentage = ($_total_human_visits > 0)?sprintf("%01.2f", (100*$count/$_total_human_visits)):0;
		$extra_info =  "title='".__('Hits','wp-slimstat').": {$count}'";
		echo "<p $extra_info>".__('5 - 7 minutes','wp-slimstat')." <span>$percentage%</span></p>";

		$count = wp_slimstat_db::count_records_having('t1.visit_id > 0 AND tb.type <> 1', 'visit_id', 'max(t1.dt) - min(t1.dt) > 420 AND max(t1.dt) - min(t1.dt) <= 600');
		$percentage = ($_total_human_visits > 0)?sprintf("%01.2f", (100*$count/$_total_human_visits)):0;
		$extra_info =  "title='".__('Hits','wp-slimstat').": {$count}'";
		echo "<p $extra_info>".__('7 - 10 minutes','wp-slimstat')." <span>$percentage%</span></p>";

		$count = wp_slimstat_db::count_records_having('t1.visit_id > 0 AND tb.type <> 1', 'visit_id', 'max(t1.dt) - min(t1.dt) > 600 AND max(t1.dt) - min(t1.dt) <= 1200');
		$percentage = ($_total_human_visits > 0)?sprintf("%01.2f", (100*$count/$_total_human_visits)):0;
		$extra_info =  "title='".__('Hits','wp-slimstat').": {$count}'";
		echo "<p $extra_info>".__('10 - 20 minutes','wp-slimstat')." <span>$percentage%</span></p>";

		$count = wp_slimstat_db::count_records_having('t1.visit_id > 0 AND tb.type <> 1', 'visit_id', 'max(t1.dt) - min(t1.dt) > 1200');
		$percentage = ($_total_human_visits > 0)?sprintf("%01.2f", (100*$count/$_total_human_visits)):0;
		$extra_info =  "title='".__('Hits','wp-slimstat').": {$count}'";
		echo "<p $extra_info>".__('More than 20 minutes','wp-slimstat')." <span>$percentage%</span></p>";
	}
	
	public static function show_traffic_sources_summary($_id = 'p0', $_current_pageviews = 0){
		if ($_id != 'p0' && (in_array($_id, self::$hidden_reports) || wp_slimstat::$options['async_load'] == 'yes')) return;

		$total_human_hits = wp_slimstat_db::count_records('t1.visit_id > 0 AND tb.type <> 1');
		$new_visitors = wp_slimstat_db::count_records_having('visit_id > 0', 'ip', 'COUNT(visit_id) = 1');
		$new_visitors_rate = ($total_human_hits > 0)?sprintf("%01.2f", (100*$new_visitors/$total_human_hits)):0;
		if (intval($new_visitors_rate) > 99) $new_visitors_rate = '100'; ?>		
		<p><?php self::inline_help(htmlentities(__('A request to load a single HTML file. WP SlimStat logs a "pageview" each time the tracking code is executed.','wp-slimstat'), ENT_QUOTES, 'UTF-8')) ?>
			<?php _e('Pageviews', 'wp-slimstat') ?> <span><?php echo number_format($_current_pageviews, 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(htmlentities(__('A referrer (or referring site) is the site that a visitor previously visited before following a link to your site.','wp-slimstat'), ENT_QUOTES, 'UTF-8')) ?>
			<?php _e('Unique Referrers', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_records("t1.domain <> '{$_SERVER['SERVER_NAME']}' AND t1.domain <> ''", 't1.domain'), 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(htmlentities(__("Visitors who visited the site by typing the URL directly into their browser. <em>Direct</em> can also refer to the visitors who clicked on the links from their bookmarks/favorites, untagged links within emails, or links from documents that don't include tracking variables.",'wp-slimstat'), ENT_QUOTES, 'UTF-8')) ?>
			<?php _e('Direct Pageviews', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_records('t1.domain = ""', 't1.id'), 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(htmlentities(__("Visitors who came to your site via searches on Google or some other search engine.",'wp-slimstat'), ENT_QUOTES, 'UTF-8')) ?>
			<?php _e('From a search result', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_records("t1.searchterms <> '' AND t1.domain <> '{$_SERVER['SERVER_NAME']}' AND t1.domain <> ''", 't1.id'), 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(htmlentities(__("The first page that a user views during a session. This is also known as the <em>entrance page</em>. For example, if they search for 'Brooklyn Office Space,' and they land on your home page, it gets counted (for that visit) as a landing page.",'wp-slimstat'), ENT_QUOTES, 'UTF-8')) ?>
			<?php _e('Unique Landing Pages', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_records('t1.domain <> ""', 't1.resource'), 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(htmlentities(__("Number of single-page visits to your site over the selected period.",'wp-slimstat'), ENT_QUOTES, 'UTF-8')) ?>
			<?php _e('Bounce Pages', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_bouncing_pages(), 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p>
		<p><?php self::inline_help(htmlentities(__('Percentage of single-page visits, i.e. visits in which the person left your site from the entrance page.','wp-slimstat'), ENT_QUOTES, 'UTF-8')) ?>
			<?php _e('New Visitors Rate', 'wp-slimstat') ?> <span><?php echo number_format($new_visitors_rate, 2, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?>%</span></p>
		<p><?php self::inline_help(htmlentities(__("Visitors who visited the site in the last 5 minutes coming from a search engine.",'wp-slimstat'), ENT_QUOTES, 'UTF-8')) ?>
			<?php _e('Currently from search engines', 'wp-slimstat') ?> <span><?php echo number_format(wp_slimstat_db::count_records("t1.searchterms <> '' AND t1.domain <> '{$_SERVER['SERVER_NAME']}' AND t1.domain <> '' AND t1.dt > UNIX_TIMESTAMP()-300", 't1.id', false), 0, wp_slimstat_db::$formats['decimal'], wp_slimstat_db::$formats['thousand']) ?></span></p><?php
	}
	
	public static function show_report_wrapper($_report_id = 'p0'){
		$report_id = ''; $is_ajax = true;
		if (!empty($_POST['report_id'])){
			// Let's make sure the request is coming from the right place
			check_ajax_referer('meta-box-order', 'security');
			$report_id = $_POST['report_id'];
			self::$current_tab_url = !empty($_POST['current_tab'])?wp_slimstat_admin::$view_url.intval($_POST['current_tab']):1;
			$ajax_report_id = 'p0';
		}
		else if (!empty($_report_id)){
			$report_id = $ajax_report_id = '#'.$_report_id;
		}

		// Some boxes need extra information
		if (in_array($report_id, array('#slim_p1_03', '#slim_p1_08', '#slim_p1_13', '#slim_p2_03', '#slim_p2_04', '#slim_p2_05', '#slim_p2_06', '#slim_p2_18', '#slim_p2_19', '#slim_p2_10', '#slim_p3_02', '#slim_p3_04'))){
			$current_pageviews = wp_slimstat_db::count_records();
		}

		switch($report_id){
			case '#slim_p1_01':
			case '#slim_p1_03':
				$chart_data = wp_slimstat_db::extract_data_for_chart('COUNT(t1.ip)', 'COUNT(DISTINCT(t1.ip))');
				$chart_labels = array(__('Pageviews','wp-slimstat'), __('Unique IPs','wp-slimstat'));
				break;
			case '#slim_p2_01':
				$chart_data = wp_slimstat_db::extract_data_for_chart('COUNT(DISTINCT t1.visit_id)', 'COUNT(DISTINCT t1.ip)', 'AND (tb.type = 0 OR tb.type = 2)');
				$chart_labels = array(__('Visits','wp-slimstat'), __('Unique IPs','wp-slimstat'));
				break;
			case '#slim_p3_01':
				$chart_data = wp_slimstat_db::extract_data_for_chart('COUNT(DISTINCT(`domain`))', 'COUNT(DISTINCT(ip))', "AND domain <> '' AND domain <> '{$_SERVER['SERVER_NAME']}'");
				$chart_labels = array(__('Domains','wp-slimstat'), __('Unique IPs','wp-slimstat'));
				break;
			case '#slim_p4_01':
				$sql_from_where = " FROM (SELECT t1.visit_id, count(t1.ip) count, MAX(t1.dt) dt FROM [from_tables] WHERE [where_clause] GROUP BY t1.visit_id) AS ts1";
				$chart_data = wp_slimstat_db::extract_data_for_chart('ROUND(AVG(ts1.count),2)', 'MAX(ts1.count)', 'AND t1.visit_id > 0', $sql_from_where);
				$chart_labels = array(__('Avg Pageviews','wp-slimstat'), __('Longest visit','wp-slimstat'));
				break;
			default:
		}
		
		switch($report_id){
			case '#slim_p1_01':
			case '#slim_p2_01':
			case '#slim_p3_01':
			case '#slim_p4_01':
				self::show_chart($ajax_report_id, $chart_data, $chart_labels);
				break;
			case '#slim_p1_02':
				self::show_about_wpslimstat($ajax_report_id);
				break;
			case '#slim_p1_03':
				self::show_overview_summary($ajax_report_id, $current_pageviews, $chart_data);
				break;
			case '#slim_p1_04':
				self::show_results('recent', $ajax_report_id, 'user');
				break;
			case '#slim_p1_05':
			case '#slim_p3_08':
				self::show_spy_view($ajax_report_id);
				break;
			case '#slim_p1_06':
			case '#slim_p3_09':
				self::show_results('recent', $ajax_report_id, 'searchterms');
				break;
			case '#slim_p1_07':
				self::show_results('popular', $ajax_report_id, 'language', array('total_for_percentage' => wp_slimstat_db::count_records('t1.visit_id > 0 AND tb.type <> 1'), 'custom_where' => 't1.visit_id > 0 AND tb.type <> 1'));
				break;
			case '#slim_p1_08':
				self::show_results('popular', $ajax_report_id, 'SUBSTRING_INDEX(t1.resource, "?", 1)', array('total_for_percentage' => $current_pageviews, 'as_column' => 'resource', 'filter_op' => 'contains'));
				break;
			case '#slim_p1_09':
			case '#slim_p2_13':
			case '#slim_p3_10':
				self::show_results('recent', $ajax_report_id, 'country');
				break;
			case '#slim_p1_10':
			case '#slim_p3_05':
				$self_domain = parse_url(site_url());
				$self_domain = $self_domain['host'];
				self::show_results('popular_complete', $ajax_report_id, 'domain', array('total_for_percentage' => wp_slimstat_db::count_records('t1.referer <> ""'), 'custom_where' => 't1.domain <> "'.$self_domain.'" AND t1.domain <> ""'));
				break;
			case '#slim_p1_11':
				self::show_results('popular_complete', $ajax_report_id, 'user', array('total_for_percentage' => wp_slimstat_db::count_records('t1.user <> ""')));
				break;
			case '#slim_p1_12':
			case '#slim_p3_03':
			case '#slim_p4_14':
				self::show_results('popular', $ajax_report_id, 'searchterms', array('total_for_percentage' => wp_slimstat_db::count_records('t1.searchterms <> ""')));
				break;
			case '#slim_p1_13':
			case '#slim_p2_10':
			case '#slim_p3_04':
				self::show_results('popular', $ajax_report_id, 'country', array('total_for_percentage' => $current_pageviews));
				break;
			case '#slim_p1_14':
				self::show_results('popular', $ajax_report_id, 'outbound_resource', array('total_for_percentage' => wp_slimstat_db::count_records('tob.outbound_resource <> "" AND tob.type = 1'), 'custom_where' => 'tob.type = 1'));
				break;
			case '#slim_p2_02':
				self::show_visitors_summary($ajax_report_id, wp_slimstat_db::count_records('t1.visit_id > 0 AND tb.type <> 1'), wp_slimstat_db::count_records('t1.visit_id > 0 AND tb.type <> 1', 'visit_id'));
				break;
			case '#slim_p2_03':
				self::show_results('popular', $ajax_report_id, 'language', array('total_for_percentage' => $current_pageviews));
				break;
			case '#slim_p2_04':
				self::show_results('popular', $ajax_report_id, 'browser', array('total_for_percentage' => $current_pageviews, 'more_columns' => ',tb.version,tb.user_agent'));
				break;
			case '#slim_p2_05':
				self::show_results('popular', $ajax_report_id, 'ip', array('total_for_percentage' =>$current_pageviews));
				break;
			case '#slim_p2_06':
				self::show_results('popular', $ajax_report_id, 'platform', array('total_for_percentage' => $current_pageviews));
				break;
			case '#slim_p2_07':
				self::show_results('popular', $ajax_report_id, 'resolution', array('total_for_percentage' => wp_slimstat_db::count_records('tss.resolution <> ""')));
				break;
			case '#slim_p2_09':
				self::show_plugins($ajax_report_id, wp_slimstat_db::count_records('t1.visit_id > 0 AND tb.type <> 1'));
				break;
			case '#slim_p2_12':
				self::show_visit_duration($ajax_report_id, wp_slimstat_db::count_records('visit_id > 0 AND tb.type <> 1', 'visit_id'));
				break;
			case '#slim_p2_14':
				self::show_results('recent', $ajax_report_id, 'resolution', array('join_tables' => 'tss.*'));
				break;
			case '#slim_p2_15':
				self::show_results('recent', $ajax_report_id, 'platform', array('join_tables' => 'tb.*'));
				break;
			case '#slim_p2_16':
				self::show_results('recent', $ajax_report_id, 'browser', array('join_tables' => 'tb.*'));
				break;
			case '#slim_p2_17':
				self::show_results('recent', $ajax_report_id, 'language');
				break;
			case '#slim_p2_18':
				self::show_results('popular', $ajax_report_id, 'browser', array('total_for_percentage' => $current_pageviews));
				break;
			case '#slim_p2_19':
				self::show_results('popular', $ajax_report_id, 'CONCAT("p-", SUBSTRING(tb.platform, 1, 3))', array('total_for_percentage' => $current_pageviews, 'as_column' => 'platform'));
				break;
			case '#slim_p2_20':
				self::show_results('recent', $ajax_report_id, 'user', array('custom_where' => 'notes LIKE "%[user:%"'));
				break;
			case '#slim_p2_21':
				self::show_results('popular_complete', $ajax_report_id, 'user', array('total_for_percentage' => wp_slimstat_db::count_records('notes LIKE "%[user:%"'), 'custom_where' => 'notes LIKE "%[user:%"'));
				break;
			case '#slim_p3_02':
				self::show_traffic_sources_summary($ajax_report_id, $current_pageviews);
				break;
			case '#slim_p3_06':
				self::show_results('popular_complete', $ajax_report_id, 'domain', array('total_for_percentage' => wp_slimstat_db::count_records("t1.searchterms <> '' AND t1.domain <> '{$_SERVER['SERVER_NAME']}' AND t1.domain <> ''", 't1.id'), 'custom_where' => "t1.searchterms <> '' AND t1.domain <> '{$_SERVER['SERVER_NAME']}'"));
				break;
			case '#slim_p3_11':
			case '#slim_p4_17':
				self::show_results('popular', $ajax_report_id, 'resource', array('total_for_percentage' => wp_slimstat_db::count_records('t1.domain <> ""'), 'custom_where' => 't1.domain <> ""'));
				break;
			case '#slim_p4_02':
				self::show_results('recent', $ajax_report_id, 'resource', array('custom_where' => 'tci.content_type = "post"'));
				break;
			case '#slim_p4_03':
				self::show_results('recent', $ajax_report_id, 'resource', array('custom_where' => 'tci.content_type <> "404"', 'having_clause' => 'HAVING COUNT(visit_id) = 1'));
				break;
			case '#slim_p4_04':
				self::show_results('recent', $ajax_report_id, 'resource', array('custom_where' => '(t1.resource LIKE "%/feed%" OR t1.resource LIKE "%?feed=%" OR t1.resource LIKE "%&feed=%" OR tci.content_type LIKE "%feed%")'));
				break;
			case '#slim_p4_05':
				self::show_results('recent', $ajax_report_id, 'resource', array('custom_where' => '(t1.resource LIKE "[404]%" OR tci.content_type LIKE "%404%")'));
				break;
			case '#slim_p4_06':
				self::show_results('recent', $ajax_report_id, 'searchterms', array('custom_where' => '(t1.resource = "__l_s__" OR t1.resource = "" OR tci.content_type LIKE "%search%")'));
				break;
			case '#slim_p4_07':
				self::show_results('popular', $ajax_report_id, 'category', array('total_for_percentage' => wp_slimstat_db::count_records('(tci.content_type LIKE "%category%")'), 'custom_where' => '(tci.content_type LIKE "%category%")', 'more_columns' => ',tci.category'));
				break;
			case '#slim_p4_08':
				self::show_spy_view($ajax_report_id, 0);
				break;
			case '#slim_p4_10':
				self::show_spy_view($ajax_report_id, -1);
				break;
			case '#slim_p4_11':
				self::show_results('popular', $ajax_report_id, 'resource', array('total_for_percentage' => wp_slimstat_db::count_records('tci.content_type = "post"'), 'custom_where' => 'tci.content_type = "post"'));
				break;
			case '#slim_p4_12':
				self::show_results('popular', $ajax_report_id, 'resource', array('total_for_percentage' => wp_slimstat_db::count_records('(t1.resource LIKE "%/feed%" OR t1.resource LIKE "%?feed=%" OR t1.resource LIKE "%&feed=%" OR tci.content_type LIKE "%feed%")'), 'custom_where' => '(t1.resource LIKE "%/feed%" OR t1.resource LIKE "%?feed=%" OR t1.resource LIKE "%&feed=%" OR tci.content_type LIKE "%feed%")'));
				break;
			case '#slim_p4_13':
				self::show_results('popular', $ajax_report_id, 'searchterms', array('total_for_percentage' => wp_slimstat_db::count_records('(t1.resource = "__l_s__" OR t1.resource = "" OR tci.content_type LIKE "%search%")'), 'custom_where' => '(t1.resource = "__l_s__" OR t1.resource = "" OR tci.content_type LIKE "%search%")'));
				break;
			case '#slim_p4_15':
				self::show_results('recent', $ajax_report_id, 'resource', array('custom_where' => '(tci.content_type = "category" OR tci.content_type = "tag")', 'join_tables' => 'tci.*'));
				break;
			case '#slim_p4_16':
				self::show_results('popular', $ajax_report_id, 'resource', array('total_for_percentage' => wp_slimstat_db::count_records('(t1.resource LIKE "[404]%" OR tci.content_type LIKE "%404%")'), 'custom_where' => '(t1.resource LIKE "[404]%" OR tci.content_type LIKE "%404%")'));
				break;
			case '#slim_p4_18':
				self::show_results('popular', $ajax_report_id, 'author', array('total_for_percentage' => wp_slimstat_db::count_records('tci.author <> ""')));
				break;
			case '#slim_p4_19':
				self::show_results('popular', $ajax_report_id, 'category', array('total_for_percentage' => wp_slimstat_db::count_records('(tci.content_type LIKE "%tag%")'), 'custom_where' => '(tci.content_type LIKE "%tag%")', 'more_columns' => ',tci.category'));
				break;
			case '#slim_p4_20':
				self::show_spy_view($ajax_report_id, 1);
				break;
			case '#slim_p7_02':
				$using_screenres = wp_slimstat_admin::check_screenres();
				include_once(WP_PLUGIN_DIR."/wp-slimstat/admin/view/right-now.php");
				break;
			default:
		}
		if (!empty($_POST['report_id'])) die();
	}
}