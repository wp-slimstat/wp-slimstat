<?php
/*
Plugin Name: WP SlimStat Dashboard Widgets
Plugin URI: http://lab.duechiacchiere.it/index.php?board=1.0
Description: Add some widgets to monitor your WP SlimStat reports directly from your Wordpress dashboard.
Version: 2.5.1
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
$admin_url = admin_url('index.php');
if (!in_array('wp-slimstat/wp-slimstat.php', $plugins)) return;

if (!empty($_GET['page']) || ! preg_match('#wp-admin/(index.php)?(\?.*)?$#', $_SERVER['REQUEST_URI'])) return;

// Import the class where all the reports are defined
if (!class_exists('wp_slimstat_view')) include_once(WP_PLUGIN_DIR."/wp-slimstat/view/wp-slimstat-view.php");

class wp_slimstat_dashboard extends wp_slimstat_view{

	/**
	 * Constructor -- Sets things up.
	 */
	public function __construct(){		
		global $wpdb;
		
		parent::__construct();
		
		// Reset MySQL timezone settings, our dates and times are recorded using WP settings
		$wpdb->query("SET @@session.time_zone = '+00:00'");
		
		// Information about visits and pageviews
		$this->current_pageviews = $this->count_records();

		// Localization files
		load_plugin_textdomain('wp-slimstat-dashboard', WP_PLUGIN_DIR .'/wp-slimstat/lang', '/wp-slimstat/lang');
		load_plugin_textdomain('wp-slimstat-view', WP_PLUGIN_DIR .'/wp-slimstat/lang', '/wp-slimstat/lang');
		load_plugin_textdomain('countries-languages', WP_PLUGIN_DIR .'/wp-slimstat/lang', '/wp-slimstat/lang');

		// If a local translation for countries and languages does not exist, use English
		if (!isset($l10n['countries-languages'])){
			load_textdomain('countries-languages', WP_PLUGIN_DIR .'/wp-slimstat/lang/countries-languages-en_US.mo');
		}

		// Add some custom stylesheets
		add_action('admin_print_styles-index.php', array(&$this, 'slimstat_stylesheet'));

		// Hook into the 'wp_dashboard_setup' action to register our function
		add_action('wp_dashboard_setup', array(&$this, 'add_dashboard_widgets'));
	}
	// end __construct
	
	/**
	 * Enqueues a custom CSS for the admin interface
	 */
	public function slimstat_stylesheet(){
		wp_register_style('wp_slimstat_dashboard_stylesheet', plugins_url('/css/dashboard.css', __FILE__));
		wp_enqueue_style( 'wp_slimstat_dashboard_stylesheet');
    }
	// end slimstat_stylesheet

	/**
	 * Displays the top pages by pageviews
	 */
	public function show_top_pages(){
		$results = $this->get_top('t1.resource', 't1.ip, t1.user', "t1.resource NOT LIKE '[404]%'", '');
		$count_results = count($results);
		if ($count_results == 0) '<p class="slimstat-row nodata">'.__('No data to display','wp-slimstat-view').'</p>';

		for($i=0;$i<$count_results;$i++){
			$strings = $this->trim_value($results[$i]['resource'], 60);
			$last_element = ($i == $count_results-1)?' class="slimstat-row last"':' class="slimstat-row"';
			$extra_info = "title='".__('Last','wp-slimstat-view').': '.date_i18n($this->date_time_format, $results[$i]['dt']).', '.(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";
			$clean_string = urlencode($results[$i]['resource']);
			$element_title = sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['resource']);
			$element_url = $this->blog_domain.$results[$i]['resource'];

			echo "<p$last_element $extra_info><a target='_blank' title='$element_title' href='$element_url'><img src='".plugins_url('/images/url.gif', __FILE__)."' /></a> <span class='element-title'>{$strings['text']}</span> <span class='narrowcolumn' style='text-align:right'>".number_format($results[$i]['count'], 0, $this->decimal_separator, $this->thousand_separator)."</span></p>";
		}
	}
	// end show_top_pages
	
	/**
	 * Displays what users have recently browsed (visits)
	 */
	public function show_spy_view(){
		$results = $this->get_recent('t1.id', 't1.ip, t1.user, t1.resource, t1.searchterms, t1.visit_id, t1.country, t1.domain, t1.referer, tb.browser', 't1.visit_id > 0', 'browsers');
		$count_results = count($results);
		$visit_id = 0;
		if ($count_results == 0) echo '<p class="slimstat-row nodata">'.__('No data to display','wp-slimstat-view').'</p>';

		for($i=0;$i<$count_results;$i++){
			$results[$i]['ip'] = long2ip($results[$i]['ip']);
			$results[$i]['dt'] = date_i18n($this->date_time_format, $results[$i]['dt']);
			if (empty($results[$i]['resource'])){
				$searchterms = $this->trim_value($results[$i]['searchterms'], 32);
				$results[$i]['resource'] = __('Search for','wp-slimstat-view').': '.$searchterms['text'];
			}
			if ($visit_id != $results[$i]['visit_id']){
				if (empty($results[$i]['user']))
					$ip_address = "<a class='activate-filter' href='$admin_url?page=wp-slimstat&amp;slimpanel=1&amp;ip-op=equal&amp;ip={$results[$i]['ip']}'>{$results[$i]['ip']}</a>";
				else
					$ip_address = "<a class='activate-filter' href='$admin_url?page=wp-slimstat&slimpanel=1&amp;user-op=equal&amp;user={$results[$i]['user']}'>{$results[$i]['user']}</a>";
				$ip_address = "<a href='http://www.infosniper.net/index.php?ip_address={$results[$i]['ip']}' target='_blank' title='WHOIS: {$results[$i]['ip']}'><img src='".plugins_url('/images/whois.gif', __FILE__)."' /></a> $ip_address";
				$country = __('c-'.$results[$i]['country'],'countries-languages');

				echo "<p class='slimstat-row header'>$ip_address <span class='widecolumn'>$country</span> <span class='widecolumn'>{$results[$i]['browser']}</span> <span class='widecolumn'>{$results[$i]['dt']}</span></p>";
				$visit_id = $results[$i]['visit_id'];
			}
			$last_element = ($i == $count_results-1)?' class="slimstat-row last"':' class="slimstat-row"';
			$element_title = sprintf(__('Open %s in a new window','wp-slimstat-view'), $results[$i]['referer']);
			echo "<p$last_element title='{$results[$i]['domain']}{$results[$i]['referer']}'>";
			if (!empty($results[$i]['domain']))
				echo "<a target='_blank' title='$element_title' href='http://{$results[$i]['domain']}{$results[$i]['referer']}'><img src='".plugins_url('/images/url.gif', __FILE__)."' /></a> {$results[$i]['domain']} &raquo;";
			else
				echo __('Direct visit to','wp-slimstat-view');
			echo ' '.substr($results[$i]['resource'], 0, 40).'</p>';
		}
	}
	// end show_spy_view

	/**
	 * Displays a summary of pageviews for this month
	 */
	public function show_summary_for(){
		$current_data = $this->extract_data_for_chart('COUNT(ip)', 'COUNT(DISTINCT(ip))', 1, __('Pageviews','wp-slimstat-view'), __('Unique IPs','wp-slimstat-view'));
		$today_pageviews = !empty($current_data->current_data1[$this->current_date['d']])?$current_data->current_data1[$this->current_date['d']]:0;
		$yesterday_pageviews = (intval($this->current_date['d'])==1)?(!empty($current_data->previous_data1[$this->yesterday['d']])?$current_data->previous_data1[$this->yesterday['d']]:0):(!empty($current_data->current_data1[$this->yesterday['d']])?$current_data->current_data1[$this->yesterday['d']]:0); ?>
		<p class="slimstat-row"><span class='element-title'><?php _e('Pageviews', 'wp-slimstat-view') ?></span> <span><?php echo number_format($this->current_pageviews, 0, $this->decimal_separator, $this->thousand_separator) ?></span></p>
		<p class="slimstat-row"><span class='element-title'><?php _e('Unique IPs', 'wp-slimstat-view') ?></span> <span><?php echo number_format($this->count_records('1=1', 'DISTINCT ip'), 0, $this->decimal_separator, $this->thousand_separator) ?></span></p>
		<p class="slimstat-row"><span class='element-title'><?php _e('Bots', 'wp-slimstat-view') ?></span> <span><?php echo number_format($this->count_records('tb.type = 1', '*', true, 'browsers'), 0, $this->decimal_separator, $this->thousand_separator) ?></span></p>
		<p class="slimstat-row"><span class='element-title'><?php _e('Avg Pageviews', 'wp-slimstat-view') ?></span> <span><?php echo number_format(($current_data->current_non_zero_count > 0)?intval($this->current_pageviews/$current_data->current_non_zero_count):0, 0, $this->decimal_separator, $this->thousand_separator) ?></span></p>
		<p class="slimstat-row"><span class='element-title'><?php _e('On', 'wp-slimstat-view'); echo ' '.$this->current_date['d'].'/'.$this->current_date['m'] ?></span> <span><?php echo number_format($current_data->today, 0, $this->decimal_separator, $this->thousand_separator) ?></span></p>
		<p class="slimstat-row"><span class='element-title'><?php _e('On', 'wp-slimstat-view'); echo ' '.$this->yesterday['d'].'/'.$this->yesterday['m'] ?></span> <span><?php echo number_format($current_data->yesterday, 0, $this->decimal_separator, $this->thousand_separator) ?></span></p>
		<p class="slimstat-row last"><span class='element-title'><?php _e('Last Month', 'wp-slimstat-view'); ?></span> <span><?php echo number_format($current_data->previous_total, 0, $this->decimal_separator, $this->thousand_separator) ?></span></p><?php
	}
	// end show_summary_for

	/**
	 * Displays a list of recent user agents
	 */
	public function show_user_agents(){
		$results = $this->get_top('tb.browser, tb.version', '', "tb.browser <> ''", 'browsers');
		$count_results = count($results);
		if ($count_results == 0) echo '<p class="slimstat-row nodata">'.__('No data to display','wp-slimstat-view').'</p>';

		for($i=0;$i<$count_results;$i++){
			$last_element = ($i == $count_results-1)?' class="slimstat-row last"':' class="slimstat-row"';
			$percentage = ($this->current_pageviews > 0)?number_format(sprintf("%01.2f", (100*$results[$i]['count']/$this->current_pageviews)), 2, $this->decimal_separator, $this->thousand_separator):0;
			$browser_version = ($results[$i]['version']!=0)?$results[$i]['version']:'';
			$results[$i]['count'] = number_format($results[$i]['count'], 0, $this->decimal_separator, $this->thousand_separator);
			$extra_info = "title='".__('Hits','wp-slimstat-view').": {$results[$i]['count']}'";
			echo "<p$last_element $extra_info><span class='element-title'>{$results[$i]['browser']} $browser_version</span> <span>$percentage%</span></p>";
		}
	}
	// end show_user_agents

	/**
	 * Displays a list of recent search queries
	 */
	public function show_recent_keywords(){
		$results = $this->get_recent('t1.searchterms', 't1.ip, t1.user');
		$count_results = count($results);
		if ($count_results == 0) echo '<p class="slimstat-row nodata">'.__('No data to display','wp-slimstat-view').'</p>';

		for($i=0;$i<$count_results;$i++){
			$strings = $this->trim_value($results[$i]['searchterms'], 50);
			$last_element = ($i == $count_results-1)?' class="slimstat-row last"':' class="slimstat-row"';
			$extra_info = "title='".date_i18n($this->date_time_format, $results[$i]['dt']).', '.(empty($results[$i]['user'])?long2ip($results[$i]['ip']):$results[$i]['user'])."'";
			$clean_string = urlencode($results[$i]['searchterms']);
			if (!isset($wp_slimstat_view->filters_parsed['searchterms'][0])) $strings['text'] = "<a{$strings['tooltip']} class='activate-filter' href='$admin_url?page=wp-slimstat&amp;slimpanel=1&amp;searchterms=$clean_string'>{$strings['text']}</a>";

			echo "<p$last_element $extra_info>{$strings['text']}</p>";
		}
	}
	// end show_recent_keywords

	/**
	 * Attaches all the widgets to the dashboard
	 */
	public function add_dashboard_widgets() {
		global $current_user;
		$array_allowed_users = get_option('slimstat_can_view', array());

		if (!empty($array_allowed_users) && !in_array($current_user->user_login, $array_allowed_users)) return;

		wp_add_dashboard_widget('slim_top_pages', 'WP SlimStat - '.__('Top pages for this month', 'wp-slimstat-dashboard'), array(&$this,'show_top_pages'));
		wp_add_dashboard_widget('slim_spy_view', 'WP SlimStat - '.__('Spy View', 'wp-slimstat-dashboard'), array(&$this,'show_spy_view'));
		wp_add_dashboard_widget('slim_summary_for', 'WP SlimStat - '.__('Summary for this month', 'wp-slimstat-dashboard'), array(&$this,'show_summary_for'));
		wp_add_dashboard_widget('slim_user_agents', 'WP SlimStat - '.__('User Agents', 'wp-slimstat-dashboard'), array(&$this,'show_user_agents'));
		wp_add_dashboard_widget('slim_recent_keywords', 'WP SlimStat - '.__('Recent Keywords', 'wp-slimstat-dashboard'), array(&$this,'show_recent_keywords'));
	}
	// end add_dashboard_widgets

	private function trim_value($_string = '', $_length = 32){
		if (strlen($_string) > $_length){
			$result['text'] = substr($_string, 0, $_length).'...';
			$result['tooltip'] = " title='".htmlspecialchars($_string, ENT_QUOTES)."'";
		}
		else{
			$result['text'] = $_string;
			$result['tooltip'] = '';
		}
		$result['text'] = str_replace('\\', '', htmlspecialchars($result['text'], ENT_QUOTES));
		return $result;
	}
}
// end of class declaration

// Ok, let's use every tool we defined here above 
$wp_slimstat_dashboard = new wp_slimstat_dashboard();
?>