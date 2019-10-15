<?php

class wp_slimstat_admin {
	public static $screens_info = array();
	public static $config_url = '';
	public static $current_screen = 'slimview1';
	public static $page_location = 'slimstat';
	public static $meta_user_reports = array();

	protected static $admin_notice = '';
	protected static $data_for_column = array(
		'url' => array(),
		'sql' => array(),
		'count' => array()
	);

	/**
	 * Init -- Sets things up.
	 */
	public static function init() {
		self::$admin_notice = "Just a quick reminder that, in our quest for improved performance, we are deprecating the two columns <em>type</em> and <em>event_description</em> in the events table, and consolidating that information in the <em>notes</em> field. Code will be added to Slimstat in a few released to actually drop these columns from the database. If you are using those two columns in your custom code, please feel free to contact our support team to discuss your options and how to update your code using the information collected by the new tracker.";
		// self::$admin_notice = "In this day and age where every single social media platform knows our individual whereabouts on the Interwebs, we have been doing some research on what <em>the techies</em> out there call <a href='https://amiunique.org/fp' target='_blank'>browser fingerprinting</a>. With this technique, it is not necessary to rely on cookies to identify a specific user. This version of Slimstat implements <a href='https://github.com/Valve/fingerprintjs2' target='_blank'>FingerprintJS2</a>, a library that enables our tracker to record your users' unique fingerprint and local timezone (isn't it nice to know what time it was for the user when s/he was visiting your website?) Of course, if you have Privacy Mode enabled, this feature will not be used, in compliance with GDPR and other international privacy laws. Your visitors' fingerprints are now available in the Access Log and in the Filter dropdown. In the next few months, we plan to introduce new reports and to leverage this new information to increase the plugin's overall accuracy.";

		// Load language files
		load_plugin_textdomain( 'wp-slimstat', false, '/wp-slimstat/languages' );

		// Define the default screens
		$has_network_reports = get_user_option( "meta-box-order_slimstat_page_slimlayout-network", 1 );
		self::$screens_info = array(
			'slimview1' => array(
				'is_report_group' => true,
				'show_in_sidebar' => true,
				'title' => __( 'Real-time',  'wp-slimstat' ),
				'capability' => 'can_view',
				'callback' => array( __CLASS__, 'wp_slimstat_include_view' )
			),
			'slimview2' => array(
				'is_report_group' => true,
				'show_in_sidebar' => true,
				'title' => __( 'Overview',  'wp-slimstat' ),
				'capability' => 'can_view',
				'callback' => array( __CLASS__, 'wp_slimstat_include_view' )
			),
			'slimview3' => array(
				'is_report_group' => true,
				'show_in_sidebar' => true,
				'title' => __( 'Audience',  'wp-slimstat' ),
				'capability' => 'can_view',
				'callback' => array( __CLASS__, 'wp_slimstat_include_view' )
			),
			'slimview4' => array(
				'is_report_group' => true,
				'show_in_sidebar' => true,
				'title' => __( 'Site Analysis',  'wp-slimstat' ),
				'capability' => 'can_view',
				'callback' => array( __CLASS__, 'wp_slimstat_include_view' )
			),
			'slimview5' => array(
				'is_report_group' => true,
				'show_in_sidebar' => true,
				'title' => __( 'Traffic Sources',  'wp-slimstat' ),
				'capability' => 'can_view',
				'callback' => array( __CLASS__, 'wp_slimstat_include_view' )
			),
			'slimlayout' => array(
				'is_report_group' => false,
				'show_in_sidebar' => true,
				'title' => __( 'Customize',  'wp-slimstat' ),
				'capability' => 'can_customize',
				'callback' => array( __CLASS__, 'wp_slimstat_include_layout' )
			),
			'slimconfig' => array(
				'is_report_group' => false,
				'show_in_sidebar' => true,
				'title' => __( 'Settings',  'wp-slimstat' ),
				'capability' => 'can_admin',
				'callback' => array( __CLASS__, 'wp_slimstat_include_config' )
			),
			'slimaddons' => array(
				'is_report_group' => false,
				'show_in_sidebar' => current_user_can( 'manage_options' ),
				'title' => __( 'Add-ons',  'wp-slimstat' ),
				'capability' => 'can_admin',
				'callback' => array( __CLASS__, 'wp_slimstat_include_addons' )
			),
			'dashboard' => array(
				'is_report_group' => true,
				'show_in_sidebar' => false,
				'title' => __( 'WordPress Dashboard',  'wp-slimstat' ),
				'capability' => '',
				'callback' => '' // No callback and capabilities are needed if show_in_sidebar is false
			),
			'inactive' => array(
				'is_report_group' => true,
				'show_in_sidebar' => false,
				'title' => __( 'Inactive Reports' ),
				'capability' => '',
				'callback' => '' // No callback and capabilities are needed if show_in_sidebar is false
			)
		);
		self::$screens_info = apply_filters( 'slimstat_screens_info', self::$screens_info );

		// If the plugin was network activated, the tables might not have been created for this specific site
		$table_list = wp_slimstat::$wpdb->get_results( "SHOW TABLES LIKE '{$GLOBALS[ 'wpdb' ]->prefix}slim_stats'" );
		if ( empty( $table_list ) ) {
			self::init_environment();
		}

		// Settings URL
		if ( !is_network_admin() ) {
			self::$config_url = get_admin_url( $GLOBALS[ 'blog_id' ], 'admin.php?page=slimconfig&amp;tab=' );
		}
		else {
			self::$config_url = network_admin_url( 'admin.php?page=slimconfig&amp;tab=' );
		}

		// Current Screen
		if ( !empty( $_REQUEST[ 'page' ] ) && array_key_exists( $_REQUEST[ 'page' ], self::$screens_info ) ) {
			self::$current_screen = $_REQUEST[ 'page' ];
		}

		// Page Location
		if ( wp_slimstat::$settings[ 'use_separate_menu' ] != 'no' ) {
			self::$page_location = 'admin';
		}

		// Is the menu position setting being updated?
		if ( !empty( $_POST[ 'slimstat_update_settings' ] ) && wp_verify_nonce( $_POST[ 'slimstat_update_settings' ], 'slimstat_update_settings' ) && !empty( $_POST[ 'options' ][ 'use_separate_menu' ] ) ) {
			wp_slimstat::$settings[ 'use_separate_menu' ] = ( $_POST[ 'options' ][ 'use_separate_menu' ] == 'on' ) ? 'on' : 'no';
		}

		// Retrieve this user's custom report assignment (Customizer)
		// Superadmins can customize the layout at network level, to override per-site settings
		self::$meta_user_reports = get_user_option( 'meta-box-order_' . wp_slimstat_admin::$page_location . '_page_slimlayout-network', 1 );

		// No network-wide settings found
		if ( empty( self::$meta_user_reports ) ) {
			self::$meta_user_reports = get_user_option( 'meta-box-order_' . wp_slimstat_admin::$page_location . '_page_slimlayout', $GLOBALS[ 'current_user' ]->ID );
		}

		// WPMU - New blog created
		$active_sitewide_plugins = get_site_option( 'active_sitewide_plugins' );
		if ( !empty( $active_sitewide_plugins[ 'wp-slimstat/wp-slimstat.php' ] ) ) {
			add_action( 'wpmu_new_blog', array( __CLASS__, 'new_blog' ) );
		}

		// WPMU - Blog Deleted
		add_filter( 'wpmu_drop_tables', array( __CLASS__, 'drop_tables' ), 10, 2 );

		// Display a notice that hightlights this version's features
		if ( !empty( $_GET[ 'page' ] ) && strpos( $_GET[ 'page' ], 'slimview' ) !== false ) {
			if ( !empty( self::$admin_notice ) && wp_slimstat::$settings[ 'notice_latest_news' ] == 'on' && is_super_admin() ) {
				add_action( 'admin_notices', array( __CLASS__, 'show_latest_news' ) );
			}

			if ( wp_slimstat::$settings[ 'notice_translate' ] == 'on' && is_super_admin() ) {
				add_filter( 'admin_notices', array( __CLASS__, 'show_translate_notice' ) );
			}
		}

		// Remove spammers from the database
		if ( wp_slimstat::$settings[ 'ignore_spammers' ] == 'on' ) {
			add_action( 'transition_comment_status', array( __CLASS__, 'remove_spam' ), 15, 3 );
		}

		// Add a menu to the admin bar
		if ( wp_slimstat::$settings[ 'use_separate_menu' ] != 'no' && is_admin_bar_showing() ) {
			add_action( 'admin_bar_menu', array( __CLASS__, 'add_menu_to_adminbar' ), 100 );
		}

		if ( function_exists( 'is_network_admin' ) && !is_network_admin() ) {
			// Add the appropriate entries to the admin menu, if this user can view/admin  Slimstat
			add_action( 'admin_menu', array( __CLASS__, 'add_menus' ) );

			// Display the column in the Edit Posts / Pages screen
			if ( wp_slimstat::$settings[ 'add_posts_column' ] == 'on' ) {
				$post_types = get_post_types( array( 'public' => true, 'show_ui'  => true ), 'names' );
				include_once( plugin_dir_path( __FILE__ ) . 'view/wp-slimstat-reports.php' );
				include_once( plugin_dir_path( __FILE__ ) . 'view/wp-slimstat-db.php' );

				foreach ( $post_types as $a_post_type ) {
					add_filter( "manage_{$a_post_type}_posts_columns", array( __CLASS__, 'add_column_header' ) );
					add_action( "manage_{$a_post_type}_posts_custom_column", array( __CLASS__, 'add_post_column' ), 10, 2 );
				}

				if ( strpos( $_SERVER[ 'REQUEST_URI' ], 'edit.php' ) !== false ) {
					add_action( 'admin_enqueue_scripts', array( __CLASS__, 'wp_slimstat_stylesheet' ) );
					add_action( 'wp', array( __CLASS__, 'init_data_for_column' ) );
				}
			}

			// Update the table structure and options, if needed
			if ( !empty( wp_slimstat::$settings[ 'version' ] ) && wp_slimstat::$settings[ 'version' ] != wp_slimstat::$version ) {
				add_action( 'admin_init', array(__CLASS__, 'update_tables_and_options' ) );
			}
		}

		// Load the library of functions to generate the reports
		if ( ( !empty( $_GET[ 'page' ] ) && strpos( $_GET[ 'page' ], 'slim' ) === 0 ) || ( !empty( $_POST[ 'action' ] ) && $_POST[ 'action' ] == 'slimstat_load_report' ) ) {
			include_once( plugin_dir_path( __FILE__ ) . 'view/wp-slimstat-reports.php' );
			wp_slimstat_reports::init();

			if ( !empty( $_POST[ 'report_id' ] ) ) {
				$report_id = sanitize_title( $_POST[ 'report_id' ], 'slim_p0_00' );

				if ( !empty( wp_slimstat_reports::$reports[ $report_id ] ) ) {
					add_action( 'wp_ajax_slimstat_load_report', array( 'wp_slimstat_reports', 'callback_wrapper' ), 10, 2 );
				}
			}
		}

		// Dashboard Widgets
		if ( wp_slimstat::$settings[ 'add_dashboard_widgets' ] == 'on' ) {
			$temp = strlen( $_SERVER[ 'REQUEST_URI' ] ) - 10;

			if( strpos( $_SERVER[ 'REQUEST_URI' ], 'index.php' ) !== false || ( $temp >= 0 && strpos( $_SERVER[ 'REQUEST_URI' ], '/wp-admin/', $temp ) !== false ) ) {
				add_action( 'admin_enqueue_scripts', array(__CLASS__, 'wp_slimstat_enqueue_scripts' ) );
				add_action( 'admin_enqueue_scripts', array(__CLASS__, 'wp_slimstat_stylesheet' ) );
			}

			add_action( 'wp_dashboard_setup', array( __CLASS__, 'add_dashboard_widgets' ) );
		}

		// AJAX Handlers
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			add_action( 'wp_ajax_slimstat_notice_latest_news', array( __CLASS__, 'notices_handler' ) );
			add_action( 'wp_ajax_slimstat_notice_geolite', array( __CLASS__, 'notices_handler' ) );
			add_action( 'wp_ajax_slimstat_notice_browscap', array( __CLASS__, 'notices_handler' ) );
			add_action( 'wp_ajax_slimstat_notice_caching', array( __CLASS__, 'notices_handler' ) );
			add_action( 'wp_ajax_slimstat_notice_translate', array( __CLASS__, 'notices_handler' ) );

			add_action( 'wp_ajax_slimstat_manage_filters', array( __CLASS__, 'manage_filters' ) );
			add_action( 'wp_ajax_slimstat_delete_pageview', array( __CLASS__, 'delete_pageview' ) );
		}

		// Hide add-ons
		if ( wp_slimstat::$settings[ 'hide_addons' ] == 'on' ) {
			add_filter( 'all_plugins', array( __CLASS__, 'hide_addons' ) );
		}

		// Schedule a daily cron job to purge the data
		if ( !wp_next_scheduled( 'wp_slimstat_purge' ) ) {
			wp_schedule_event( time(), 'twicedaily', 'wp_slimstat_purge' );
		}
	}
	// END: init
	
	/**
	 * Clears the purge cron job
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'wp_slimstat_purge' );
	}

	/**
	 * Support for WP MU network activations
	 */
	public static function new_blog( $_blog_id ) {
		switch_to_blog( $_blog_id );
		self::init_environment();
		restore_current_blog();
	}
	// END: new_blog
	
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
	// END: drop_tables

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
	// END: init_environment

	/**
	 * Creates and populates tables, if they aren't already there.
	 */
	public static function init_tables( $_wpdb = '' ) {
		// Is InnoDB available?
		$have_innodb = $_wpdb->get_results( "SHOW VARIABLES LIKE 'have_innodb'", ARRAY_A );
		$use_innodb = ( !empty( $have_innodb[ 0 ] ) && $have_innodb[ 0 ][ 'Value' ] == 'YES' ) ? 'ENGINE=InnoDB' : '';

		// Table that stores the actual data about visits
		$stats_table_sql = "
			CREATE TABLE IF NOT EXISTS {$GLOBALS['wpdb']->prefix}slim_stats (
				id INT UNSIGNED NOT NULL auto_increment,
				ip VARCHAR(39) DEFAULT NULL,
				other_ip VARCHAR(39) DEFAULT NULL,
				username VARCHAR(256) DEFAULT NULL,
				email VARCHAR(256) DEFAULT NULL,

				country VARCHAR(16) DEFAULT NULL,
				location VARCHAR(36) DEFAULT NULL,
				city VARCHAR(256) DEFAULT NULL,

				referer VARCHAR(2048) DEFAULT NULL,
				resource VARCHAR(2048) DEFAULT NULL,
				searchterms VARCHAR(2048) DEFAULT NULL,
				notes VARCHAR(2048) DEFAULT NULL,
				visit_id INT UNSIGNED NOT NULL DEFAULT 0,
				server_latency INT(10) UNSIGNED DEFAULT 0,
				page_performance INT(10) UNSIGNED DEFAULT 0,

				browser VARCHAR(40) DEFAULT NULL,
				browser_version VARCHAR(15) DEFAULT NULL,
				browser_type TINYINT UNSIGNED DEFAULT 0,
				platform VARCHAR(15) DEFAULT NULL,
				language VARCHAR(5) DEFAULT NULL,
				fingerprint VARCHAR(256) DEFAULT NULL,
				user_agent VARCHAR(2048) DEFAULT NULL,

				resolution VARCHAR(12) DEFAULT NULL,
				screen_width SMALLINT UNSIGNED DEFAULT 0,
				screen_height SMALLINT UNSIGNED DEFAULT 0,

				content_type VARCHAR(64) DEFAULT NULL,
				category VARCHAR(256) DEFAULT NULL,
				author VARCHAR(64) DEFAULT NULL,
				content_id BIGINT(20) UNSIGNED DEFAULT 0,

				outbound_resource VARCHAR(2048) DEFAULT NULL,

				tz_offset SMALLINT DEFAULT 0,
				dt_out INT(10) UNSIGNED DEFAULT 0,
				dt INT(10) UNSIGNED DEFAULT 0,

				CONSTRAINT PRIMARY KEY (id),
				INDEX {$GLOBALS[ 'wpdb' ]->prefix}slim_stats_dt_idx (dt),
				INDEX {$GLOBALS[ 'wpdb' ]->prefix}stats_resource_idx( resource( 20 ) ),
				INDEX {$GLOBALS[ 'wpdb' ]->prefix}stats_browser_idx( browser( 10 ) ),
				INDEX {$GLOBALS[ 'wpdb' ]->prefix}stats_searchterms_idx( searchterms( 15 ) ),
				INDEX {$GLOBALS[ 'wpdb' ]->prefix}stats_fingerprint_idx( fingerprint( 20 ) )
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
				INDEX {$GLOBALS['wpdb']->prefix}slim_stat_events_idx (dt),
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
				INDEX {$GLOBALS['wpdb']->prefix}slim_stat_events_archive_idx (dt)
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
	// END: init_tables

	/**
	 * Updates stuff around as needed (table schema, options, settings, files, etc)
	 */
	public static function update_tables_and_options() {
		$my_wpdb = apply_filters( 'slimstat_custom_wpdb', $GLOBALS[ 'wpdb' ] );

		// --- Updates for version 4.8.2 ---
		if ( version_compare( wp_slimstat::$settings[ 'version' ], '4.8.2', '<' ) ) {
			// Add new email column to database
			$my_wpdb->query( "ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_stats ADD COLUMN email VARCHAR(255) DEFAULT NULL AFTER username" );
			$my_wpdb->query( "ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_stats_archive ADD COLUMN email VARCHAR(255) DEFAULT NULL AFTER username" );
		}
		// --- END: Updates for version 4.8.2 ---

		// --- Updates for version 4.8.4 ---
		if ( version_compare( wp_slimstat::$settings[ 'version' ], '4.8.4', '<' ) ) {
			// Switch option to track WP users (from track to ignore)
			wp_slimstat::$settings[ 'ignore_wp_users' ] = ( !empty( wp_slimstat::$settings[ 'track_users' ] ) && wp_slimstat::$settings[ 'track_users' ] == 'no' ) ? 'on' : 'no';

			// Remove unused options
			unset( wp_slimstat::$settings[ 'track_users' ] );
			unset( wp_slimstat::$settings[ 'enable_javascript' ] );
			unset( wp_slimstat::$settings[ 'honor_dnt_header' ] );
			unset( wp_slimstat::$settings[ 'no_maxmind_warning' ] );
			unset( wp_slimstat::$settings[ 'no_browscap_warning' ] );
			unset( wp_slimstat::$settings[ 'use_european_separators' ] );
			unset( wp_slimstat::$settings[ 'date_format' ] );
			unset( wp_slimstat::$settings[ 'time_format' ] );
			unset( wp_slimstat::$settings[ 'expand_details' ] );

			// Add table indexes for improved performance
			$check_index = wp_slimstat::$wpdb->get_results( "SHOW INDEX FROM {$GLOBALS[ 'wpdb' ]->prefix}slim_stats WHERE Key_name = '{$GLOBALS[ 'wpdb' ]->prefix}stats_resource_idx'" );
			if ( empty( $check_index ) ) {
				wp_slimstat::$wpdb->query( "ALTER TABLE {$GLOBALS[ 'wpdb' ]->prefix}slim_stats ADD INDEX {$GLOBALS[ 'wpdb' ]->prefix}stats_resource_idx( resource( 20 ) )" );
				wp_slimstat::$wpdb->query( "ALTER TABLE {$GLOBALS[ 'wpdb' ]->prefix}slim_stats ADD INDEX {$GLOBALS[ 'wpdb' ]->prefix}stats_browser_idx( browser( 10 ) )" );
				wp_slimstat::$wpdb->query( "ALTER TABLE {$GLOBALS[ 'wpdb' ]->prefix}slim_stats ADD INDEX {$GLOBALS[ 'wpdb' ]->prefix}stats_searchterms_idx( searchterms( 15 ) )" );
			}

			wp_slimstat::$settings[ 'db_indexes' ] = 'on';
		}
		// --- END: Updates for version 4.8.4 ---

		// --- Updates for version 4.8.4.1 ---
		if ( version_compare( wp_slimstat::$settings[ 'version' ], '4.8.4.1', '<' ) ) {
			// Goodbye, browser plugins
			wp_slimstat::$wpdb->query( "ALTER TABLE {$GLOBALS[ 'wpdb' ]->prefix}slim_stats DROP COLUMN plugins" );

			// Hello there, fingerprint and timezone offset
			$my_wpdb->query( "ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_stats ADD COLUMN fingerprint VARCHAR(256) DEFAULT NULL AFTER language" );
			$my_wpdb->query( "ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_stats_archive ADD COLUMN fingerprint VARCHAR(255) DEFAULT NULL AFTER language" );
			$my_wpdb->query( "ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_stats ADD COLUMN tz_offset SMALLINT DEFAULT 0 AFTER outbound_resource" );
			$my_wpdb->query( "ALTER TABLE {$GLOBALS['wpdb']->prefix}slim_stats_archive ADD COLUMN tz_offset SMALLINT DEFAULT 0 AFTER outbound_resource" );
		}
		// --- END: Updates for version 4.8.4.1 ---

		// --- Updates for version 4.8.8 ---
		if ( version_compare( wp_slimstat::$settings[ 'version' ], '4.8.8', '<' ) ) {
			// Adding new index on the 'fingerprint' column for improved performance
			if ( wp_slimstat::$settings[ 'db_indexes' ] == 'on' ) {
				$my_wpdb->query( "ALTER TABLE {$GLOBALS[ 'wpdb' ]->prefix}slim_stats ADD INDEX {$GLOBALS[ 'wpdb' ]->prefix}stats_fingerprint_idx( fingerprint( 20 ) )" );
			}

			$my_wpdb->query( "UPDATE {$GLOBALS[ 'wpdb' ]->prefix}slim_stats SET notes = CONCAT( '[', REPLACE( notes, ';', '][' ), ']' ) WHERE notes NOT LIKE '[%'" );
		}

		// Now we can update the version stored in the database
		wp_slimstat::$settings[ 'version' ] = wp_slimstat::$version;
		wp_slimstat::$settings[ 'notice_latest_news' ] = 'on';
		wp_slimstat::update_option( 'slimstat_options', wp_slimstat::$settings );

		return true;
	}
	// END: update_tables_and_options

	public static function add_dashboard_widgets() {
		// If this user is whitelisted, we use the minimum capability
		$minimum_capability = 'read';
		if ( strpos( wp_slimstat::$settings[ 'can_view' ], $GLOBALS[ 'current_user' ]->user_login) === false &&  !empty( wp_slimstat::$settings[ 'capability_can_view' ] ) ) {
			$minimum_capability = wp_slimstat::$settings[ 'capability_can_view' ];
		}

		if ( !current_user_can( $minimum_capability ) ) {
			return;
		}

		// The Reports library is only loaded on the plugin's screens
		include_once( plugin_dir_path( __FILE__ ) . 'view/wp-slimstat-reports.php' );
		wp_slimstat_reports::init();

		if ( !empty( wp_slimstat_reports::$user_reports[ 'dashboard' ] ) && is_array( wp_slimstat_reports::$user_reports[ 'dashboard' ] ) ) {
			foreach ( wp_slimstat_reports::$user_reports[ 'dashboard' ] as $a_report_id ) {
				wp_add_dashboard_widget( $a_report_id, wp_slimstat_reports::$reports[ $a_report_id ][ 'title' ], array( 'wp_slimstat_reports', 'callback_wrapper' ) );
			}
		}
	}
	// END: add_dashboard_widgets

	/**
	 * Removes 'spammers' from the database when the corresponding comments are marked as spam
	 */
	public static function remove_spam( $_new_status = '', $_old_status = '', $_comment = '' ) {
		$my_wpdb = apply_filters( 'slimstat_custom_wpdb', $GLOBALS[ 'wpdb' ] );

		if ( $_new_status == 'spam'  && !empty( $_comment->comment_author ) && !empty( $_comment->comment_author_IP ) ) {
			$my_wpdb->query( wp_slimstat::$wpdb->prepare( "
				DELETE ts
				FROM {$GLOBALS['wpdb']->prefix}slim_stats ts
				WHERE username = %s OR INET_NTOA(ip) = %s", $_comment->comment_author, $_comment->comment_author_IP ) );
		}
	}
	// END: remove_spam

	/**
	 * Loads a custom stylesheet file for the administration panels
	 */
	public static function wp_slimstat_stylesheet( $_hook = '' ) {
		wp_register_style(  'wp-slimstat', plugins_url( '/admin/assets/css/admin.css', dirname( __FILE__ ) ) );
		wp_enqueue_style(  'wp-slimstat' );

		if ( !empty( wp_slimstat::$settings[ 'custom_css' ] ) ) {
			wp_add_inline_style( 'wp-slimstat', wp_slimstat::$settings[ 'custom_css' ] );
		}
	}
	// END: wp_slimstat_stylesheet

	/**
	 * Loads user-defined stylesheet code
	 */
	public static function wp_slimstat_userdefined_stylesheet(){
		echo '<style type="text/css" media="screen">' . wp_slimstat::$settings[ 'custom_css' ] . '</style>';
	}
	// END: wp_slimstat_userdefined_stylesheet

	/**
	 * Enqueues Javascript and styles needed in the admin
	 */
	public static function wp_slimstat_enqueue_scripts( $_hook = '' ) {
		wp_enqueue_script( 'dashboard' );
		wp_enqueue_script( 'jquery-ui-datepicker' );

		// Enqueue the built-in code editor to use on the Settings
		if ( self::$current_screen ) {
			wp_enqueue_code_editor( array( 'type' => 'text/html' ) );
		}
		
		wp_enqueue_script( 'slimstat_admin', plugins_url( '/admin/assets/js/admin.js', dirname( __FILE__ ) ), array( 'jquery-ui-dialog' ), null, false );

		// Pass some information to Javascript
		$params = array(
			'async_load' => !empty( wp_slimstat::$settings[ 'async_load' ] ) ? wp_slimstat::$settings[ 'async_load' ] : 'no',
			'datepicker_image' => plugins_url( '/admin/assets/images/datepicker.png', dirname( __FILE__ ) ),
			'refresh_interval' => intval( wp_slimstat::$settings[ 'refresh_interval' ] ),
			'page_location' => self::$page_location
		);
		wp_localize_script( 'slimstat_admin', 'SlimStatAdminParams', $params );
	}
	// END: wp_slimstat_enqueue_scripts

	/**
	 * Adds a new entry in the admin menu, to view the stats
	 */
	public static function add_menus( $_s = '' ) {
		// If this user is whitelisted, we use the minimum capability
		$minimum_capability = 'read';
		if ( is_network_admin() ) {
			$minimum_capability = 'manage_network';
		}
		else if ( strpos( wp_slimstat::$settings[ 'can_view' ], $GLOBALS[ 'current_user' ]->user_login ) === false && !empty( wp_slimstat::$settings[ 'capability_can_view' ] ) ) {
			$minimum_capability = wp_slimstat::$settings[ 'capability_can_view' ];
		}

		// Find the first available location (screens with no reports assigned to them are hidden from the nav)
		$parent = 'slimview1';
		if ( is_array( self::$meta_user_reports ) ) {
			$parent = '';
			foreach ( self::$screens_info as $a_screen_id => $a_screen_info ) {
				if ( !empty( self::$meta_user_reports[ $a_screen_id ] ) && $a_screen_info[ 'show_in_sidebar' ] ) {
					$parent = $a_screen_id;
					break;
				}
			}

			if ( empty( $parent ) ) {
				$parent = 'slimlayout';
			}
		}

		// Get the current menu position
		$new_entry = array();
		if ( wp_slimstat::$settings[ 'use_separate_menu' ] == 'no' || is_network_admin() ) {
			$new_entry[] = add_menu_page( __( 'Slimstat',  'wp-slimstat' ), __( 'Slimstat',  'wp-slimstat' ), $minimum_capability, $parent, array( __CLASS__, 'wp_slimstat_include_view' ), 'dashicons-chart-area' );
		}
		else {
			$parent = 'admin.php';
		}

		foreach ( self::$screens_info as $a_screen_id => $a_screen_info ) {
			if ( isset( self::$meta_user_reports[ $a_screen_id ] ) && empty( self::$meta_user_reports[ $a_screen_id ] ) ) {
				continue;
			}

			$minimum_capability = 'read';
			if ( !empty( $a_screen_info[ 'capability' ] ) && strpos( wp_slimstat::$settings[ $a_screen_info[ 'capability' ] ], $GLOBALS[ 'current_user' ]->user_login ) === false && !empty( wp_slimstat::$settings[ 'capability_' . $a_screen_info[ 'capability' ] ] ) ) {
				$minimum_capability = wp_slimstat::$settings[ 'capability_' . $a_screen_info[ 'capability' ] ];
			}

			if ( $a_screen_info[ 'show_in_sidebar' ] ) {
				$new_entry[] = add_submenu_page( 
					$parent,
					$a_screen_info[ 'title' ],
					$a_screen_info[ 'title' ],
					$minimum_capability,
					$a_screen_id,
					$a_screen_info[ 'callback' ]
				);
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
	// END: add_menus

	/**
	 * Adds a new entry in the WordPress Admin Bar
	 */
	public static function add_menu_to_adminbar() {
		// If this user is whitelisted, we use the minimum capability
		$minimum_capability = 'read';
		if ( is_network_admin() ) {
			$minimum_capability = 'manage_network';
		}
		else if ( strpos( wp_slimstat::$settings[ 'can_view' ], $GLOBALS[ 'current_user' ]->user_login ) === false && !empty( wp_slimstat::$settings[ 'capability_can_view' ] ) ) {
			$minimum_capability = wp_slimstat::$settings[ 'capability_can_view' ];
		}

		// Find the first available location (screens with no reports assigned to them are hidden from the nav)
		$parent = 'slimview1';
		if ( is_array( self::$meta_user_reports ) ) {
			$parent = '';
			foreach ( self::$screens_info as $a_screen_id => $a_screen_info ) {
				if ( !empty( self::$meta_user_reports[ $a_screen_id ] ) && $a_screen_info[ 'show_in_sidebar' ] ) {
					$parent = $a_screen_id;
					break;
				}
			}

			if ( empty( $parent ) ) {
				$parent = 'slimlayout';
			}
		}

		if ( current_user_can( $minimum_capability ) ) {
			$view_url = get_admin_url( $GLOBALS[ 'blog_id' ], 'admin.php?page=' );

			$GLOBALS[ 'wp_admin_bar' ]->add_menu( array(
				'id' => 'slimstat-header',
				'title' => '<span class="ab-icon dashicons dashicons-chart-area" style="font-size:1rem;margin-top:3px"></span>' . __( 'Slimstat',  'wp-slimstat' ),
				'href' => "{$view_url}{$parent}"
			) );

			foreach ( self::$screens_info as $a_screen_id => $a_screen_info ) {
				if ( isset( self::$meta_user_reports[ $a_screen_id ] ) && empty( self::$meta_user_reports[ $a_screen_id ] ) ) {
					continue;
				}

				$minimum_capability = 'read';
				if ( !empty( $a_screen_info[ 'capability' ] ) && strpos( wp_slimstat::$settings[ $a_screen_info[ 'capability' ] ], $GLOBALS[ 'current_user' ]->user_login ) === false && !empty( wp_slimstat::$settings[ 'capability_' . $a_screen_info[ 'capability' ] ] ) ) {
					$minimum_capability = wp_slimstat::$settings[ 'capability_' . $a_screen_info[ 'capability' ] ];
				}

				if ( $a_screen_info[ 'show_in_sidebar' ] && current_user_can( $minimum_capability ) ) {
					$GLOBALS[ 'wp_admin_bar' ]->add_menu( array(
						'id' => $a_screen_id,
						'href' => "{$view_url}$a_screen_id",
						'parent' => 'slimstat-header',
						'title' => $a_screen_info[ 'title' ]
					) );
				}
			}
		}
	}
	// END: add_menu_to_adminbar

	/**
	 * Includes the appropriate panel to view the stats
	 */
	public static function wp_slimstat_include_view() {
		include( dirname( __FILE__ ) . '/view/index.php' );
	}
	// END: wp_slimstat_include_view

	/**
	 * Includes the screen to arrange the reports
	 */
	public static function wp_slimstat_include_layout() {
		include( dirname( __FILE__ ) . '/view/layout.php' );
	}
	// END: wp_slimstat_include_addons

	/**
	 * Includes the screen to manage add-ons
	 */
	public static function wp_slimstat_include_addons() {
		include( dirname( __FILE__ ) . '/view/addons.php' );
	}
	// END: wp_slimstat_include_addons

	/**
	 * Includes the appropriate panel to configure Slimstat
	 */
	public static function wp_slimstat_include_config() {
		include( dirname( __FILE__ ) . '/config/index.php' );
	}
	// END: wp_slimstat_include_config

	/**
	 * Retrieves all the information to be used in the custom column on posts, pages and CPTs
	 */
	public static function init_data_for_column() {
		if ( !is_array( $GLOBALS[ 'wp_query' ]->posts ) ) {
			return 0;
		}

		foreach ( $GLOBALS[ 'wp_query' ]->posts as $a_post ) {
			self::$data_for_column[ 'url' ][ $a_post->ID ] = parse_url( get_permalink( $a_post->ID ) );
			self::$data_for_column[ 'url' ][ $a_post->ID ] = self::$data_for_column[ 'url' ][ $a_post->ID ][ 'path' ] . ( !empty( self::$data_for_column[ 'url' ][ $a_post->ID ][ 'query' ] ) ? '?' . self::$data_for_column[ 'url' ][ $a_post->ID ][ 'query' ] : '' );
			self::$data_for_column[ 'sql' ][ $a_post->ID ] = self::$data_for_column[ 'url' ][ $a_post->ID ] . '%';
		}

		if ( empty( self::$data_for_column ) ) {
			return 0;
		}

		wp_slimstat_db::init( 'interval equals -' . wp_slimstat::$settings[ 'posts_column_day_interval' ] );

		$column = ( wp_slimstat::$settings[ 'posts_column_pageviews' ] == 'on' ) ? 'id' : 'ip';
		$where = wp_slimstat_db::get_combined_where( '(' . implode( ' OR ', array_fill( 1, count( self::$data_for_column[ 'url' ] ), 'resource LIKE %s' ) ) . ')', '*', true );

		$sql = wp_slimstat::$wpdb->prepare( "
			SELECT resource, COUNT( DISTINCT $column ) as counthits 
			FROM {$GLOBALS[ 'wpdb' ]->prefix}slim_stats
			WHERE " . $where . "
			GROUP BY resource
			LIMIT 0, " . wp_slimstat_db::$filters_normalized[ 'misc' ][ 'limit_results' ], self::$data_for_column[ 'sql' ] );

		$results = wp_slimstat_db::get_results( $sql );

		foreach ( self::$data_for_column[ 'url' ] as $post_id => $a_url ) {
			self::$data_for_column[ 'count' ][ $post_id ] = 0;

			foreach( $results as $i => $a_row ) {
				if ( strpos( $a_row[ 'resource' ], $a_url ) !== false ) {
					self::$data_for_column[ 'count' ][ $post_id ] += $a_row[ 'counthits' ];
					unset( $results[ $i ] );
				}
			}
		}
	}
	// END: init_data_for_column

	/**
	 * Adds a new column header to the Posts panel (to show the number of pageviews for each post)
	 */
	public static function add_column_header( $_columns = array() ) {
		if ( wp_slimstat::$settings[ 'posts_column_day_interval' ] == 0 ) {
			wp_slimstat::$settings[ 'posts_column_day_interval' ] = 30;
		}

		if ( wp_slimstat::$settings[ 'posts_column_pageviews' ] == 'on' ) {
			$_columns[ 'wp-slimstat' ] = '<span class="slimstat-icon" title="' . sprintf( __( 'Pageviews in the last %s days', 'wp-slimstat' ), wp_slimstat::$settings[ 'posts_column_day_interval' ] ) . '"></span>';
		}
		else {
			$_columns[ 'wp-slimstat' ] = '<span class="slimstat-icon" title="' . sprintf(__( 'Unique IPs in the last %s days', 'wp-slimstat' ), wp_slimstat::$settings[ 'posts_column_day_interval' ] ) . '"></span>';
		}

		return $_columns;
	}
	// END: add_comment_column_header

	/**
	 * Adds a new column to the Posts management panel
	 */
	public static function add_post_column( $_column_name, $_post_id ) {
		if (  'wp-slimstat' != $_column_name || empty( self::$data_for_column[ 'url' ][ $_post_id ] ) ) {
			return 0;
		}

		$count = empty( self::$data_for_column[ 'count' ][ $_post_id ] ) ? 0 : self::$data_for_column[ 'count' ][ $_post_id ];

		echo '<a href="'.wp_slimstat_reports::fs_url( 'resource starts_with ' . self::$data_for_column[ 'url' ][ $_post_id ] . '&&&interval equals -' . wp_slimstat::$settings[ 'posts_column_day_interval' ] ). '">' . $count . '</a>';
	}
	// END: add_column

	public static function hide_addons( $_plugins = array() ) {
		if ( !is_array( $_plugins ) ) {
			return $_plugins;
		}

		foreach ( $_plugins as $a_plugin_slug => $a_plugin_info ) {
			if ( strpos( $a_plugin_slug,  'wp-slimstat-' ) !== false  && is_plugin_active( $a_plugin_slug ) ) {
				unset( $_plugins[ $a_plugin_slug ] );
			}
		}

		return $_plugins;
	}
	// END: hide_addons

	/**
	 * Displays an alert message
	 */
	public static function show_message( $_message = '', $_type = 'info', $_dismiss_handle = '' ) {
		if ( empty( $_message ) ) {
			return 0;
		}

		$_message = wpautop( $_message );

		if ( !empty( $_dismiss_handle ) ) {
			echo '<div id="slimstat-notice-' . $_dismiss_handle .'" class="notice is-dismissible notice-' . $_type . '">' . $_message . '</div>';
		}
		else {
			echo '<div class="notice notice-' . $_type . '">' . $_message . '</div>';
		}
	}
	// END: show_message

	/**
	 * Displays a message related to the current version of Slimstat
	 */
	public static function show_latest_news() {
		self::show_message( self::$admin_notice, 'info', 'latest-news' );
	}
	// END: show_latest_news

	/**
	 * Displays a message if this user speaks a language other than English, to encourage them to help us translate Slimstat in their language
	 */
	public static function show_translate_notice() {
		// echo '<div class="notice slimstat-notice" style="padding:10px"><span>'.self::$admin_notice.'</span></div>';
		include_once( plugin_dir_path( __FILE__ ) . '../languages/i18n-v3.php' );
		include_once( plugin_dir_path( __FILE__ ) . '../languages/i18n-wordpressorg-v3.php' );

		$i18n_module = new Yoast_I18n_WordPressOrg_v3(
			array(
				'textdomain' =>  'wp-slimstat',
				'plugin_name' => 'Slimstat Analytics'
			),
			false
		);

		self::show_message( $i18n_module->get_promo_message(), 'warning', 'translate' );
	}
	// END: show_translate_notice

	/**
	 * Handles the Ajax request to hide the admin notice
	 */
	public static function notices_handler() {
		$tag = current_filter();

		if ( !empty( $tag ) ) {
			$tag = str_replace( 'wp_ajax_slimstat_', '', $tag );
			wp_slimstat::$settings[ $tag ] = 'no';
			
			// Save the default values in the database
			wp_slimstat::update_option( 'slimstat_options', wp_slimstat::$settings );
		}

		exit();
	}
	// END: notices_handler

	/**
	 * Deletes a given pageview from the database
	 */
	public static function delete_pageview() {
		$my_wpdb = apply_filters( 'slimstat_custom_wpdb', $GLOBALS[ 'wpdb' ] );
		$pageview_id = intval( $_POST[ 'pageview_id' ] );
		$my_wpdb->query( "DELETE ts FROM {$GLOBALS[ 'wpdb' ]->prefix}slim_stats ts WHERE ts.id = $pageview_id" );
		exit();
	}
	// END: delete_pageview

	/**
	 * Handles the Ajax requests to load, save or delete existing filters
	 */
	public static function manage_filters() {
		check_ajax_referer( 'meta-box-order', 'security' );

		include_once( plugin_dir_path( __FILE__ ) . 'view/wp-slimstat-reports.php' );
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
						echo __( 'Already saved',  'wp-slimstat' );
						break;
					}
				}

				if ( empty( $saved_filters ) || $filter_found > 0 ) {
					$saved_filters[] = $new_filter;
					update_option( 'slimstat_filters', $saved_filters );
					echo __( 'Saved',  'wp-slimstat' );
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
						$filter_html[] = strtolower( wp_slimstat_db::$columns_names[ $a_filter_label ][ 0 ] ) . ' ' . __( str_replace( '_', ' ', $a_filter_details[ 0 ] ),  'wp-slimstat' ) . ' ' . $filter_value_no_slashes;
						$filter_strings[] = "$a_filter_label {$a_filter_details[0]} $filter_value_no_slashes";
					}
					echo '<p><a class="slimstat-font-cancel slimstat-delete-filter" data-filter-id="' . $a_filter_id . '" title="' . __( 'Delete this filter',  'wp-slimstat' ) . '" href="#"></a> <a class="slimstat-filter-link" data-reset-filters="true" href="' . wp_slimstat_reports::fs_url( implode( '&&&', $filter_strings ) ).'">' . implode( ', ', $filter_html ) . '</a></p>';
				}
				echo '</div>';
				break;
		}
		exit();
	}
	// END: manage_filters

	/**
	 * Contextual help
	 */
	public static function contextual_help() {
		// This contextual help is only available to those using WP 3.3 or newer
		if ( empty( $GLOBALS[ 'wp_version' ] ) || version_compare( $GLOBALS[ 'wp_version' ], '3.3', '<' ) ) {
			return true;
		}

		$screen = get_current_screen();

		$screen->add_help_tab(
			array(
				'id' =>  'wp-slimstat-definitions',
				'title' => __( 'Definitions',  'wp-slimstat' ),
				'content' => '
<ul>
<li><b>' . __( 'Pageview', 'wp-slimstat' ) . '</b>: ' . __( 'A request to load a single HTML file ("page"). This should be contrasted with a "hit", which refers to a request for any file from a web server. Slimstat logs a pageview each time the tracking code is executed', 'wp-slimstat' ) . '</li>
<li><b>' . __( '(Human) Visit', 'wp-slimstat' ) . '</b>: '. __("A period of interaction between a visitor's browser and your website, ending when the browser is closed or when the user has been inactive on that site for 30 minutes", 'wp-slimstat' ) . '</li>
<li><b>' . __( 'Known Visitor', 'wp-slimstat' ) . '</b>: '. __( 'Any user who has left a comment on your blog, and is thus identified by WordPress as a returning visitor', 'wp-slimstat' ) . '</li>
<li><b>' . __( 'Unique IP', 'wp-slimstat' ) . '</b>: '. __( 'Used to differentiate between multiple requests to download a file from one internet address (IP) and requests originating from many distinct addresses; since this measurement looks only at the internet address a pageview came from, it is useful, but not perfect', 'wp-slimstat' ) . '</li>
<li><b>' . __( 'Originating IP', 'wp-slimstat' ) . '</b>: '. __( 'the originating IP address of a client connecting to a web server through an HTTP proxy or load balancer', 'wp-slimstat' ) . '</li>
<li><b>' . __( 'Direct Traffic', 'wp-slimstat' ) . '</b>: '. __( 'All those people showing up to your Web site by typing in the URL of your Web site coming or from a bookmark; some people also call this "default traffic" or "ambient traffic"', 'wp-slimstat' ) . '</li>
<li><b>' . __( 'Search Engine', 'wp-slimstat' ) . '</b>: '. __( 'Google, Yahoo, MSN, Ask, others; this bucket will include both your organic as well as your paid (PPC/SEM) traffic, so be aware of that', 'wp-slimstat' ) . '</li>
<li><b>' . __( 'Search Terms', 'wp-slimstat' ) . '</b>: '. __( 'Keywords used by your visitors to find your website on a search engine', 'wp-slimstat' ) . '</li>
<li><b>' . __( 'SERP', 'wp-slimstat' ) . '</b>: '. __( 'Short for search engine results page, the Web page that a search engine returns with the results of its search. The value shown represents your rank (or position) within that list of results', 'wp-slimstat' ) . '</li>
<li><b>' . __( 'User Agent', 'wp-slimstat' ) . '</b>: '. __( 'Any program used for accessing a website; this includes browsers, robots, spiders and any other program that was used to retrieve information from the site', 'wp-slimstat' ) . '</li>
<li><b>' . __( 'Outbound Link', 'wp-slimstat' ) . '</b>: '. __( 'A link from one domain to another is said to be outbound from its source anchor and inbound to its target. This report lists all the links to other websites followed by your visitors.', 'wp-slimstat' ) . '</li>
</ul>'
			)
		);
		$screen->add_help_tab(
			array(
				'id' => 'wp-slimstat-basic-filters',
				'title' => __( 'Basic Filters', 'wp-slimstat' ),
				'content' => '
<ul>
<li><b>' . __( 'Browser', 'wp-slimstat' ) . '</b>: '. __( 'User agent (Firefox, Chrome, ...)', 'wp-slimstat' ) . '</li>
<li><b>' . __( 'Country Code', 'wp-slimstat' ) . '</b>: '. __( '2-letter code (us, ru, de, it, ...)', 'wp-slimstat' ) . '</li>
<li><b>' . __( 'IP', 'wp-slimstat' ) . '</b>: '. __( 'Visitor\'s public IP address', 'wp-slimstat' ) . '</li>
<li><b>' . __( 'Search Terms', 'wp-slimstat' ) . '</b>: '. __( 'Keywords used by your visitors to find your website on a search engine', 'wp-slimstat' ) . '</li>
<li><b>' . __( 'Language Code', 'wp-slimstat' ) . '</b>: '. __( 'Please refer to this <a target="_blank" href="https://msdn.microsoft.com/en-us/library/ee825488(v=cs.20).aspx">language culture page</a> (first column) for more information', 'wp-slimstat' ) . '</li>
<li><b>' . __( 'Operating System', 'wp-slimstat' ) . '</b>: '. __( 'Accepts identifiers like win7, win98, macosx, ...; please refer to <a target="_blank" href="https://php.net/manual/en/function.get-browser.php">this manual page</a> for more information', 'wp-slimstat' ) . '</li>
<li><b>' . __( 'Permalink', 'wp-slimstat' ) . '</b>: '. __( 'URL accessed on your site', 'wp-slimstat' ) . '</li>
<li><b>' . __( 'Referer', 'wp-slimstat' ) . '</b>: '. __( 'Complete address of the referrer page', 'wp-slimstat' ) . '</li>
<li><b>' . __( 'Visitor\'s Name', 'wp-slimstat' ) . '</b>: '. __( 'Visitors\' names according to the cookie set by WordPress after they leave a comment', 'wp-slimstat' ) . '</li>
</ul>'
			)
		);

		$screen->add_help_tab(
			array(
				'id' =>  'wp-slimstat-advanced-filters',
				'title' => __( 'Advanced Filters',  'wp-slimstat' ),
				'content' => '
<ul>
<li><b>' . __( 'Browser Version', 'wp-slimstat' ) . '</b>: '. __( 'user agent version (9.0, 11, ...)', 'wp-slimstat' ) . '</li>
<li><b>' . __( 'Browser Type', 'wp-slimstat' ) . '</b>: '. __( '1 = search engine crawler, 2 = mobile device, 3 = syndication reader, 0 = all others', 'wp-slimstat' ) . '</li>
<li><b>' . __( 'Pageview Attributes', 'wp-slimstat' ) . '</b>: '. __( 'this field is set to <em>[pre]</em> if the resource has been accessed through <a target="_blank" href="https://developer.mozilla.org/en/Link_prefetching_FAQ">Link Prefetching</a> or similar techniques', 'wp-slimstat' ) . '</li>
<li><b>' . __( 'Post Author', 'wp-slimstat' ) . '</b>: '. __( 'author associated to that post/page when the resource was accessed', 'wp-slimstat' ) . '</li>
<li><b>' . __( 'Post Category ID', 'wp-slimstat' ) . '</b>: '. __( 'ID of the category/term associated to the resource, when available', 'wp-slimstat' ) . '</li>
<li><b>' . __( 'Originating IP', 'wp-slimstat' ) . '</b>: '. __( 'visitor\'s originating IP address, if available', 'wp-slimstat' ) . '</li>
<li><b>' . __( 'Resource Content Type', 'wp-slimstat' ) . '</b>: '. __( 'post, page, cpt:<em>custom-post-type</em>, attachment, singular, post_type_archive, tag, taxonomy, category, date, author, archive, search, feed, home; please refer to the <a target="_blank" href="https://codex.wordpress.org/Conditional_Tags">Conditional Tags</a> manual page for more information', 'wp-slimstat' ) . '</li>
<li><b>' . __( 'Screen Resolution', 'wp-slimstat' ) . '</b>: '. __( 'viewport width and height (1024x768, 800x600, ...)', 'wp-slimstat' ) . '</li>
<li><b>' . __( 'Visit ID', 'wp-slimstat' )."</b>: ". __( 'generally used in conjunction with <em>is not empty</em>, identifies human visitors', 'wp-slimstat' ) . '</li>
<li><b>' . __( 'Date Filters', 'wp-slimstat' )."</b>: ". __( 'you can specify the timeframe by entering a number in the <em>interval</em> field; use -1 to indicate <em>to date</em> (i.e., day=1, month=1, year=blank, interval=-1 will set a year-to-date filter)', 'wp-slimstat' ) . '</li>
<li><b>' . __( 'SERP Position', 'wp-slimstat' )."</b>: ". __( 'set the filter to Referer contains cd=N&, where N is the position you are looking for', 'wp-slimstat' ) . '</li>
</ul>'
			)
		);
	}
	// END: contextual_help

	/**
	 * Creates a table in the database
	 */
	protected static function _create_table( $_sql = '', $_tablename = '', $_wpdb = '' ) {
		$_wpdb->query( $_sql );

		// Let's make sure this table was actually created
		foreach ( $_wpdb->get_col( "SHOW TABLES LIKE '$_tablename'", 0 ) as $a_table ) {
			if ( $a_table == $_tablename ) {
				return true;
			}
		}

		return false;
	}
	// END: _create_table
}
// END: class declaration