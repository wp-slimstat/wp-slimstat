<?php
/*
Plugin Name: WP SlimStat Dashboard Widgets
Plugin URI: http://lab.duechiacchiere.it/index.php?board=1.0
Description: Adds some widgets to monitor your WP SlimStat reports directly from your dashboard.
Version: 2.2.3
Author: Camu
Author URI: http://www.duechiacchiere.it/
*/

// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}

// In order to activate this plugin, WP SlimStat needs to be installed and active
$plugins = get_option('active_plugins');
if (!in_array('wp-slimstat/wp-slimstat.php', $plugins)){
	return;
}

// Import the class where all the reports are defined
if (!class_exists('wp_slimstat_view')) include_once(WP_PLUGIN_DIR."/wp-slimstat/view/wp-slimstat-view.php");

class wp_slimstat_dashboard extends wp_slimstat_view{

	// Function: __construct
	// Description: Constructor -- Sets things up.
	// Input: none
	// Output: none
	public function __construct(){		
		global $wpdb;
		
		parent::__construct();
		
		// Reset MySQL timezone settings, our dates and times are recorded using WP settings
		$wpdb->query("SET @@session.time_zone = '+00:00'");
		
		// It seems like WP_PLUGIN_URL doesn't honor the HTTPS setting in wp-config.php
		$this->slimstat_plugin_url = is_ssl()?str_replace('http://', 'https://', WP_PLUGIN_URL):WP_PLUGIN_URL;
		$this->current = $this->get_pageviews_by_day();
		$this->current_pageviews = intval(array_sum($this->current->current_data1));
	}
	// end __construct
	
	// Function: slimstat_stylesheet
	// Description: Enqueues a custom CSS for the admin interface
	// Input: none
	// Output: HTML code
	public function slimstat_stylesheet() {
        $myStyleUrl = WP_PLUGIN_URL . '/wp-slimstat/css/dashboard.css';
		wp_register_style('wp_slimstat_dashboard_stylesheet', $myStyleUrl);
		wp_enqueue_style( 'wp_slimstat_dashboard_stylesheet');
    }
	// end slimstat_stylesheet

	// Function: show_top_five_pages
	// Description: Displays the top 5 pages by pageviews
	// Input: none
	// Output: HTML code
	public function show_top_five_pages() {
		$results = $this->get_top('resource', '', 90, false, 5);
		$count_results = count($results);
		if ($count_results == 0) {
			echo '<p class="slimstat-row nodata">'.__('No data to display','wp-slimstat-view').'</p>';
		} else {
			for($i=0;$i<$count_results;$i++){
				$show_title_tooltip = ($results[$i]['len'] > 60)?' title="'.$results[$i]['long_string'].'"':'';
				$last_element = ($i == $count_results-1)?' class="slimstat-row last"':' class="slimstat-row"';
				$element_title = sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['long_string']);
				$element_url = get_bloginfo('url').preg_replace('/\[.*\]/','', $results[$i]['long_string']);
				$element_text = $results[$i]['short_string'].(($results[$i]['len'] > 60)?'...':'');
				$clean_long_string = urlencode($results[$i]['long_string']);
				echo "<p$last_element$show_title_tooltip><span class='element-title'>";
				if (strpos($element_url, '[404]') == 0){
					echo "<a target='_blank' title='$element_title'";
					echo " href='$element_url'><img src='$this->slimstat_plugin_url/wp-slimstat/images/url.gif' /></a>";
				}
				echo $element_text."</span> <span class='narrowcolumn'>".number_format($results[$i]['count'])."</span></p>";
			}
		}
	}
	// end show_top_five_pages
	
	// Function: show_pathstats
	// Description: Displays what users have recently browsed (visits)
	// Input: none
	// Output: HTML code
	public function show_pathstats() {
		$results = $this->get_details_recent_visits();
		$count_results = count($results);
		$visit_id = 0;
		if ($count_results == 0) {
			echo '<p class="slimstat-row nodata">'.__('No data to display','wp-slimstat-view').'</p>';
		} else {
			for($i=0;$i<$count_results;$i++){
				if ($visit_id != $results[$i]['visit_id']){
					$ip_address = "<a href='http://www.ip2location.com/{$results[$i]['ip']}' target='_blank' title='WHOIS: {$results[$i]['ip']}'><img src='$this->slimstat_plugin_url/wp-slimstat/images/whois.gif' /></a> {$results[$i]['ip']}";
					$country = __('c-'.$results[$i]['country'],'countries-languages');
	
					echo "<p class='slimstat-row header'>$ip_address <span class='widecolumn'>$country</span> <span class='widecolumn'>{$results[$i]['browser']}</span> <span class='widecolumn'>{$results[$i]['customdatetime']}</span></p>";
					$visit_id = $results[$i]['visit_id'];
				}
				$last_element = ($i == $count_results-1)?' class="slimstat-row last"':' class="slimstat-row"';
				$element_title = sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['referer']);
				echo "<p$last_element title='{$results[$i]['domain']}{$results[$i]['referer']}'>";
				if (!empty($results[$i]['domain'])){
					echo "<a target='_blank' title='$element_title' href='http://{$results[$i]['domain']}{$results[$i]['referer']}'><img src='$this->slimstat_plugin_url/wp-slimstat/images/url.gif' /></a> {$results[$i]['domain']} &raquo;";
				}
				else{
					echo __('Direct visit to','wp-slimstat-view');
				}
				echo ' '.substr($results[$i]['resource'],0,40).'</p>';				
			}
		}
	}
	// end show_pathstats
	
	// Function: show_about_wp_slimstat
	// Description: Displays some information about the database
	// Input: none
	// Output: HTML code
	public function show_about_wp_slimstat(){ ?>
		<p class="slimstat-row"><span class='element-title'><?php _e( 'Total Hits', 'wp-slimstat-dashboard' ); ?></span> <span><?php echo number_format($this->count_total_pageviews(false)) ?></span></p>
		<p class="slimstat-row"><span class='element-title'><?php _e( 'Data Size', 'wp-slimstat-dashboard' ); ?></span> <span><?php echo $this->get_data_size() ?></span></p>
		<p class="slimstat-row"><span class='element-title'><?php _e( 'Tracking Active', 'wp-slimstat-dashboard' ); ?></span> <span><?php _e(get_option('slimstat_is_tracking', 'no'), 'countries-languages') ?></span></p>
		<p class="slimstat-row"><span class='element-title'><?php _e( 'Auto purge', 'wp-slimstat-dashboard' ); ?></span> <span><?php echo (($auto_purge = get_option('slimstat_auto_purge', '0')) > 0)?$auto_purge.' days':'No'; ?></span></p>
		<p class="slimstat-row"><span class='element-title'>Geo IP</span> <span><?php echo date(get_option('date_format'), @filemtime(WP_PLUGIN_DIR.'/wp-slimstat/geoip.csv')) ?></span></p>
		<p class="slimstat-row last"><span class='element-title'>BrowsCap</span> <span><?php echo date(get_option('date_format'), @filemtime(WP_PLUGIN_DIR.'/wp-slimstat/cache/browscap.ini')) ?></span></p><?php
	}
	// end show_about_wp_slimstat
	
	// Function: show_summary_for
	// Description: Displays a summary of pageviews for this month
	// Input: none
	// Output: HTML code
	public function show_summary_for(){
		$today_pageviews = !empty($this->current->current_data1[$this->current_date['d']])?$this->current->current_data1[$this->current_date['d']]:0;
		$yesterday_pageviews = (intval($this->current_date['d'])==1)?(!empty($this->current->previous_data1[$this->yesterday['d']])?$this->current->previous_data1[$this->yesterday['d']]:0):(!empty($this->current->current_data1[$this->yesterday['d']])?$this->current->current_data1[$this->yesterday['d']]:0); ?>
		<p class="slimstat-row"><span class='element-title'><?php _e( 'Pageviews', 'wp-slimstat-view' ); ?></span> <span><?php echo number_format($this->current_pageviews); ?></span></p>
		<p class="slimstat-row"><span class='element-title'><?php _e( 'Unique IPs', 'wp-slimstat-view' ); ?></span> <span><?php echo number_format($this->count_unique_ips()); ?></span></p>
		<p class="slimstat-row"><span class='element-title'><?php _e( 'Avg Pageviews', 'wp-slimstat-view' ); ?></span> <span><?php echo number_format(($this->current->current_non_zero_count > 0)?intval($this->current_pageviews/$this->current->current_non_zero_count):0); ?></span></p>
		<p class="slimstat-row"><span class='element-title'><?php _e( 'On', 'wp-slimstat-view' ); echo ' '.$this->current_date['d'].'/'.$this->current_date['m'] ?></span> <span><?php echo number_format(intval($today_pageviews)); ?></span></p>
		<p class="slimstat-row"><span class='element-title'><?php _e( 'On', 'wp-slimstat-view' ); echo ' '.$this->yesterday['d'].'/'.$this->yesterday['m'] ?></span> <span><?php echo number_format(intval($yesterday_pageviews)); ?></span></p>
		<p class="slimstat-row last"><span class='element-title'><?php _e( 'Last Month', 'wp-slimstat-view' ); ?></span> <span><?php echo number_format(intval(array_sum($this->current->previous_data1))); ?></span></p><?php
	}
	// end show_summary_for
	
	// Function: show_user_agents
	// Description: Displays a list of recent user agents
	// Input: none
	// Output: HTML code
	public function show_user_agents(){
		$results = $this->get_browsers();
		$count_results = count($results); // 0 if $results is null
		if ($count_results == 0) {
			echo '<p class="slimstat-row nodata">'.__('No data to display','wp-slimstat-view').'</p>';
		} else {
			for($i=0;$i<$count_results;$i++){
				$last_element = ($i == $count_results-1)?' class="slimstat-row last"':' class="slimstat-row"';
				$percentage = ($this->current_pageviews > 0)?sprintf("%01.2f", (100*$results[$i]['count']/$this->current_pageviews)):0;
						$browser_version = ($results[$i]['version']!=0)?$results[$i]['version']:'';
						echo "<p$last_element><span class='element-title'>{$results[$i]['browser']} $browser_version</span> <span>$percentage%</span></p>";
					}
				}
	}
	// end show_user_agents
	
	// Function: show_recent_keywords
	// Description: Displays a list of recent search queries
	// Input: none
	// Output: HTML code
	public function show_recent_keywords(){
		$results = $this->get_recent('searchterms', '', 65);
		$count_results = count($results); // 0 if $results is null
		if ($count_results == 0) {
			echo '<p class="slimstat-row nodata">'.__('No data to display','wp-slimstat-view').'</p>';
		} else {
			for($i=0;$i<$count_results;$i++){
				$results[$i]['short_string'] = str_replace('\\', '', htmlspecialchars($results[$i]['short_string']));
				$results[$i]['long_string'] = str_replace('\\', '', htmlspecialchars($results[$i]['long_string']));
				$show_title_tooltip = ($results[$i]['len'] > 35)?' title="'.$results[$i]['long_string'].'"':'';
				$last_element = ($i == $count_results-1)?' class="slimstat-row last"':' class="slimstat-row"';
				$element_text = $results[$i]['short_string'].(($results[$i]['len'] > 35)?'...':'');
				$clean_long_string = urlencode($results[$i]['long_string']);
				$element_text = "<a class='activate-filter' href='index.php?page=wp-slimstat/view/index.php&slimpanel=1&searchterms=$clean_long_string'>$element_text</a>";
				echo "<p$last_element$show_title_tooltip>$element_text</p>";
			}
		}
	}
	// end show_recent_keywords
	
	// Function: show_traffic_sources
	// Description: Displays referring pages
	// Input: none
	// Output: HTML code
	public function show_traffic_sources(){
		$results = $this->get_top('domain', 'referer');
		$count_results = count($results); // 0 if $results is null
		$count_pageviews_with_referer = $this->count_referers();
		if ($count_results == 0) {
			echo '<p class="slimstat-row nodata">'.__('No data to display','wp-slimstat-dashboard').'</p>';
		} else {
			for($i=0;$i<$count_results;$i++){
				if (strpos(get_bloginfo('url'), $results[$i]['long_string'])) continue;	
				$last_element = ($i == $count_results-1)?' class="slimstat-row last"':' class="slimstat-row"';
				$percentage = ($count_pageviews_with_referer > 0)?intval(100*$results[$i]['count']/$count_pageviews_with_referer):0;
				$element_title = sprintf(__('Open %s in a new window','wp-slimstat-dashboard'), $results[$i]['long_string']);
				$element_url = 'http://'.$results[$i]['long_string'].$results[$i]['referer'];
				$element_text = $results[$i]['short_string'].(($results[$i]['len'] > 65)?'...':'');
				$element_text = "<a class='activate-filter' href='index.php?page=wp-slimstat/view/index.php&slimpanel=1&domain={$results[$i]['short_string']}'>$element_text</a>";
				
				echo "<p$last_element><span class='element-title'><a target='_blank' title='$element_title'";
				echo " href='$element_url'><img src='$this->slimstat_plugin_url/wp-slimstat/images/url.gif' /></a> ";
				echo $element_text."</span> <span>$percentage%</span> <span>".number_format($results[$i]['count'])."</span></p>";
			}
		}
	}
	// end show_traffic_sources
	
	// Function: show_keywords_and_pages
	// Description: Displays what keywords brought the user to what page
	// Input: none
	// Output: HTML code
	public function show_keywords_and_pages(){
		$results = $this->get_recent_keywords_pages();
		$count_results = count($results); // 0 if $results is null
		if ($count_results == 0) {
			echo '<p class="slimstat-row nodata">'.__('No data to display','wp-slimstat-dashboard').'</p>';
		} else {		
			for($i=0;$i<$count_results;$i++){
				$last_element = ($i == $count_results-1)?' class="slimstat-row last"':' class="slimstat-row"';
				$element_title = __('Open referer in a new window','wp-slimstat-dashboard');
				$trimmed_searchterms = $results[$i]['short_searchterms'].(($results[$i]['len_searchterms'] > 40)?'...':'');
				$trimmed_searchterms = str_replace('\\', '', htmlspecialchars($trimmed_searchterms));
				$trimmed_searchterms = "<a class='activate-filter' href='index.php?page=wp-slimstat/view/index.php&slimpanel=3&searchterms={$results[$i]['short_searchterms']}'>$trimmed_searchterms</a>";
				$show_searchterms_tooltip = ($results[$i]['len_searchterms'] > 40)?" title='{$results[$i]['searchterms']}'":'';
				$trimmed_resource = $results[$i]['short_resource'].(($results[$i]['len_resource'] > 40)?'...':'');
				$show_resource_tooltip = ($results[$i]['len_resource'] > 40)?" title='{$results[$i]['resource']}'":'';

				echo "<p$last_element><span class='element-title'$show_searchterms_tooltip><a target='_blank' title='$element_title'";
				echo " href='http://{$results[$i]['domain']}{$results[$i]['referer']}'><img src='$this->slimstat_plugin_url/wp-slimstat/images/url.gif' /></a> ";
				echo $trimmed_searchterms."</span> <span$show_resource_tooltip>$trimmed_resource</span></p>";
			}
		}
	}
	// end show_keywords_and_pages
}
// end of class declaration

// Ok, let's use every tool we defined here above 
$wp_slimstat_dashboard = new wp_slimstat_dashboard();

// Localization files
load_plugin_textdomain('wp-slimstat-dashboard', WP_PLUGIN_URL .'/wp-slimstat/lang', '/wp-slimstat/lang');
load_plugin_textdomain('countries-languages', WP_PLUGIN_URL .'/wp-slimstat/lang', '/wp-slimstat/lang');

// If a local translation for countries and languages does not exist, use English
if (!isset($l10n['countries-languages'])){
	load_textdomain('countries-languages', WP_PLUGIN_DIR .'/wp-slimstat/lang/countries-languages-en_US.mo');
}

// Add our custom stylesheets
add_action('admin_print_styles-index.php', array( &$wp_slimstat_dashboard, 'slimstat_stylesheet'));

// Function: wp_slimstat_add_dashboard_widgets
// Description: Attaches all the widgets to the dashboard
// Input: none
// Output: none
function wp_slimstat_add_dashboard_widgets() {
	global $wp_slimstat_dashboard;
	wp_add_dashboard_widget('show_top_five_pages', 'WP SlimStat - '.__('Top 5 pages', 'wp-slimstat-dashboard'), array( &$wp_slimstat_dashboard,'show_top_five_pages'));
	wp_add_dashboard_widget('show_pathstats', 'WP SlimStat - '.__('Pathstats', 'wp-slimstat-dashboard'), array( &$wp_slimstat_dashboard,'show_pathstats'));
	wp_add_dashboard_widget('show_about_wp_slimstat', 'WP SlimStat - '.__('About', 'wp-slimstat-dashboard'), array( &$wp_slimstat_dashboard,'show_about_wp_slimstat'));
	wp_add_dashboard_widget('show_summary_for', 'WP SlimStat - '.__('Summary of pageviews', 'wp-slimstat-dashboard'), array( &$wp_slimstat_dashboard,'show_summary_for'));
	wp_add_dashboard_widget('show_user_agents', 'WP SlimStat - '.__('User Agents', 'wp-slimstat-dashboard'), array( &$wp_slimstat_dashboard,'show_user_agents'));
	wp_add_dashboard_widget('show_recent_keywords', 'WP SlimStat - '.__('Recent Keywords', 'wp-slimstat-dashboard'), array( &$wp_slimstat_dashboard,'show_recent_keywords'));
	wp_add_dashboard_widget('show_traffic_sources', 'WP SlimStat - '.__('Traffic Sources', 'wp-slimstat-dashboard'), array( &$wp_slimstat_dashboard,'show_traffic_sources'));
	wp_add_dashboard_widget('show_keywords_and_pages', 'WP SlimStat - '.__('Recent Keywords &raquo; Pages', 'wp-slimstat-dashboard'), array( &$wp_slimstat_dashboard,'show_keywords_and_pages'));
}
// end wp_slimstat_add_dashboard_widgets

// Hook into the 'wp_dashboard_setup' action to register our function
add_action('wp_dashboard_setup', 'wp_slimstat_add_dashboard_widgets');


?>