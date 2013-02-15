<?php
/*
Plugin Name: WP SlimStat Dashboard Widgets
Plugin URI: http://wordpress.org/extend/plugins/wp-slimstat/
Description: Monitor your visitors from your Wordpress dashboard.
Version: 2.9.4
Author: Camu
Author URI: http://www.duechiacchiere.it/
*/

// In order to activate this plugin, WP SlimStat needs to be installed and active
$multisite_plugin_active = (is_multisite() && array_key_exists('wp-slimstat/wp-slimstat.php', get_site_option('active_sitewide_plugins', array())) );
if (!$multisite_plugin_active && !in_array('wp-slimstat/wp-slimstat.php', get_option('active_plugins', array()))) return;

class wp_slimstat_dashboard{
	/**
	 * Loads localization files and adds a few actions
	 */
	public static function init(){
		// Add some custom stylesheets
		add_action('admin_print_styles-index.php', array(__CLASS__, 'wp_slimstat_dashboard_css_js'));

		// Hook into the 'wp_dashboard_setup' action to register our function
		add_action('wp_dashboard_setup', array(__CLASS__, 'add_dashboard_widgets'));
	}
	// end init
	
	/**
	 * Loads a custom stylesheet file for the administration panels
	 */
	public static function wp_slimstat_dashboard_css_js(){
		wp_register_style('wp-slimstat-dashboard-view', plugins_url('/admin/css/dashboard.css', __FILE__));
		wp_enqueue_style('wp-slimstat-dashboard-view');
		wp_enqueue_script('slimstat_flot', plugins_url('/admin/js/jquery.flot.min.js', __FILE__), array('jquery'), '0.7');
		wp_enqueue_script('slimstat_flot_navigate', plugins_url('/admin/js/jquery.flot.navigate.min.js', __FILE__), array('jquery','slimstat_flot'), '0.7');
		wp_enqueue_script('slimstat_admin', plugins_url('/admin/js/slimstat.admin.js', __FILE__));
		wp_localize_script('slimstat_admin', 'SlimStatParams', array('async_load' => wp_slimstat::$options['async_load']));
	}
	// end wp_slimstat_stylesheet

	/**
	 * Attaches all the widgets to the dashboard
	 */
	public static function add_dashboard_widgets(){
		if (!empty(wp_slimstat::$options['can_view']) && !in_array($GLOBALS['current_user']->user_login, array_map('strtolower', wp_slimstat::string_to_array(wp_slimstat::$options['can_view']))) && !in_array($GLOBALS['current_user']->user_login, array_map('strtolower', wp_slimstat::string_to_array(wp_slimstat::$options['can_admin']))) && !current_user_can('manage_options'))
			return;

		include_once(WP_PLUGIN_DIR."/wp-slimstat/admin/view/wp-slimstat-boxes.php");
		wp_slimstat_boxes::init();

		$widgets = array('slim_p1_01','slim_p1_02','slim_p1_03','slim_p1_04','slim_p1_05','slim_p1_06','slim_p1_08','slim_p1_11','slim_p1_12','slim_p2_04','slim_p2_12','slim_p4_07','slim_p4_11');
		
		foreach ($widgets as $a_widget)
			wp_add_dashboard_widget($a_widget, wp_slimstat_boxes::$all_boxes_titles[$a_widget], array(__CLASS__, $a_widget));
	}
	// end add_dashboard_widgets

	// Widget wrappers
	public static function slim_p1_01(){
		wp_slimstat_boxes::show_chart('slim_p1_01', wp_slimstat_db::extract_data_for_chart('COUNT(t1.ip)', 'COUNT(DISTINCT(t1.ip))'), array(__('Pageviews','wp-slimstat-view'), __('Unique IPs','wp-slimstat-view')));
	}	
	public static function slim_p1_02(){
		
		wp_slimstat_boxes::show_about_wpslimstat('slim_p1_02');
	}
	public static function slim_p1_03(){
		wp_slimstat_boxes::show_overview_summary('slim_p1_03', wp_slimstat_db::count_records(), wp_slimstat_db::extract_data_for_chart('COUNT(t1.ip)', 'COUNT(DISTINCT(t1.ip))'));
	}
	public static function slim_p1_04(){
		wp_slimstat_boxes::show_results('recent', 'slim_p1_04', 'user');
	}
	public static function slim_p1_05(){
		wp_slimstat_boxes::show_spy_view('slim_p1_05');
	}
	public static function slim_p1_06(){
		wp_slimstat_boxes::show_results('recent', 'slim_p1_06', 'searchterms');
	}
	public static function slim_p1_08(){
		wp_slimstat_boxes::show_results('popular', 'slim_p1_08', 'resource', array('total_for_percentage' => wp_slimstat_db::count_records()));
	}
	public static function slim_p1_11(){
		wp_slimstat_boxes::show_results('popular', 'slim_p1_11', 'user', array('total_for_percentage' => wp_slimstat_db::count_records('t1.user <> ""')));
	}
	public static function slim_p1_12(){
		wp_slimstat_boxes::show_results('popular', 'slim_p1_12', 'searchterms', array('total_for_percentage' => wp_slimstat_db::count_records('t1.searchterms <> ""')));
	}
	public static function slim_p2_04(){
		wp_slimstat_boxes::show_results('popular', 'slim_p2_04', 'browser', array('total_for_percentage' => wp_slimstat_db::count_records(), 'more_columns' => ',tb.version'));
	}
	public static function slim_p2_12(){
		wp_slimstat_boxes::show_visit_duration('slim_p2_12', wp_slimstat_db::count_records_having('visit_id > 0', 'visit_id'));
	}
	public static function slim_p4_07(){
		wp_slimstat_boxes::show_results('popular', 'slim_p4_07', 'category', array('total_for_percentage' => wp_slimstat_db::count_records('tci.category <> ""')));
	}
	public static function slim_p4_11(){
		wp_slimstat_boxes::show_results('popular', 'slim_p4_11', 'resource', array('total_for_percentage' => wp_slimstat_db::count_records('tci.content_type = "post"'), 'custom_where' => 'tci.content_type = "post"'));
	}
}
// end of class declaration

// Bootstrap
if (function_exists('add_action') && empty($_GET['page']) && preg_match('#wp-admin/(index.php)?(\?.*)?$#', $_SERVER['REQUEST_URI']))
	add_action('init', array('wp_slimstat_dashboard', 'init'), 10);