<?php
/*
Plugin Name: WP SlimStat Dashboard Widgets
Plugin URI: http://www.duechiacchiere.it/wp-slimstat/
Description: Adds some widgets to monitor your WP SlimStat reports directly from your dashboard.
Version: 2.0
Author: Camu
Author URI: http://www.duechiacchiere.it/
*/

// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}

class wp_slimstat_dashboard {

	// Function: __construct
	// Description: Constructor -- Sets things up.
	// Input: none
	// Output: none
	public function __construct(){
		global $table_prefix;
		
		$this->version = '1.0';
		
		// We use a bunch of tables to store data
		$this->table_stats = $table_prefix . 'slim_stats';
		$this->table_countries = $table_prefix . 'slim_countries';
		$this->table_browsers = $table_prefix . 'slim_browsers';
		$this->table_screenres = $table_prefix . 'slim_screenres';
		$this->table_visits = $table_prefix . 'slim_visits';
	}
	// end __construct

	// Function: get_top
	// Description: Fetches popular pages from the DB for displaying them inside the widget
	// Input: column to filter, second optional column, maximum length of the short_string, maximum number of results to return
	// Output: array of results
	private function _get_top($_field = 'id', $_field2 = '', $_limit_lenght = 30, $_limit_results = 20){
		global $wpdb;

		$sql = "SELECT SUBSTRING(`$_field`, 1, $_limit_lenght) short_string,`$_field` long_string, LENGTH(`$_field`) len, COUNT(*) count
				".(!empty($_field2)?", `$_field2` $_field2 ":'')."
				FROM `$this->table_stats`
				WHERE `$_field` <> ''
				GROUP BY long_string
				ORDER BY count DESC
				LIMIT $_limit_results";
	
		$array_result = $wpdb->get_results($sql, ARRAY_A);
	
		return $array_result;	
	}
	// end get_top

	// Function: top_five_pages
	// Description: Displays the top 5 pages by pageviews
	// Input: none
	// Output: HTML code
	public function top_five_pages() {
		$results = $this->_get_top('resource', '', 90, 5);
		$count_results = count($results);
		for($i=0;$i<$count_results;$i++){
			$show_title_tooltip = ($results[$i]['len'] > 90)?' title="'.$results[$i]['long_string'].'"':'';
			$last_element = ($i != $count_results-1)?' style="border-bottom:1px dashed #ddd;font-size:.85em;height:1.6em;margin:0;padding:5px 5px 5px 6px"':' style="font-size:.85em;height:1.6em;margin:0;padding:5px 5px 5px 6px"';
			echo '<p'.$show_title_tooltip.$last_element.'><a target="_blank" href="'.get_bloginfo('url').$results[$i]['long_string'].'"><img src="'.WP_PLUGIN_URL.'/wp-slimstat/images/url.gif" /></a> '.$results[$i]['short_string'].(($results[$i]['len'] > 90)?'...':'').' <span style="float:right">'.$results[$i]['count'].'</span></p>';
		}
	}
	// end top_five_pages
	
	// Function: pathstats
	// Description: Displays what users have recently browsed (visits)
	// Input: none
	// Output: HTML code
	public function pathstats() {
		global $wpdb;
	
		$sql = "SELECT ts.`ip`, ts.`country`, ts.`domain`, ts.`referer`, ts.`resource`, tb.`browser`, ts.`visit_id`, ts.`dt`
				FROM `$this->table_stats` ts, `$this->table_browsers` tb 
				WHERE ts.`browser_id` = tb.`browser_id`
					AND ts.`visit_id` > 0
				ORDER BY `visit_id` DESC, `dt` ASC
				LIMIT 0,10";
		
		$results = $wpdb->get_results($sql, ARRAY_A);
		$count_results = count($results);
				
		$visit_id = 0;
		for($i=0;$i<$count_results;$i++){
			if ($visit_id != $results[$i]['visit_id']){
				$ip_address = long2ip($results[$i]['ip']);
				$country = __('c-'.$results[$i]['country'],'countries-languages');
				$time_of_pageview = date_i18n(get_option('date_format'),$results[$i]['dt']).'@'.date_i18n(get_option('time_format'),$results[$i]['dt']);
				
				echo "<p style='background-color:#eee;font-size:.85em;height:1.6em;margin:0;padding:5px 5px 5px 6px'>$ip_address, $country, {$results[$i]['browser']}, {$time_of_pageview}</p>";
				$visit_id = $results[$i]['visit_id'];
			}
			$last_element = ($i != $count_results-1)?' style="border-bottom:1px dashed #ddd;font-size:.85em;height:1.6em;margin:0;padding:5px 5px 5px 6px"':' style="font-size:.85em;height:1.6em;margin:0;padding:5px 5px 5px 6px"';
			$element_title = sprintf(__('Open %s in a new window','wp-slimstat-dashboard'), $results[$i]['referer']);
			echo "<p$last_element title='{$results[$i]['domain']}{$results[$i]['referer']}'>";
			if (!empty($results[$i]['domain'])){
				echo "<a target='_blank' title='$element_title' href='http://{$results[$i]['domain']}{$results[$i]['referer']}'><img src='".WP_PLUGIN_URL."/wp-slimstat/images/url.gif' /></a> {$results[$i]['domain']} &raquo;";
			}
			else{
				echo __('Direct visit to','wp-slimstat-dashboard');
			}
			echo ' '.substr($results[$i]['resource'],0,40).'</p>';
		}
	}
	// end pathstats

}
// end of class declaration

// Ok, let's use every tool we defined here above 
$wp_slimstat_dashboard = new wp_slimstat_dashboard();

// Localization files
load_plugin_textdomain('wp-slimstat-dashboard', WP_PLUGIN_URL .'/wp-slimstat/lang', '/wp-slimstat/lang');
load_plugin_textdomain('countries-languages', WP_PLUGIN_URL .'/wp-slimstat/lang', '/wp-slimstat/lang');

// Function: wp_slimstat_add_dashboard_widgets
// Description: Attaches all the widgets to the dashboard
// Input: none
// Output: none
function wp_slimstat_add_dashboard_widgets() {
	global $wp_slimstat_dashboard;
	wp_add_dashboard_widget('wp_slimstat_top_5', __('WP SlimStat - Top 5 pages', 'wp-slimstat-dashboard'), array( &$wp_slimstat_dashboard,'top_five_pages'));
	wp_add_dashboard_widget('wp_slimstat_pathstats', __('WP SlimStat - Pathstats', 'wp-slimstat-dashboard'), array( &$wp_slimstat_dashboard,'pathstats'));
}
// end wp_slimstat_add_dashboard_widget

// Hook into the 'wp_dashboard_setup' action to register our function
add_action('wp_dashboard_setup', 'wp_slimstat_add_dashboard_widgets');

?>