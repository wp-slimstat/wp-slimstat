<?php
/*
Plugin Name: WP SlimStat Custom Reports
Plugin URI: http://www.duechiacchiere.it/wp-slimstat/
Description: This is not a real plugin, it just demonstrates how to add your custom reports to WP SlimStat.
Version: 2.0.1
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

class wp_slimstat_custom_reports {

	// Function: __construct
	// Description: Constructor -- Sets things up.
	// Input: none
	// Output: none
	public function __construct(){
		global $table_prefix;
		
		$this->version = '2.0';
		
		$this->table_stats = $table_prefix . 'slim_stats';
		$this->table_browsers = $table_prefix . 'slim_browsers';
		$this->table_screenres = $table_prefix . 'slim_screenres';
		$this->table_visits = $table_prefix . 'slim_visits';
	}
	// end __construct

	// Function: _get_top_pages
	// Description: Fetches popular pages from the DB
	// Input: none
	// Output: array of results
	private function _get_top_pages(){
		global $wpdb;

		$sql = "SELECT `resource`, COUNT(*) count
				FROM `$this->table_stats`
				WHERE `resource` <> ''
				GROUP BY `resource`
				ORDER BY count DESC
				LIMIT 0,20";
	
		return $wpdb->get_results($sql, ARRAY_A);
	}
	// end _get_top_pages

	// Function: show_top_pages
	// Description: Formats the results obtained through _get_top_pages
	// Input: none
	// Output: HTML code
	public function show_top_pages() {
		$results = $this->_get_top_pages();
		
		// Boxes come in three sizes: wide, medium, normal (default).
		// Just add the corresponding class (wide, medium) to the wrapper DIV (see here below)
		echo '<div class="metabox-holder medium"><div class="postbox">';
		echo '<h3>'.__( 'Title of your custom report', 'wp-slimstat-view' ).'</h3>';
		
		// You need this div here below only if your content is 'taller' than 180px
		echo '<div>';
		foreach($results as $a_result){
			echo "<p><span class='left'>{$a_result['resource']}</span> <span>{$a_result['count']}</span></p>";
		}
		// Don't forget to close your inner div, if you used it :)
		echo '</div>';
		
		echo '</div></div>';

	}
	// end show_top_pages
}
// end of class declaration

// Ok, let's use the functions defined here above 
$wp_slimstat_custom = new wp_slimstat_custom_reports();

// Please provide your localization files, if needed
load_plugin_textdomain('wp-slimstat-view', WP_PLUGIN_URL .'/wp-slimstat/lang', '/wp-slimstat/lang');

// Use the hook 'wp_dashboard_setup' to attach your reports to the panel
// Of course you can attach as many reports as you want :-)
add_action('wp_slimstat_custom_report', array( &$wp_slimstat_custom,'show_top_pages'));

?>