<?php
/*
Plugin Name: WP SlimStat
Plugin URI: http://wordpress.org/extend/plugins/wp-slimstat/
Description: A powerful real-time web analytics plugin for Wordpress.
Version: 2.8
Author: Camu
Author URI: http://www.duechiacchiere.it/
*/

// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME']){
  header('Location: /');
  exit;
}

class wp_slimstat{

	/**
	 * Constructor -- Sets things up.
	 */
	public function __construct(){
		global $wpdb;

		// Current version 
		$this->version = '2.8';

		// Let's keep track of transaction IDs
		$this->tid = 0;
		if (($this->options = get_option('slimstat_options', array())) == array() && function_exists('is_network_admin') && !is_network_admin()){
			$this->options = array(
				// Version is useful to see if we need to upgrade the database
				'version' => 0,

				// We need a secret key to make sure the js-php interaction is secure
				'secret' => get_option('slimstat_secret', md5(time())),

				// You can activate or deactivate tracking, but still be able to view reports
				'is_tracking' => get_option('slimstat_is_tracking', 'yes'),

				// Track screen resolutions, outbound links and other stuff using a javascript component
				'enable_javascript' => get_option('slimstat_enable_javascript', 'yes'),

				// Custom path to get to wp-slimstat-js.php
				'custom_js_path' => get_option('siteurl').'/wp-content/plugins/wp-slimstat',

				// Enable Browscap's autoupdate capability
				'browscap_autoupdate' => get_option('slimstat_browscap_autoupdate', 'no'),

				// Tracks logged in users, adding their login to the resource they requested
				'track_users' => get_option('slimstat_track_users', 'yes'),

				// Automatically purge stats db after x days (0 = no purge)
				'auto_purge' => get_option('slimstat_auto_purge', '120'),

				// Shows a new column to the Edit Posts page with the number of hits per post
				'add_posts_column' => get_option('slimstat_add_posts_column', 'no'),

				// Use a separate menu for the admin interface
				'use_separate_menu' => get_option('slimstat_use_separate_menu', 'no'),

				// Activate or deactivate the conversion of ip addresses into hostnames
				'convert_ip_addresses' => get_option('slimstat_convert_ip_addresses', 'no'),

				// Specify what number format to use (European or American)
				'use_european_separators' => get_option('slimstat_use_european_separators', 'yes'),

				// Number of rows to show in each module
				'rows_to_show' => get_option('slimstat_rows_to_show', '20'),

				// Number of rows to show in the Raw Data panel
				'number_results_raw_data' => get_option('slimstat_number_results_raw_data', '50'),

				// Customize the IP Lookup service (geolocation) URL
				'ip_lookup_service' => get_option('slimstat_ip_lookup_service', 'http://www.maxmind.com/app/lookup_city?ips='),

				// Refresh the RAW DATA view every X seconds
				'refresh_interval' => get_option('slimstat_refresh_interval', '0'),

				// Don't ignore bots and spiders by default
				'ignore_bots' => get_option('slimstat_ignore_bots', 'no'),

				// Ignore spammers?
				'ignore_spammers' => get_option('slimstat_ignore_spammers', 'no'),

				// Ignore Link Prefetching?
				'ignore_prefetch' => get_option('slimstat_ignore_prefetch', 'no'),

				// Ignore requests that have the same information and are less than x seconds far from each other
				'ignore_interval' => get_option('slimstat_ignore_interval', '30'),

				// List of IPs to ignore
				'ignore_ip' => get_option('slimstat_ignore_ip', array()),

				// List of Countries to ignore
				'ignore_countries' => get_option('slimstat_ignore_countries', array()),

				// List of local resources to ignore
				'ignore_resources' => get_option('slimstat_ignore_resources', array()),

				// List of domains to ignore
				'ignore_referers' => get_option('slimstat_ignore_referers', array()),

				// List of browsers to ignore
				'ignore_browsers' => get_option('slimstat_ignore_browsers', array()),

				// List of users to ignore
				'ignore_users' => get_option('slimstat_ignore_users', array()),

				// List of users who can view the stats: if empty, all users are allowed
				'can_view' => get_option('slimstat_can_view', array()),

				// List of capabilities needed to view the stats: if empty, all users are allowed
				'capability_can_view' => get_option('slimstat_capability_can_view', 'read'),

				// List of users who can administer this plugin's options: if empty, all users are allowed
				'can_admin' => get_option('slimstat_can_admin', array())
			);

			// Save these default options in the database
			add_option('slimstat_options', $this->options, '', 'no');
		}

		if (!is_admin()){
			// Is tracking active?
			if ($this->options['is_tracking'] == 'yes'){
				add_action('wp', array(&$this, 'slimtrack'), 5);
			}

			// WP SlimStat tracks screen resolutions, outbound links and other stuff using some javascript custom code
			if (($this->options['enable_javascript'] == 'yes') && ($this->options['is_tracking'] == 'yes')){
				add_action('init', array(&$this, 'wp_slimstat_register_tracking_script'));
				add_action('wp_footer', array(&$this, 'wp_slimstat_js_data'), 10);
			}
		}
		// Initialization routine should be executed on activation
		else{
			register_activation_hook(__FILE__, array(&$this, 'activate'));
			register_deactivation_hook(__FILE__, array(&$this, 'deactivate'));

			// Hook for WPMU - New blog created
			add_action('wpmu_new_blog', array(&$this, 'new_blog'), 10, 1);

			// Show the activation and config links, if the network is not too large
			add_filter('plugin_action_links', array(&$this, 'plugin_action_links'), 10, 2);

			if (function_exists('is_network_admin') && !is_network_admin()){
				// Add the appropriate entries to the admin menu, if this user can view/admin WP SlimStats
				add_action('admin_menu', array(&$this, 'wp_slimstat_add_view_menu'));
				add_action('admin_menu', array(&$this, 'wp_slimstat_add_config_menu'));

				// Display the column in the Edit Posts screen
				if ($this->options['add_posts_column'] == 'yes'){
					add_filter('manage_posts_columns', array(&$this, 'add_column_header'));
					add_action('manage_posts_custom_column', array(&$this, 'add_post_column'));
				}

				// Add some custom stylesheets
				add_action('admin_print_styles-wp-slimstat/options/index.php', array(&$this, 'wp_slimstat_stylesheet'));
				add_action('admin_print_styles-edit.php', array(&$this, 'wp_slimstat_stylesheet'));
				
				// Update the table structure and options, if needed
				if (!isset($this->options['version']) || $this->options['version'] != $this->version){
					$this->_update_tables_and_options();
				}
			}
		}

		// Add a link to the admin bar
		add_action('admin_bar_menu', array(&$this, 'wp_slimstat_adminbar'), 100);

		// Create a hook to use with the daily cron job
		add_action('wp_slimstat_purge', array(&$this, 'wp_slimstat_purge'));
	}
	// end __construct

	/**
	 * Support for WP MU network activations (experimental)
	 */
	public function activate(){
		global $wpdb;

		if (function_exists('is_multisite') && is_multisite() && isset($_GET['networkwide']) && ($_GET['networkwide'] == 1)){
			$blogids = $wpdb->get_col($wpdb->prepare("
				SELECT blog_id
				FROM $wpdb->blogs
				WHERE site_id = %d
				AND deleted = 0
				AND spam = 0", $wpdb->siteid));

			foreach ($blogids as $blog_id) {
				switch_to_blog($blog_id);
				$this->_activate();
			}
			restore_current_blog();
		}
		else{
			$this->_activate();
		}
	}
	// end activate

	/**
	 * Support for WP MU network activations (experimental)
	 */
	public function new_blog($_blog_id){
		switch_to_blog($_blog_id);
		$this->_activate();
		restore_current_blog();
	}
	// end new_blog

	/**
	 * Creates and populates tables, if they aren't already there.
	 */
	protected function _activate(){
		global $wpdb;

		// Is InnoDB available?
		$have_innodb = $wpdb->get_results("SHOW VARIABLES LIKE 'have_innodb'", ARRAY_A);
		$use_innodb = '';
		if ($have_innodb[0]['Value'] == 'YES') $use_innodb = ' ENGINE=InnoDB';

		// Table that stores the actual data about visits
		$stats_table_sql =
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}slim_stats (
				id INT UNSIGNED NOT NULL auto_increment,
				ip INT UNSIGNED DEFAULT 0,
				other_ip INT UNSIGNED DEFAULT 0,
				user VARCHAR(255) DEFAULT '',
				language VARCHAR(5) DEFAULT '',
				country VARCHAR(2) DEFAULT '',
				domain VARCHAR(255) DEFAULT '',
				referer VARCHAR(2048) DEFAULT '',
				searchterms VARCHAR(2048) DEFAULT '',
				resource VARCHAR(2048) DEFAULT '',
				browser_id SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				screenres_id SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				plugins VARCHAR(255) DEFAULT '',
				visit_id INT UNSIGNED NOT NULL DEFAULT 0,
				dt INT(10) UNSIGNED DEFAULT 0,
				PRIMARY KEY id (id)
			)$use_innodb";

		// Information about Countries
		$country_table_sql =
			"CREATE TABLE IF NOT EXISTS {$wpdb->base_prefix}slim_countries (
				ip_from INT UNSIGNED DEFAULT 0,
				ip_to INT UNSIGNED DEFAULT 0,
				country_code CHAR(2) DEFAULT '',
				CONSTRAINT ip_from_idx PRIMARY KEY (ip_from, ip_to)
			)$use_innodb";

		// A lookup table for browsers can help save some space
		$browsers_table_sql =
			"CREATE TABLE IF NOT EXISTS {$wpdb->base_prefix}slim_browsers (
				browser_id SMALLINT UNSIGNED NOT NULL auto_increment,
				browser VARCHAR(40) DEFAULT '',
				version VARCHAR(15) DEFAULT '',
				platform VARCHAR(15) DEFAULT '',
				css_version VARCHAR(5) DEFAULT '',
				type TINYINT UNSIGNED DEFAULT 0,
				PRIMARY KEY (browser_id)
			)$use_innodb";

		// A lookup table for screen resolutions can help save some space, too
		$screen_res_table_sql =
			"CREATE TABLE IF NOT EXISTS {$wpdb->base_prefix}slim_screenres (
				screenres_id SMALLINT UNSIGNED NOT NULL auto_increment,
				resolution VARCHAR(12) DEFAULT '',
				colordepth VARCHAR(5) DEFAULT '',
				antialias BOOL DEFAULT FALSE,
				PRIMARY KEY (screenres_id)
			)$use_innodb";

		// This table will track outbound links (clicks on links to external sites)
		$outbound_table_sql =
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}slim_outbound (
				outbound_id INT UNSIGNED NOT NULL auto_increment,
				resource VARCHAR(2048) DEFAULT '',
				outbound_domain VARCHAR(255) DEFAULT '',
				outbound_resource VARCHAR(2048) DEFAULT '',
				type TINYINT UNSIGNED DEFAULT 0,
				notes VARCHAR(512) DEFAULT '',
				id INT UNSIGNED NOT NULL DEFAULT 0,
				dt INT(10) UNSIGNED DEFAULT 0,
				PRIMARY KEY (outbound_id)
			)$use_innodb";

		// Ok, let's create the table structure
		if ($this->_create_table($country_table_sql, $wpdb->base_prefix.'slim_countries')){
			$this->_import_countries();
		}

		$this->_create_table($browsers_table_sql, $wpdb->base_prefix.'slim_browsers');
		$this->_create_table($screen_res_table_sql, $wpdb->base_prefix.'slim_screenres');
		$this->_create_table($outbound_table_sql, $wpdb->prefix.'slim_outbound');
		$this->_create_table($stats_table_sql, $wpdb->prefix.'slim_stats');

		// Schedule the autopurge hook
		if (!wp_next_scheduled('wp_slimstat_purge'))
			wp_schedule_event('1262311200', 'daily', 'wp_slimstat_purge');
	}
	// end _activate

	/**
	 * Performs some clean-up maintenance (disable cron job).
	 */
	public function deactivate(){
		global $wpdb;

		// Unschedule the autopurge hook
		if (function_exists('is_multisite') && is_multisite() && isset($_GET['networkwide']) && ($_GET['networkwide'] == 1)){
			$blogids = $wpdb->get_col($wpdb->prepare("
				SELECT blog_id
				FROM $wpdb->blogs
				WHERE site_id = %d
				AND deleted = 0
				AND spam = 0", $wpdb->siteid));

			foreach ($blogids as $blog_id) {
				switch_to_blog($blog_id);
				wp_clear_scheduled_hook('wp_slimstat_purge');
			}
			restore_current_blog();
		}
		else{
			wp_clear_scheduled_hook('wp_slimstat_purge');
		}
	}
	// end deactivate

	/**
	 * Core tracking function
	 */
	public function slimtrack($_argument = ''){
		global $wpdb;

		// Do not track admin pages
		if ( is_admin() ||
				strpos($_SERVER['PHP_SELF'], 'wp-content/') !== FALSE ||
				strpos($_SERVER['PHP_SELF'], 'wp-cron.php') !== FALSE ||
				strpos($_SERVER['PHP_SELF'], 'xmlrpc.php') !== FALSE ||
				strpos($_SERVER['PHP_SELF'], 'wp-login.php') !== FALSE ||
				strpos($_SERVER['PHP_SELF'], 'wp-comments-post.php') !== FALSE ) return $_argument;

		$stat = array();
		// User's IP address
		list($long_user_ip, $long_other_ip) = $this->_get_ip2long_remote_ip();

		// Should we ignore this user?
		if (is_user_logged_in()){
			global $current_user;
			if (in_array($current_user->user_login, $this->options['ignore_users'])) return $_argument;

			// Don't track logged-in users, if the corresponding option is enabled
			if ($this->options['track_users'] == 'no' && !empty($current_user->user_login)) return $_argument;

			// Track commenters and logged-in users
			if (!empty($current_user->user_login))
				$stat['user'] = $current_user->user_login;

			$not_spam = true;
		}
		elseif (isset($_COOKIE['comment_author_'.COOKIEHASH])){
			// Is this a spammer?
			$spam_comment = $wpdb->get_row("SELECT comment_author, COUNT(*) comment_count FROM {$wpdb->prefix}comments WHERE INET_ATON(comment_author_IP) = '$long_user_ip' AND comment_approved = 'spam' GROUP BY comment_author LIMIT 0,1", ARRAY_A);
			if (isset($spam_comment['comment_count']) && $spam_comment['comment_count'] > 0){
				if ($this->options['ignore_spammers'] == 'yes')
					return $_argument;
				else
					$stat['user'] .= "[spam] {$spam_comment['comment_author']}";
			}
			else
				$stat['user'] = $_COOKIE['comment_author_'.COOKIEHASH];
		}

		// Should we ignore this IP address?
		foreach($this->options['ignore_ip'] as $a_ip_range){
			list ($ip_to_ignore, $mask) = @explode("/", trim($a_ip_range));
			if (empty($mask)) $mask = 32;
			$long_ip_to_ignore = ip2long($ip_to_ignore);
			$long_mask = bindec( str_pad('', $mask, '1') . str_pad('', 32-$mask, '0') );
			$long_masked_user_ip = $long_user_ip & $long_mask;
			$long_masked_ip_to_ignore = $long_ip_to_ignore & $long_mask;
			if ($long_masked_user_ip == $long_masked_ip_to_ignore)
				return $_argument;
		}

		// Avoid PHP warnings
		$referer = array();
		$stat['ip'] = sprintf("%u", $long_user_ip);
		if (!empty($long_other_ip)) $stat['other_ip'] = sprintf("%u", $long_other_ip);
		$stat['language'] = $this->_get_language();
		$stat['country'] = $this->_get_country($stat['ip']);

		// Country table not initialized
		if ($stat['country'] === false) return $_argument;

		// Is this country blacklisted?
		foreach($this->options['ignore_countries'] as $a_filter)
			if ($stat['country'] == $a_filter) return $_argument;

		if (isset( $_SERVER['HTTP_REFERER'])){
			$referer = @parse_url($_SERVER['HTTP_REFERER']);
			
			// This must be a 'seriously malformed' URL
			if (!$referer)
				$referer = $_SERVER['HTTP_REFERER'];
			else if (isset($referer['host'])){
				$stat['domain'] = $referer['host'];
				$stat['referer'] = $_SERVER['HTTP_REFERER'];

				// Fix Google Images referring domain
				if ((strpos($stat['domain'], 'www.google') !== false) && (strpos($stat['referer'], '/imgres?') !== false))
					$stat['domain'] = str_replace('www.google', 'images.google', $stat['domain']);
			}
		}

		// Is this referer blacklisted?
		if (!empty($stat['referer'])){
			foreach($this->options['ignore_referers'] as $a_filter){
				$pattern = str_replace( array('\*', '\!') , array('(.*)', '.'), preg_quote($a_filter, '/'));
				if (preg_match("/^$pattern$/i", $stat['referer'])) return $_argument;
			}
		}

		// We want to record both hits and searches (performed through the site search form)
		if (empty($_REQUEST['s'])){
			$stat['searchterms'] = $this->_get_search_terms($referer);
			if (isset($_SERVER['REQUEST_URI'])){
				$stat['resource'] = $_SERVER['REQUEST_URI'];
			}
			elseif (isset($_SERVER['SCRIPT_NAME'])){
				$stat['resource'] = isset( $_SERVER['QUERY_STRING'] )?$_SERVER['SCRIPT_NAME']."?".$_SERVER['QUERY_STRING']:$_SERVER['SCRIPT_NAME'];
			}
			else{
				$stat['resource'] = isset( $_SERVER['QUERY_STRING'] )?$_SERVER['PHP_SELF']."?".$_SERVER['QUERY_STRING']:$_SERVER['PHP_SELF'];
			}
		}
		else{
			$stat['searchterms'] = str_replace('\\', '', $_REQUEST['s']);
			$stat['resource'] = ''; // Mark the resource to remember that this is a 'local search'
		}

		// Mark or ignore Firefox/Safari prefetching requests (X-Moz: Prefetch and X-purpose: Preview)
		if ((isset($_SERVER['HTTP_X_MOZ']) && (strtolower($_SERVER['HTTP_X_MOZ']) == 'prefetch')) ||
			(isset($_SERVER["HTTP_X_PURPOSE"]) && (strtolower($_SERVER['HTTP_X_PURPOSE']) == 'preview'))){
			if ($this->options['ignore_prefetch'] == 'yes'){
				return $_argument;
			}
			else{
				$stat['resource'] = '[PRE]'.$stat['resource'];
			}
		}

		// Mark 404 pages
		if (is_404()) $stat['resource'] = '[404]'.$stat['resource'];

		// Is this resource blacklisted?
		if (!empty($stat['resource'])){
			foreach($this->options['ignore_resources'] as $a_filter){
				$pattern = str_replace( array('\*', '\!') , array('(.*)', '.'), preg_quote($a_filter, '/'));
				if (preg_match("/^$pattern$/i", $stat['resource'])) return $_argument;
			}
		}

		// Loads the class to determine the user agent
		require 'browscap.php';

		// Creates a new Browscap object (loads or creates the cache)
		$browscap = new browscap(WP_PLUGIN_DIR.'/wp-slimstat/cache');

		// Do autoupdate?
		$browscap->doAutoUpdate = ($this->options['browscap_autoupdate'] == 'yes');
		if (($browscap->doAutoUpdate) && ((intval(substr(sprintf('%o',fileperms(WP_PLUGIN_DIR.'/wp-slimstat/cache/browscap.ini')), -3)) < 644) || (intval(substr(sprintf('%o',fileperms(WP_PLUGIN_DIR.'/wp-slimstat/cache/cache.php')), -3)) < 644))){
			$browscap->doAutoUpdate = false;
		}

		$browser_details = $browscap->getBrowser($_SERVER['HTTP_USER_AGENT'], true);

		// This User Agent hasn't been recognized, let's try with a heuristic match
		if ($browser_details['Browser'] == 'Default Browser'){
			require 'browscap_heuristic.php';
			$browscap = new browscap_heuristic();
			$browser_details = $browscap->getBrowser($_SERVER['HTTP_USER_AGENT']);
		}

		// This information goes into a separate lookup table
		$browser['browser'] = $browser_details['Browser'];
		$browser['version'] = $browser_details['Version'];
		$browser['platform'] = strtolower($browser_details['Platform']);
		$browser['css_version'] = $browser_details['CssVersion'];
		$browser['type'] = 0;

		// Browser Types: 
		//		0: regular
		//		1: crawler
		//		2: mobile
		//		3: syndication reader
		if ($browser_details['Crawler'] == 'true' || $browser_details['Browser'] == 'Default Browser')
			$browser['type'] = 1;
		elseif ($browser_details['isMobileDevice'] == 'true')
			$browser['type'] = 2;
		elseif ($browser_details['isSyndicationReader'] == 'true')
			$browser['type'] = 3;

		// Is this browser blacklisted?
		foreach($this->options['ignore_browsers'] as $a_filter){
			$pattern = str_replace( array('\*', '\!') , array('(.*)', '.'), preg_quote($a_filter, '/'));
			if (preg_match("/^$pattern$/i", $browser['browser'].'/'.$browser['version'])) return $_argument;
		}

		// Ignore bots?
		if (($this->options['ignore_bots'] == 'yes') && ($browser['type'] == 1)) return $_argument;

		// Is this a returning visitor?
		if (isset($_COOKIE['slimstat_tracking_code'])){
			list($identifier, $control_code) = explode('.', $_COOKIE['slimstat_tracking_code']);
	
			// Make sure only authorized information is recorded
			if ($control_code == md5($identifier.$this->options['secret'])){

				// Set the visit_id for this session's first pageview
				if (strpos($identifier, 'id') !== false){
					// Emulate auto-increment on visit_id
					$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}slim_stats 
													SET visit_id = (
														SELECT max_visit_id FROM ( SELECT MAX(visit_id) AS max_visit_id FROM {$wpdb->prefix}slim_stats ) AS sub_max_visit_id_table
													) + 1
													WHERE id = %d AND visit_id = 0", intval($identifier)));
					$stat['visit_id'] = $wpdb->get_var($wpdb->prepare("SELECT visit_id FROM {$wpdb->prefix}slim_stats WHERE id = %d", intval($identifier)));
					@setcookie('slimstat_tracking_code', "{$stat['visit_id']}.".md5($stat['visit_id'].$this->options['secret']), time()+1800, '/');
				}
				else{
					$stat['visit_id'] = intval($identifier);
				}
			}
		}

		$stat['dt'] = date_i18n('U');

		// Now we insert the new browser in the lookup table, if it doesn't exist
		$insert_new_browser_sql = "INSERT INTO {$wpdb->base_prefix}slim_browsers (browser, version, platform, css_version, type)
			SELECT %s,%s,%s,%s,%d
			FROM DUAL
			WHERE NOT EXISTS ( ";
		$select_sql = "SELECT browser_id
					FROM {$wpdb->base_prefix}slim_browsers
					WHERE browser = %s AND
							version = %s AND
							platform = %s AND
							css_version = %s AND
							type = %d LIMIT 1";

		$insert_new_browser_sql .= $select_sql . ")";

		$wpdb->query($wpdb->prepare($insert_new_browser_sql, array_merge($browser, array_values($browser))));
		$stat['browser_id'] = $wpdb->insert_id;

		// This can happen if the browser already exists in the table
		if (empty($stat['browser_id'])) 
			$stat['browser_id'] = $wpdb->get_var($wpdb->prepare($select_sql, $browser));

		// If the same user visited the same page in the last X seconds, we ignore it
		$ignore_interval = intval($this->options['ignore_interval']);

		$insert_new_hit_sql = "INSERT INTO {$wpdb->prefix}slim_stats ( `" . implode( "`, `", array_keys( $stat ) ) . "` )
			SELECT " . substr(str_repeat('%s,', count($stat)), 0, -1) . "
			FROM DUAL
			WHERE NOT EXISTS ( ";
		$select_sql = "SELECT `id`
				FROM {$wpdb->prefix}slim_stats
				WHERE ";
		foreach ($stat as $a_key => $a_value){
			if ($a_key == 'dt')
				$select_sql .= "(TIME_TO_SEC(TIMEDIFF(FROM_UNIXTIME(%s),FROM_UNIXTIME(`dt`))) < $ignore_interval) AND ";
			else
				$select_sql .= "`$a_key` = %s" . (($a_key != 'browser_id')?" AND ":" LIMIT 1 ");
		}
		$insert_new_hit_sql .= $select_sql . ")";

		$wpdb->query($wpdb->prepare($insert_new_hit_sql, array_merge($stat, array_values($stat))));
		$this->tid = $wpdb->insert_id;

		if (empty($this->tid)) // There's already an entry with the same info, less than x seconds old
			$this->tid = $wpdb->get_var($wpdb->prepare($select_sql, $stat));

		// Is this a new visitor?
		if (!isset($_COOKIE['slimstat_tracking_code']) && !empty($this->tid)){
			// Set a 30-minute cookie to track this visitor (Google and other non-human engines will just ignore it)
			@setcookie('slimstat_tracking_code', "{$this->tid}id.".md5($this->tid.'id'.$this->options['secret']), time()+1800, '/');
		}

		return $_argument;
	}
	// end slimtrack

	/**
	 * Creates a table in the database
	 */
	protected function _create_table($_sql = '', $_tablename = '', $_fail_on_exists = false){
	    global $wpdb;
		if ($_fail_on_exists)
			foreach ($wpdb->get_col("SHOW TABLES LIKE '$_tablename'", 0) as $a_table)
				if ($a_table == $_tablename) return false;

		$wpdb->query($_sql);

		// Let's make sure this table was actually created
		foreach ($wpdb->get_col("SHOW TABLES LIKE '$_tablename'", 0) as $a_table)
			if ($a_table == $_tablename) return true;

		return false;
	}
	// end _create_table

	/**
	 * Updates the table structure, adding new columns and resizing existing ones
	 */
	protected function _update_tables_and_options(){
	    global $wpdb;

		// WP_SLIM_STATS: Let's see if the structure is up-to-date
		$table_structure = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}slim_stats", ARRAY_A);
		$user_exists = $other_ip_exists = false;

		foreach($table_structure as $a_row){
			if ($a_row['Field'] == 'user') $user_exists = true;
			if ($a_row['Field'] == 'other_ip') $other_ip_exists = true;
			if ($a_row['Field'] == 'referer' && $a_row['Type'] == 'varchar(255)'){
				$wpdb->query("ALTER TABLE {$wpdb->prefix}slim_stats MODIFY referer VARCHAR(2048), MODIFY searchterms VARCHAR(2048), MODIFY resource VARCHAR(2048)");
				$wpdb->query("ALTER TABLE {$wpdb->prefix}slim_outbound MODIFY outbound_resource VARCHAR(2048)");
			}
		}
		if (!$user_exists)
			$wpdb->query("ALTER TABLE {$wpdb->prefix}slim_stats ADD COLUMN user VARCHAR(255) DEFAULT '' AFTER ip");
		if (!$other_ip_exists)
			$wpdb->query("ALTER TABLE {$wpdb->prefix}slim_stats ADD COLUMN other_ip INT UNSIGNED DEFAULT 0 AFTER ip");

		// WP_SLIM_BROWSERS: Let's see if the structure is up-to-date
		$table_structure = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->base_prefix}slim_browsers", ARRAY_A);
		$field_exists = false;

		foreach($table_structure as $a_row){
			if ($a_row['Field'] == 'type'){
				$field_exists = true;
				break;
			}
		}
		if (!$field_exists){
			$wpdb->query("ALTER TABLE {$wpdb->base_prefix}slim_browsers ADD COLUMN type TINYINT UNSIGNED DEFAULT 0 AFTER css_version");
	
			// Set the type of existing browsers
			$wpdb->query("UPDATE {$wpdb->base_prefix}slim_browsers SET type = 1 WHERE platform = 'unknown' AND css_version = '0'");
		}

		// WP_SLIM_VISITS: not needed starting from version 2.7
		$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}slim_visits");

		// Options are kept in a single array, starting from version 2.7
		if (get_option('slimstat_secret', 'still there?') != 'still there?'){
			delete_option('slimstat_secret');

			delete_option('slimstat_is_tracking');
			delete_option('slimstat_enable_javascript');
			delete_option('slimstat_custom_js_path');
			delete_option('slimstat_browscap_autoupdate');
			delete_option('slimstat_track_users');
			delete_option('slimstat_auto_purge');
			delete_option('slimstat_add_posts_column');	
			delete_option('slimstat_use_separate_menu');

			delete_option('slimstat_convert_ip_addresses');
			delete_option('slimstat_use_european_separators');
			delete_option('slimstat_rows_to_show');
			delete_option('slimstat_number_results_raw_data');
			delete_option('slimstat_ip_lookup_service');
			delete_option('slimstat_refresh_interval');

			delete_option('slimstat_ignore_bots');
			delete_option('slimstat_ignore_spammers');
			delete_option('slimstat_ignore_prefetch');
			delete_option('slimstat_ignore_interval');	
			delete_option('slimstat_ignore_ip');
			delete_option('slimstat_ignore_countries');
			delete_option('slimstat_ignore_resources');
			delete_option('slimstat_ignore_referers');
			delete_option('slimstat_ignore_browsers');
			delete_option('slimstat_ignore_users');

			delete_option('slimstat_can_view');
			delete_option('slimstat_capability_can_view');
			delete_option('slimstat_can_admin');
			delete_option('slimstat_enable_footer_link');
		}
		
		// WP_SLIM_OUTBOUND: Let's see if the structure is up-to-date
		$table_structure = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}slim_outbound", ARRAY_A);
		$notes_exists = $position_exists = false;

		foreach($table_structure as $a_row){
			if ($a_row['Field'] == 'notes') $notes_exists = true;
			if ($a_row['Field'] == 'position') $position_exists = true;
		}
		if (!$notes_exists){
			$wpdb->query("ALTER TABLE {$wpdb->prefix}slim_outbound ADD COLUMN notes VARCHAR(512) DEFAULT '' AFTER type");
		}
		if (!$position_exists){
			$wpdb->query("ALTER TABLE {$wpdb->prefix}slim_outbound ADD COLUMN position VARCHAR(32) DEFAULT '' AFTER notes");
		}

		// New option 'version' added in version 2.8 - Keep it up-to-date
		if (!isset($this->options['version']) || $this->options['version'] != $this->version){
			$this->options['version'] = 0; // $this->update_option only works if the key already exists in the database
			$this->update_option('version', $this->version, 'string');
		}

		return true;
	}
	// end _update_stats_table

	/**
	 * Reads data from CSV file and copies them into countries table
	 */
	protected function _import_countries(){
		global $wpdb;

		// If there is already a (not empty) country table, skip import
		$country_rows = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->base_prefix}slim_countries", 0);
		if ( $country_rows !== false && $country_rows > 0 ) return false;

		$country_file = "geoip.csv";

		// To avoid problems with SAFE_MODE, we will not use is_file or file_exists, but a loop to scan current directory
		$is_country_file = false;
		$handle = opendir(dirname(__FILE__));
		while (false !== ($filename = readdir($handle))){
			if ($country_file == $filename){
				$is_country_file = true;
				break;
			}
		}
		closedir($handle);

		if (!$is_country_file) return false;

		// Allow plenty of time for this to happen
		@set_time_limit(600);

		// Since the file can be too big, we are not using file_get_contents to not exceed the server's memory limit
		if (!$handle = fopen(WP_PLUGIN_DIR."/wp-slimstat/".$country_file, "r")) return false;

		$row_counter = 0;
		$insert_sql = "INSERT INTO {$wpdb->base_prefix}slim_countries (ip_from, ip_to, country_code) VALUES ";

		while (!feof($handle)){
			$entry = fgets($handle);
			if (empty($entry)) break;
			$entry = str_replace("\n", '', $entry);
			$array_entry = explode(',', $entry);
			$insert_sql .= "('".implode( "','", $array_entry )."'),";
			if ($row_counter == 200) {
				$insert_sql = substr($insert_sql, 0, -1);
				$wpdb->query($insert_sql);
				$row_counter = 0;
				$insert_sql = "INSERT INTO {$wpdb->base_prefix}slim_countries (ip_from, ip_to, country_code) VALUES ";
			}
			else $row_counter++;
		}
		if (!empty($insert_sql) && $insert_sql != "INSERT INTO {$wpdb->base_prefix}slim_countries (ip_from, ip_to, country_code) VALUES "){
			$insert_sql = substr($insert_sql, 0, -1);
			$wpdb->query($insert_sql);
		}
		fclose( $handle );
		return true;
	}
	// end _import_countries

	/**
	 * Searches for country associated to a given IP address
	 */
	protected function _get_country($_ip = ''){
		global $wpdb;

		$sql = "SELECT country_code
					FROM {$wpdb->base_prefix}slim_countries
					WHERE ip_from <= $_ip AND ip_to >= $_ip";

		$country_code = $wpdb->get_var($sql, 0 , 0);

		// Error handling
		$error = mysql_error();
		if (!empty($error)) return false;

		if (!empty($country_code)) return $country_code;

		return 'xx';
	}
	// end _get_country

	/**
	 * Tries to find the user's REAL IP address
	 */
	protected function _get_ip2long_remote_ip(){
		$long_ip = array(0, 0);

		if (isset($_SERVER["REMOTE_ADDR"]) && long2ip($ip2long = ip2long($_SERVER["REMOTE_ADDR"])) == $_SERVER["REMOTE_ADDR"])
			$long_ip[0] = $ip2long;

		if (isset($_SERVER["HTTP_CLIENT_IP"]) && long2ip($long_ip[1] = ip2long($_SERVER["HTTP_CLIENT_IP"])) == $_SERVER["HTTP_CLIENT_IP"])
			return $long_ip;

		if (isset($_SERVER["HTTP_X_FORWARDED_FOR"]))
			foreach (explode(",",$_SERVER["HTTP_X_FORWARDED_FOR"]) as $a_ip){
				if (long2ip($long_ip[1] = ip2long($a_ip)) == $a_ip)
					return $long_ip;
			}

		if (isset($_SERVER["HTTP_X_FORWARDED"]) && long2ip($long_ip[1] = ip2long($_SERVER["HTTP_X_FORWARDED"])) == $_SERVER["HTTP_X_FORWARDED"])
			return $long_ip;

		if (isset($_SERVER["HTTP_FORWARDED_FOR"]) && long2ip($long_ip[1] = ip2long($_SERVER["HTTP_FORWARDED_FOR"])) == $_SERVER["HTTP_FORWARDED_FOR"])
			return $long_ip;

		if (isset($_SERVER["HTTP_FORWARDED"]) && long2ip($long_ip[1] = ip2long($_SERVER["HTTP_FORWARDED"])) == $_SERVER["HTTP_FORWARDED"])
			return $long_ip;

		return $long_ip;
	}
	// end _get_ip2long_remote_ip

	/**
	 * Extracts the accepted language from browser headers
	 */
	protected function _get_language(){
		if(isset($_SERVER["HTTP_ACCEPT_LANGUAGE"])){

			// Capture up to the first delimiter (, found in Safari)
			preg_match("/([^,;]*)/", $_SERVER["HTTP_ACCEPT_LANGUAGE"], $array_languages);

			// Fix some codes, the correct syntax is with minus (-) not underscore (_)
			return str_replace( "_", "-", strtolower( $array_languages[0] ) );
		}
		return 'xx';  // Indeterminable language
	}
	// end _get_language

	/**
	 * Sniffs out referrals from search engines and tries to determine the query string
	 */
	protected function _get_search_terms($_url = array()){
		if(!is_array($_url) || !isset($_url['host']) || !isset($_url['query'])) return '';

		parse_str($_url['query'], $query);
		parse_str("daum=q&eniro=search_word&naver=query&google=q&www.google=as_q&yahoo=p&msn=q&bing=q&aol=query&aol=encquery&lycos=query&ask=q&altavista=q&netscape=query&cnn=query&about=terms&mamma=query&alltheweb=q&voila=rdata&virgilio=qs&live=q&baidu=wd&alice=qs&yandex=text&najdi=q&aol=q&mama=query&seznam=q&search=q&wp=szukaj&onet=qt&szukacz=q&yam=k&pchome=q&kvasir=q&sesam=q&ozu=q&terra=query&mynet=q&ekolay=q&rambler=words", $query_formats);
		preg_match("/(daum|eniro|naver|google|www.google|yahoo|msn|bing|aol|aol|lycos|ask|altavista|netscape|cnn|about|mamma|alltheweb|voila|virgilio|live|baidu|alice|yandex|najdi|aol|mama|seznam|search|wp|onet|szukacz|yam|pchome|kvasir|sesam|ozu|terra|mynet|ekolay|rambler)./", $_url['host'], $matches);

		if (isset($matches[1]) && isset($query[$query_formats[$matches[1]]])){
			// Test for encodings different from UTF-8
			if (!mb_check_encoding($query[$query_formats[$matches[1]]], 'UTF-8'))
				$query[$query_formats[$matches[1]]] = mb_convert_encoding($query[$query_formats[$matches[1]]], 'UTF-8', 'Windows-1251');

			return str_replace('\\', '', trim(urldecode($query[$query_formats[$matches[1]]])));
		}

		// We weren't lucky, but there's still hope
		foreach(array_unique(array_values($query_formats)) as $a_format)
			if (isset($query[$a_format]))
				return str_replace('\\', '', trim(urldecode($query[$a_format])));

		return '';
	}
	// end _get_search_terms

	/**
	 * Counts how many visits are currently recorded in the database
	 */
	protected function _count_records(){
		global $wpdb;
		return intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}slim_stats"));
	}
	// end _count_records

	/**
	 * Removes old entries from the database
	 */
	public function wp_slimstat_purge(){
		global $wpdb;

		if (($autopurge_interval = intval($this->options['auto_purge'])) <= 0) return;

		// Delete old entries
		$wpdb->query("DELETE ts FROM {$wpdb->prefix}slim_stats ts WHERE ts.dt < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL $autopurge_interval DAY))");
		
		// Optimize table
		$wpdb->query("OPTIMIZE TABLE {$wpdb->prefix}slim_stats");
	}
	// end wp_slimstat_purge

	/**
	 * Loads a custom stylesheet file for the administration panels
	 */
	public function wp_slimstat_stylesheet(){
		$stylesheet_url = plugins_url('/css/view.css', __FILE__);
		wp_register_style('wp-slimstat-view', $stylesheet_url);
		wp_enqueue_style('wp-slimstat-view');
	}
	// end wp_slimstat_stylesheet

	public function wp_slimstat_enqueue_scripts(){
		wp_enqueue_script('dashboard');
		wp_enqueue_script('slimstat_flot', plugins_url('/view/flot/jquery.flot.min.js', __FILE__), array('jquery'), '0.7');
		wp_enqueue_script('slimstat_flot_navigate', plugins_url('/view/flot/jquery.flot.navigate.min.js', __FILE__), array('jquery','slimstat_flot'), '0.7');
	}

	/**
	 * Adds a new entry in the admin menu, to view the stats
	 */
	public function wp_slimstat_add_view_menu($_s){
		global $current_user;

		$this->options['capability_can_view'] = empty($this->options['capability_can_view'])?'read':$this->options['capability_can_view'];

		if ((empty($this->options['can_view']) && $this->options['capability_can_view'] == 'read') || in_array($current_user->user_login, $this->options['can_view']) || current_user_can($this->options['capability_can_view'])){
			if ($this->options['use_separate_menu'] == 'yes')
				$new_entry = add_menu_page('SlimStat', 'SlimStat', $this->options['capability_can_view'], 'wp-slimstat', array(&$this, 'wp_slimstat_include_view'), plugins_url('/images/wp-slimstat-menu.png', __FILE__));
			else
				$new_entry = add_dashboard_page('SlimStat', 'SlimStat', $this->options['capability_can_view'], 'wp-slimstat', array(&$this, 'wp_slimstat_include_view'));
		}
		add_action('load-'.$new_entry, array(&$this, 'wp_slimstat_stylesheet'));
		add_action('load-'.$new_entry, array(&$this, 'wp_slimstat_enqueue_scripts'));
		return $_s;
	}
	// end wp_slimstat_add_view_menu

	/**
	 * Adds a new entry in the admin menu, to manage SlimStat options
	 */
	public function wp_slimstat_add_config_menu($_s){
		global $current_user;

		if (empty($this->options['can_admin']) || in_array($current_user->user_login, $this->options['can_admin'])){
			if ($this->options['use_separate_menu'] == 'yes' || !current_user_can('manage_options'))
				add_submenu_page('wp-slimstat', 'Config', 'Config', 'edit_posts', WP_PLUGIN_DIR.'/wp-slimstat/options/index.php');
			else
				add_submenu_page('options-general.php', 'SlimStat', 'SlimStat', 'edit_posts', WP_PLUGIN_DIR.'/wp-slimstat/options/index.php');
		}
		return $_s;
	}
	// end wp_slimstat_add_config_menu

	/**
	 * Includes the appropriate panel to view the stats
	 */
	public function wp_slimstat_include_view(){
		include(WP_PLUGIN_DIR.'/wp-slimstat/view/index.php');
	}
	// end wp_slimstat_include_view

	/**
	 * Removes the activation link if the network is too big
	 */
	public	function plugin_action_links($links, $file){
		if ($file == plugin_basename( dirname(__FILE__).'/wp-slimstat.php' )){
			if (function_exists('get_blog_count') && (get_blog_count() > 50))
				$links = array();
			else if (empty($this->options['can_admin']) || in_array($current_user->user_login, $this->options['can_admin'])){
				if ($this->options['use_separate_menu'] == 'yes' || !current_user_can('manage_options'))
					$links[] = '<a href="admin.php?page=wp-slimstat/options/index.php">Config</a>';
				else
					$links[] = '<a href="options-general.php?page=wp-slimstat/options/index.php">Config</a>';
			}
		}
		return $links;
	}
	// end plugin_action_links

	/**
	 * Enqueues a javascript to track users' screen resolution and other browser-based information
	 */
	public function wp_slimstat_register_tracking_script(){
		wp_register_script('wp_slimstat', plugins_url('/wp-slimstat.js', __FILE__), array(), false, true);
	}
	// end wp_slimstat_javascript

	/**
	 * Adds some javascript data specific to this pageview 
	 */
	public function wp_slimstat_js_data(){
		global $wpdb;
		$intval_tid = intval($this->tid);
		if ($intval_tid > 0){
			$hexval_tid = base_convert($intval_tid, 10, 16);

			echo "<script type='text/javascript'>slimstat_tid='$hexval_tid';";
			echo "slimstat_path='{$this->options['custom_js_path']}';";
			$slimstat_blog_id = (function_exists('is_multisite') && is_multisite())?$wpdb->blogid:1;
			echo "slimstat_blog_id='$slimstat_blog_id';";
			echo 'slimstat_session_id=\''.md5($intval_tid.$this->options['secret']).'\';</script>';
			wp_print_scripts('wp_slimstat');
		}
	}
	// end wp_slimstat_js_data

	/**
	 * Adds a new entry to the Wordpress Toolbar
	 */
	public function wp_slimstat_adminbar(){
		if (!is_super_admin() || !is_admin_bar_showing()) return;
		global $wp_admin_bar, $current_user, $blog_id;
		load_plugin_textdomain('wp-slimstat-view', WP_PLUGIN_DIR .'/wp-slimstat/lang', '/wp-slimstat/lang');

		$this->options['capability_can_view'] = empty($this->options['capability_can_view'])?'read':$this->options['capability_can_view'];

		if ((empty($this->options['can_view']) && $this->options['capability_can_view'] == 'read') || in_array($current_user->user_login, $this->options['can_view']) || current_user_can($this->options['capability_can_view'])){
			$wp_admin_bar->add_menu( array( 'id' => 'slimstat', 'title' => 'SlimStat', 'href' => get_site_url($blog_id, '/wp-admin/index.php?page=wp-slimstat') ) );
			$wp_admin_bar->add_menu( array( 'id' => 'slimstat-panel1', 'title' => __('Dashboard', 'wp-slimstat-view'), 'href' => get_site_url($blog_id, '/wp-admin/index.php?page=wp-slimstat&slimpanel=1'), 'parent' => 'slimstat' ) );
			$wp_admin_bar->add_menu( array( 'id' => 'slimstat-panel2', 'title' => __('Visitors', 'wp-slimstat-view'), 'href' => get_site_url($blog_id, '/wp-admin/index.php?page=wp-slimstat&slimpanel=2'), 'parent' => 'slimstat' ) );
			$wp_admin_bar->add_menu( array( 'id' => 'slimstat-panel3', 'title' => __('Traffic Sources', 'wp-slimstat-view'), 'href' => get_site_url($blog_id, '/wp-admin/index.php?page=wp-slimstat&slimpanel=3'), 'parent' => 'slimstat' ) );
			$wp_admin_bar->add_menu( array( 'id' => 'slimstat-panel4', 'title' => __('Content', 'wp-slimstat-view'), 'href' => get_site_url($blog_id, '/wp-admin/index.php?page=wp-slimstat&slimpanel=4'), 'parent' => 'slimstat' ) );
			$wp_admin_bar->add_menu( array( 'id' => 'slimstat-panel5', 'title' => __('Raw Data', 'wp-slimstat-view'), 'href' => get_site_url($blog_id, '/wp-admin/index.php?page=wp-slimstat&slimpanel=5'), 'parent' => 'slimstat' ) );
			$wp_admin_bar->add_menu( array( 'id' => 'slimstat-panel6', 'title' => __('World Map', 'wp-slimstat-view'), 'href' => get_site_url($blog_id, '/wp-admin/index.php?page=wp-slimstat&slimpanel=6'), 'parent' => 'slimstat' ) );
		}
	}
	// end wp_slimstat_adminbar
	
	/**
	 * Adds a new column header to the Posts panel (to show the number of pageviews for each post)
	 */
	public function add_column_header($_columns){
		$_columns['wp-slimstat'] = "<img src='".plugins_url('/images/stats.gif', __FILE__)."' width='17' height='12' alt='SlimStat' />";
		return $_columns;
	}
	// end add_comment_column_header
	
	/**
	 * Adds a new column to the Posts management panel
	 */
	public function add_post_column($_column_name){
		if ('wp-slimstat' != $_column_name) return;
		global $post, $wpdb;
		$permalink = str_replace(get_bloginfo('url'), '', get_permalink( $post->ID ));
		$count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}slim_stats WHERE resource = %s", $permalink));
		echo "<a href='index.php?page=wp-slimstat&amp;filter=resource&amp;f_operator=equals&amp;f_value=$permalink'>$count</a>";
	}
	// end add_column
	
	public function update_option($_option, $_value, $_type = 'string'){
		if (!isset($_value) || !array_key_exists($_option, $this->options)) return false;

		// Is there anything we need to update?
		if (($_type != 'list' && $this->options[$_option] == $_value) || ($_type == 'list' && implode(',', $this->options[$_option]) == $_value)) return true;

		switch($_type){
			case 'list':
				// Avoid XSS attacks
				$clean_value = preg_replace('/[^a-zA-Z0-9\,\.\/\ \-\?\!=&;_\*]/', '', $_value);
				if (strlen($_value)==0)
					$this->options[$_option] = array();
				else
					$this->options[$_option] = explode(',',$clean_value);
				break;
			case 'yesno':
				if ($_value=='yes' || $_value=='no')
					$this->options[$_option] = $_value;
				break;
			case 'integer':
				$this->options[$_option] = abs(intval($_value));
				break;
			default:
				$this->options[$_option] = strip_tags($_value);
				break;
		}
		return update_option('slimstat_options', $this->options);
	}
} 
// end of class declaration

// Ok, let's dance, Sparky!
$GLOBALS['wp_slimstat'] = new wp_slimstat();