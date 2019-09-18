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
		self::$admin_notice = "We recently introduced a new simplified tracker that gets rid of a few layers of convoluted functions and algorithms accumulated over the years. Even though we have tested our new code using a variety of scenarios, you can understand how it would be impossible to cover all the environments available out there. <strong>Make sure to clear your caches</strong> (local, Cloudflare, WP plugins, etc), to allow Slimstat to append the new tracking script to your pages. Also, if you are using Slimstat to track <strong>external</strong> pages (outside of your WP install), please make sure to update the code you're using on those pages with the new one you can find in Slimstat > Settings > Tracker > External Pages. As always, do not hesitate to contact <a href='https://support.wp-slimstat.com/' target='_blank'>our support team</a> if you notice any issues.";
		// self::$admin_notice = "Just a quick reminder that, in our quest for improved performance, we are deprecating the two columns <em>type</em> and <em>event_description</em> in the events table, and consolidating that information in the <em>notes</em> field. Code will be added to Slimstat in a few released to actually drop these columns from the database. If you are using those two columns in your custom code, please feel free to contact our support team to discuss your options and how to update your code using the information collected by the new tracker.";
		// self::$admin_notice = "In this day and age where every single social media platform knows our individual whereabouts on the Interwebs, we have been doing some research on what <em>the techies</em> out there call <a href='https://amiunique.org/fp' target='_blank'>browser fingerprinting</a>. With this technique, it is not necessary to install any cookies to identify a specific user. This means that the act of fingerprinting a specific browser is stateless and transparent, and thus much more accurate. We are already wearing our lab coats and are hard at work to leverage <a href='https://github.com/Valve/fingerprintjs2' target='_blank'>tools like Fingerprint2</a> in Slimstat. This library, among other things, will allow our tracker to record your users' timezone: wouldn't it be nice to know what time it was for the user who was visiting your website? Of course, if you have Privacy Mode enabled, this feature will not be used, in compliance with GDPR and other international privacy laws. Stay tuned!";

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
				INDEX {$GLOBALS[ 'wpdb' ]->prefix}stats_searchterms_idx( searchterms( 15 ) )
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

		// --- Updates for version 4.7.8.2 ---
		if ( version_compare( wp_slimstat::$settings[ 'version' ], '4.7.8.2', '<' ) ) {
			wp_slimstat::$settings[ 'opt_out_message' ] = '<p style="display:block;position:fixed;left:0;bottom:0;margin:0;padding:1em 2em;background-color:#eee;width:100%;z-index:99999;">This website stores cookies on your computer. These cookies are used to provide a more personalized experience and to track your whereabouts around our website in compliance with the European General Data Protection Regulation. If you decide to to opt-out of any future tracking, a cookie will be setup in your browser to remember this choice for one year.<br><br><a href="#" onclick="javascript:SlimStat.optout(event, false);">Accept</a> or <a href="#" onclick="javascript:SlimStat.optout(event, true);">Deny</a></p>';
		}
		// --- END: Updates for version 4.7.8.2 ---

		// --- Updates for version 4.7.9 ---
		if ( version_compare( wp_slimstat::$settings[ 'version' ], '4.7.9', '<' ) ) {
			// Delete the old Browscap Library and install the new one
			if ( file_exists( wp_slimstat::$upload_dir . '/browscap-db/autoload.php' ) ) {
				WP_Filesystem();
				$GLOBALS[ 'wp_filesystem' ]->rmdir( wp_slimstat::$upload_dir . '/browscap-db/', true );
				slim_browser::update_browscap_database( true );
			}
		}
		// --- END: Updates for version 4.7.9 ---

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

		// Now we can update the version stored in the database
		wp_slimstat::$settings[ 'version' ] = wp_slimstat::$version;
		wp_slimstat::$settings[ 'notice_latest_news' ] = 'on';

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

	/*
	 * Updates the options 
	 */
	public static function update_settings( $_settings = array() ) {
		
	}
	// END: update_settings

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

class slim_i18n {
	public static $dynamic_strings = array();

	public static function init_dynamic_strings() {
		if ( false === ( self::$dynamic_strings = get_transient( 'slimstat_dynamic_strings' ) ) ) {
			self::$dynamic_strings = array(
				'xx' => __( 'Unknown', 'wp-slimstat' ),

				// Countries
				'c-' => __( 'Unknown', 'wp-slimstat' ),
				'c-xx' => __( 'Unknown', 'wp-slimstat' ),
				'c-xy' => __( 'Local IP Address', 'wp-slimstat' ),

				'c-af' => __( 'Afghanistan', 'wp-slimstat' ),
				'c-ax' => __( 'Aland Islands', 'wp-slimstat' ),
				'c-al' => __( 'Albania', 'wp-slimstat' ),
				'c-dz' => __( 'Algeria', 'wp-slimstat' ),
				'c-ad' => __( 'Andorra', 'wp-slimstat' ),
				'c-ao' => __( 'Angola', 'wp-slimstat' ),
				'c-ai' => __( 'Anguilla', 'wp-slimstat' ),
				'c-ag' => __( 'Antigua and Barbuda', 'wp-slimstat' ),
				'c-ar' => __( 'Argentina', 'wp-slimstat' ),
				'c-am' => __( 'Armenia', 'wp-slimstat' ),
				'c-aw' => __( 'Aruba', 'wp-slimstat' ),
				'c-au' => __( 'Australia', 'wp-slimstat' ),
				'c-at' => __( 'Austria', 'wp-slimstat' ),
				'c-az' => __( 'Azerbaijan', 'wp-slimstat' ),
				'c-bs' => __( 'Bahamas', 'wp-slimstat' ),
				'c-bh' => __( 'Bahrain', 'wp-slimstat' ),
				'c-bd' => __( 'Bangladesh', 'wp-slimstat' ),
				'c-bb' => __( 'Barbados', 'wp-slimstat' ),
				'c-by' => __( 'Belarus', 'wp-slimstat' ),
				'c-be' => __( 'Belgium', 'wp-slimstat' ),
				'c-bz' => __( 'Belize', 'wp-slimstat' ),
				'c-bj' => __( 'Benin', 'wp-slimstat' ),
				'c-bm' => __( 'Bermuda', 'wp-slimstat' ),
				'c-bt' => __( 'Bhutan', 'wp-slimstat' ),
				'c-bo' => __( 'Bolivia', 'wp-slimstat' ),
				'c-ba' => __( 'Bosnia and Herzegovina', 'wp-slimstat' ),
				'c-bw' => __( 'Botswana', 'wp-slimstat' ),
				'c-br' => __( 'Brazil', 'wp-slimstat' ),
				'c-bn' => __( 'Brunei Darussalam', 'wp-slimstat' ),
				'c-bg' => __( 'Bulgaria', 'wp-slimstat' ),
				'c-bf' => __( 'Burkina Faso', 'wp-slimstat' ),
				'c-bi' => __( 'Burundi', 'wp-slimstat' ),
				'c-kh' => __( 'Cambodia', 'wp-slimstat' ),
				'c-cm' => __( 'Cameroon', 'wp-slimstat' ),
				'c-ca' => __( 'Canada', 'wp-slimstat' ),
				'c-cv' => __( 'Cape Verde', 'wp-slimstat' ),
				'c-ky' => __( 'Cayman Islands', 'wp-slimstat' ),
				'c-cf' => __( 'Central African Republic', 'wp-slimstat' ),
				'c-td' => __( 'Chad', 'wp-slimstat' ),
				'c-cl' => __( 'Chile', 'wp-slimstat' ),
				'c-cn' => __( 'China', 'wp-slimstat' ),
				'c-co' => __( 'Colombia', 'wp-slimstat' ),
				'c-km' => __( 'Comoros', 'wp-slimstat' ),
				'c-cg' => __( 'Congo', 'wp-slimstat' ),
				'c-cd' => __( 'The Democratic Republic of the Congo', 'wp-slimstat' ),
				'c-cr' => __( 'Costa Rica', 'wp-slimstat' ),
				'c-ci' => __( 'Côte d\'Ivoire', 'wp-slimstat' ),
				'c-hr' => __( 'Croatia', 'wp-slimstat' ),
				'c-cu' => __( 'Cuba', 'wp-slimstat' ),
				'c-cy' => __( 'Cyprus', 'wp-slimstat' ),
				'c-cz' => __( 'Czech Republic', 'wp-slimstat' ),
				'c-dk' => __( 'Denmark', 'wp-slimstat' ),
				'c-dj' => __( 'Djibouti', 'wp-slimstat' ),
				'c-dm' => __( 'Dominica', 'wp-slimstat' ),
				'c-do' => __( 'Dominican Republic', 'wp-slimstat' ),
				'c-ec' => __( 'Ecuador', 'wp-slimstat' ),
				'c-eg' => __( 'Egypt', 'wp-slimstat' ),
				'c-sv' => __( 'El Salvador', 'wp-slimstat' ),
				'c-gq' => __( 'Equatorial Guinea', 'wp-slimstat' ),
				'c-er' => __( 'Eritrea', 'wp-slimstat' ),
				'c-ee' => __( 'Estonia', 'wp-slimstat' ),
				'c-et' => __( 'Ethiopia', 'wp-slimstat' ),
				'c-fo' => __( 'Faroe Islands', 'wp-slimstat' ),
				'c-fk' => __( 'Falkland Islands (Malvinas)', 'wp-slimstat' ),
				'c-fj' => __( 'Fiji', 'wp-slimstat' ),
				'c-fi' => __( 'Finland', 'wp-slimstat' ),
				'c-fr' => __( 'France', 'wp-slimstat' ),
				'c-gf' => __( 'French Guiana', 'wp-slimstat' ),
				'c-ga' => __( 'Gabon', 'wp-slimstat' ),
				'c-gm' => __( 'Gambia', 'wp-slimstat' ),
				'c-ge' => __( 'Georgia', 'wp-slimstat' ),
				'c-de' => __( 'Germany', 'wp-slimstat' ),
				'c-gh' => __( 'Ghana', 'wp-slimstat' ),
				'c-gr' => __( 'Greece', 'wp-slimstat' ),
				'c-gl' => __( 'Greenland', 'wp-slimstat' ),
				'c-gd' => __( 'Grenada', 'wp-slimstat' ),
				'c-gp' => __( 'Guadeloupe', 'wp-slimstat' ),
				'c-gt' => __( 'Guatemala', 'wp-slimstat' ),
				'c-gn' => __( 'Guinea', 'wp-slimstat' ),
				'c-gw' => __( 'Guinea-Bissau', 'wp-slimstat' ),
				'c-gy' => __( 'Guyana', 'wp-slimstat' ),
				'c-ht' => __( 'Haiti', 'wp-slimstat' ),
				'c-hn' => __( 'Honduras', 'wp-slimstat' ),
				'c-hk' => __( 'Hong Kong', 'wp-slimstat' ),
				'c-hu' => __( 'Hungary', 'wp-slimstat' ),
				'c-is' => __( 'Iceland', 'wp-slimstat' ),
				'c-in' => __( 'India', 'wp-slimstat' ),
				'c-id' => __( 'Indonesia', 'wp-slimstat' ),
				'c-ir' => __( 'Islamic Republic of Iran', 'wp-slimstat' ),
				'c-iq' => __( 'Iraq', 'wp-slimstat' ),
				'c-ie' => __( 'Ireland', 'wp-slimstat' ),
				'c-il' => __( 'Israel', 'wp-slimstat' ),
				'c-it' => __( 'Italy', 'wp-slimstat' ),
				'c-jm' => __( 'Jamaica', 'wp-slimstat' ),
				'c-jp' => __( 'Japan', 'wp-slimstat' ),
				'c-jo' => __( 'Jordan', 'wp-slimstat' ),
				'c-kz' => __( 'Kazakhstan', 'wp-slimstat' ),
				'c-ke' => __( 'Kenya', 'wp-slimstat' ),
				'c-nr' => __( 'Nauru', 'wp-slimstat' ),
				'c-kp' => __( 'Democratic People\'s Republic of Korea', 'wp-slimstat' ),
				'c-kr' => __( 'Republic of Korea', 'wp-slimstat' ),
				'c-kv' => __( 'Kosovo', 'wp-slimstat' ),
				'c-kw' => __( 'Kuwait', 'wp-slimstat' ),
				'c-kg' => __( 'Kyrgyzstan', 'wp-slimstat' ),
				'c-la' => __( 'Lao People\'s Democratic Republic', 'wp-slimstat' ),
				'c-lv' => __( 'Latvia', 'wp-slimstat' ),
				'c-lb' => __( 'Lebanon', 'wp-slimstat' ),
				'c-ls' => __( 'Lesotho', 'wp-slimstat' ),
				'c-lr' => __( 'Liberia', 'wp-slimstat' ),
				'c-ly' => __( 'Libyan Arab Jamahiriya', 'wp-slimstat' ),
				'c-li' => __( 'Liechtenstein', 'wp-slimstat' ),
				'c-lt' => __( 'Lithuania', 'wp-slimstat' ),
				'c-lu' => __( 'Luxembourg', 'wp-slimstat' ),
				'c-mk' => __( 'The Former Yugoslav Republic of Macedonia', 'wp-slimstat' ),
				'c-mg' => __( 'Madagascar', 'wp-slimstat' ),
				'c-mw' => __( 'Malawi', 'wp-slimstat' ),
				'c-my' => __( 'Malaysia', 'wp-slimstat' ),
				'c-ml' => __( 'Mali', 'wp-slimstat' ),
				'c-mt' => __( 'Malta', 'wp-slimstat' ),
				'c-mq' => __( 'Martinique', 'wp-slimstat' ),
				'c-mr' => __( 'Mauritania', 'wp-slimstat' ),
				'c-mu' => __( 'Mauritius', 'wp-slimstat' ),
				'c-mx' => __( 'Mexico', 'wp-slimstat' ),
				'c-md' => __( 'Moldova', 'wp-slimstat' ),
				'c-mn' => __( 'Mongolia', 'wp-slimstat' ),
				'c-me' => __( 'Montenegro', 'wp-slimstat' ),
				'c-ms' => __( 'Montserrat', 'wp-slimstat' ),
				'c-ma' => __( 'Morocco', 'wp-slimstat' ),
				'c-mz' => __( 'Mozambique', 'wp-slimstat' ),
				'c-mm' => __( 'Myanmar', 'wp-slimstat' ),
				'c-na' => __( 'Namibia', 'wp-slimstat' ),
				'c-np' => __( 'Nepal', 'wp-slimstat' ),
				'c-nl' => __( 'Netherlands', 'wp-slimstat' ),
				'c-nc' => __( 'New Caledonia', 'wp-slimstat' ),
				'c-nz' => __( 'New Zealand', 'wp-slimstat' ),
				'c-ni' => __( 'Nicaragua', 'wp-slimstat' ),
				'c-ne' => __( 'Niger', 'wp-slimstat' ),
				'c-ng' => __( 'Nigeria', 'wp-slimstat' ),
				'c-no' => __( 'Norway', 'wp-slimstat' ),
				'c-om' => __( 'Oman', 'wp-slimstat' ),
				'c-pk' => __( 'Pakistan', 'wp-slimstat' ),
				'c-pw' => __( 'Palau', 'wp-slimstat' ),
				'c-ps' => __( 'Occupied Palestinian Territory', 'wp-slimstat' ),
				'c-pa' => __( 'Panama', 'wp-slimstat' ),
				'c-pg' => __( 'Papua New Guinea', 'wp-slimstat' ),
				'c-py' => __( 'Paraguay', 'wp-slimstat' ),
				'c-pe' => __( 'Peru', 'wp-slimstat' ),
				'c-ph' => __( 'Philippines', 'wp-slimstat' ),
				'c-pl' => __( 'Poland', 'wp-slimstat' ),
				'c-pt' => __( 'Portugal', 'wp-slimstat' ),
				'c-pr' => __( 'Puerto Rico', 'wp-slimstat' ),
				'c-qa' => __( 'Qatar', 'wp-slimstat' ),
				'c-re' => __( 'Réunion', 'wp-slimstat' ),
				'c-ro' => __( 'Romania', 'wp-slimstat' ),
				'c-ru' => __( 'Russian Federation', 'wp-slimstat' ),
				'c-rw' => __( 'Rwanda', 'wp-slimstat' ),
				'c-kn' => __( 'Saint Kitts and Nevis', 'wp-slimstat' ),
				'c-lc' => __( 'Saint Lucia', 'wp-slimstat' ),
				'c-mf' => __( 'Saint Martin', 'wp-slimstat' ),
				'c-vc' => __( 'Saint Vincent and the Grenadines', 'wp-slimstat' ),
				'c-ws' => __( 'Samoa', 'wp-slimstat' ),
				'c-st' => __( 'Sao Tome and Principe', 'wp-slimstat' ),
				'c-sa' => __( 'Saudi Arabia', 'wp-slimstat' ),
				'c-sn' => __( 'Senegal', 'wp-slimstat' ),
				'c-rs' => __( 'Serbia', 'wp-slimstat' ),
				'c-sl' => __( 'Sierra Leone', 'wp-slimstat' ),
				'c-sg' => __( 'Singapore', 'wp-slimstat' ),
				'c-sk' => __( 'Slovakia', 'wp-slimstat' ),
				'c-si' => __( 'Slovenia', 'wp-slimstat' ),
				'c-sb' => __( 'Solomon Islands', 'wp-slimstat' ),
				'c-so' => __( 'Somalia', 'wp-slimstat' ),
				'c-za' => __( 'South Africa', 'wp-slimstat' ),
				'c-gs' => __( 'South Georgia and the South Sandwich Islands', 'wp-slimstat' ),
				'c-es' => __( 'Spain', 'wp-slimstat' ),
				'c-lk' => __( 'Sri Lanka', 'wp-slimstat' ),
				'c-sc' => __( 'Seychelles', 'wp-slimstat' ),
				'c-sd' => __( 'Sudan', 'wp-slimstat' ),
				'c-ss' => __( 'South Sudan', 'wp-slimstat' ),
				'c-sr' => __( 'Suriname', 'wp-slimstat' ),
				'c-sj' => __( 'Svalbard and Jan Mayen', 'wp-slimstat' ),
				'c-sz' => __( 'Swaziland', 'wp-slimstat' ),
				'c-se' => __( 'Sweden', 'wp-slimstat' ),
				'c-ch' => __( 'Switzerland', 'wp-slimstat' ),
				'c-sy' => __( 'Syrian Arab Republic', 'wp-slimstat' ),
				'c-tw' => __( 'Taiwan', 'wp-slimstat' ),
				'c-tj' => __( 'Tajikistan', 'wp-slimstat' ),
				'c-tz' => __( 'United Republic of Tanzania', 'wp-slimstat' ),
				'c-th' => __( 'Thailand', 'wp-slimstat' ),
				'c-tl' => __( 'Timor-Leste', 'wp-slimstat' ),
				'c-tg' => __( 'Togo', 'wp-slimstat' ),
				'c-to' => __( 'Tonga', 'wp-slimstat' ),
				'c-tt' => __( 'Trinidad and Tobago', 'wp-slimstat' ),
				'c-tn' => __( 'Tunisia', 'wp-slimstat' ),
				'c-tr' => __( 'Turkey', 'wp-slimstat' ),
				'c-tm' => __( 'Turkmenistan', 'wp-slimstat' ),
				'c-tc' => __( 'Turks and Caicos Islands', 'wp-slimstat' ),
				'c-ug' => __( 'Uganda', 'wp-slimstat' ),
				'c-ua' => __( 'Ukraine', 'wp-slimstat' ),
				'c-ae' => __( 'United Arab Emirates', 'wp-slimstat' ),
				'c-gb' => __( 'United Kingdom', 'wp-slimstat' ),
				'c-us' => __( 'United States', 'wp-slimstat' ),
				'c-uy' => __( 'Uruguay', 'wp-slimstat' ),
				'c-uz' => __( 'Uzbekistan', 'wp-slimstat' ),
				'c-vu' => __( 'Vanuatu', 'wp-slimstat' ),
				'c-ve' => __( 'Venezuela', 'wp-slimstat' ),
				'c-vn' => __( 'Viet Nam', 'wp-slimstat' ),
				'c-vg' => __( 'British Virgin Islands', 'wp-slimstat' ),
				'c-vi' => __( 'U.S. Virgin Islands', 'wp-slimstat' ),
				'c-eh' => __( 'Western Sahara', 'wp-slimstat' ),
				'c-ye' => __( 'Yemen', 'wp-slimstat' ),
				'c-zm' => __( 'Zambia', 'wp-slimstat' ),
				'c-zw' => __( 'Zimbabwe', 'wp-slimstat' ),
				'c-gg' => __( 'Guernsey', 'wp-slimstat' ),
				'c-je' => __( 'Jersey', 'wp-slimstat' ),
				'c-im' => __( 'Isle of Man', 'wp-slimstat' ),
				'c-mv' => __( 'Maldives', 'wp-slimstat' ),
				'c-eu' => __( 'Europe', 'wp-slimstat' ),

				// Languages
				'l-' => __( 'Unknown', 'wp-slimstat' ),
				'l-empty' => __( 'Unknown', 'wp-slimstat' ),
				'l-xx' => __( 'Unknown', 'wp-slimstat' ),

				'l-af' => __( 'Afrikaans', 'wp-slimstat' ),
				'l-af-za' => __( 'Afrikaans (South Africa)', 'wp-slimstat' ),
				'l-ar' => __( 'Arabic', 'wp-slimstat' ),
				'l-ar-ae' => __( 'Arabic (U.A.E.)', 'wp-slimstat' ),
				'l-ar-bh' => __( 'Arabic (Bahrain)', 'wp-slimstat' ),
				'l-ar-dz' => __( 'Arabic (Algeria)', 'wp-slimstat' ),
				'l-ar-eg' => __( 'Arabic (Egypt)', 'wp-slimstat' ),
				'l-ar-iq' => __( 'Arabic (Iraq)', 'wp-slimstat' ),
				'l-ar-jo' => __( 'Arabic (Jordan)', 'wp-slimstat' ),
				'l-ar-kw' => __( 'Arabic (Kuwait)', 'wp-slimstat' ),
				'l-ar-lb' => __( 'Arabic (Lebanon)', 'wp-slimstat' ),
				'l-ar-ly' => __( 'Arabic (Libya)', 'wp-slimstat' ),
				'l-ar-ma' => __( 'Arabic (Morocco)', 'wp-slimstat' ),
				'l-ar-om' => __( 'Arabic (Oman)', 'wp-slimstat' ),
				'l-ar-qa' => __( 'Arabic (Qatar)', 'wp-slimstat' ),
				'l-ar-sa' => __( 'Arabic (Saudi Arabia)', 'wp-slimstat' ),
				'l-ar-sy' => __( 'Arabic (Syria)', 'wp-slimstat' ),
				'l-ar-tn' => __( 'Arabic (Tunisia)', 'wp-slimstat' ),
				'l-ar-ye' => __( 'Arabic (Yemen)', 'wp-slimstat' ),
				'l-az' => __( 'Azeri (Latin)', 'wp-slimstat' ),
				'l-az-az' => __( 'Azeri (Latin) (Azerbaijan)', 'wp-slimstat' ),
				'l-be' => __( 'Belarusian', 'wp-slimstat' ),
				'l-be-by' => __( 'Belarusian (Belarus)', 'wp-slimstat' ),
				'l-bg' => __( 'Bulgarian', 'wp-slimstat' ),
				'l-bg-bg' => __( 'Bulgarian (Bulgaria)', 'wp-slimstat' ),
				'l-bs-ba' => __( 'Bosnian (Bosnia and Herzegovina)', 'wp-slimstat' ),
				'l-ca' => __( 'Catalan', 'wp-slimstat' ),
				'l-ca-es' => __( 'Catalan (Spain)', 'wp-slimstat' ),
				'l-cs' => __( 'Czech', 'wp-slimstat' ),
				'l-cs-cz' => __( 'Czech (Czech Republic)', 'wp-slimstat' ),
				'l-cy' => __( 'Welsh', 'wp-slimstat' ),
				'l-cy-gb' => __( 'Welsh (United Kingdom)', 'wp-slimstat' ),
				'l-da' => __( 'Danish', 'wp-slimstat' ),
				'l-da-dk' => __( 'Danish (Denmark)', 'wp-slimstat' ),
				'l-de' => __( 'German', 'wp-slimstat' ),
				'l-de-at' => __( 'German (Austria)', 'wp-slimstat' ),
				'l-de-ch' => __( 'German (Switzerland)', 'wp-slimstat' ),
				'l-de-de' => __( 'German (Germany)', 'wp-slimstat' ),
				'l-de-li' => __( 'German (Liechtenstein)', 'wp-slimstat' ),
				'l-de-lu' => __( 'German (Luxembourg)', 'wp-slimstat' ),
				'l-dv' => __( 'Divehi', 'wp-slimstat' ),
				'l-dv-mv' => __( 'Divehi (Maldives)', 'wp-slimstat' ),
				'l-el' => __( 'Greek', 'wp-slimstat' ),
				'l-el-gr' => __( 'Greek (Greece)', 'wp-slimstat' ),
				'l-en' => __( 'English', 'wp-slimstat' ),
				'l-en-au' => __( 'English (Australia)', 'wp-slimstat' ),
				'l-en-bz' => __( 'English (Belize)', 'wp-slimstat' ),
				'l-en-ca' => __( 'English (Canada)', 'wp-slimstat' ),
				'l-en-cb' => __( 'English (Caribbean)', 'wp-slimstat' ),
				'l-en-gb' => __( 'English (United Kingdom)', 'wp-slimstat' ),
				'l-en-ie' => __( 'English (Ireland)', 'wp-slimstat' ),
				'l-en-jm' => __( 'English (Jamaica)', 'wp-slimstat' ),
				'l-en-nz' => __( 'English (New Zealand)', 'wp-slimstat' ),
				'l-en-ph' => __( 'English (Republic of the Philippines)', 'wp-slimstat' ),
				'l-en-tt' => __( 'English (Trinidad and Tobago)', 'wp-slimstat' ),
				'l-en-us' => __( 'English (United States)', 'wp-slimstat' ),
				'l-en-za' => __( 'English (South Africa)', 'wp-slimstat' ),
				'l-en-zw' => __( 'English (Zimbabwe)', 'wp-slimstat' ),
				'l-eo' => __( 'Esperanto', 'wp-slimstat' ),
				'l-es' => __( 'Spanish', 'wp-slimstat' ),
				'l-es-ar' => __( 'Spanish (Argentina)', 'wp-slimstat' ),
				'l-es-bo' => __( 'Spanish (Bolivia)', 'wp-slimstat' ),
				'l-es-cl' => __( 'Spanish (Chile)', 'wp-slimstat' ),
				'l-es-co' => __( 'Spanish (Colombia)', 'wp-slimstat' ),
				'l-es-cr' => __( 'Spanish (Costa Rica)', 'wp-slimstat' ),
				'l-es-do' => __( 'Spanish (Dominican Republic)', 'wp-slimstat' ),
				'l-es-ec' => __( 'Spanish (Ecuador)', 'wp-slimstat' ),
				'l-es-es' => __( 'Spanish (Spain)', 'wp-slimstat' ),
				'l-es-gt' => __( 'Spanish (Guatemala)', 'wp-slimstat' ),
				'l-es-hn' => __( 'Spanish (Honduras)', 'wp-slimstat' ),
				'l-es-mx' => __( 'Spanish (Mexico)', 'wp-slimstat' ),
				'l-es-ni' => __( 'Spanish (Nicaragua)', 'wp-slimstat' ),
				'l-es-pa' => __( 'Spanish (Panama)', 'wp-slimstat' ),
				'l-es-pe' => __( 'Spanish (Peru)', 'wp-slimstat' ),
				'l-es-pr' => __( 'Spanish (Puerto Rico)', 'wp-slimstat' ),
				'l-es-py' => __( 'Spanish (Paraguay)', 'wp-slimstat' ),
				'l-es-sv' => __( 'Spanish (El Salvador)', 'wp-slimstat' ),
				'l-es-uy' => __( 'Spanish (Uruguay)', 'wp-slimstat' ),
				'l-es-ve' => __( 'Spanish (Venezuela)', 'wp-slimstat' ),
				'l-et' => __( 'Estonian', 'wp-slimstat' ),
				'l-et-ee' => __( 'Estonian (Estonia)', 'wp-slimstat' ),
				'l-eu' => __( 'Basque', 'wp-slimstat' ),
				'l-eu-es' => __( 'Basque (Spain)', 'wp-slimstat' ),
				'l-fa' => __( 'Farsi', 'wp-slimstat' ),
				'l-fa-ir' => __( 'Farsi (Iran)', 'wp-slimstat' ),
				'l-fi' => __( 'Finnish', 'wp-slimstat' ),
				'l-fi-fi' => __( 'Finnish (Finland)', 'wp-slimstat' ),
				'l-fo' => __( 'Faroese', 'wp-slimstat' ),
				'l-fo-fo' => __( 'Faroese (Faroe Islands)', 'wp-slimstat' ),
				'l-fr' => __( 'French', 'wp-slimstat' ),
				'l-fr-be' => __( 'French (Belgium)', 'wp-slimstat' ),
				'l-fr-ca' => __( 'French (Canada)', 'wp-slimstat' ),
				'l-fr-ch' => __( 'French (Switzerland)', 'wp-slimstat' ),
				'l-fr-fr' => __( 'French (France)', 'wp-slimstat' ),
				'l-fr-lu' => __( 'French (Luxembourg)', 'wp-slimstat' ),
				'l-fr-mc' => __( 'French (Principality of Monaco)', 'wp-slimstat' ),
				'l-gl' => __( 'Galician', 'wp-slimstat' ),
				'l-gl-es' => __( 'Galician (Spain)', 'wp-slimstat' ),
				'l-gu' => __( 'Gujarati', 'wp-slimstat' ),
				'l-gu-in' => __( 'Gujarati (India)', 'wp-slimstat' ),
				'l-he' => __( 'Hebrew', 'wp-slimstat' ),
				'l-he-il' => __( 'Hebrew (Israel)', 'wp-slimstat' ),
				'l-hi' => __( 'Hindi', 'wp-slimstat' ),
				'l-hi-in' => __( 'Hindi (India)', 'wp-slimstat' ),
				'l-hr' => __( 'Croatian', 'wp-slimstat' ),
				'l-hr-ba' => __( 'Croatian (Bosnia and Herzegovina)', 'wp-slimstat' ),
				'l-hr-hr' => __( 'Croatian (Croatia)', 'wp-slimstat' ),
				'l-hu' => __( 'Hungarian', 'wp-slimstat' ),
				'l-hu-hu' => __( 'Hungarian (Hungary)', 'wp-slimstat' ),
				'l-hy' => __( 'Armenian', 'wp-slimstat' ),
				'l-hy-am' => __( 'Armenian (Armenia)', 'wp-slimstat' ),
				'l-id' => __( 'Indonesian', 'wp-slimstat' ),
				'l-id-id' => __( 'Indonesian (Indonesia)', 'wp-slimstat' ),
				'l-is' => __( 'Icelandic', 'wp-slimstat' ),
				'l-is-is' => __( 'Icelandic (Iceland)', 'wp-slimstat' ),
				'l-it' => __( 'Italian', 'wp-slimstat' ),
				'l-it-ch' => __( 'Italian (Switzerland)', 'wp-slimstat' ),
				'l-it-it' => __( 'Italian (Italy)', 'wp-slimstat' ),
				'l-ja' => __( 'Japanese', 'wp-slimstat' ),
				'l-ja-jp' => __( 'Japanese (Japan)', 'wp-slimstat' ),
				'l-ka' => __( 'Georgian', 'wp-slimstat' ),
				'l-ka-ge' => __( 'Georgian (Georgia)', 'wp-slimstat' ),
				'l-kk' => __( 'Kazakh', 'wp-slimstat' ),
				'l-kk-kz' => __( 'Kazakh (Kazakhstan)', 'wp-slimstat' ),
				'l-kn' => __( 'Kannada', 'wp-slimstat' ),
				'l-kn-in' => __( 'Kannada (India)', 'wp-slimstat' ),
				'l-ko' => __( 'Korean', 'wp-slimstat' ),
				'l-ko-kr' => __( 'Korean (Korea)', 'wp-slimstat' ),
				'l-kok' => __( 'Konkani', 'wp-slimstat' ),
				'l-kok-in' => __( 'Konkani (India)', 'wp-slimstat' ),
				'l-ky' => __( 'Kyrgyz', 'wp-slimstat' ),
				'l-ky-kg' => __( 'Kyrgyz (Kyrgyzstan)', 'wp-slimstat' ),
				'l-lt' => __( 'Lithuanian', 'wp-slimstat' ),
				'l-lt-lt' => __( 'Lithuanian (Lithuania)', 'wp-slimstat' ),
				'l-lv' => __( 'Latvian', 'wp-slimstat' ),
				'l-lv-lv' => __( 'Latvian (Latvia)', 'wp-slimstat' ),
				'l-mi' => __( 'Maori', 'wp-slimstat' ),
				'l-mi-nz' => __( 'Maori (New Zealand)', 'wp-slimstat' ),
				'l-mk' => __( 'FYRO Macedonian', 'wp-slimstat' ),
				'l-mk-ml' => __( 'FYRO Macedonian (Former Yugoslav Republic of Macedonia)', 'wp-slimstat' ),
				'l-mn' => __( 'Mongolian', 'wp-slimstat' ),
				'l-mn-mn' => __( 'Mongolian (Mongolia)', 'wp-slimstat' ),
				'l-mr' => __( 'Marathi', 'wp-slimstat' ),
				'l-mr-in' => __( 'Marathi (India)', 'wp-slimstat' ),
				'l-ms' => __( 'Malay', 'wp-slimstat' ),
				'l-ms-bn' => __( 'Malay (Brunei Darussalam)', 'wp-slimstat' ),
				'l-ms-my' => __( 'Malay (Malaysia)', 'wp-slimstat' ),
				'l-mt' => __( 'Maltese', 'wp-slimstat' ),
				'l-mt-mt' => __( 'Maltese (Malta)', 'wp-slimstat' ),
				'l-nb' => __( 'Norwegian (Bokmål)', 'wp-slimstat' ),
				'l-nb-no' => __( 'Norwegian (Bokmål) (Norway)', 'wp-slimstat' ),
				'l-nl' => __( 'Dutch', 'wp-slimstat' ),
				'l-nl-be' => __( 'Dutch (Belgium)', 'wp-slimstat' ),
				'l-nl-nl' => __( 'Dutch (Netherlands)', 'wp-slimstat' ),
				'l-nn-no' => __( 'Norwegian (Nynorsk) (Norway)', 'wp-slimstat' ),
				'l-ns' => __( 'Northern Sotho', 'wp-slimstat' ),
				'l-ns-za' => __( 'Northern Sotho (South Africa)', 'wp-slimstat' ),
				'l-pa' => __( 'Punjabi', 'wp-slimstat' ),
				'l-pa-in' => __( 'Punjabi (India)', 'wp-slimstat' ),
				'l-pl' => __( 'Polish', 'wp-slimstat' ),
				'l-pl-pl' => __( 'Polish (Poland)', 'wp-slimstat' ),
				'l-ps' => __( 'Pashto', 'wp-slimstat' ),
				'l-ps-ar' => __( 'Pashto (Afghanistan)', 'wp-slimstat' ),
				'l-pt' => __( 'Portuguese', 'wp-slimstat' ),
				'l-pt-br' => __( 'Portuguese (Brazil)', 'wp-slimstat' ),
				'l-pt-pt' => __( 'Portuguese (Portugal)', 'wp-slimstat' ),
				'l-qu' => __( 'Quechua', 'wp-slimstat' ),
				'l-qu-bo' => __( 'Quechua (Bolivia)', 'wp-slimstat' ),
				'l-qu-ec' => __( 'Quechua (Ecuador)', 'wp-slimstat' ),
				'l-qu-pe' => __( 'Quechua (Peru)', 'wp-slimstat' ),
				'l-ro' => __( 'Romanian', 'wp-slimstat' ),
				'l-ro-ro' => __( 'Romanian (Romania)', 'wp-slimstat' ),
				'l-ru' => __( 'Russian', 'wp-slimstat' ),
				'l-ru-ru' => __( 'Russian (Russia)', 'wp-slimstat' ),
				'l-sa' => __( 'Sanskrit', 'wp-slimstat' ),
				'l-sa-in' => __( 'Sanskrit (India)', 'wp-slimstat' ),
				'l-se' => __( 'Sami (Northern)', 'wp-slimstat' ),
				'l-se-fi' => __( 'Sami (Northern) (Finland)', 'wp-slimstat' ),
				'l-se-no' => __( 'Sami (Northern) (Norway)', 'wp-slimstat' ),
				'l-se-se' => __( 'Sami (Northern) (Sweden)', 'wp-slimstat' ),
				'l-sk' => __( 'Slovak', 'wp-slimstat' ),
				'l-sk-sk' => __( 'Slovak (Slovakia)', 'wp-slimstat' ),
				'l-sl' => __( 'Slovenian', 'wp-slimstat' ),
				'l-sl-si' => __( 'Slovenian (Slovenia)', 'wp-slimstat' ),
				'l-sq' => __( 'Albanian', 'wp-slimstat' ),
				'l-sq-al' => __( 'Albanian (Albania)', 'wp-slimstat' ),
				'l-sr-ba' => __( 'Serbian (Latin) (Bosnia and Herzegovina)', 'wp-slimstat' ),
				'l-sr-rs' => __( 'Serbian (Serbia and Montenegro)', 'wp-slimstat' ),
				'l-sr-sp' => __( 'Serbian (Latin) (Serbia and Montenegro)', 'wp-slimstat' ),
				'l-sv' => __( 'Swedish', 'wp-slimstat' ),
				'l-sv-fi' => __( 'Swedish (Finland)', 'wp-slimstat' ),
				'l-sv-se' => __( 'Swedish (Sweden)', 'wp-slimstat' ),
				'l-sw' => __( 'Swahili', 'wp-slimstat' ),
				'l-sw-ke' => __( 'Swahili (Kenya)', 'wp-slimstat' ),
				'l-ta' => __( 'Tamil', 'wp-slimstat' ),
				'l-ta-in' => __( 'Tamil (India)', 'wp-slimstat' ),
				'l-te' => __( 'Telugu', 'wp-slimstat' ),
				'l-te-in' => __( 'Telugu (India)', 'wp-slimstat' ),
				'l-th' => __( 'Thai', 'wp-slimstat' ),
				'l-th-th' => __( 'Thai (Thailand)', 'wp-slimstat' ),
				'l-tl' => __( 'Tagalog', 'wp-slimstat' ),
				'l-tl-ph' => __( 'Tagalog (Philippines)', 'wp-slimstat' ),
				'l-tn' => __( 'Tswana', 'wp-slimstat' ),
				'l-tn-za' => __( 'Tswana (South Africa)', 'wp-slimstat' ),
				'l-tr' => __( 'Turkish', 'wp-slimstat' ),
				'l-tr-tr' => __( 'Turkish (Turkey)', 'wp-slimstat' ),
				'l-tt' => __( 'Tatar', 'wp-slimstat' ),
				'l-tt-ru' => __( 'Tatar (Russia)', 'wp-slimstat' ),
				'l-ts' => __( 'Tsonga', 'wp-slimstat' ),
				'l-uk' => __( 'Ukrainian', 'wp-slimstat' ),
				'l-uk-ua' => __( 'Ukrainian (Ukraine)', 'wp-slimstat' ),
				'l-ur' => __( 'Urdu', 'wp-slimstat' ),
				'l-ur-pk' => __( 'Urdu (Islamic Republic of Pakistan)', 'wp-slimstat' ),
				'l-uz' => __( 'Uzbek (Latin)', 'wp-slimstat' ),
				'l-uz-uz' => __( 'Uzbek (Cyrillic) (Uzbekistan)', 'wp-slimstat' ),
				'l-vi' => __( 'Vietnamese', 'wp-slimstat' ),
				'l-vi-vn' => __( 'Vietnamese (Viet Nam)', 'wp-slimstat' ),
				'l-xh' => __( 'Xhosa', 'wp-slimstat' ),
				'l-xh-za' => __( 'Xhosa (South Africa)', 'wp-slimstat' ),
				'l-zh' => __( 'Chinese', 'wp-slimstat' ),
				'l-zh-cn' => __( 'Chinese (S)', 'wp-slimstat' ),
				'l-zh-hk' => __( 'Chinese (Hong Kong)', 'wp-slimstat' ),
				'l-zh-mo' => __( 'Chinese (Macau)', 'wp-slimstat' ),
				'l-zh-sg' => __( 'Chinese (Singapore)', 'wp-slimstat' ),
				'l-zh-tw' => __( 'Chinese (T)', 'wp-slimstat' ),
				'l-zu' => __( 'Zulu', 'wp-slimstat' ),
				'l-zu-za' => __( 'Zulu (South Africa)', 'wp-slimstat' ),

				// Operating Systems
				'aix' => __( 'IBM AIX', 'wp-slimstat' ),
				'amiga' => __( 'Amiga', 'wp-slimstat' ),
				'android' => __( 'Android', 'wp-slimstat' ),
				'beos' => __( 'BeOS', 'wp-slimstat' ),
				'blackberry os' => __( 'BlackBerry OS', 'wp-slimstat' ),
				'centos' => __( 'CentOS', 'wp-slimstat' ),
				'chromeos' => __( 'ChromeOS', 'wp-slimstat' ),
				'commodore64' => __( 'Commodore 64', 'wp-slimstat' ),
				'cygwin' => __( 'Cygwin', 'wp-slimstat' ),
				'debian' => __( 'Debian', 'wp-slimstat' ),
				'digital unix' => __( 'Digital Unix', 'wp-slimstat' ),
				'fedora' => __( 'Fedora', 'wp-slimstat' ),
				'firefoxos' => __( 'Firefox OS', 'wp-slimstat' ),
				'freebsd' => __( 'FreeBSD', 'wp-slimstat' ),
				'gentoo' => __( 'Gentoo', 'wp-slimstat' ),
				'hp-ux' => __( 'HP-UX', 'wp-slimstat' ),
				'ios' => __( 'iPhone OS', 'wp-slimstat' ),
				'iphone os' => __( 'iPhone OS', 'wp-slimstat' ),
				'iphone osx' => __( 'iPhone OS X', 'wp-slimstat' ),
				'irix' => __( 'SGI / IRIX', 'wp-slimstat' ),
				'java' => __( 'Java', 'wp-slimstat' ),
				'kanotix' => __( 'Kanotix Linux', 'wp-slimstat' ),
				'knoppix' => __( 'Knoppix Linux', 'wp-slimstat' ),
				'linux' => __( 'Linux Generic', 'wp-slimstat' ),
				'mac' => __( 'Mac', 'wp-slimstat' ),
				'mac68k' => __( 'Mac 68k', 'wp-slimstat' ),
				'macos' => __( 'Mac OS X', 'wp-slimstat' ),
				'macosx' => __( 'Mac OS X', 'wp-slimstat' ),
				'macppc' => __( 'Mac PowerPC', 'wp-slimstat' ),
				'mandrake' => __( 'Mandrake Linux', 'wp-slimstat' ),
				'mandriva' => __( 'MS-DOS', 'wp-slimstat' ),
				'mepis' => __( 'MEPIS Linux', 'wp-slimstat' ),
				'ms-dos' => __( 'MS-DOS', 'wp-slimstat' ),
				'netbsd' => __( 'NetBSD', 'wp-slimstat' ),
				'nintendo' => __( 'Nintendo', 'wp-slimstat' ),
				'openbsd' => __( 'OpenBSD', 'wp-slimstat' ),
				'openvms' => __( 'OpenVMS', 'wp-slimstat' ),
				'os/2' => __( 'IBM OS/2', 'wp-slimstat' ),
				'palm' => __( 'Palm OS', 'wp-slimstat' ),
				'palmos' => __( 'Palm OS', 'wp-slimstat' ),
				'pclinuxos' => __( 'PCLinux OS', 'wp-slimstat' ),
				'playstation' => __( 'Playstation', 'wp-slimstat' ),
				'powertv' => __( 'PowerTV', 'wp-slimstat' ),
				'redhat' => __( 'RedHat Linux', 'wp-slimstat' ),
				'rim os' => __( 'Blackberry', 'wp-slimstat' ),
				'risc os' => __( 'Risc OS', 'wp-slimstat' ),
				'slackware' => __( 'Slackware Linux', 'wp-slimstat' ),
				'solaris' => __( 'Solaris', 'wp-slimstat' ),
				'sunos' => __( 'Sun OS', 'wp-slimstat' ),
				'suse' => __( 'SuSE Linux', 'wp-slimstat' ),
				'symbianos' => __( 'Symbian OS', 'wp-slimstat' ),
				'ubuntu' => __( 'Ubuntu', 'wp-slimstat' ),
				'unix' => __( 'Unix', 'wp-slimstat' ),
				'unknown' => __( 'Unknown', 'wp-slimstat' ),
				'xandros' => __( 'Xandros Linux', 'wp-slimstat' ),
				'wap' => __( 'WAP', 'wp-slimstat' ),
				'webos' => __( 'WebOS', 'wp-slimstat' ),
				'win10' => __( 'Windows 10', 'wp-slimstat' ),
				'win16' => __( 'Windows 16-bit', 'wp-slimstat' ),
				'win2000' => __( 'Windows 2000', 'wp-slimstat' ),
				'win2003' => __( 'Windows 2003', 'wp-slimstat' ),
				'win31' => __( 'Windows 3.1', 'wp-slimstat' ),
				'win32' => __( 'Windows 32-bit', 'wp-slimstat' ),
				'win7' => __( 'Windows 7', 'wp-slimstat' ),
				'win7' => __( 'Windows 7', 'wp-slimstat' ),
				'win8' => __( 'Windows 8', 'wp-slimstat' ),
				'win8.1' => __( 'Windows 8.1', 'wp-slimstat' ),
				'win95' => __( 'Windows 95', 'wp-slimstat' ),
				'win98' => __( 'Windows 98', 'wp-slimstat' ),
				'wince' => __( 'Windows CE', 'wp-slimstat' ),
				'winme' => __( 'Windows ME', 'wp-slimstat' ),
				'winnt' => __( 'Windows NT', 'wp-slimstat' ),
				'winphone7' => __( 'Windows Phone', 'wp-slimstat' ),
				'winphone7.5' => __( 'Windows Phone', 'wp-slimstat' ),
				'winphone8' => __( 'Windows Phone', 'wp-slimstat' ),
				'winphone8.1' => __( 'Windows RT / Runtime', 'wp-slimstat' ),
				'winrt' => __( 'Windows Phone', 'wp-slimstat' ),
				'winvista' => __( 'Windows Vista', 'wp-slimstat' ),
				'winxp' => __( 'Windows XP', 'wp-slimstat' ),
				'wyderos' => __( 'WyderOS', 'wp-slimstat' ),
				'zaurus' => __( 'Zaurus WAP', 'wp-slimstat' ),

				// Operating System Families
				'p-unk' => __( 'Unknown', 'wp-slimstat' ),
				'p-' => __( 'Unknown', 'wp-slimstat' ),

				'p-and' => __( 'Android', 'wp-slimstat' ),
				'p-bla' => __( 'BlackBerry', 'wp-slimstat' ),
				'p-chr' => __( 'Chrome OS', 'wp-slimstat' ),
				'p-fir' => __( 'Fire OS', 'wp-slimstat' ),
				'p-fre' => __( 'Linux FreeBSD', 'wp-slimstat' ),
				'p-ios' => __( 'Apple iOS', 'wp-slimstat' ),
				'p-jav' => __( 'Java-based OS', 'wp-slimstat' ),
				'p-lin' => __( 'Linux', 'wp-slimstat' ),
				'p-mac' => __( 'Apple', 'wp-slimstat' ),
				'p-rim' => __( 'Blackberry', 'wp-slimstat' ),
				'p-sym' => __( 'Symbian OS', 'wp-slimstat' ),
				'p-ubu' => __( 'Linux', 'wp-slimstat' ),
				'p-win' => __( 'Microsoft', 'wp-slimstat' ),

				// Tracker Errors
				'e-101' => __( 'Invalid data signature. Try clearing your WordPress cache.', 'wp-slimstat' ),
				'e-102' => __( 'Invalid content type signature. Try clearing your WordPress cache.', 'wp-slimstat' ),
				'e-103' => __( 'Invalid content type format. Try clearing your WordPress cache.', 'wp-slimstat' ),
				'e-200' => __( 'Unspecified error while attempting to add a new record to the table', 'wp-slimstat' ),
				'e-201' => __( 'Malformed referrer URL', 'wp-slimstat' ),
				'e-202' => __( 'Pageview not tracked because the IP address format was invalid.', 'wp-slimstat' ),
				'e-203' => __( 'Malformed resource URL', 'wp-slimstat' ),
				'e-204' => __( 'Tracking is turned off, but it looks like the client-side code is still attached to your pages. Do you have a caching tool enabled?', 'wp-slimstat' ),
				'e-205' => __( 'Invalid MaxMind data file. Please <a target="_blank" href="https://slimstat.freshdesk.com/support/solutions/articles/12000039798-how-to-manually-install-the-maxmind-geolocation-data-file-">follow these steps</a> to download it manually.', 'wp-slimstat' ),
			);

			// set_transient( 'slimstat_dynamic_strings', self::$dynamic_strings, 86400 );
		}
	}

	public static function get_country_codes() {
		if ( empty( self::$dynamic_strings ) ) {
			self::init_dynamic_strings();
		}

		$country_codes = array();
		foreach ( array_keys( self::$dynamic_strings ) as $a_code ) {
			if ( strpos( $a_code, 'c-', 0 ) !== false && strlen( $a_code ) > 2 && $a_code != 'c-xx' && $a_code != 'c-xy' ) {
				$country_codes[ strtolower( str_replace( 'c-', '', $a_code ) ) ] = self::$dynamic_strings[ $a_code ];
			}
		}

		return $country_codes;
	}

	public static function get_string( $_code = '' ) {
		if ( empty( self::$dynamic_strings ) ) {
			self::init_dynamic_strings();
		}

		if ( !isset( self::$dynamic_strings[ $_code ] ) ) {
			return $_code;
		}

		return self::$dynamic_strings[ $_code ];
	}
}