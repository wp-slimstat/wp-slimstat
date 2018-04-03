<?php

class wp_slimstat_admin {
	public static $screens_info = array();
	public static $config_url = '';
	public static $faulty_fields = array();
	
	protected static $admin_notice = '';
	
	/**
	 * Init -- Sets things up.
	 */
	public static function init() {
		self::$admin_notice = "We've completely rewritten the portion of code that handles the date ranges in the Filter Bar. In order to simplify things, <strong>we have deprecated</strong> the shortcode filter <code>interval_direction</code>, which is now expressed by the sign in front of the interval value (positive for going forward from a given start date, and negative for going back in time). Please note that this change affect your existing shortcodes, if they use the aforementioned filter. We will update our documentation in the next few days to remove any reference to this filter, and to avoid any confusion. We've also reintroduced the various levels of granularity for our charts: hourly (when a single day is selected), daily (for ranges up to 120 days) and monthly. Last but not least, the comparison chart is now <strong>always</strong> displayed, using new criteria to determine the range to use. You may want to change your settings (Settings > Reports > Default Time Span > Days, and Reports > Comparison Chart) to mimic the old behavior or hide the comparison chart altogether, if you like. Please feel free to contact us if you have any questions or to report any issues.";
		self::$admin_notice .= '<br/><br/><a id="slimstat-hide-admin-notice" href="#" class="button-secondary">Got it, thanks</a>';

		// Load language files
		load_plugin_textdomain( 'wp-slimstat', WP_PLUGIN_DIR .'/wp-slimstat/languages', '/wp-slimstat/languages' );

		// If a localization does not exist, use English
		if ( !isset( $l10n[ 'wp-slimstat' ] ) ) {
			load_textdomain( 'wp-slimstat', WP_PLUGIN_DIR .'/wp-slimstat/languages/wp-slimstat-en_US.mo' );
		}

		// Define the default screens
		$has_network_reports = get_user_option( "meta-box-order_slimstat_page_slimlayout-network", 1 );
		self::$screens_info = array(
			'slimview1' => array(
				'is_report_group' => false,
				'show_in_sidebar' => true,
				'title' => __( 'Access Log', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'wp_slimstat_include_view' )
			),
			'slimview2' => array(
				'is_report_group' => true,
				'show_in_sidebar' => true,
				'title' => __( 'Overview', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'wp_slimstat_include_view' )
			),
			'slimview3' => array(
				'is_report_group' => true,
				'show_in_sidebar' => true,
				'title' => __( 'Audience', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'wp_slimstat_include_view' )
			),
			'slimview4' => array(
				'is_report_group' => true,
				'show_in_sidebar' => true,
				'title' => __( 'Site Analysis', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'wp_slimstat_include_view' )
			),
			'slimview5' => array(
				'is_report_group' => true,
				'show_in_sidebar' => true,
				'title' => __( 'Traffic Sources', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'wp_slimstat_include_view' )
			),
			'slimview6' => array(
				'is_report_group' => true,
				'show_in_sidebar' => true,
				'title' => __( 'Geolocation', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'wp_slimstat_include_view' )
			),
			'slimlayout' => array(
				'is_report_group' => false,
				'show_in_sidebar' => ( empty( $has_network_reports ) || current_user_can( 'manage_options' ) ),
				'title' => __( 'Customize', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'wp_slimstat_include_layout' )
			),
			'slimaddons' => array(
				'is_report_group' => false,
				'show_in_sidebar' => true,
				'title' => __( 'Add-ons', 'wp-slimstat' ),
				'callback' => array( __CLASS__, 'wp_slimstat_include_addons' )
			),
			'dashboard' => array(
				'is_report_group' => true,
				'show_in_sidebar' => false,
				'title' => __( 'WordPress Dashboard', 'wp-slimstat' ),
				'callback' => '' // No callback if show_in_sidebar is false
			),
			'inactive' => array(
				'is_report_group' => true,
				'show_in_sidebar' => false,
				'title' => __( 'Inactive Reports'),
				'callback' => '' // No callback if show_in_sidebar is false
			)
		);
		self::$screens_info = apply_filters( 'slimstat_screens_info', self::$screens_info );

		// Settings URL
		self::$config_url = 'admin.php?page=slimconfig&amp;tab=';

		// WPMU - New blog created
		$active_sitewide_plugins = get_site_option( 'active_sitewide_plugins' );
		if ( !empty( $active_sitewide_plugins[ 'wp-slimstat/wp-slimstat.php' ] ) ) {
			add_action( 'wpmu_new_blog', array( __CLASS__, 'new_blog' ) );
		}

		// WPMU - Blog Deleted
		add_filter( 'wpmu_drop_tables', array( __CLASS__, 'drop_tables' ), 10, 2 );

		// Screen options: hide/show panels to customize your view
		add_filter( 'screen_settings', array( __CLASS__, 'screen_settings' ), 10, 2 );

		// Display a notice that hightlights this version's features
		if ( !empty( $_GET[ 'page' ] ) && strpos( $_GET[ 'page' ], 'slimview' ) !== false ) {
			if ( !empty( self::$admin_notice ) && wp_slimstat::$settings[ 'show_admin_notice' ] != wp_slimstat::$version && is_super_admin() ) {
				add_action( 'admin_notices', array( __CLASS__, 'show_admin_notice' ) );
			}
			add_filter( 'admin_footer_text', array( __CLASS__, 'admin_footer_text' ) );
		}

		// Remove spammers from the database
		if ( wp_slimstat::$settings[ 'ignore_spammers' ] == 'on' ) {
			add_action('transition_comment_status', array(__CLASS__, 'remove_spam'), 15, 3);
		}

		// Add a menu to the admin bar ( this function is declared here and not in wp_slimstat_admin because the latter is only initialized if is_admin(), and not in the front-end )
		if ( wp_slimstat::$settings[ 'use_separate_menu' ] != 'on' && is_admin_bar_showing() ) {
			add_action( 'admin_bar_menu', array( __CLASS__, 'wp_slimstat_adminbar' ), 100 );
		}

		if ( function_exists( 'is_network_admin' ) && !is_network_admin() ) {
			// Add the appropriate entries to the admin menu, if this user can view/admin  Slimstat
			add_action( 'admin_menu', array( __CLASS__, 'wp_slimstat_add_view_menu' ) );
			add_action( 'admin_menu', array( __CLASS__, 'wp_slimstat_add_config_menu' ) );

			// Display the column in the Edit Posts / Pages screen
			if ( wp_slimstat::$settings[ 'add_posts_column' ] == 'on' ) {
				$post_types = get_post_types( array( 'public' => true, 'show_ui'  => true ), 'names' );
				include_once( dirname( __FILE__ ) . '/view/wp-slimstat-reports.php' );
				include_once( dirname( __FILE__ ) . '/view/wp-slimstat-db.php' );

				foreach ( $post_types as $a_post_type ) {
					add_filter( "manage_{$a_post_type}_posts_columns", array( __CLASS__, 'add_column_header' ) );
					add_action( "manage_{$a_post_type}_posts_custom_column", array( __CLASS__, 'add_post_column' ), 10, 2 );
				}

				if ( strpos( $_SERVER['REQUEST_URI'], 'edit.php' ) !== false ) {
					add_action( 'admin_enqueue_scripts', array( __CLASS__, 'wp_slimstat_stylesheet' ) );
				}
			}
			
			// Add some inline CSS to customize the icon associated to Slimstat in the sidebar
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'wp_slimstat_stylesheet_icon' ) );

			// Update the table structure and options, if needed
			if ( !empty( wp_slimstat::$settings[ 'version' ] ) && wp_slimstat::$settings[ 'version' ] != wp_slimstat::$version ) {
				add_action( 'admin_init', array(__CLASS__, 'update_tables_and_options' ) );
			}
		}

		// Load the library of functions to generate the reports
		if ( ( !empty( $_GET[ 'page' ] ) && strpos( $_GET[ 'page' ], 'slimview' ) !== false ) || (!empty($_POST['action']) && $_POST['action'] == 'slimstat_load_report')){
			include_once(dirname(__FILE__).'/view/wp-slimstat-reports.php');
			wp_slimstat_reports::init();
			
			if ( !empty( $_POST[ 'report_id' ] ) ) {
				$report_id = sanitize_title( $_POST[ 'report_id' ], 'slim_p0_00' );
				
				if ( !empty( wp_slimstat_reports::$reports_info[ $report_id ] ) ) {
					add_action('wp_ajax_slimstat_load_report', array( 'wp_slimstat_reports', 'callback_wrapper' ), 10, 2 );
				}
			}
		}

		// Dashboard Widgets
		if ( wp_slimstat::$settings[ 'add_dashboard_widgets' ] == 'on' ) {
			$temp = strlen( $_SERVER['REQUEST_URI'] ) - 10;

			if( strpos( $_SERVER['REQUEST_URI'], 'index.php' ) !== false || ( $temp >= 0 && strpos($_SERVER['REQUEST_URI'], '/wp-admin/', $temp) !== false ) ) {
				add_action( 'admin_enqueue_scripts', array(__CLASS__, 'wp_slimstat_enqueue_scripts' ) );
				add_action( 'admin_enqueue_scripts', array(__CLASS__, 'wp_slimstat_stylesheet' ) );
			}
			add_action( 'wp_dashboard_setup', array( __CLASS__, 'add_dashboard_widgets' ) );
		}

		// AJAX Handlers
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			add_action( 'wp_ajax_slimstat_hide_admin_notice', array( __CLASS__, 'notices_handler' ) );
			add_action( 'wp_ajax_slimstat_hide_geolite_notice', array( __CLASS__, 'notices_handler' ) );
			add_action( 'wp_ajax_slimstat_hide_browscap_notice', array( __CLASS__, 'notices_handler' ) );
			add_action( 'wp_ajax_slimstat_hide_caching_notice', array( __CLASS__, 'notices_handler' ) );

			add_action( 'wp_ajax_slimstat_manage_filters', array( __CLASS__, 'manage_filters' ) );
			add_action( 'wp_ajax_slimstat_delete_pageview', array( __CLASS__, 'delete_pageview' ) );
		}
		
		// Hide plugins
		if ( wp_slimstat::$settings[ 'hide_addons' ] == 'on' ) {
			add_filter( 'all_plugins', array( __CLASS__, 'hide_addons' ) );
		}

		// Schedule a daily cron job to purge the data
		if ( !wp_next_scheduled( 'wp_slimstat_purge' ) ) {
			wp_schedule_event( time(), 'twicedaily', 'wp_slimstat_purge' );
		}
	}
	// end init
	
	/**
	 * Clears the purge cron job
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'wp_slimstat_purge' );
	}
	// end deactivate

	/**
	 * Support for WP MU network activations
	 */
	public static function new_blog( $_blog_id ) {
		switch_to_blog( $_blog_id );
		self::init_environment();
		restore_current_blog();
		// wp_slimstat::$settings = get_option( 'slimstat_options', array() );
	}
	// end new_blog
	
	/**
	 * Support for WP MU site deletion
	 */
	public static function drop_tables( $_tables = array(), $_blog_id = 1) {
		$_tables[ 'slim_events' ] = $GLOBALS[ 'wpdb' ]->prefix . 'slim_events';
		$_tables[ 'slim_stats' ] = $GLOBALS[ 'wpdb' ]->prefix . 'slim_stats';
		
		$_tables[ 'slim_events_archive' ] = $GLOBALS[ 'wpdb' ]->prefix . 'slim_events_archive';
		$_tables[ 'slim_stats_archive' ] = $GLOBALS[ 'wpdb' ]->prefix . 'slim_stats_archive';

		return $_tables;
	}
	// end drop_tables

	/**
	 * Creates tables, initializes options and schedules purge cron
	 */
	public static function init_environment() {
		if ( function_exists( 'apply_filters' ) ) {
			$my_wpdb = apply_filters( 'slimstat_custom_wpdb', $GLOBALS[ 'wpdb' ] );
		}

		// Create the tables
		self::init_tables( $my_wpdb );

		return true;
	}
	// end init_environment

	/**
	 * Creates and populates tables, if they aren't already there.
	 */
	public static function init_tables($_wpdb = ''){
		// Is InnoDB available?
		$have_innodb = $_wpdb->get_results("SHOW VARIABLES LIKE 'have_innodb'", ARRAY_A);
		$use_innodb = (!empty($have_innodb[0]) && $have_innodb[0]['Value'] == 'YES')?'ENGINE=InnoDB':'';

		// Table that stores the actual data about visits
		$stats_table_sql = "
			CREATE TABLE IF NOT EXISTS {$GLOBALS['wpdb']->prefix}slim_stats (
				id INT UNSIGNED NOT NULL auto_increment,
				ip VARCHAR(39) DEFAULT NULL,
				other_ip VARCHAR(39) DEFAULT NULL,
				username VARCHAR(255) DEFAULT NULL,

				country VARCHAR(16) DEFAULT NULL,
				location VARCHAR(36) DEFAULT NULL,
				city VARCHAR(255) DEFAULT NULL,

				referer VARCHAR(2048) DEFAULT NULL,
				resource VARCHAR(2048) DEFAULT NULL,
				searchterms VARCHAR(2048) DEFAULT NULL,
				plugins VARCHAR(255) DEFAULT NULL,
				notes VARCHAR(2048) DEFAULT NULL,
				visit_id INT UNSIGNED NOT NULL DEFAULT 0,
				server_latency INT(10) UNSIGNED DEFAULT 0,
				page_performance INT(10) UNSIGNED DEFAULT 0,

				browser VARCHAR(40) DEFAULT NULL,
				browser_version VARCHAR(15) DEFAULT NULL,
				browser_type TINYINT UNSIGNED DEFAULT 0,
				platform VARCHAR(15) DEFAULT NULL,
				language VARCHAR(5) DEFAULT NULL,
				user_agent VARCHAR(2048) DEFAULT NULL,

				resolution VARCHAR(12) DEFAULT NULL,
				screen_width SMALLINT UNSIGNED DEFAULT 0,
				screen_height SMALLINT UNSIGNED DEFAULT 0,

				content_type VARCHAR(64) DEFAULT NULL,
				category VARCHAR(256) DEFAULT NULL,
				author VARCHAR(64) DEFAULT NULL,
				content_id BIGINT(20) UNSIGNED DEFAULT 0,
				
				outbound_resource VARCHAR(2048) DEFAULT NULL,

				dt_out INT(10) UNSIGNED DEFAULT 0,
				dt INT(10) UNSIGNED DEFAULT 0,

				CONSTRAINT PRIMARY KEY (id),
				INDEX idx_{$GLOBALS['wpdb']->prefix}slim_stats_dt (dt)
			) COLLATE utf8_general_ci $use_innodb";

		// This table will track outbound links (clicks on links to external sites)
		$events_table_sql = "
			CREATE TABLE IF NOT EXISTS {$GLOBALS['wpdb']->prefix}slim_events (
				event_id INT(10) NOT NULL AUTO_INCREMENT,
				type TINYINT UNSIGNED DEFAULT 0,
				event_description VARCHAR(64) DEFAULT NULL,
				notes VARCHAR(256) DEFAULT NULL,
				position VARCHAR(32) DEFAULT NULL,
				id INT UNSIGNED NOT NULL DEFAULT 0,
				dt INT(10) UNSIGNED DEFAULT 0,
				
				CONSTRAINT PRIMARY KEY (event_id),
				INDEX idx_{$GLOBALS['wpdb']->prefix}slim_stat_events (dt),
				CONSTRAINT fk_{$GLOBALS['wpdb']->prefix}slim_events_id FOREIGN KEY (id) REFERENCES {$GLOBALS['wpdb']->prefix}slim_stats(id) ON UPDATE CASCADE ON DELETE CASCADE
			) COLLATE utf8_general_ci $use_innodb";
			
		$archive_table_sql = "
			CREATE TABLE IF NOT EXISTS {$GLOBALS['wpdb']->prefix}slim_stats_archive
			LIKE {$GLOBALS['wpdb']->prefix}slim_stats";

		$events_archive_table_sql = "
			CREATE TABLE IF NOT EXISTS {$GLOBALS['wpdb']->prefix}slim_events_archive (
				event_id INT(10) NOT NULL AUTO_INCREMENT,
				type TINYINT UNSIGNED DEFAULT 0,
				event_description VARCHAR(64) DEFAULT NULL,
				notes VARCHAR(256) DEFAULT NULL,
				position VARCHAR(32) DEFAULT NULL,
				id INT UNSIGNED NOT NULL DEFAULT 0,
				dt INT(10) UNSIGNED DEFAULT 0,
				
				CONSTRAINT PRIMARY KEY (event_id),
				INDEX idx_{$GLOBALS['wpdb']->prefix}slim_stat_events_archive (dt)
			) COLLATE utf8_general_ci $use_innodb";

		// Ok, let's create the table structure
		self::_create_table( $stats_table_sql, $GLOBALS[ 'wpdb' ]->prefix . 'slim_stats', $_wpdb );
		self::_create_table( $events_table_sql, $GLOBALS[ 'wpdb' ]->prefix . 'slim_events', $_wpdb );
		self::_create_table( $archive_table_sql, $GLOBALS[ 'wpdb' ]->prefix . 'slim_stats_archive', $_wpdb );
		self::_create_table( $events_archive_table_sql, $GLOBALS[ 'wpdb' ]->prefix . 'slim_events_archive', $_wpdb );

		// Let's save the version in the database
		if ( empty( wp_slimstat::$settings[ 'version' ] ) ) {
			wp_slimstat::$settings[ 'version' ] = wp_slimstat::$version;
		}
	}
	// end init_tables

	/**
	 * Updates stuff around as needed (table schema, options, settings, files, etc)
	 */
	public static function update_tables_and_options() {
		$my_wpdb = apply_filters( 'slimstat_custom_wpdb', $GLOBALS[ 'wpdb' ] );

		// --- Updates for version 4.4.5 ---
		if ( version_compare( wp_slimstat::$settings[ 'version' ], '4.4.5', '<' ) ) {
			wp_slimstat::$settings[ 'last_tracker_error' ] = array();
			wp_slimstat::$settings[ 'last_tracker_notice' ] = array();
		}
		// --- END: Updates for version 4.4.5 ---

		// --- Updates for version 4.7.2 ---
		if ( version_compare( wp_slimstat::$settings[ 'version' ], '4.7.2', '<' ) ) {
			// Changing our toggle option values from 'yes' to 'on'
			foreach ( wp_slimstat::$settings as $a_key => $a_value ) {
				if ( $a_value == 'yes' ) {
					wp_slimstat::$settings[ $a_key ] = 'on';
				}
			}

			// If MaxMind DB is enabled, download the new GeoLite 2 data file
			$old_maxmind_path = str_replace( '.mmdb', '.dat', wp_slimstat::$maxmind_path );
			if ( file_exists( $old_maxmind_path ) ) {
				@unlink( $old_maxmind_path );
				wp_slimstat::download_maxmind_database();
			}

			$my_wpdb->query( "ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_stats ADD COLUMN city VARCHAR(255) DEFAULT NULL AFTER country, ADD COLUMN location VARCHAR(36) DEFAULT NULL AFTER country" );
		}
		// --- END: Updates for version 4.7.2 ---

		// --- Updates for version 4.7.2.2 ---
		if ( version_compare( wp_slimstat::$settings[ 'version' ], '4.7.2.2', '<' ) ) {
			$my_wpdb->query( "ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_stats_archive ADD COLUMN city VARCHAR(255) DEFAULT NULL AFTER country, ADD COLUMN location VARCHAR(36) DEFAULT NULL AFTER country" );
		}
		// --- END: Updates for version 4.7.2.2 ---

		// --- Updates for version 4.7.3.1 ---
		if ( version_compare( wp_slimstat::$settings[ 'version' ], '4.7.3.1', '<' ) ) {
			// Some users have reported that the MaxMind DB file has been created as an empty folder on their server
			if ( file_exists( wp_slimstat::$maxmind_path ) && !is_file( wp_slimstat::$maxmind_path ) ) {
				@rmdir( wp_slimstat::$maxmind_path );
			}
		}
		// --- END: Updates for version 4.7.3.1 ---

		// Now we can update the version stored in the database
		wp_slimstat::$settings[ 'version' ] = wp_slimstat::$version;

		return true;
	}
	// end update_tables_and_options

	public static function add_dashboard_widgets() {

		// If this user is whitelisted, we use the minimum capability
		$minimum_capability = 'read';
		if ( strpos( wp_slimstat::$settings[ 'can_view' ], $GLOBALS[ 'current_user' ]->user_login) === false &&  !empty( wp_slimstat::$settings[ 'capability_can_view' ] ) ) {
			$minimum_capability = wp_slimstat::$settings[ 'capability_can_view' ];
		}

		if ( !current_user_can( $minimum_capability ) ) {
			return;
		}

		include_once(dirname(__FILE__).'/view/wp-slimstat-reports.php');
		wp_slimstat_reports::init();

		if ( !empty( wp_slimstat_reports::$user_reports[ 'dashboard' ] ) ) {
			$dashboard_reports = explode( ',', wp_slimstat_reports::$user_reports[ 'dashboard' ] );
			foreach ( $dashboard_reports as $a_report_id ) {
				wp_add_dashboard_widget( $a_report_id, wp_slimstat_reports::$reports_info[ $a_report_id ][ 'title' ], array( 'wp_slimstat_reports', 'callback_wrapper' ) );
			}
		}
		else {
			foreach ( wp_slimstat_reports::$reports_info as $a_report_id => $a_report_info ) {
				if ( in_array( 'dashboard', $a_report_info[ 'screens' ] ) ) {
					// When called this way, callback_wrapper receives just the report_id as the SECOND parameter
					wp_add_dashboard_widget( $a_report_id, $a_report_info[ 'title' ], array( 'wp_slimstat_reports', 'callback_wrapper' ) );
				}
			}
		}
	}

	/**
	 * Removes 'spammers' from the database when the corresponding comments are marked as spam
	 */
	public static function remove_spam($_new_status = '', $_old_status = '', $_comment = ''){
		$my_wpdb = apply_filters('slimstat_custom_wpdb', $GLOBALS['wpdb']);

		if ($_new_status == 'spam'  && !empty($_comment->comment_author) && !empty($_comment->comment_author_IP)){
			$my_wpdb->query(wp_slimstat::$wpdb->prepare("DELETE ts FROM {$GLOBALS['wpdb']->prefix}slim_stats ts WHERE username = %s OR INET_NTOA(ip) = %s", $_comment->comment_author, $_comment->comment_author_IP));
		}
	}
	// end remove_spam

	/**
	 * Loads a custom stylesheet file for the administration panels
	 */
	public static function wp_slimstat_stylesheet( $_hook = '' ) {
		wp_register_style( 'wp-slimstat', plugins_url( '/admin/css/slimstat.css', dirname( __FILE__ ) ) );
		wp_enqueue_style( 'wp-slimstat' );

	   	if ( !empty( wp_slimstat::$settings[ 'custom_css' ] ) ) {
	   		wp_add_inline_style( 'wp-slimstat', wp_slimstat::$settings[ 'custom_css' ] );
	   	}
	}
	// end wp_slimstat_stylesheet
	
	/**
	 * Customizes the icon associated to Slimstat in the sidebar
	 */
	public static function wp_slimstat_stylesheet_icon(){
		if (!array_key_exists('dashicons', $GLOBALS['wp_styles']->registered)){
			return true;
		}

		wp_add_inline_style('dashicons', "#adminmenu #toplevel_page_slimview1 .wp-menu-image:before { content: '\\f239'; margin-top: -2px; }");
	}
	// end wp_slimstat_stylesheet_icon

	/**
	 * Loads user-defined stylesheet code
	 */
	public static function wp_slimstat_userdefined_stylesheet(){
		echo '<style type="text/css" media="screen">'.wp_slimstat::$settings['custom_css'].'</style>';
	}
	// end wp_slimstat_userdefined_stylesheet

	public static function wp_slimstat_enqueue_scripts( $_hook = '' ) {
		wp_enqueue_script( 'dashboard' );
		wp_enqueue_script( 'jquery-ui-datepicker' );
		wp_enqueue_script( 'slimstat_admin', plugins_url( '/admin/js/slimstat.admin.js', dirname( __FILE__ ) ), array( 'jquery-ui-dialog' ), null, false );
		// wp_enqueue_script( 'slimstat_chart', plugins_url( '/admin/js/slimstat.chart.js', dirname( __FILE__ ) ), array( 'jquery' ), null, false );

		// Pass some information to Javascript
		$params = array(
			'async_load' => !empty( wp_slimstat::$settings[ 'async_load' ] ) ? wp_slimstat::$settings[ 'async_load' ] : 'no',
			'datepicker_image' => plugins_url( '/admin/images/datepicker.png', dirname( __FILE__ ) ),
			'expand_details' => !empty( wp_slimstat::$settings[ 'expand_details' ] ) ? wp_slimstat::$settings[ 'expand_details' ] : 'no',
			'refresh_interval' => intval( wp_slimstat::$settings[ 'refresh_interval' ] ),
			'text_direction' => $GLOBALS[ 'wp_locale' ]->text_direction
		);
		wp_localize_script( 'slimstat_admin', 'SlimStatAdminParams', $params );
	}

	/**
	 * Adds a new entry in the admin menu, to view the stats
	 */
	public static function wp_slimstat_add_view_menu( $_s = '' ) {
		// If this user is whitelisted, we use the minimum capability
		$minimum_capability = 'read';
		if ( is_network_admin() ) {
			$minimum_capability = 'manage_network';
		}
		else if ( strpos( wp_slimstat::$settings[ 'can_view' ], $GLOBALS[ 'current_user' ]->user_login) === false && !empty( wp_slimstat::$settings[ 'capability_can_view' ] ) ) {
			$minimum_capability = wp_slimstat::$settings[ 'capability_can_view' ];
		}

		// Get the current report assignments
		$new_entry = array();
		if ( wp_slimstat::$settings[ 'use_separate_menu' ] == 'on' || is_network_admin() ) {
			$parent = 'slimview1';
			$page_location = 'slimstat';
			$new_entry[] = add_menu_page(__('Slimstat','wp-slimstat'), __('Slimstat','wp-slimstat'), $minimum_capability, $parent, array( __CLASS__, 'wp_slimstat_include_view' ) );	
		}
		else {
			$parent = 'admin.php';
			$page_location = 'admin';
		}

		$current_user = wp_get_current_user();
		$user_reports = get_user_option( "meta-box-order_{$page_location}_page_slimlayout", $current_user->ID );

		foreach ( self::$screens_info as $a_screen_id => $a_screen_info ) {
			if ( $a_screen_info[ 'show_in_sidebar' ] && ( !is_array( $user_reports ) || !empty( $user_reports[ $a_screen_id ] ) || !$a_screen_info[ 'is_report_group' ] ) ) {
				$new_entry[] = add_submenu_page( $parent, $a_screen_info[ 'title' ], $a_screen_info[ 'title' ], $minimum_capability, $a_screen_id, $a_screen_info[ 'callback' ] );
			}
		}

		// Load styles and Javascript needed to make the reports look nice and interactive
		foreach ( $new_entry as $a_entry ) {
			add_action( 'load-' . $a_entry, array( __CLASS__, 'wp_slimstat_stylesheet' ) );
			add_action( 'load-' . $a_entry, array( __CLASS__, 'wp_slimstat_enqueue_scripts' ) );
			add_action( 'load-' . $a_entry, array( __CLASS__, 'contextual_help' ) );
		}

		return $_s;
	}
	// end wp_slimstat_add_view_menu

	/**
	 * Adds a new entry to the Wordpress Toolbar
	 */
	public static function wp_slimstat_adminbar(){
		// If this user is whitelisted, we use the minimum capability
		$minimum_capability_view = 'read';
		if ( strpos( wp_slimstat::$settings[ 'can_view' ], $GLOBALS[ 'current_user' ]->user_login) === false && !empty( wp_slimstat::$settings[ 'capability_can_view' ] ) ) {
			$minimum_capability_view = wp_slimstat::$settings[ 'capability_can_view' ];
		}

		// If this user is whitelisted, we use the minimum capability
		$minimum_capability_config = 'read';
		if ( ( strpos( wp_slimstat::$settings[ 'can_admin' ], $GLOBALS[ 'current_user' ]->user_login ) === false) && $GLOBALS[ 'current_user' ]->user_login != 'slimstatadmin' && !empty( wp_slimstat::$settings[ 'capability_can_admin' ] ) ) {
			$minimum_capability_config = wp_slimstat::$settings[ 'capability_can_admin' ];
		}

		if ( ( function_exists( 'is_network_admin' ) && is_network_admin() ) || !is_user_logged_in() || !current_user_can( $minimum_capability_view ) ) {
			return;
		}

		wp_slimstat::$settings[ 'capability_can_view' ] = empty( wp_slimstat::$settings[ 'capability_can_view' ] ) ? 'read' : wp_slimstat::$settings[ 'capability_can_view' ];

		if (empty(wp_slimstat::$settings['can_view']) || strpos(wp_slimstat::$settings['can_view'], $GLOBALS['current_user']->user_login) !== false || current_user_can('manage_options')){
			$slimstat_view_url = get_admin_url($GLOBALS['blog_id'], "admin.php?page=");
			$slimstat_config_url = get_admin_url($GLOBALS['blog_id'], "admin.php?page=slimconfig");

			$page_location = ( wp_slimstat::$settings[ 'use_separate_menu' ] == 'on' ) ? 'slimstat' : 'admin';
			$user_reports = get_user_option( "meta-box-order_{$page_location}_page_slimlayout", $GLOBALS[ 'current_user' ]->ID );

			$frontend_filter = '';
			if ( !is_admin() ) {
				$frontend_filter = '&amp;fs%5Bresource%5D=contains+' . urlencode( wp_slimstat::get_request_uri() ); 
			}

			$GLOBALS[ 'wp_admin_bar' ]->add_menu( array(
				'id' => 'slimstat-header',
				'title' => __( 'Slimstat', 'wp-slimstat' ),
				'href' => "{$slimstat_view_url}slimview1"
			) );

			foreach ( self::$screens_info as $a_screen_id => $a_screen_info ) {
				if ( $a_screen_info[ 'show_in_sidebar' ] && ( !is_array( $user_reports ) || !empty( $user_reports[ $a_screen_id ] ) || !$a_screen_info[ 'is_report_group' ] ) ) {
					$GLOBALS[ 'wp_admin_bar' ]->add_menu( array(
						'id' => $a_screen_id,
						'href' => "{$slimstat_view_url}$a_screen_id" . ( ( $a_screen_info[ 'callback' ] == array( __CLASS__, 'wp_slimstat_include_view' ) ) ? $frontend_filter : '' ),
						'parent' => 'slimstat-header',
						'title' => $a_screen_info[ 'title' ]
					) );
				}
			}

			if ( ( empty( wp_slimstat::$settings[ 'can_admin' ] ) || strpos( wp_slimstat::$settings[ 'can_admin' ], $GLOBALS[ 'current_user' ]->user_login ) !== false || $GLOBALS[ 'current_user' ]->user_login == 'slimstatadmin' ) && current_user_can( $minimum_capability_config ) ) {
				$GLOBALS['wp_admin_bar']->add_menu(array('id' => 'slimstat-config', 'href' => $slimstat_config_url, 'parent' => 'slimstat-header', 'title' => __('Settings', 'wp-slimstat')));
			}
		}
	}

	/**
	 * Adds a new entry in the admin menu, to manage Slimstat options
	 */
	public static function wp_slimstat_add_config_menu($_s){
		wp_slimstat::$settings[ 'capability_can_admin' ] = empty( wp_slimstat::$settings[ 'capability_can_admin' ] ) ? 'activate_plugins' : wp_slimstat::$settings[ 'capability_can_admin' ];
		
		// If this user is whitelisted, we use the minimum capability
		$minimum_capability = 'read';
		if ( ( strpos( wp_slimstat::$settings[ 'can_admin' ], $GLOBALS[ 'current_user' ]->user_login ) === false ) && ( $GLOBALS[ 'current_user' ]->user_login != 'slimstatadmin' ) && !empty( wp_slimstat::$settings[ 'capability_can_admin' ] ) ) {
			$minimum_capability = wp_slimstat::$settings[ 'capability_can_admin' ];
		}

		if ( wp_slimstat::$settings[ 'use_separate_menu' ] == 'on' ) {
			$new_entry = add_submenu_page( 'slimview1', __( 'Settings','wp-slimstat' ), __( 'Settings','wp-slimstat' ), $minimum_capability, 'slimconfig', array( __CLASS__, 'wp_slimstat_include_config' ) );
		}
		else {
			$new_entry = add_submenu_page( null, __( 'Settings','wp-slimstat' ), __( 'Settings','wp-slimstat' ), $minimum_capability, 'slimconfig', array( __CLASS__, 'wp_slimstat_include_config' ) );
		}
		
		// Load styles and Javascript needed to make the reports look nice and interactive
		add_action( 'load-' . $new_entry, array( __CLASS__, 'wp_slimstat_stylesheet' ) );
		add_action('load-'.$new_entry, array(__CLASS__, 'wp_slimstat_enqueue_scripts'));
		//add_action('load-'.$new_entry, array(__CLASS__, 'wp_slimstat_enqueue_config_scripts'));

		return $_s;
	}
	// end wp_slimstat_add_config_menu

	/**
	 * Includes the appropriate panel to view the stats
	 */
	public static function wp_slimstat_include_view() {
		include( dirname( __FILE__ ) . '/view/index.php' );
	}
	// end wp_slimstat_include_view

	/**
	 * Includes the screen to arrange the reports
	 */
	public static function wp_slimstat_include_layout() {
		include( dirname( __FILE__ ) . '/view/layout.php' );
	}
	// end wp_slimstat_include_addons

	/**
	 * Includes the screen to manage add-ons
	 */
	public static function wp_slimstat_include_addons() {
		include( dirname( __FILE__ ) . '/view/addons.php' );
	}
	// end wp_slimstat_include_addons

	/**
	 * Includes the appropriate panel to configure Slimstat
	 */
	public static function wp_slimstat_include_config() {
		include( dirname( __FILE__ ) . '/config/index.php' );
	}
	// end wp_slimstat_include_config

	/**
	 * Adds a new column header to the Posts panel (to show the number of pageviews for each post)
	 */
	public static function add_column_header( $_columns = array() ) {
		if ( wp_slimstat::$settings[ 'posts_column_day_interval' ] == 0 ) {
			wp_slimstat::$settings[ 'posts_column_day_interval' ] = 30;
		}

		if ( wp_slimstat::$settings[ 'posts_column_pageviews' ] == 'on' ) {
			$_columns[ 'wp-slimstat' ] = '<span class="slimstat-icon" title="' . __( 'Pageviews in the last ' . wp_slimstat::$settings[ 'posts_column_day_interval' ] . ' days', 'wp-slimstat' ) . '"></span>';
		}
		else {
			$_columns[ 'wp-slimstat' ] = '<span class="slimstat-icon" title="' . __( 'Unique IPs in the last ' . wp_slimstat::$settings[ 'posts_column_day_interval' ] . ' days', 'wp-slimstat' ) . '"></span>';
		}

		return $_columns;
	}
	// end add_comment_column_header

	/**
	 * Adds a new column to the Posts management panel
	 */
	public static function add_post_column($_column_name, $_post_id){
		if ( 'wp-slimstat' != $_column_name ) {
			return;
		}

		if ( empty( wp_slimstat::$settings[ 'posts_column_day_interval' ] ) ) {
			wp_slimstat::$settings[ 'posts_column_day_interval' ] = 30;
		}

		$parsed_permalink = parse_url( get_permalink( $_post_id ) );
		$parsed_permalink = $parsed_permalink[ 'path' ] . ( !empty( $parsed_permalink[ 'query' ] ) ? '?' . $parsed_permalink[ 'query' ] : '' );
		wp_slimstat_db::init( 'resource contains ' . $parsed_permalink . '&&&interval equals -' . wp_slimstat::$settings[ 'posts_column_day_interval' ] );

		if ( wp_slimstat::$settings[ 'posts_column_pageviews' ] == 'on' ) {
			$count = wp_slimstat_db::count_records();
		}
		else{
			$count = wp_slimstat_db::count_records( 'ip' );
		}
		echo '<a href="'.wp_slimstat_reports::fs_url( 'resource contains ' . $parsed_permalink . '&&&interval equals -' . wp_slimstat::$settings[ 'posts_column_day_interval' ] ). '">'.$count.'</a>';
	}
	// end add_column

	public static function admin_footer_text( $_text = '' ) {
		return $_text . sprintf( __( ' And for keeping an eye on your web traffic with %sSlimStat Analytics%s.', 'wp-slimstat' ), '<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=BNJR5EZNY3W38" target="_blank">', '</a>' );
	}

	public static function hide_addons( $_plugins = array() ) {
		if ( !is_array( $_plugins ) ) {
			return $_plugins;
		}

		foreach ( $_plugins as $a_plugin_slug => $a_plugin_info ) {
			if ( strpos( $a_plugin_slug, 'wp-slimstat-' ) !== false  && is_plugin_active( $a_plugin_slug ) ) {
				unset( $_plugins[ $a_plugin_slug ] );
			}
		}

		return $_plugins;
	}

	/**
	 * Displays a tab to customize this user's screen options (what boxes to see/hide)
	 */
	public static function screen_settings( $_current = '', $_screen ) {
		if ( strpos( $_screen->id, 'page_slimview' ) === false ) {
			return $_current;
		}

		$current = '<form id="adv-settings" action="" method="post"><h5>'.__('Show on screen','wp-slimstat').'</h5><div class="metabox-prefs">';

		// The Reports Library wp_slimstat_reports has already been loaded at this point
		foreach( wp_slimstat_reports::$reports_info as $a_report_id => $a_report_info ) {
			if ( !is_array( $a_report_info[ 'classes' ] ) ) {
				continue;
			}

			$checked = !in_array( 'hidden', $a_report_info[ 'classes' ] ) ? ' checked="checked"' : '';

			$current .= "
				<label for='$a_report_id-hide'>
					<input class='hide-postbox-tog' name='$a_report_id-hide' type='checkbox' id='$a_report_id-hide' value='$a_report_id'$checked />{$a_report_info[ 'title' ]}
				</label>";
		}
		$current .= wp_nonce_field('closedpostboxes', 'closedpostboxesnonce', true, false)."</div></form>";

		// Some panels don't have any screen options
		if (strpos($current, 'label') === false){
			return $_current;
		}

		return $current;
	}

	/**
	 * Displays an alert message
	 */
	public static function show_alert_message( $_message = '', $_type = 'wp-ui-highlight' ){
		echo "<div id='slimstat-message' class='$_type below-h2'><p>$_message</p></div>";
	}

	/**
	 * Displays a message related to the current version of Slimstat
	 */
	public static function show_admin_notice() {
		echo '<div class="notice slimstat-notice" style="padding:10px"><span>'.self::$admin_notice.'</span></div>';
	}
	
	/**
	 * Handles the Ajax request to hide the admin notice
	 */
	public static function notices_handler() {
		$tag = current_filter();

		switch ( $tag ) {
			case 'wp_ajax_slimstat_hide_admin_notice':
				wp_slimstat::$settings[ 'show_admin_notice' ] = wp_slimstat::$version;
				break;

			case 'wp_ajax_slimstat_hide_geolite_notice':
				wp_slimstat::$settings[ 'no_maxmind_warning' ] = 'on';
				break;

			case 'wp_ajax_slimstat_hide_browscap_notice':
				wp_slimstat::$settings[ 'no_browscap_warning' ] = 'on';
				break;

			case 'wp_ajax_slimstat_hide_caching_notice':
				wp_slimstat::$settings[ 'no_caching_warning' ] = 'on';
				break;

			default:
				break;
		}
		
		die();
	}

	/**
	 * Deletes a given pageview from the database
	 */
	public static function delete_pageview(){
		$my_wpdb = apply_filters('slimstat_custom_wpdb', $GLOBALS['wpdb']);
		$pageview_id = intval($_POST['pageview_id']);
		$my_wpdb->query("DELETE ts FROM {$GLOBALS['wpdb']->prefix}slim_stats ts WHERE ts.id = $pageview_id");
		die();
	}

	/**
	 * Handles the Ajax requests to load, save or delete existing filters
	 */
	public static function manage_filters() {
		check_ajax_referer( 'meta-box-order', 'security' );

		include_once( dirname( __FILE__ ) . '/view/wp-slimstat-reports.php' );
		wp_slimstat_reports::init();

		$saved_filters = get_option( 'slimstat_filters', array() );

		switch( $_POST[ 'type' ] ) {
			case 'save':
				$new_filter = json_decode( stripslashes_deep( $_POST[ 'filter_array' ] ), true );

				// Check if this filter is already saved
				foreach ( $saved_filters as $a_saved_filter ) {
					$filter_found = 0;

					if ( count( $a_saved_filter ) != count( $new_filter ) || count( array_intersect_key( $a_saved_filter, $new_filter ) ) != count( $new_filter ) ) {
						$filter_found = 1;
						continue;
					}

					foreach ( $a_saved_filter as $a_key => $a_value ) {
						$filter_found += ( $a_value == $new_filter[ $a_key ] ) ? 0 : 1;
					}

					if ( $filter_found == 0 ) {
						echo __( 'Already saved', 'wp-slimstat' );
						break;
					}
				}

				if ( empty( $saved_filters ) || $filter_found > 0 ) {
					$saved_filters[] = $new_filter;
					update_option( 'slimstat_filters', $saved_filters );
					echo __( 'Saved', 'wp-slimstat' );
				}
				break;

			case 'delete':
				unset( $saved_filters[ intval( $_POST[ 'filter_id' ] ) ] );
				update_option( 'slimstat_filters', $saved_filters );

				// No break here - We want to return the new list of filters!

			default:
				echo '<div id="slim_filters_overlay">';
				foreach ( $saved_filters as $a_filter_id => $a_filter_data ) {

					$filter_html = $filter_strings = array();
					foreach ( $a_filter_data as $a_filter_label => $a_filter_details ) {
						$filter_value_no_slashes = htmlentities( str_replace( '\\', '', $a_filter_details[ 1 ] ), ENT_QUOTES, 'UTF-8' );
						$filter_html[] = strtolower( wp_slimstat_db::$columns_names[ $a_filter_label ][ 0 ] ) . ' ' . __( str_replace( '_', ' ', $a_filter_details[ 0 ] ), 'wp-slimstat' ) . ' ' . $filter_value_no_slashes;
						$filter_strings[] = "$a_filter_label {$a_filter_details[0]} $filter_value_no_slashes";
					}
					echo '<p><a class="slimstat-font-cancel slimstat-delete-filter" data-filter-id="' . $a_filter_id . '" title="' . __( 'Delete this filter', 'wp-slimstat' ) . '" href="#"></a> <a class="slimstat-filter-link" data-reset-filters="true" href="' . wp_slimstat_reports::fs_url( implode( '&&&', $filter_strings ) ).'">' . implode( ', ', $filter_html ) . '</a></p>';
				}
				echo '</div>';
				break;
		}
		die();
	}

	/*
	 * Displays the options 
	 */
	public static function display_settings( $_settings = array(), $_current_tab = 1 ) { ?>
		<form action="<?php echo self::$config_url.$_current_tab ?>" method="post" id="form-slimstat-options-tab-<?php echo $_current_tab ?>">
			<table class="form-table widefat <?php echo $GLOBALS['wp_locale']->text_direction ?>">
			<tbody><?php
				$i = 0;
				foreach( $_settings as $_setting_slug => $_setting_info ) {
					$i++;
					$_setting_info = array_merge( array(
						'description' =>'',
						'type' => '',
						'long_description' => '',
						'before_input_field' => '',
						'after_input_field' => '',
						'custom_label_yes' => '',
						'custom_label_no' => '',
						'readonly' => false,
						'use_tag_list' => true
					), $_setting_info );

					$is_readonly = ( !empty( $_setting_info[ 'readonly' ] ) && $_setting_info[ 'readonly' ] === true ) ? ' readonly' : '';
					$use_tag_list = ( empty( $is_readonly ) && !empty( $_setting_info[ 'use_tag_list' ] ) && $_setting_info[ 'use_tag_list' ] === true ) ? ' slimstat-taglist' : '';

					$network_override_checkbox = is_network_admin() ? '
							<input class="slimstat-checkbox-toggle"
								type="checkbox"
								name="options[addon_network_settings_' . $_setting_slug . ']"' .
								( ( !empty( wp_slimstat::$settings[ 'addon_network_settings_' . $_setting_slug ] ) && wp_slimstat::$settings[ 'addon_network_settings_' . $_setting_slug ] == 'on' ) ? ' checked="checked"' : '' ) . '
								id="addon_network_settings_' . $_setting_slug . '"
								data-size="mini" data-handle-width="50" data-on-color="warning" data-on-text="Network" data-off-text="Site">' : '';

					if ( !isset( wp_slimstat::$settings[ $_setting_slug ] ) && !isset( $_setting_info[ 'skip_update' ] ) && !isset( $_setting_info[ 'readonly' ] ) && $_setting_info[ 'type' ] != 'section_header') {
						wp_slimstat::$settings[ $_setting_slug ] = ''; 
					}

					echo '<tr' . ( $i % 2 == 0 ? ' class="alternate"' : '' ) . '>';
					switch ( $_setting_info[ 'type' ] ) {
						case 'section_header':
							echo '<td colspan="2" class="slimstat-options-section-header" id="wp-slimstat-' . sanitize_title( $_setting_info[ 'description' ] ). '">' . $_setting_info[ 'description' ] . '</td>';
							break;

						case 'static':
							echo '<td colspan="2">' . $_setting_info[ 'description' ] . '<textarea rows="7" class="large-text code" readonly>' . $_setting_info[ 'long_description' ] . '</textarea></td>';
							break;

						case 'toggle':
							echo '<th scope="row"><label for="' . $_setting_slug . '">' . $_setting_info[ 'description' ] . '</label></th>
							<td>
								<span class="block-element">
									<input class="slimstat-checkbox-toggle" type="checkbox"' . $is_readonly . '
										name="options[' . $_setting_slug . ']"
										id="' . $_setting_slug . '"
										data-size="mini" data-handle-width="50" data-on-color="success"' . 
										( ( isset( wp_slimstat::$settings[ $_setting_slug ] ) && wp_slimstat::$settings[ $_setting_slug ] == 'on' ) ? ' checked="checked"' : '' ) . '
										data-on-text="' . ( !empty( $_setting_info[ 'custom_label_on' ] ) ? $_setting_info[ 'custom_label_on' ] : __( 'On', 'wp-slimstat' ) ) . '"
										data-off-text="' . ( !empty( $_setting_info[ 'custom_label_off' ] ) ? $_setting_info[ 'custom_label_off' ] : __( 'Off', 'wp-slimstat' ) ) . '">' .
										$network_override_checkbox . '
								</span>
								<span class="description">' . $_setting_info[ 'long_description' ] . '</span>
							</td>';
							// ( is_network_admin() ? ' data-indeterminate="true"' : '' ) . '>
							break;

						case 'select':
							echo '<th scope="row"><label for="' . $_setting_slug . '">' . $_setting_info[ 'description' ] . '</label></th>
							<td>
								<span class="block-element">
									<select' . $is_readonly .' name="options[' . $_setting_slug . ']" id="' . $_setting_slug .'">';
										foreach ( $_setting_info[ 'values' ] as $a_key => $a_value ) {
											$is_selected = ( !empty( wp_slimstat::$settings[ $_setting_slug ] ) && wp_slimstat::$settings[ $_setting_slug ] == $a_key ) ? ' selected' : '';
											echo '<option' . $is_selected . ' value="' . $a_key . '">' . $a_value . '</option>';
										}
									echo '</select>' .
									$network_override_checkbox . '
								</span>
								<span class="description">' . $_setting_info[ 'long_description' ] . '</span>
							</td>';
							break;
							
						case 'text':
						case 'integer':
							$empty_value = ( $_setting_info[ 'type' ] == 'text' ) ? '' : '0';
							echo '<th scope="row"><label for="' . $_setting_slug . '">' . $_setting_info[ 'description' ] . '</label></th>
							<td>
								<span class="block-element"> ' .
									$_setting_info[ 'before_input_field' ] . '
									<input class="' . ( ( $_setting_info[ 'type' ] == 'integer' ) ? 'small-text' : 'regular-text' ) . '"' . $is_readonly . '
										type="' . ( ( $_setting_info[ 'type' ] == 'integer' ) ? 'number' : 'text' ) . '"
										name="options[' . $_setting_slug . ']"
										id="' . $_setting_slug . '"
										value="' . ( !empty( wp_slimstat::$settings[ $_setting_slug ] ) ? wp_slimstat::$settings[ $_setting_slug ] : $empty_value ) . '"> ' . $_setting_info[ 'after_input_field' ] .
										$network_override_checkbox . '
								</span>
								<span class="description">' . $_setting_info[ 'long_description' ] . '</span>
							</td>';
							break;

						case 'textarea':
							echo '
							<td colspan="2">
								<label for="' . $_setting_slug . '">' . $_setting_info[ 'description' ] . $network_override_checkbox . '</label>
								<p class="description">' . $_setting_info[ 'long_description' ] . '</p>
								<p>
									<textarea class="large-text code' . $use_tag_list . '"' . $is_readonly . '
										id="' . $_setting_slug . '"
										name="options[' . $_setting_slug . ']">' . ( isset( wp_slimstat::$settings[ $_setting_slug ] ) ? stripslashes( wp_slimstat::$settings[ $_setting_slug ] ) : '' ) . '</textarea>
									<span class="description">' . $_setting_info[ 'after_input_field' ] . '</span>
								</p>
							</td>';
							break;

						case 'custom':
							echo '<td colspan="2">' . $_setting_info[ 'description' ] . '<br/><br/>' . $_setting_info[ 'markup' ] . '</td>';
							break;

						default:
					}
					echo '</tr>';
				}
			?></tbody>
			</table>
			<input type="hidden" name="options[make_sure_post_is_not_empty]" id="make_sure_post_is_not_empty" value="make_sure_post_is_not_empty">
			<?php if ( empty( $_settings[ $_current_tab ][ 'include' ] ) ): ?><p class="submit"><input type="submit" value="<?php _e('Save Changes','wp-slimstat') ?>" class="button-primary" name="Submit"></p><?php endif ?>
		</form><?php
	}

	/*
	 * Updates the options 
	 */
	public static function update_settings( $_settings = array() ) {

		// Nothing to do, if there is no data to be parsed
		if ( !isset( $_POST[ 'options' ] ) || empty( $_settings ) ) {
			return true;
		}

		foreach( $_settings as $_setting_slug => $_setting_info ) {
			// Some options require a special treatment and are updated somewhere else
			if ( isset( $_setting_info[ 'skip_update' ] ) || isset( $_setting_info[ 'readonly' ] ) || $_setting_info[ 'type' ] == 'section_header' ) {
				continue;
			}

			// An empty toggle option is saved in the database as 'no'
			if ( $_setting_info[ 'type' ] == 'toggle' && ( !isset( $_POST[ 'options' ][ $_setting_slug ] ) || strtolower( $_POST[ 'options' ][ $_setting_slug ] != 'on' ) ) ) {
				wp_slimstat::$settings[ $_setting_slug ] = 'no';
			}
			else if ( isset( $_POST[ 'options' ][ $_setting_slug ] ) ) {
				wp_slimstat::$settings[ $_setting_slug ] = $_POST[ 'options' ][ $_setting_slug ];
			}

			// If the Network Settings add-on is enabled, there might be a switch to decide if this option needs to override what single sites have set
			if ( is_network_admin() ) {
				if ( !isset( $_POST[ 'options' ][ 'addon_network_settings_' . $_setting_slug ] ) || strtolower( $_POST[ 'options' ][ 'addon_network_settings_' . $_setting_slug ] != 'on' ) ) {
					wp_slimstat::$settings[ 'addon_network_settings_' . $_setting_slug ] = 'no';
				}
				else {
					wp_slimstat::$settings[ 'addon_network_settings_' . $_setting_slug ] = 'on';
				}
			}
			else if ( isset( wp_slimstat::$settings[ 'addon_network_settings_' . $_setting_slug ] ) ) {
				// Keep settings clean
				unset( wp_slimstat::$settings[ 'addon_network_settings_' . $_setting_slug ] );
			}
		}

		if ( !empty( self::$faulty_fields ) ) {
			self::show_alert_message( __( 'There was an error updating the following options:', 'wp-slimstat' ) . ' ' . implode( ', ', self::$faulty_fields ), 'wp-ui-highlight below-h2' );
		}
		else{
			self::show_alert_message( __('Your changes have been saved.', 'wp-slimstat' ), 'wp-ui-highlight below-h2' );
		}
	}

	/**
	 * Contextual help
	 */
	public static function contextual_help(){
		// This contextual help is only available to those using WP 3.3 or newer
		if ( empty( $GLOBALS[ 'wp_version' ] ) || version_compare( $GLOBALS[ 'wp_version' ], '3.3', '<' ) ) {
			return true;
		}

		$screen = get_current_screen();

		$screen->add_help_tab(
			array(
				'id' => 'wp-slimstat-definitions',
				'title' => __( 'Definitions', 'wp-slimstat' ),
				'content' => '
<ul>
<li><b>'.__('Pageview','wp-slimstat').'</b>: '.__('A request to load a single HTML file ("page"). This should be contrasted with a "hit", which refers to a request for any file from a web server. Slimstat logs a pageview each time the tracking code is executed','wp-slimstat').'</li>
<li><b>'.__('(Human) Visit','wp-slimstat').'</b>: '.__("A period of interaction between a visitor's browser and your website, ending when the browser is closed or when the user has been inactive on that site for 30 minutes",'wp-slimstat').'</li>
<li><b>'.__('Known Visitor','wp-slimstat').'</b>: '.__('Any user who has left a comment on your blog, and is thus identified by Wordpress as a returning visitor','wp-slimstat').'</li>
<li><b>'.__('Unique IP','wp-slimstat').'</b>: '.__('Used to differentiate between multiple requests to download a file from one internet address (IP) and requests originating from many distinct addresses; since this measurement looks only at the internet address a pageview came from, it is useful, but not perfect','wp-slimstat').'</li>
<li><b>'.__('Originating IP','wp-slimstat').'</b>: '.__('the originating IP address of a client connecting to a web server through an HTTP proxy or load balancer','wp-slimstat').'</li>
<li><b>'.__('Direct Traffic','wp-slimstat').'</b>: '.__('All those people showing up to your Web site by typing in the URL of your Web site coming or from a bookmark; some people also call this "default traffic" or "ambient traffic"','wp-slimstat').'</li>
<li><b>'.__('Search Engine','wp-slimstat').'</b>: '.__('Google, Yahoo, MSN, Ask, others; this bucket will include both your organic as well as your paid (PPC/SEM) traffic, so be aware of that','wp-slimstat').'</li>
<li><b>'.__('Search Terms','wp-slimstat').'</b>: '.__('Keywords used by your visitors to find your website on a search engine','wp-slimstat').'</li>
<li><b>'.__('SERP','wp-slimstat').'</b>: '.__('Short for search engine results page, the Web page that a search engine returns with the results of its search. The value shown represents your rank (or position) within that list of results','wp-slimstat').'</li>
<li><b>'.__('User Agent','wp-slimstat').'</b>: '.__('Any program used for accessing a website; this includes browsers, robots, spiders and any other program that was used to retrieve information from the site','wp-slimstat').'</li>
<li><b>'.__('Outbound Link','wp-slimstat').'</b>: '.__('A link from one domain to another is said to be outbound from its source anchor and inbound to its target. This report lists all the links to other websites followed by your visitors.','wp-slimstat').'</li>
</ul>'
			)
		);
		$screen->add_help_tab(
			array(
				'id' => 'wp-slimstat-basic-filters',
				'title' => __('Basic Filters','wp-slimstat'),
				'content' => '
<ul>
<li><b>'.__('Browser','wp-slimstat').'</b>: '.__('User agent (Firefox, Chrome, ...)','wp-slimstat').'</li>
<li><b>'.__('Country Code','wp-slimstat').'</b>: '.__('2-letter code (us, ru, de, it, ...)','wp-slimstat').'</li>
<li><b>'.__('IP','wp-slimstat').'</b>: '.__('Visitor\'s public IP address','wp-slimstat').'</li>
<li><b>'.__('Search Terms','wp-slimstat').'</b>: '.__('Keywords used by your visitors to find your website on a search engine','wp-slimstat').'</li>
<li><b>'.__('Language Code','wp-slimstat').'</b>: '.__('Please refer to this <a target="_blank" href="http://msdn.microsoft.com/en-us/library/ee825488(v=cs.20).aspx">language culture page</a> (first column) for more information','wp-slimstat').'</li>
<li><b>'.__('Operating System','wp-slimstat').'</b>: '.__('Accepts identifiers like win7, win98, macosx, ...; please refer to <a target="_blank" href="http://php.net/manual/en/function.get-browser.php">this manual page</a> for more information','wp-slimstat').'</li>
<li><b>'.__('Permalink','wp-slimstat').'</b>: '.__('URL accessed on your site','wp-slimstat').'</li>
<li><b>'.__('Referer','wp-slimstat').'</b>: '.__('Complete address of the referrer page','wp-slimstat').'</li>
<li><b>'.__('Visitor\'s Name','wp-slimstat').'</b>: '.__('Visitors\' names according to the cookie set by Wordpress after they leave a comment','wp-slimstat').'</li>
</ul>'
			)
		);

		$screen->add_help_tab(
			array(
				'id' => 'wp-slimstat-advanced-filters',
				'title' => __('Advanced Filters','wp-slimstat'),
				'content' => '
<ul>
<li><b>'.__('Browser Version','wp-slimstat').'</b>: '.__('user agent version (9.0, 11, ...)','wp-slimstat').'</li>
<li><b>'.__('Browser Type','wp-slimstat').'</b>: '.__('1 = search engine crawler, 2 = mobile device, 3 = syndication reader, 0 = all others','wp-slimstat').'</li>
<li><b>'.__('Pageview Attributes','wp-slimstat').'</b>: '.__('this field is set to <em>[pre]</em> if the resource has been accessed through <a target="_blank" href="https://developer.mozilla.org/en/Link_prefetching_FAQ">Link Prefetching</a> or similar techniques','wp-slimstat').'</li>
<li><b>'.__('Post Author','wp-slimstat').'</b>: '.__('author associated to that post/page when the resource was accessed','wp-slimstat').'</li>
<li><b>'.__('Post Category ID','wp-slimstat').'</b>: '.__('ID of the category/term associated to the resource, when available','wp-slimstat').'</li>
<li><b>'.__('Originating IP','wp-slimstat').'</b>: '.__('visitor\'s originating IP address, if available','wp-slimstat').'</li>
<li><b>'.__('Resource Content Type','wp-slimstat').'</b>: '.__('post, page, cpt:<em>custom-post-type</em>, attachment, singular, post_type_archive, tag, taxonomy, category, date, author, archive, search, feed, home; please refer to the <a target="_blank" href="http://codex.wordpress.org/Conditional_Tags">Conditional Tags</a> manual page for more information','wp-slimstat').'</li>
<li><b>'.__('Screen Resolution','wp-slimstat').'</b>: '.__('viewport width and height (1024x768, 800x600, ...)','wp-slimstat').'</li>
<li><b>'.__('Visit ID','wp-slimstat')."</b>: ".__('generally used in conjunction with <em>is not empty</em>, identifies human visitors','wp-slimstat').'</li>
<li><b>'.__('Date Filters','wp-slimstat')."</b>: ".__('you can specify the timeframe by entering a number in the <em>interval</em> field; use -1 to indicate <em>to date</em> (i.e., day=1, month=1, year=blank, interval=-1 will set a year-to-date filter)','wp-slimstat').'</li>
<li><b>'.__('SERP Position','wp-slimstat')."</b>: ".__('set the filter to Referer contains cd=N&, where N is the position you are looking for','wp-slimstat').'</li>
</ul>'
			)
		);
	}
	// end contextual_help

	/**
	 * Creates a table in the database
	 */
	protected static function _create_table($_sql = '', $_tablename = '', $_wpdb = ''){
		$_wpdb->query($_sql);

		// Let's make sure this table was actually created
		foreach ($_wpdb->get_col("SHOW TABLES LIKE '$_tablename'", 0) as $a_table)
			if ($a_table == $_tablename) return true;

		return false;
	}
	// end _create_table
}
// end of class declaration