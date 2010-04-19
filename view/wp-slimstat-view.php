<?php

// Let's extend the main class with the methods we use in this panel
class wp_slimstat_view {

	function __construct(){
		global $table_prefix;
	
		// We use WP SlimStat tables to retrieve metrics
		$this->table_stats = $table_prefix . 'slim_stats';
		$this->table_countries = $table_prefix . 'slim_countries';
		$this->table_browsers = $table_prefix . 'slim_browsers';
		$this->table_screenres = $table_prefix . 'slim_screenres';
		$this->table_visits = $table_prefix . 'slim_visits';
	
		// TODO: get filters from $_GET
		$this->current_date = array();
		$this->current_date['d'] = date_i18n('d');
		$this->current_date['m'] = date_i18n('m');
		$this->current_date['y'] = date_i18n('Y');
	
		$this->yesterday['d'] = date_i18n('d', strtotime("{$this->current_date['y']}-{$this->current_date['m']}-".($this->current_date['d'] - 1)) ); 
		$this->yesterday['m'] = date_i18n('m', strtotime("{$this->current_date['y']}-{$this->current_date['m']}-".($this->current_date['d'] - 1)) ); 
		$this->yesterday['y'] = date_i18n('Y', strtotime("{$this->current_date['y']}-{$this->current_date['m']}-".($this->current_date['d'] - 1)) ); 

		$this->previous_month['m'] = $this->current_date['m'] - 1;
		$this->previous_month['m'] = date_i18n('m', strtotime("{$this->current_date['y']}-".($this->current_date['m'] - 1)."-01") );
		$this->previous_month['y'] = date_i18n('Y', strtotime("{$this->current_date['y']}-".($this->current_date['m'] - 1)."-01") );
	}
	
	// Functions are declared in alphabetical order
	
	public function count_bots(){
		global $wpdb;
		
		$sql = "SELECT COUNT(`ip`)
				FROM `$this->table_stats`
				WHERE YEAR(FROM_UNIXTIME(`dt`)) = {$this->current_date['y']} AND MONTH(FROM_UNIXTIME(`dt`)) = {$this->current_date['m']}
					AND `visit_id` = 0";
		
		return intval($wpdb->get_var($sql));
	}
	
	public function count_new_visitors(){
		global $wpdb;
		
		$sql = "SELECT COUNT(*) FROM (
					SELECT `ip`
					FROM `$this->table_stats`
					WHERE YEAR(FROM_UNIXTIME(`dt`)) = {$this->current_date['y']} AND MONTH(FROM_UNIXTIME(`dt`)) = {$this->current_date['m']}  AND `visit_id` > 0
					GROUP BY `ip`
					HAVING COUNT(`visit_id`) = 1)
				AS ts1";
		
		return intval($wpdb->get_var($sql));
	}
	
	public function count_plugin($_plugin_name = ''){
		global $wpdb;

		$sql = "SELECT COUNT(*) count
				FROM `$this->table_stats`
				WHERE (YEAR(FROM_UNIXTIME(`dt`)) = {$this->current_date['y']} AND MONTH(FROM_UNIXTIME(`dt`)) = {$this->current_date['m']})
					AND `plugins` LIKE '%$_plugin_name%'";
		
		return intval($wpdb->get_var($sql));
	}
	
	public function get_browsers(){
		global $wpdb;

		$sql = "SELECT DISTINCT `browser`,`version`, COUNT(*) count
				FROM `$this->table_stats` ts INNER JOIN `$this->table_browsers` tb ON ts.`browser_id` = tb.`browser_id`
				WHERE tb.`browser` <> ''
				GROUP BY `browser`, `version`
				ORDER BY count DESC
				LIMIT 20";
	
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
	
		$sql = "SELECT ts.`ip`, ts.`country`, ts.`domain`, ts.`referer`, ts.`resource`, tb.`browser`, ts.`visit_id`, ts.`dt`
				FROM `$this->table_stats` ts, `$this->table_browsers` tb 
				WHERE ts.`browser_id` = tb.`browser_id`
					AND ts.`visit_id` > 0
				ORDER BY `visit_id` DESC, `dt` ASC
				LIMIT 0,20";
		
		return $wpdb->get_results($sql, ARRAY_A);
	}
	
	public function get_max_and_average_pages_per_visit(){
		global $wpdb;
		
		$sql = "SELECT AVG(ts1.count) avg, MAX(ts1.count) max FROM (
					SELECT count(`ip`) count, `visit_id`
					FROM `$this->table_stats`
					WHERE YEAR(FROM_UNIXTIME(`dt`)) = {$this->current_date['y']} AND MONTH(FROM_UNIXTIME(`dt`)) = {$this->current_date['m']}
						AND `visit_id` > 0
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
				FROM `$this->table_stats`
				WHERE `searchterms` = '' AND `domain` <> '{$_SERVER['SERVER_NAME']}' AND `domain` <> ''
					AND (YEAR(FROM_UNIXTIME(`dt`)) = {$this->current_date['y']} AND MONTH(FROM_UNIXTIME(`dt`)) = {$this->current_date['m']})
				GROUP BY `domain`
				ORDER BY count DESC
				LIMIT 0,20";
	
		return $wpdb->get_results($sql, ARRAY_A);
	}
	
	public function get_pageviews_by_day(){
		global $wpdb;
	
		$sql = "SELECT YEAR(FROM_UNIXTIME(`dt`)) y, DATE_FORMAT(FROM_UNIXTIME(`dt`), '%m') m, DATE_FORMAT(FROM_UNIXTIME(`dt`), '%d') d, COUNT(`ip`) count_pageviews, COUNT(DISTINCT(`ip`)) count_unique
				FROM `$this->table_stats`
				WHERE (YEAR(FROM_UNIXTIME(`dt`)) = {$this->current_date['y']} AND MONTH(FROM_UNIXTIME(`dt`)) = {$this->current_date['m']}) 
					OR (YEAR(FROM_UNIXTIME(`dt`)) = {$this->previous_month['y']} AND MONTH(FROM_UNIXTIME(`dt`)) = {$this->previous_month['m']})
				GROUP BY YEAR(FROM_UNIXTIME(`dt`)), DATE_FORMAT(FROM_UNIXTIME(`dt`), '%m'), DATE_FORMAT(FROM_UNIXTIME(`dt`), '%d')
				ORDER BY d,m ASC";
		$array_results = $wpdb->get_results($sql, ARRAY_A);
	
		$array_current_month = $array_previous_month = $array_unique_ips_current = $array_unique_ips_previous = array();
		$current_non_zero_count = $previous_non_zero_count = 0;
		$current_month_xml = $previous_month_xml = $unique_ips_xml = '';
	
		// Let's reorganize the result
		if (is_array($array_results)){
			foreach($array_results as $a_result) {
		
				if ($a_result['m'] == $this->current_date['m']) {
					// Pageviews
					$array_current_month[intval($a_result['d'])] = $a_result['count_pageviews'];
			
					// Unique IPs
					$array_unique_ips_current[intval($a_result['d'])] = $a_result['count_unique'];
			
					if ($a_result['count_unique'] > 0) $current_non_zero_count++;
				}
				else {
					$array_previous_month[intval($a_result['d'])] = $a_result['count_pageviews'];		
					$array_unique_ips_previous[intval($a_result['d'])] = $a_result['count_unique'];
			
					if ($a_result['count_unique'] > 0) $previous_non_zero_count++;
				}
			}

			// Let's generate the XML for the flash chart
			for($i=1;$i<32;$i++) { // a month can have 31 days at maximum
				$categories_xml .= "<category name='$i'/>";
				if ($i <= $this->current_date['d']) {
					$current_month_xml .= "<set value='".intval($array_current_month[$i])."' link='/'/>";
					$unique_ips_xml .= "<set value='".intval($array_unique_ips_current[$i])."'/>";
				}
				$previous_month_xml .= "<set value='".intval($array_previous_month[$i])."' alpha='80' link='/'/>";
			}
		}
		
		$xml = "<graph canvasBorderThickness='0' canvasBorderColor='ffffff' decimalPrecision='0' divLineAlpha='20' formatNumberScale='0' lineThickness='2' showNames='1' showShadow='0' showValues='0' yAxisName='".__('Pageviews','wp-slimstat-view')."'>";
		$xml .= "<categories>$categories_xml</categories>";
		$xml .= "<dataset seriesname='".__('Unique IPs','wp-slimstat-view')." {$this->current_date['m']}/{$this->current_date['y']}' color='bbbbbb' showValue='1' anchorSides='3'>$unique_ips_xml</dataset>";
		$xml .= "<dataset seriesname='".__('Pageviews','wp-slimstat-view')." {$this->previous_month['m']}/{$this->previous_month['y']}' color='0099ff' showValue='1'>$previous_month_xml</dataset>";
		$xml .= "<dataset seriesname='".__('Pageviews','wp-slimstat-view')." {$this->current_date['m']}/{$this->current_date['y']}' color='0022cc' showValue='1' anchorSides='10'>$current_month_xml</dataset>";
		$xml .= "</graph>";
	
		$result->xml = $xml;
		$result->pageviews_current_month = $array_current_month;
		$result->pageviews_previous_month = $array_previous_month;
		$result->unique_ips_current_month = $array_unique_ips_current;
		$result->unique_ips_previous_month = $array_unique_ips_previous;
		$result->current_non_zero_count = $current_non_zero_count;
		$result->previous_non_zero_count = $previous_non_zero_count;
	
		return $result;
	}
	
	public function get_recent($_field = 'id', $_field2 = '', $_limit_lenght = 30){
		global $wpdb;
	
		$sql = "SELECT SUBSTRING(`$_field`, 1, $_limit_lenght) short_string, `$_field` long_string, LENGTH(`$_field`) len
				".(!empty($_field2)?", `$_field2` $_field2 ":'')."
				FROM `$this->table_stats` 
				WHERE `$_field` <> ''
				GROUP BY long_string
				ORDER BY `dt` DESC
				LIMIT 20";
		
		return $wpdb->get_results($sql, ARRAY_A);
	}
	
	public function get_recent_browsers(){
		global $wpdb;
	
		$sql = "SELECT DISTINCT SUBSTRING(tb.`browser`, 1, 25) as browser, tb.`version`, tb.`css_version`
				FROM `$this->table_stats` ts, `$this->table_browsers` tb 
				WHERE ts.`browser_id` = tb.`browser_id`
					AND tb.`platform` <> '' AND tb.`platform` <> '0'
					AND tb.`css_version` <> '' AND tb.`css_version` <> '0'
				ORDER BY `dt` DESC
				LIMIT 0,20";
		
		return $wpdb->get_results($sql, ARRAY_A);
	}
	
	public function get_recent_keywords_pages(){
		global $wpdb;
	
		$sql = "SELECT SUBSTRING(`searchterms`, 1, 40) short_searchterms, `searchterms`, LENGTH(`searchterms`) len_searchterms,
						SUBSTRING(`resource`, 1, 40) short_resource, `resource`, LENGTH(`resource`) len_resource, 
						`domain`, `referer`, COUNT(*) count
				FROM `$this->table_stats`
				WHERE `searchterms` <> ''
				GROUP BY `searchterms`, `resource`, `domain`, `referer`
				ORDER BY `dt` DESC
				LIMIT 0,20";
		
		return $wpdb->get_results($sql, ARRAY_A);
	}
	
	public function get_referer_count(){
		global $wpdb;

		$sql = "SELECT COUNT(*) count
				FROM `$this->table_stats`
				WHERE `referer` <> ''";
	
		return intval($wpdb->get_var($sql));
	}

	public function get_top($_field = 'id', $_field2 = '', $_limit_lenght = 30, $_only_current_month = false){
		global $wpdb;

		$sql = "SELECT DISTINCT SUBSTRING(`$_field`, 1, $_limit_lenght) short_string,`$_field` long_string, LENGTH(`$_field`) len, COUNT(*) count
				".(!empty($_field2)?", `$_field2` $_field2 ":'')."
				FROM `$this->table_stats`
				WHERE `$_field` <> ''
				".($_only_current_month?" AND (YEAR(FROM_UNIXTIME(`dt`)) = {$this->current_date['y']} AND MONTH(FROM_UNIXTIME(`dt`)) = {$this->current_date['m']})":'')."
				GROUP BY long_string
				ORDER BY count DESC
				LIMIT 20";
	
		return $wpdb->get_results($sql, ARRAY_A);
	}
	
	public function get_top_browsers_by_operating_system(){
		global $wpdb;

		$sql = "SELECT tb.`browser`, tb.`version`, tb.`platform`, COUNT(*) count
				FROM `$this->table_stats` ts, `$this->table_browsers` tb
				WHERE (YEAR(FROM_UNIXTIME(`dt`)) = {$this->current_date['y']} AND MONTH(FROM_UNIXTIME(`dt`)) = {$this->current_date['m']})
					AND ts.`browser_id` = tb.`browser_id`
					AND tb.`platform` <> '' AND tb.`platform` <> 'unknown' AND tb.`version` <> '' AND tb.`version` <> '0'
				GROUP BY tb.`browser`, tb.`version`, tb.`platform`
				ORDER BY count DESC
				LIMIT 0,20";
		
		return $wpdb->get_results($sql, ARRAY_A);
	}
	
	public function get_top_only_visits($_field = 'id', $_field2 = '', $_limit_lenght = 30){
		global $wpdb;

		$sql = "SELECT  SUBSTRING(`$_field`, 1, $_limit_lenght) short_string,`$_field` long_string, LENGTH(`$_field`) len, COUNT(*) count
				".(!empty($_field2)?", `$_field2` $_field2 ":'')."
				FROM `$this->table_stats`
				WHERE `$_field` <> ''
					AND (YEAR(FROM_UNIXTIME(`dt`)) = {$this->current_date['y']} AND MONTH(FROM_UNIXTIME(`dt`)) = {$this->current_date['m']})
					AND `visit_id` > 0
				GROUP BY long_string
				ORDER BY count DESC
				LIMIT 0,20";
	
		return $wpdb->get_results($sql, ARRAY_A);
	}
	
	public function get_top_operating_systems(){
		global $wpdb;
	
		$sql = "SELECT tb.`platform`, COUNT(*) count
				FROM `$this->table_stats` ts, `$this->table_browsers` tb 
				WHERE ts.`browser_id` = tb.`browser_id`
					AND tb.`platform` <> '' AND tb.`platform` <> 'unknown'
				GROUP BY tb.`platform`
				ORDER BY count DESC
				LIMIT 0,20";
		
		return $wpdb->get_results($sql, ARRAY_A);
	}
	
	public function get_top_screenres($_group_by_colordepth = false){
		global $wpdb;

		$sql = "SELECT tsr.`resolution`, COUNT(*) count
				".(($_group_by_colordepth)?", tsr.`colordepth`, tsr.`antialias`":'')."
				FROM `$this->table_stats` ts, `$this->table_screenres` tsr
				WHERE (YEAR(FROM_UNIXTIME(`dt`)) = {$this->current_date['y']} AND MONTH(FROM_UNIXTIME(`dt`)) = {$this->current_date['m']})
					AND ts.`screenres_id` = tsr.`screenres_id`
				GROUP BY tsr.`resolution`
				".(($_group_by_colordepth)?", tsr.`colordepth`, tsr.`antialias`":'')."
				ORDER BY count DESC
				LIMIT 0,20";
		
		return $wpdb->get_results($sql, ARRAY_A);
	}
	
	public function get_top_search_engines(){
		global $wpdb;

		$sql = "SELECT `domain`, COUNT(*) count
				FROM `$this->table_stats`
				WHERE `searchterms` <> '' AND `domain` <> '{$_SERVER['SERVER_NAME']}'
					AND (YEAR(FROM_UNIXTIME(`dt`)) = {$this->current_date['y']} AND MONTH(FROM_UNIXTIME(`dt`)) = {$this->current_date['m']})
				GROUP BY `domain`
				ORDER BY count DESC
				LIMIT 0,20";
	
		return $wpdb->get_results($sql, ARRAY_A);
	}
	
	public function get_total_count(){
		global $wpdb;

		$sql = "SELECT COUNT(*) count
				FROM `$this->table_stats`";
	
		return intval($wpdb->get_var($sql));
	}
	
	public function get_traffic_sources_by_day(){
		global $wpdb;
	
		$sql = "SELECT YEAR(FROM_UNIXTIME(`dt`)) y, DATE_FORMAT(FROM_UNIXTIME(`dt`), '%m') m, DATE_FORMAT(FROM_UNIXTIME(`dt`), '%d') d, COUNT(`ip`) count_sources
				FROM `$this->table_stats`
				WHERE ((YEAR(FROM_UNIXTIME(`dt`)) = {$this->current_date['y']} AND MONTH(FROM_UNIXTIME(`dt`)) = {$this->current_date['m']}) 
					OR (YEAR(FROM_UNIXTIME(`dt`)) = {$this->previous_month['y']} AND MONTH(FROM_UNIXTIME(`dt`)) = {$this->previous_month['m']}))
					AND `domain` <> '' AND `domain` <> '{$_SERVER['SERVER_NAME']}'
				GROUP BY YEAR(FROM_UNIXTIME(`dt`)), DATE_FORMAT(FROM_UNIXTIME(`dt`), '%m'), DATE_FORMAT(FROM_UNIXTIME(`dt`), '%d')
				ORDER BY d,m ASC";
		
		$array_results = $wpdb->get_results($sql, ARRAY_A);
	
		$array_current_month = $array_previous_month = array();
		$current_non_zero_count = $previous_non_zero_count = 0;
		$current_month_xml = $previous_month_xml = '';
		
		// Let's reorganize the result
		if (is_array($array_results)){
			foreach($array_results as $a_result) {

				if ($a_result['m'] == $this->current_date['m']) {
					$array_current_month[intval($a_result['d'])] = $a_result['count_sources'];
					if ($a_result['count_sources'] > 0) $current_non_zero_count++;
				}
				else {
					$array_previous_month[intval($a_result['d'])] = $a_result['count_sources'];			
					if ($a_result['count_sources'] > 0) $previous_non_zero_count++;
				}
			}

			// Let's generate the XML for the flash chart
			for($i=1;$i<32;$i++) { // a month can have 31 days at maximum
				$categories_xml .= "<category name='$i'/>";
				if ($i <= $this->current_date['d']) {
					$current_month_xml .= "<set value='".intval($array_current_month[$i])."' link='/'/>";
				}
				$previous_month_xml .= "<set value='".intval($array_previous_month[$i])."' alpha='80' link='/'/>";
			}
		}
	
		$xml = "<graph canvasBorderThickness='0' canvasBorderColor='ffffff' decimalPrecision='0' divLineAlpha='20' formatNumberScale='0' lineThickness='2' showNames='1' showShadow='0' showValues='0' yAxisName='".__('Sources','wp-slimstat-view')."'>";
		$xml .= "<categories>$categories_xml</categories>";
		$xml .= "<dataset seriesname='".__('Sources','wp-slimstat-view')." {$this->previous_month['m']}/{$this->previous_month['y']}' color='0099ff' showValue='1'>$previous_month_xml</dataset>";
		$xml .= "<dataset seriesname='".__('Sources','wp-slimstat-view')." {$this->current_date['m']}/{$this->current_date['y']}' color='0022cc' showValue='1' anchorSides='10'>$current_month_xml</dataset>";
		$xml .= "</graph>";
	
		$result->xml = $xml;
		$result->visits_current_month = $array_current_month;
		$result->visits_previous_month = $array_previous_month;
		$result->current_non_zero_count = $current_non_zero_count;
		$result->previous_non_zero_count = $previous_non_zero_count;
	
		return $result;
	}
	
	public function get_visits_by_day(){
		global $wpdb;
	
		$sql = "SELECT YEAR(FROM_UNIXTIME(`dt`)) y, DATE_FORMAT(FROM_UNIXTIME(`dt`), '%m') m, DATE_FORMAT(FROM_UNIXTIME(`dt`), '%d') d, COUNT(`ip`) count_visits
				FROM `$this->table_stats`
				WHERE ((YEAR(FROM_UNIXTIME(`dt`)) = {$this->current_date['y']} AND MONTH(FROM_UNIXTIME(`dt`)) = {$this->current_date['m']}) 
					OR (YEAR(FROM_UNIXTIME(`dt`)) = {$this->previous_month['y']} AND MONTH(FROM_UNIXTIME(`dt`)) = {$this->previous_month['m']}))
					AND `visit_id` > 0
				GROUP BY YEAR(FROM_UNIXTIME(`dt`)), DATE_FORMAT(FROM_UNIXTIME(`dt`), '%m'), DATE_FORMAT(FROM_UNIXTIME(`dt`), '%d')
				ORDER BY d,m ASC";
		
		$array_results = $wpdb->get_results($sql, ARRAY_A);
	
		$array_current_month = $array_previous_month = array();
		$current_non_zero_count = $previous_non_zero_count = 0;
		$current_month_xml = $previous_month_xml = '';
	
		// Let's reorganize the result
		if (is_array($array_results)){
			foreach($array_results as $a_result) {
		
				if ($a_result['m'] == $this->current_date['m']) {
					$array_current_month[intval($a_result['d'])] = $a_result['count_visits'];
					if ($a_result['count_visits'] > 0) $current_non_zero_count++;
				}
				else {
					$array_previous_month[intval($a_result['d'])] = $a_result['count_visits'];			
					if ($a_result['count_visits'] > 0) $previous_non_zero_count++;
				}
			}

			// Let's generate the XML for the flash chart
			for($i=1;$i<32;$i++) { // a month can have 31 days at maximum
				$categories_xml .= "<category name='$i'/>";
				if ($i <= $this->current_date['d']) {
					$current_month_xml .= "<set value='".intval($array_current_month[$i])."' link='/'/>";
				}
				$previous_month_xml .= "<set value='".intval($array_previous_month[$i])."' alpha='80' link='/'/>";
			}
		}
	
		$xml = "<graph canvasBorderThickness='0' canvasBorderColor='ffffff' decimalPrecision='0' divLineAlpha='20' formatNumberScale='0' lineThickness='2' showNames='1' showShadow='0' showValues='0' yAxisName='".__('Visits','wp-slimstat-view')."'>";
		$xml .= "<categories>$categories_xml</categories>";
		$xml .= "<dataset seriesname='".__('Visits','wp-slimstat-view')." {$this->previous_month['m']}/{$this->previous_month['y']}' color='0099ff' showValue='1'>$previous_month_xml</dataset>";
		$xml .= "<dataset seriesname='".__('Visits','wp-slimstat-view')." {$this->current_date['m']}/{$this->current_date['y']}' color='0022cc' showValue='1' anchorSides='10'>$current_month_xml</dataset>";
		$xml .= "</graph>";
	
		$result->xml = $xml;
		$result->visits_current_month = $array_current_month;
		$result->visits_previous_month = $array_previous_month;
		$result->current_non_zero_count = $current_non_zero_count;
		$result->previous_non_zero_count = $previous_non_zero_count;
	
		return $result;
	}
}

?>