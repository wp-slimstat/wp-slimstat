<?php
/*
Plugin Name: WP SlimStat
Plugin URI: http://lab.duechiacchiere.it/index.php?board=1.0
Description: A simple but powerful web analytics plugin for Wordpress.
Version: 2.3
Author: Camu
Author URI: http://www.duechiacchiere.it/
*/

// Avoid direct access to this piece of code
if (__FILE__ == $_SERVER['SCRIPT_FILENAME'] ) {
  header('Location: /');
  exit;
}

class wp_slimstat {

	/**
	 * Constructor -- Sets things up.
	 */
	public function __construct() {
		global $wpdb;

		// Current version
		$this->version = '2.3';
		
		// We use three of tables to store data about visits
		$this->table_stats = $wpdb->prefix . 'slim_stats';
		$this->table_visits = $wpdb->prefix . 'slim_visits';
		$this->table_outbound = $wpdb->prefix . 'slim_outbound';
		
		// Some tables can be shared among the various installations (wordpress multi-user)
		$this->table_countries = $wpdb->base_prefix . 'slim_countries';
		$this->table_browsers = $wpdb->base_prefix . 'slim_browsers';
		$this->table_screenres = $wpdb->base_prefix . 'slim_screenres';

		// Let's keep track of transaction IDs
		$this->tid = 0;
		
		//$autopurge_interval = intval(get_option('slimstat_auto_purge', 0));
		//echo "DELETE FROM `$this->table_stats` ts WHERE ts.`visit_id` = 0 AND ts.`dt` < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL $autopurge_interval DAY))";
	}
	// end __construct

	/**
	 * Creates and populates tables, if they aren't already there.
	 */
	public function activate() {
		global $wpdb;
		
		// Update table names in case of network activation
		$this->table_stats = $wpdb->prefix . 'slim_stats';
		$this->table_visits = $wpdb->prefix . 'slim_visits';
		$this->table_outbound = $wpdb->prefix . 'slim_outbound';

		$have_innodb = $wpdb->get_results("SHOW VARIABLES LIKE 'have_innodb'", ARRAY_A);
		$use_innodb = '';
		if ($have_innodb[0]['Value'] == 'YES') $use_innodb = ' ENGINE=InnoDB';

		// Table that stores the actual data about visits
		$stats_table_sql =
			"CREATE TABLE IF NOT EXISTS `$this->table_stats` (
				`id` INT UNSIGNED NOT NULL auto_increment,
				`ip` INT UNSIGNED DEFAULT 0,
				`language` VARCHAR(5) DEFAULT '',
				`country` VARCHAR(2) DEFAULT '',
				`domain` VARCHAR(255) DEFAULT '',
				`referer` VARCHAR(255) DEFAULT '',
				`searchterms` VARCHAR(255) DEFAULT '',
				`resource` VARCHAR(255) DEFAULT '',
				`browser_id` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				`screenres_id` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				`plugins` VARCHAR(255) DEFAULT '',
				`visit_id` INT UNSIGNED NOT NULL DEFAULT 0,
				`dt` INT(10) UNSIGNED DEFAULT 0,
				PRIMARY KEY `id` (`id`)
			)$use_innodb";

		// We store in the database the information about Countries. So you don't
		// need to access a remote service to translate numbers and codes
		$country_table_sql = 
			"CREATE TABLE IF NOT EXISTS `$this->table_countries` (
				`ip_from` INT UNSIGNED DEFAULT 0,
				`ip_to` INT UNSIGNED DEFAULT 0,
				`country_code` CHAR(2) DEFAULT '',
				KEY `ip_from_idx` (`ip_from`, `ip_to`)
			)$use_innodb";

		// A lookup table for browsers can help save some space
		$browsers_table_sql =
			"CREATE TABLE IF NOT EXISTS `$this->table_browsers` (
				`browser_id` SMALLINT UNSIGNED NOT NULL auto_increment,
				`browser` VARCHAR(40) DEFAULT '',
				`version` VARCHAR(15) DEFAULT '',
				`platform` VARCHAR(15) DEFAULT '',
				`css_version` VARCHAR(5) DEFAULT '',
				PRIMARY KEY (`browser_id`)
			)$use_innodb";

		// A lookup table for screen resolutions can help save some space, too
		$screen_res_table_sql =
			"CREATE TABLE IF NOT EXISTS `$this->table_screenres` (
				`screenres_id` SMALLINT UNSIGNED NOT NULL auto_increment,
				`resolution` VARCHAR(12) DEFAULT '',
				`colordepth` VARCHAR(5) DEFAULT '',
				`antialias` BOOL DEFAULT FALSE,
				PRIMARY KEY (`screenres_id`)
			)$use_innodb";

		// This table will keep track of visits (user sessions of at most 30 minutes)
		$visits_table_sql =
			"CREATE TABLE IF NOT EXISTS `$this->table_visits` (
				`visit_id` INT UNSIGNED NOT NULL auto_increment,
				`tracking_code` VARCHAR(255) DEFAULT '',
				PRIMARY KEY (`visit_id`)
			)$use_innodb";

		// This table will track outbound links (clicks on links to external sites)
		$outbound_table_sql = 
			"CREATE TABLE IF NOT EXISTS `$this->table_outbound` (
				`outbound_id` INT UNSIGNED NOT NULL auto_increment,
				`outbound_domain` VARCHAR(255) DEFAULT '',
				`outbound_resource` VARCHAR(255) DEFAULT '',
				`type` TINYINT UNSIGNED DEFAULT 0,
				`id` INT UNSIGNED NOT NULL DEFAULT 0,
				`dt` INT(10) UNSIGNED DEFAULT 0,
				PRIMARY KEY (`outbound_id`)
			)$use_innodb";

		// Ok, let's create the table structure
		if ($this->_create_table($country_table_sql, $this->table_countries, false)){
			$this->_import_countries();
		}
		
		$this->_create_table($browsers_table_sql, $this->table_browsers);
		$this->_create_table($screen_res_table_sql, $this->table_screenres);
		$this->_create_table($visits_table_sql, $this->table_visits);
		$this->_create_table($outbound_table_sql, $this->table_outbound);
		$this->_create_table($stats_table_sql, $this->table_stats);

		// We need a secret key to make sure the js-php interaction is secure
		add_option('slimstat_secret', md5(time()), '', 'no');

		// Activate or deactivate tracking, but still be able to view reports
		add_option('slimstat_is_tracking', 'yes', '', 'no');

		// Track screen resolutions, outbound links and other stuff using a javascript component
		add_option('slimstat_enable_javascript', 'yes', '', 'no');
		
		// Custom path to get to wp-slimstat-js.php
		add_option('slimstat_custom_js_path', WP_PLUGIN_URL.'/wp-slimstat', '', 'no');

		// Enable Browscap's autoupdate feature
		add_option('slimstat_browscap_autoupdate', 'yes', '', 'no');

		// Ignore requests that have the same information and are less than x seconds far from each other
		add_option('slimstat_ignore_interval', '30', '', 'no');

		// Don't ignore bots and spiders by default
		add_option('slimstat_ignore_bots', 'no', '', 'no');

		// Tracks logged in users, adding their login to the resource they requested
		add_option('slimstat_track_users', 'no', '', 'no');

		// Automatically purge stats db after x days (0 = no purge)
		add_option('slimstat_auto_purge', '120', '', 'no');
		
		// Use a separate menu for the admin interface
		add_option('slimstat_use_separate_menu', 'no', '', 'no');

		// Activate or deactivate the conversion of ip addresses into hostnames
		add_option('slimstat_convert_ip_addresses', 'yes', '', 'no');

		// Activate or deactivate the conversion of ip addresses into hostnames
		add_option('slimstat_rows_to_show', '20', '', 'no');

		// List of IPs to ignore
		add_option('slimstat_ignore_ip', array(), '', 'no');

		// List of local resources to ignore
		add_option('slimstat_ignore_resources', array(), '', 'no');

		// List of browsers to ignore
		add_option('slimstat_ignore_browsers', array(), '', 'no');
		
		// List of users to ignore
		add_option('slimstat_ignore_users', array(), '', 'no');

		// List of users who can view the stats: if empty, all users are allowed
		add_option('slimstat_can_view', array(), '', 'no');

		// List of capabilities needed to view the stats: if empty, all users are allowed
		add_option('slimstat_capability_can_view', 'read', '', 'no');

		// List of users who can administer this plugin's options: if empty, all users are allowed
		add_option('slimstat_can_admin', array(), '', 'no');

		// List of users who can administer this plugin's options: if empty, all users are allowed
		add_option('slimstat_enable_footer_link', 'yes', '', 'no');

		// Schedule the autopurge hook
		if (!wp_next_scheduled('wp_slimstat_purge'))
			wp_schedule_event('1262311200', 'daily', 'wp_slimstat_purge');
			
		// Please do not remove this function, it helps me keep track of WP SlimStat's user base.
		// Your privacy is 100% guaranteed, I promise :-)
		$opts = array( 'http'=>array( 'method'=>'GET', 'header'=>"Accept-language: en\r\nUser-Agent: wp-slimstat\r\n" ) );
		$context = @stream_context_create($opts);
		$devnull = @file_get_contents('http://www.duechiacchiere.it/wp-slimstat-count.php?h='.urlencode(get_bloginfo('url')).'&v='.$this->version.'&c='.$this->_count_records(), false, $context);
	}
	// end activate

	/**
	 * Performs some clean-up maintenance (disable cron job).
	 */
	public function deactivate() {

		// Unschedule the autopurge hook
		if (wp_next_scheduled('wp_slimstat_purge') > 0)
			wp_clear_scheduled_hook('wp_slimstat_purge');
	}
	// end deactivate

	/**
	 * This is the function which tracks visits
	 */
	public function slimtrack( $_argument = '' ) {
		global $wpdb;

		// Is tracking enabled?
		if (get_option('slimstat_is_tracking', 'yes') == 'no') return $_argument;

		// Should we ignore this user?
		if ( is_user_logged_in() ){ 
			global $current_user;
			$array_users_to_ignore = get_option('slimstat_ignore_users', array());	
			if (in_array($current_user->user_login, $array_users_to_ignore)) return $_argument;
		}

		// We do not want to track admin pages
		if ( is_admin() ||
				strpos($_SERVER['PHP_SELF'], 'wp-content/') !== FALSE ||
				strpos($_SERVER['PHP_SELF'], 'wp-cron.php') !== FALSE ||
				strpos($_SERVER['PHP_SELF'], 'xmlrpc.php') !== FALSE ||
				strpos($_SERVER['PHP_SELF'], 'wp-login.php') !== FALSE ||
				strpos($_SERVER['PHP_SELF'], 'wp-comments-post.php') !== FALSE ) return $_argument;

		// Set $isIgnored to TRUE if this IP is blacklisted
		$array_ip_to_ignore = get_option('slimstat_ignore_ip', array());

		// Let's get the user's IP address
		$long_user_ip = $this->_get_ip2long_remote_ip();
		if ($long_user_ip === false) return $_argument;

		foreach($array_ip_to_ignore as $a_ip_range){
			list ($ip_to_ignore, $mask) = @explode("/", $a_ip_range);
			if (empty($mask)) $mask = 32;
			$long_ip_to_ignore = ip2long($ip_to_ignore);
			$long_mask = bindec( str_pad('', $mask, '1') . str_pad('', 32-$mask, '0') ); 
			$long_masked_user_ip = $long_user_ip & $long_mask;
			$long_masked_ip_to_ignore = $long_ip_to_ignore & $long_mask;
			if ($long_masked_user_ip == $long_masked_ip_to_ignore) return $_argument;
		}

		$stat = array();
		$referer = array(); 
		if ( isset( $_SERVER['HTTP_REFERER'] ) ) {
			$referer = @parse_url( $_SERVER['HTTP_REFERER'] );
			if ( !$referer ) {
				$referer = $_SERVER['HTTP_REFERER'];
			}
			else if ( isset( $referer['host'] ) ) {
				$stat['domain'] = $wpdb->escape( $referer['host'] );
				$stat['referer'] = $wpdb->escape( str_replace( $referer['scheme'].'://'.$referer['host'], '', $_SERVER['HTTP_REFERER'] ) );
			}
		}

		// We want to record both hits and searches (through site search form)
		if ( empty( $_REQUEST['s'] ) ) {
			$stat['searchterms'] = $wpdb->escape( $this->_get_search_terms( $referer ) );
			if ( isset( $_SERVER['REQUEST_URI'] ) ) {
				$stat['resource'] = $wpdb->escape( $_SERVER['REQUEST_URI'] );
			}
			elseif ( isset( $_SERVER['SCRIPT_NAME'] ) ) {
				$stat['resource'] = $wpdb->escape( ( isset( $_SERVER['QUERY_STRING'] ) )?$_SERVER['SCRIPT_NAME']."?".$_SERVER['QUERY_STRING']:$_SERVER['SCRIPT_NAME'] );
			}
			else {
				$stat['resource'] = $wpdb->escape( ( isset( $_SERVER['QUERY_STRING'] ) ) ? $_SERVER['PHP_SELF']."?".$_SERVER['QUERY_STRING'] : $_SERVER['PHP_SELF'] );
			}
		} // end if ( empty( $_REQUEST['s'] ) )
		else {
			$stat['searchterms'] = $wpdb->escape( stripslashes( $_REQUEST['s'] ) );

			// Mark the resource to remember that this is a 'local search'
			$stat['resource'] = '';
		} // end else 
		
		// Is this resource blacklisted?
		$array_resources_to_ignore = get_option('slimstat_ignore_resources', array());
		foreach( $array_resources_to_ignore as $a_resource ) {
			// TODO: use regolar expressions to filter resources
			if ( !empty($stat['resource']) && strpos($a_resource, $stat['resource']) === 0 ) return $_argument;
		}

		// Mark 404 pages
		if (is_404()) $stat['resource'] = '[404]'.$stat['resource'];
		
		// Track logged-in users
		if ((get_option('slimstat_track_users', 'no') == 'yes') &&
			is_user_logged_in() && !empty($current_user->user_login))
			$stat['resource'] = "[u:$current_user->user_login]".$stat['resource'];

		// Loads the class to determine the user agent
		require 'browscap.php';

		// Creates a new Browscap object (loads or creates the cache)
		$browscap = new browscap(WP_PLUGIN_DIR.'/wp-slimstat/cache');
		
		// Do autoupdate?
		$do_autoUpdate = get_option('slimstat_browscap_autoupdate', 'no');
		if (($do_autoUpdate == 'yes') && 
			((intval(substr(sprintf('%o',fileperms(WP_PLUGIN_DIR.'/wp-slimstat/cache/browscap.ini')), -3)) < 664) ||
			(intval(substr(sprintf('%o',fileperms(WP_PLUGIN_DIR.'/wp-slimstat/cache/cache.php')), -3)) < 664))){
			$browscap->doAutoUpdate = false;
		}
		else{
			$browscap->doAutoUpdate = ($do_autoUpdate == 'yes');
		}

		$stat['ip'] = sprintf( "%u", $long_user_ip );
		$stat['language']	= $this->_get_language();
		$stat['country']	= $this->_get_country( $stat['ip'] );

		$browser_details = $browscap->getBrowser( $_SERVER['HTTP_USER_AGENT'], true );

		// This information goes into a separate lookup table
		$browser['browser'] = $browser_details['Browser'];
		$browser['version'] = $browser_details['Version'];
		$browser['platform'] = strtolower($browser_details['Platform']);
		$browser['css_version'] = $browser_details['CssVersion'];

		// Is this browser blacklisted?
		$array_browsers_to_ignore = get_option('slimstat_ignore_browsers', array());
		foreach( $array_browsers_to_ignore as $a_browser ) {
			// TODO: use regolar expressions to filter browsers
			if ( strpos($a_browser, $browser['browser'].'/'.$browser['version']) === 0 ) return $_argument;
		}

		// If platform = unknown or css_version = 0, it's a bot
		$ignore_bots = get_option('slimstat_ignore_bots', 'no');
		if ( ($ignore_bots == 'yes') && ( ($browser['css_version'] == '0') || 
			($browser['platform'] == 'unknown') ||
			(strpos($browser['browser'], 'crawl') !== false) ||
			(strpos($browser['browser'], 'bot') !== false) || 
			(strpos($browser['browser'], 'libw') !== false) ) ) return $_argument;

		$stat['dt'] = date_i18n('U');

		// Now we insert the new browser in the lookup table, if it doesn't exist
		$insert_new_browser_sql = "INSERT INTO `$this->table_browsers` ( `" . implode( "`, `", array_keys( $browser ) ) . "` )
			SELECT '" . implode( "', '", array_values( $browser ) ) . "'
			FROM DUAL
			WHERE NOT EXISTS ( ";

		$select_sql = "SELECT `browser_id`
					FROM `$this->table_browsers` 
					WHERE `browser` = '{$browser['browser']}' AND
							`version` = '{$browser['version']}' AND
							`platform` = '{$browser['platform']}' AND
							`css_version` = '{$browser['css_version']}' LIMIT 1";

		$insert_new_browser_sql .= $select_sql . ")";
		$wpdb->query($insert_new_browser_sql);
		$stat['browser_id'] = $wpdb->insert_id; 

		if ( empty($stat['browser_id']) ) { // This can happen if the browser already exists in the table
			$stat['browser_id'] = $wpdb->get_var($select_sql);
		}

		// Last but not least, we insert the information about this visit
		// If the same user visited the same page in the last x seconds (default 30), we can ignore it
		// This should save some space in the database
		$ignore_interval = intval(get_option('slimstat_ignore_interval', '30'));

		$insert_new_hit_sql = "INSERT INTO `$this->table_stats` ( `" . implode( "`, `", array_keys( $stat ) ) . "` )
			SELECT '" . implode( "', '", array_values( $stat ) ) . "'
			FROM DUAL
			WHERE NOT EXISTS ( ";

		$select_sql = "SELECT `id` 
				FROM `$this->table_stats`
				WHERE ";
		foreach ($stat as $a_key => $a_value) {
			if ($a_key == 'dt' && $ignore_interval > 0) {
				$select_sql .= "(UNIX_TIMESTAMP() - `dt` < $ignore_interval) AND ";
			}
			else {
				$select_sql .= "`$a_key` = '$a_value'" . (($a_key != 'browser_id')?" AND ":" LIMIT 1 ");
			}
		}
		$insert_new_hit_sql .= $select_sql . ")";

		$wpdb->query($insert_new_hit_sql);
		$this->tid = $wpdb->insert_id;

		if ( empty($this->tid) ) { // There's already an entry with the same info, less than x seconds old
			$this->tid = $wpdb->get_var($select_sql);
		}

		// Is this a new visitor?
		if (!isset($_COOKIE['slimstat_tracking_code']) || strlen($_COOKIE['slimstat_tracking_code']) != 32){
			// Set a cookie to track this visitor (Google and other non-human engines will just ignore it)
			$my_secret_key = get_option('slimstat_secret', '123');
			@setcookie('slimstat_tracking_code', md5($this->tid.$my_secret_key), time()+1800, '/');
		}

		return $_argument;
	}
	// end slimtrack

	/**
	 * Creates a table in the database
	 */
	private function _create_table($_sql = '', $_tablename = '', $_check_if_exists = true) {
	    global $wpdb;

		// If the table is already there, we won't do anything
		if ($_check_if_exists)
			foreach ( $wpdb->get_col("SHOW TABLES", 0) as $a_table )
				if ( $a_table == $_tablename ) return false;
	
		$wpdb->query( $_sql );

		// Let's make sure this table was actually created
		foreach ( $wpdb->get_col("SHOW TABLES", 0) as $a_table ) {
			if ( $a_table == $_tablename ) {
				return true;
			}
		}
		return false;
	}
	// end _create_table

	/**
	 * Reads data from CSV file and copies them into countries table
	 */
	private function _import_countries() {
		global $wpdb;

		// If there is already a (not empty) country table, skip import
		$country_rows = $wpdb->get_var("SELECT COUNT(*) FROM `$this->table_countries`", 0);
		if ( $country_rows !== false && $country_rows > 0 ) return false;

		$country_file = "geoip.csv";

		// To avoid problems with SAFE_MODE, we will not use is_file
		// or file_exists, but a loop to scan current directory
		$is_country_file = false;
		$handle = opendir(dirname(__FILE__));
		while ( false !== ($filename = readdir($handle)) ) {
			if ( $country_file == $filename ) {
				$is_country_file = true;
				break;
			}
		}
		closedir($handle);

		if (!$is_country_file) return false;	

		// Allow plenty of time for this to happen
		@set_time_limit( 600 ); 

		// Since the file can be too big, we are not using file_get_contents to not exceed the server's memory limit
		if (!$handle = fopen( WP_PLUGIN_DIR."/wp-slimstat/".$country_file, "r" )) return false;

		$insert_sql = "INSERT INTO `$this->table_countries` ( `ip_from`, `ip_to`, `country_code` ) VALUES ";
		$row_counter = 0;

		while ( !feof( $handle ) ) {
			$entry = fgets($handle);
			if (empty($entry)) break;
			$entry = str_replace("\n", '', $entry);
			$array_entry = explode(',', $entry);
			$insert_sql .= "('".implode( "','", $array_entry )."'),";
			if ($row_counter == 200) {
				$insert_sql = substr($insert_sql, 0, -1);
				$wpdb->query($insert_sql);
				$row_counter = 0;
				$insert_sql = "INSERT INTO `$this->table_countries` ( `ip_from`, `ip_to`, `country_code` ) VALUES ";
			}
			else $row_counter++;
		}
		if (!empty($insert_sql) && $insert_sql != "INSERT INTO `$this->table_countries` ( `ip_from`, `ip_to`, `country_code` ) VALUES ") {
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
	private function _get_country( $_ip = '') {
		global $wpdb;

		$sql = "SELECT `country_code` 
					FROM `$this->table_countries`
					WHERE `ip_from` <= $_ip AND `ip_to` >= $_ip";

		$country_code = $wpdb->get_var( $sql, 0 , 0 );
		if ( !empty( $country_code ) ) return $country_code;

		return 'xx';
	}
	// end _get_country

	/**
	 * Tries to find the user's REAL IP address
	 */
	private function _get_ip2long_remote_ip() {
		if (isset($_SERVER["HTTP_CLIENT_IP"]) && 
			long2ip($long_ip = ip2long($_SERVER["HTTP_CLIENT_IP"])) == $_SERVER["HTTP_CLIENT_IP"]) return $long_ip;
		if (isset($_SERVER["HTTP_X_FORWARDED_FOR"])) {
			foreach (explode(",",$_SERVER["HTTP_X_FORWARDED_FOR"]) as $a_ip) {
				if (long2ip($long_ip = ip2long($a_ip)) == $a_ip) return $long_ip;
			}
		}
		if (isset($_SERVER["HTTP_X_FORWARDED"]) &&
			long2ip($long_ip = ip2long($_SERVER["HTTP_X_FORWARDED"])) == $_SERVER["HTTP_X_FORWARDED"]) return $long_ip;
		if (isset($_SERVER["HTTP_FORWARDED_FOR"]) &&
			long2ip($long_ip = ip2long($_SERVER["HTTP_FORWARDED_FOR"])) == $_SERVER["HTTP_FORWARDED_FOR"]) return $long_ip;
		if (isset($_SERVER["HTTP_FORWARDED"]) &&
			long2ip($long_ip = ip2long($_SERVER["HTTP_FORWARDED"])) == $_SERVER["HTTP_FORWARDED"]) return $long_ip;
		if (isset($_SERVER["REMOTE_ADDR"]) &&
			long2ip($long_ip = ip2long($_SERVER["REMOTE_ADDR"])) == $_SERVER["REMOTE_ADDR"]) return $long_ip;
		
		return false;
	}
	// end _get_ip2long_remote_ip

	/**
	 * Extracts the accepted language from browser headers
	 */
	private function _get_language() {
		global $wpdb;
		$array_languages = array(); 

		if( isset( $_SERVER["HTTP_ACCEPT_LANGUAGE"] ) ) {

			// Capture up to the first delimiter (, found in Safari)
			preg_match( "/([^,;]*)/", $_SERVER["HTTP_ACCEPT_LANGUAGE"], $array_languages );

			// Fix some codes, the correct syntax is with minus (-) not underscore (_)
			return $wpdb->escape( str_replace( "_", "-", strtolower( $array_languages[0] ) ) );
		}
		return 'xx';  // Indeterminable language
	}
	// end _get_language

	/**
	 * Sniffs out referrals from search engines and tries to determine the query string
	 */
	private function _get_search_terms( $_url = '' ) { 
		global $wpdb;

		$q = array();
		$search_terms = '';
		$array_url = $_url;

		if( !is_array( $_url ) ) {
			$array_url = @parse_url( $_url );
			if (!$array_url) return '';
		}

		if( !isset( $array_url['host'] ) || !isset( $array_url['query'] ) ) return '';

		// Host regexp, query portion containing search terms
		$array_sniffs = array( 
			array( '/google\./i', 'q' ),
			array( '/alltheweb\./i', 'q' ),
			array( '/yahoo\./i', 'p' ),
			array( '/search\.aol\./i', 'query' ),
			array( '/search\.looksmart\./i', 'p' ),
			array( '/gigablast\./i', 'q' ),
			array( '/s\.teoma\./i', 'q' ),
			array( '/clusty\./i', 'query' ),
			array( '/yandex\./i', 'text' ),
			array( '/rambler\./i', 'words' ),
			array( '/aport\./i', 'r' ),
			array( '/search\.naver\./i', 'query' ),
			array( '/search\.cs\./i', 'query' ),
			array( '/search\.netscape\./i', 'query' ),
			array( '/hotbot\./i', 'query' ),
			array( '/search\.msn\./i', 'q' ),
			array( '/altavista\./i', 'q' ),
			array( '/web\.ask\./i', 'q' ),
			array( '/search\.wanadoo\./i', 'q' ),
			array( '/www\.bbc\./i', 'q' ),
			array( '/tesco\.net/i', 'q' ),
			array( '/.*/', 'query' ),
			array( '/.*/', 'q' )
		);

		foreach ( $array_sniffs as $a_sniff ) {
			if( preg_match( $a_sniff[0], $array_url['host'] ) ) {
				parse_str( $array_url['query'], $q );
				if ( isset( $q[ $a_sniff[1] ] ) ) {
					$search_terms = stripslashes( trim( urldecode( $q[ $a_sniff[1] ] ) ) );
					break;
				}
			}
		}

		return $search_terms;
	}
	// end _get_search_terms

	/**
	 * Counts how many visits are currently recorded in the database
	 */
	private function _count_records() {
		global $wpdb;

		$sql = "SELECT COUNT(*) FROM `$this->table_stats`";
		return intval($wpdb->get_var($sql));
	}
	// end _count_records

	/**
	 * Removes old entries from the database
	 */
	public function wp_slimstat_purge() {
		global $wpdb;

		if (($autopurge_interval = intval(get_option('slimstat_auto_purge', 0))) <= 0) return;

		// Delete old entries		
		$delete_sql = "DELETE ts, tv FROM `$this->table_stats` ts, `$this->table_visits` tv WHERE ts.`dt` < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL $autopurge_interval DAY)) AND ts.`visit_id` = tv.`visit_id`";
		$wpdb->query($delete_sql);
		
		// Delete all the other entries with no matching visit records
		$delete_sql = "DELETE ts FROM `$this->table_stats` ts WHERE ts.`dt` < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL $autopurge_interval DAY))";
		$wpdb->query($delete_sql);
	}
	// end wp_slimstat_purge

	/**
	 * Loads a custom stylesheet file for the administration panels
	 */
	public function wp_slimstat_stylesheet() {
		// It looks like WP_PLUGIN_URL doesn't honor the HTTPS setting in wp-config.php
		$slimstat_plugin_url = is_ssl()?str_replace('http://', 'https://', WP_PLUGIN_URL):WP_PLUGIN_URL;
		$stylesheeth_url = $slimstat_plugin_url . '/wp-slimstat/css/view.css';
		wp_register_style('wp-slimstat-view', $stylesheeth_url);
		wp_enqueue_style('wp-slimstat-view');
	}
	// end wp_slimstat_stylesheet

	/**
	 * Adds a new entry in the admin menu, to view the stats
	 */
	public function wp_slimstat_add_view_menu( $_s ) {
		global $current_user;

		// Load localization files
		load_plugin_textdomain('wp-slimstat', WP_PLUGIN_DIR .'/wp-slimstat/lang', '/wp-slimstat/lang');

		$slimstat_plugin_url = is_ssl()?str_replace('http://', 'https://', WP_PLUGIN_URL):WP_PLUGIN_URL;

		$array_allowed_users = get_option('slimstat_can_view', array());
		$minimum_capability = get_option('slimstat_capability_can_view', 'read');
		$minimum_capability = empty($minimum_capability)?'read':$minimum_capability;
		$use_separate_menu = get_option('slimstat_use_separate_menu', 'no');
		
		if ((empty($array_allowed_users) && $minimum_capability == 'read') || in_array($current_user->user_login, $array_allowed_users) || current_user_can($minimum_capability)) {
			if ($use_separate_menu == 'yes'){
				add_menu_page( 'SlimStat', 'SlimStat', $minimum_capability, WP_PLUGIN_DIR.'/wp-slimstat/view/index.php', '', $slimstat_plugin_url.'/wp-slimstat/images/wp-slimstat-menu.png' );
			}
			else{
				add_submenu_page( 'index.php', 'SlimStat', 'SlimStat', $minimum_capability, WP_PLUGIN_DIR.'/wp-slimstat/view/index.php' );
			}
		}
		return $_s;
	}
	// end wp_slimstat_add_view_menu

	/**
	 * Adds a new entry in the admin menu, to manage SlimStat options
	 */
	public function wp_slimstat_add_config_menu( $_s ) {
		global $current_user;

		$array_allowed_users = get_option('slimstat_can_admin', array());
		$use_separate_menu = get_option('slimstat_use_separate_menu', 'no');
		if (empty($array_allowed_users) || in_array($current_user->user_login, $array_allowed_users)){
			if ($use_separate_menu == 'yes'){
				add_submenu_page( WP_PLUGIN_DIR.'/wp-slimstat/view/index.php', 'Options', 'Options', 'edit_posts', WP_PLUGIN_DIR.'/wp-slimstat/options/index.php' );
			}
			else{
				if (current_user_can('manage_options')){
					add_options_page( 'SlimStat', 'SlimStat', 'manage_options', WP_PLUGIN_DIR.'/wp-slimstat/options/index.php' );
				}
				else{
					add_menu_page( 'SlimStat Config', 'SlimStat Config', 'edit_posts', WP_PLUGIN_DIR.'/wp-slimstat/options/index.php' );
				}
			}
		}
		return $_s;
	}
	// end wp_slimstat_add_config_menu

	/**
	 * Adds a javascript code to track users' screen resolution and other browser-based information
	 */
	public function wp_slimstat_javascript() {
		global $wpdb;
		if ($this->tid > 0){
			$intval_tid = intval($this->tid);
			$hexval_tid = base_convert($intval_tid, 10, 16);
			$my_secret_key = get_option('slimstat_secret', '123');
			$custom_slimstat_js_path = get_option('slimstat_custom_js_path', WP_PLUGIN_URL.'/wp-slimstat');
			$enable_footer_link = get_option('slimstat_enable_footer_link', 'yes');
			$slimstat_plugin_url = is_ssl()?str_replace('http://', 'https://', WP_PLUGIN_URL):WP_PLUGIN_URL;
			
			if ($enable_footer_link == 'yes') {	
				echo '<p id="statsbywpslimstat" style="text-align:center"><a href="http://www.duechiacchiere.it/wp-slimstat" title="A simple but powerful web analytics plugin for Wordpress"><img src="'.$slimstat_plugin_url.'/wp-slimstat/images/wp-slimstat-antipixel.png" width="80" height="15" alt="WP SlimStat"/></a></p>';
			}
			echo "<script type='text/javascript'>slimstat_tid='$hexval_tid';";
			echo "slimstat_path='$custom_slimstat_js_path';";
			$slimstat_blog_id = (function_exists('is_multisite') && is_multisite())?$wpdb->blogid:0;
			echo "slimstat_blog_id='$slimstat_blog_id';";
			echo 'slimstat_session_id=\''.md5($intval_tid.$my_secret_key).'\';</script>';
			echo "<script type='text/javascript' src='$slimstat_plugin_url/wp-slimstat/wp-slimstat.js'></script>\n";
		}
	}
	// end wp_slimstat_javascript
	
	/**
	 * Contextual help (link to the support forum)
	 */
	public function wp_slimstat_contextual_help($contextual_help, $screen_id, $screen) {
		if (($screen_id == 'wp-slimstat/view/index') || ($screen_id == 'wp-slimstat/options/index')){
			load_plugin_textdomain('wp-slimstat-view', WP_PLUGIN_URL .'/wp-slimstat/lang', '/wp-slimstat/lang');
			$contextual_help = __('Need help on how to use WP SlimStat? Visit the official','wp-slimstat-view').' <a href="http://lab.duechiacchiere.it/index.php?board=1.0" target="_blank">'.__('support forum','wp-slimstat-view').'</a>. ';
			$contextual_help .= __('Feeling generous?','wp-slimstat-view').' <a href="https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=Z732JS7KQ6RRL&lc=US&item_name=WP%20SlimStat&currency_code=USD&bn=PP%2dDonationsBF%3abtn_donate_SM%2egif%3aNonHosted" target="_blank">'.__('Donate a few bucks!','wp-slimstat-view').'</a>';
		}
		return $contextual_help;
	}
	// end wp_slimstat_contextual_help
}
// end of class declaration

// Ok, let's use every tool we defined here above 
$wp_slimstat = new wp_slimstat();

// Define when we want to run the tracking: on init
add_action('wp', array(&$wp_slimstat, 'slimtrack'),5);

// Initialization routine should be executed on activation
register_activation_hook( __FILE__, array( &$wp_slimstat, 'activate' ) );
register_deactivation_hook( __FILE__, array( &$wp_slimstat, 'deactivate' ) );

// Add the appropriate entries to the admin menu, if this user can view/admin WP SlimStats
add_action('admin_menu', array(&$wp_slimstat, 'wp_slimstat_add_view_menu'));
add_action('admin_menu', array(&$wp_slimstat, 'wp_slimstat_add_config_menu'));

// Add some custom stylesheets
add_action('admin_print_styles-wp-slimstat/view/index.php', array( &$wp_slimstat, 'wp_slimstat_stylesheet') );
add_action('admin_print_styles-wp-slimstat/options/index.php', array( &$wp_slimstat, 'wp_slimstat_stylesheet') );

// WP SlimStat tracks screen resolutions, outbound links and other stuff using some javascript custom code
if (get_option('slimstat_enable_javascript', 'no') == 'yes'){
	add_action('wp_footer', array(&$wp_slimstat,'wp_slimstat_javascript'),10);
}

// Create a hook to use with the daily cron job
add_action('wp_slimstat_purge', array(&$wp_slimstat,'wp_slimstat_purge'));

// Contextual help
add_action('contextual_help', array(&$wp_slimstat,'wp_slimstat_contextual_help'), 10,3);
