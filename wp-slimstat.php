<?php
/*
Plugin Name: WP SlimStat
Plugin URI: http://wordpress.org/extend/plugins/wp-slimstat/
Description: A simple but powerful web analytics plugin for Wordpress.
Version: 2.4
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
		$this->version = '2.4';

		// We use three of tables to store data about visits
		$this->table_stats = $wpdb->prefix.'slim_stats';
		$this->table_visits = $wpdb->prefix.'slim_visits';
		$this->table_outbound = $wpdb->prefix.'slim_outbound';

		// Some tables can be shared among the various installations (wordpress multi-user)
		$this->table_countries = $wpdb->base_prefix.'slim_countries';
		$this->table_browsers = $wpdb->base_prefix.'slim_browsers';
		$this->table_screenres = $wpdb->base_prefix.'slim_screenres';
		
		// It looks like WP_PLUGIN_URL doesn't honor the HTTPS setting in wp-config.php
		$this->plugin_url = is_ssl()?str_replace('http://', 'https://', WP_PLUGIN_URL):WP_PLUGIN_URL;

		// Let's keep track of transaction IDs
		$this->tid = 0;

		// Define when we want to run the tracking: on init
		add_action('wp', array(&$this, 'slimtrack'), 5);

		// Initialization routine should be executed on activation
		register_activation_hook(__FILE__, array(&$this, 'activate'));
		register_deactivation_hook(__FILE__, array(&$this, 'deactivate'));

		// Add the appropriate entries to the admin menu, if this user can view/admin WP SlimStats
		add_action('admin_menu', array(&$this, 'wp_slimstat_add_view_menu'));
		add_action('admin_menu', array(&$this, 'wp_slimstat_add_config_menu'));

		// Add some custom stylesheets
		add_action('admin_print_styles-wp-slimstat/view/index.php', array(&$this, 'wp_slimstat_stylesheet'));
		add_action('admin_print_styles-wp-slimstat/options/index.php', array(&$this, 'wp_slimstat_stylesheet'));

		// WP SlimStat tracks screen resolutions, outbound links and other stuff using some javascript custom code
		if (get_option('slimstat_enable_javascript', 'no') == 'yes'){
			add_action('wp_footer', array(&$this,'wp_slimstat_javascript'), 10);
		}

		// Create a hook to use with the daily cron job
		add_action('wp_slimstat_purge', array(&$this, 'wp_slimstat_purge'));

		// Contextual help
		add_action('contextual_help', array(&$this, 'wp_slimstat_contextual_help'), 10, 3);
	}
	// end __construct

	/**
	 * Creates and populates tables, if they aren't already there.
	 */
	public function activate() {
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
			"CREATE TABLE IF NOT EXISTS $this->table_countries (
				ip_from INT UNSIGNED DEFAULT 0,
				ip_to INT UNSIGNED DEFAULT 0,
				country_code CHAR(2) DEFAULT '',
				KEY ip_from_idx (ip_from, ip_to)
			)";

		// A lookup table for browsers can help save some space
		$browsers_table_sql =
			"CREATE TABLE IF NOT EXISTS $this->table_browsers (
				browser_id SMALLINT UNSIGNED NOT NULL auto_increment,
				browser VARCHAR(40) DEFAULT '',
				version VARCHAR(15) DEFAULT '',
				platform VARCHAR(15) DEFAULT '',
				css_version VARCHAR(5) DEFAULT '',
				PRIMARY KEY (browser_id)
			)$use_innodb";

		// A lookup table for screen resolutions can help save some space, too
		$screen_res_table_sql =
			"CREATE TABLE IF NOT EXISTS $this->table_screenres (
				screenres_id SMALLINT UNSIGNED NOT NULL auto_increment,
				resolution VARCHAR(12) DEFAULT '',
				colordepth VARCHAR(5) DEFAULT '',
				antialias BOOL DEFAULT FALSE,
				PRIMARY KEY (screenres_id)
			)$use_innodb";

		// This table will keep track of visits (user sessions of up to 30 minutes)
		$visits_table_sql =
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}slim_visits (
				visit_id INT UNSIGNED NOT NULL auto_increment,
				tracking_code VARCHAR(255) DEFAULT '',
				PRIMARY KEY (visit_id)
			)$use_innodb";

		// This table will track outbound links (clicks on links to external sites)
		$outbound_table_sql =
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}slim_outbound (
				outbound_id INT UNSIGNED NOT NULL auto_increment,
				outbound_domain VARCHAR(255) DEFAULT '',
				outbound_resource VARCHAR(2048) DEFAULT '',
				type TINYINT UNSIGNED DEFAULT 0,
				id INT UNSIGNED NOT NULL DEFAULT 0,
				dt INT(10) UNSIGNED DEFAULT 0,
				PRIMARY KEY (outbound_id)
			)$use_innodb";

		// Ok, let's create the table structure
		if ($this->_create_table($country_table_sql, $this->table_countries)){
			$this->_import_countries();
		}

		$this->_create_table($browsers_table_sql, $this->table_browsers);
		$this->_create_table($screen_res_table_sql, $this->table_screenres);
		$this->_create_table($visits_table_sql, $this->table_visits);
		$this->_create_table($outbound_table_sql, $this->table_outbound);
		if (!$this->_create_table($stats_table_sql, $this->table_stats, true)){
			// Update the table structure ( versions < 2.4 ), if needed
			$this->_update_stats_table();
		}

		// We need a secret key to make sure the js-php interaction is secure
		add_option('slimstat_secret', md5(time()), '', 'no');

		// Activate or deactivate tracking, but still be able to view reports
		add_option('slimstat_is_tracking', 'yes', '', 'no');

		// Track screen resolutions, outbound links and other stuff using a javascript component
		add_option('slimstat_enable_javascript', 'yes', '', 'no');

		// Custom path to get to wp-slimstat-js.php
		add_option('slimstat_custom_js_path', WP_PLUGIN_URL.'/wp-slimstat', '', 'no');

		// Enable Browscap's autoupdate feature
		add_option('slimstat_browscap_autoupdate', 'no', '', 'no');

		// Ignore requests that have the same information and are less than x seconds far from each other
		add_option('slimstat_ignore_interval', '30', '', 'no');

		// Don't ignore bots and spiders by default
		add_option('slimstat_ignore_bots', 'no', '', 'no');

		// Tracks logged in users, adding their login to the resource they requested
		add_option('slimstat_track_users', 'yes', '', 'no');

		// Automatically purge stats db after x days (0 = no purge)
		add_option('slimstat_auto_purge', '120', '', 'no');

		// Use a separate menu for the admin interface
		add_option('slimstat_use_separate_menu', 'no', '', 'no');

		// Activate or deactivate the conversion of ip addresses into hostnames
		add_option('slimstat_convert_ip_addresses', 'yes', '', 'no');

		// Specify what number format to use (European or American)
		add_option('slimstat_use_european_separators', 'yes', '', 'no');

		// Activate or deactivate the conversion of ip addresses into hostnames
		add_option('slimstat_rows_to_show', '20', '', 'no');

		// List of IPs to ignore
		add_option('slimstat_ignore_ip', array(), '', 'no');

		// List of local resources to ignore
		add_option('slimstat_ignore_resources', array(), '', 'no');

		// List of browsers to ignore
		add_option('slimstat_ignore_browsers', array(), '', 'no');

		// List of domains to ignore
		add_option('slimstat_ignore_referers', array(), '', 'no');

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

		// Please do not remove this function, it helps me to keep track of who is using WP SlimStat.
		// Your privacy is 100% guaranteed, I promise :-)
		$opts = array('http'=>array( 'method'=>'GET', 'header'=>"Accept-language: en\r\nUser-Agent: wp-slimstat\r\n"));
		$context = @stream_context_create($opts);
		$devnull = @file_get_contents('http://www.duechiacchiere.it/wp-slimstat-count.php?h='.urlencode(get_bloginfo('url')).'&v='.$this->version.'&c='.$this->_count_records(), false, $context);
	}
	// end activate

	/**
	 * Performs some clean-up maintenance (disable cron job).
	 */
	public function deactivate(){
		// Unschedule the autopurge hook
		if (wp_next_scheduled('wp_slimstat_purge') > 0) wp_clear_scheduled_hook('wp_slimstat_purge');
	}
	// end deactivate

	/**
	 * Core tracking function
	 */
	public function slimtrack($_argument = ''){
		global $wpdb;

		// Is tracking enabled?
		if (get_option('slimstat_is_tracking', 'yes') == 'no') return $_argument;

		// Should we ignore this user?
		if (is_user_logged_in()){
			global $current_user;
			$to_ignore = get_option('slimstat_ignore_users', array());
			if (in_array($current_user->user_login, $to_ignore)) return $_argument;
		}

		// We do not want to track admin pages
		if ( is_admin() ||
				strpos($_SERVER['PHP_SELF'], 'wp-content/') !== FALSE ||
				strpos($_SERVER['PHP_SELF'], 'wp-cron.php') !== FALSE ||
				strpos($_SERVER['PHP_SELF'], 'xmlrpc.php') !== FALSE ||
				strpos($_SERVER['PHP_SELF'], 'wp-login.php') !== FALSE ||
				strpos($_SERVER['PHP_SELF'], 'wp-comments-post.php') !== FALSE ) return $_argument;

		// User's IP address
		$long_user_ip = $this->_get_ip2long_remote_ip();
		if ($long_user_ip === false) return $_argument;

		// Is this IP blacklisted?
		$to_ignore = get_option('slimstat_ignore_ip', array());
		foreach($to_ignore as $a_ip_range){
			list ($ip_to_ignore, $mask) = @explode("/", trim($a_ip_range));
			if (empty($mask)) $mask = 32;
			$long_ip_to_ignore = ip2long($ip_to_ignore);
			$long_mask = bindec( str_pad('', $mask, '1') . str_pad('', 32-$mask, '0') );
			$long_masked_user_ip = $long_user_ip & $long_mask;
			$long_masked_ip_to_ignore = $long_ip_to_ignore & $long_mask;
			if ($long_masked_user_ip == $long_masked_ip_to_ignore) return $_argument;
		}

		// Avoid PHP warnings
		$stat = array();
		$referer = array();

		$stat['ip'] = sprintf("%u", $long_user_ip);
		$stat['language'] = $this->_get_language();
		$stat['country'] = $this->_get_country($stat['ip']);

		if (isset( $_SERVER['HTTP_REFERER'])){
			$referer = @parse_url($_SERVER['HTTP_REFERER']);
			if (!$referer) $referer = $_SERVER['HTTP_REFERER'];
			else if (isset( $referer['host'])){
				$stat['domain'] = $referer['host'];
				$stat['referer'] = str_replace( $referer['scheme'].'://'.$referer['host'], '', $_SERVER['HTTP_REFERER'] );
			}
		}

		// Is this referer blacklisted?
		$to_ignore = get_option('slimstat_ignore_referers', array());
		foreach($to_ignore as $a_filter){
			if (!empty($stat['referer']) && strpos($stat['domain'].$stat['referer'], $a_filter) !== false) return $_argument;
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

		// Mark 404 pages
		if (is_404()) $stat['resource'] = '[404]'.$stat['resource'];

		// Is this resource blacklisted?
		$to_ignore = get_option('slimstat_ignore_resources', array());
		foreach($to_ignore as $a_filter){
			if (!empty($stat['resource']) && strpos($stat['resource'], $a_filter) === 0) return $_argument;
		}

		// Don't track logged-in users, if the corresponding option is enabled
		if (get_option('slimstat_track_users', 'no') == 'no' &&  is_user_logged_in() && !empty($current_user->user_login)) return $_argument;

		// Track commenters and logged-in users
		if (isset($_COOKIE['comment_author_'. COOKIEHASH])) $stat['user'] = $_COOKIE['comment_author_'. COOKIEHASH];
		if (is_user_logged_in() && !empty($current_user->user_login)) $stat['user'] = $current_user->user_login;		

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
		else
			$browscap->doAutoUpdate = ($do_autoUpdate == 'yes');

		$browser_details = $browscap->getBrowser($_SERVER['HTTP_USER_AGENT'], true);

		// This information goes into a separate lookup table
		$browser['browser'] = $browser_details['Browser'];
		$browser['version'] = $browser_details['Version'];
		$browser['platform'] = strtolower($browser_details['Platform']);
		$browser['css_version'] = $browser_details['CssVersion'];

		// Is this browser blacklisted?
		$to_ignore = get_option('slimstat_ignore_browsers', array());
		foreach($to_ignore as $a_filter){
			// TODO: use regolar expressions to filter browsers
			if (strpos($a_filter, $browser['browser'].'/'.$browser['version']) === 0) return $_argument;
		}

		// If platform = unknown or css_version = 0, it's likely a bot
		$ignore_bots = get_option('slimstat_ignore_bots', 'no');
		if ( ($ignore_bots == 'yes') && ( ($browser['css_version'] == '0') ||
			($browser['platform'] == 'unknown') ||
			(strpos($browser['browser'], 'crawl') !== false) ||
			(strpos($browser['browser'], 'bot') !== false) ||
			(strpos($browser['browser'], 'libw') !== false) ) ) return $_argument;

		$stat['dt'] = date_i18n('U');

		// Now we insert the new browser in the lookup table, if it doesn't exist
		$insert_new_browser_sql = "INSERT INTO `$this->table_browsers` (`browser`,`version`,`platform`,`css_version`)
			SELECT %s,%s,%s,%s
			FROM DUAL
			WHERE NOT EXISTS ( ";
		$select_sql = "SELECT `browser_id`
					FROM `$this->table_browsers`
					WHERE `browser` = %s AND
							`version` = %s AND
							`platform` = %s AND
							`css_version` = %s LIMIT 1";

		$insert_new_browser_sql .= $select_sql . ")";

		$wpdb->query($wpdb->prepare($insert_new_browser_sql, array_merge($browser, array_values($browser))));
		$stat['browser_id'] = $wpdb->insert_id;

		if (empty($stat['browser_id'])) // This can happen if the browser already exists in the table
			$stat['browser_id'] = $wpdb->get_var($wpdb->prepare($select_sql, $browser));

		// Last but not least, we insert the information about this visit
		// If the same user visited the same page in the last X seconds, we ignore it
		$ignore_interval = intval(get_option('slimstat_ignore_interval', '30'));

		$insert_new_hit_sql = "INSERT INTO `$this->table_stats` ( `" . implode( "`, `", array_keys( $stat ) ) . "` )
			SELECT " . substr(str_repeat('%s,', count($stat)), 0, -1) . "
			FROM DUAL
			WHERE NOT EXISTS ( ";
		$select_sql = "SELECT `id`
				FROM `$this->table_stats`
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
	private function _create_table($_sql = '', $_tablename = '', $_fail_on_exists = false){
	    global $wpdb;
		if ($_fail_on_exists)
			foreach ($wpdb->get_col("SHOW TABLES", 0) as $a_table)
				if ($a_table == $_tablename) return false;

		$wpdb->query($_sql);

		// Let's make sure this table was actually created
		foreach ($wpdb->get_col("SHOW TABLES", 0) as $a_table)
			if ($a_table == $_tablename) return true;

		return false;
	}
	// end _create_table

	/**
	 * Updates the table structure, adding a new column 'user' to wp_slim_stats
	 */
	private function _update_stats_table(){
	    global $wpdb;

		$table_structure = $wpdb->get_results("SHOW COLUMNS FROM $this->table_stats", ARRAY_A);
		$user_field_exists = false;
		$is_field_right_size = true;
		
		// Let's see if the structure is up-to-date
		foreach($table_structure as $a_row){
			if ($a_row['Field'] == 'user') $user_field_exists = true;
			if ($a_row['Field'] == 'referer' && $a_row['Type'] == 'varchar(255)') $is_field_right_size = false;
		}
		
		if (!$user_field_exists)
			$wpdb->query("ALTER TABLE $this->table_stats ADD COLUMN user VARCHAR(255) DEFAULT '' AFTER ip");
			
		if (!$is_field_right_size){
			$wpdb->query("ALTER TABLE $this->table_stats MODIFY referer VARCHAR(2048), MODIFY searchterms VARCHAR(2048), MODIFY resource VARCHAR(2048)");
			$wpdb->query("ALTER TABLE $this->table_outbound MODIFY outbound_resource VARCHAR(2048)");
			
		}
		return true;
	}
	// end _update_stats_table

	/**
	 * Reads data from CSV file and copies them into countries table
	 */
	private function _import_countries(){
		global $wpdb;

		// If there is already a (not empty) country table, skip import
		$country_rows = $wpdb->get_var("SELECT COUNT(*) FROM `$this->table_countries`", 0);
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
		$insert_sql = "INSERT INTO `$this->table_countries` ( `ip_from`, `ip_to`, `country_code` ) VALUES ";

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
	private function _get_country($_ip = ''){
		global $wpdb;

		$sql = "SELECT `country_code`
					FROM `$this->table_countries`
					WHERE `ip_from` <= $_ip AND `ip_to` >= $_ip";

		$country_code = $wpdb->get_var($sql, 0 , 0);
		if (!empty($country_code)) return $country_code;

		return 'xx';
	}
	// end _get_country

	/**
	 * Tries to find the user's REAL IP address
	 */
	private function _get_ip2long_remote_ip(){
		if (isset($_SERVER["HTTP_CLIENT_IP"]) &&
			long2ip($long_ip = ip2long($_SERVER["HTTP_CLIENT_IP"])) == $_SERVER["HTTP_CLIENT_IP"]) return $long_ip;
		if (isset($_SERVER["HTTP_X_FORWARDED_FOR"])){
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
	private function _get_language(){
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
	private function _get_search_terms($_url = array()){
		if(!is_array($_url) || !isset($_url['host']) || !isset($_url['query'])) return '';

		parse_str($_url['query'], $query);
		parse_str("daum=q&eniro=search_word&naver=query&images.google=q&google=q&yahoo=p&msn=q&bing=q&aol=query&aol=encquery&lycos=query&ask=q&altavista=q&netscape=query&cnn=query&about=terms&mamma=query&alltheweb=q&voila=rdata&virgilio=qs&live=q&baidu=wd&alice=qs&yandex=text&najdi=q&aol=q&mama=query&seznam=q&search=q&wp=szukaj&onet=qt&szukacz=q&yam=k&pchome=q&kvasir=q&sesam=q&ozu=q&terra=query&mynet=q&ekolay=q&rambler=words", $query_formats);
		preg_match("/(daum|eniro|naver|images.google|google|yahoo|msn|bing|aol|aol|lycos|ask|altavista|netscape|cnn|about|mamma|alltheweb|voila|virgilio|live|baidu|alice|yandex|najdi|aol|mama|seznam|search|wp|onet|szukacz|yam|pchome|kvasir|sesam|ozu|terra|mynet|ekolay|rambler)./", $_url['host'], $matches);
		if (isset($matches[1]) && isset($query[$query_formats[$matches[1]]])) return str_replace('\\', '', trim(urldecode($query[$query_formats[$matches[1]]])));

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
	private function _count_records(){
		global $wpdb;
		return intval($wpdb->get_var("SELECT COUNT(*) FROM `$this->table_stats`"));
	}
	// end _count_records

	/**
	 * Removes old entries from the database
	 */
	public function wp_slimstat_purge(){
		global $wpdb;

		if (($autopurge_interval = intval(get_option('slimstat_auto_purge', 0))) <= 0) return;

		// Delete old entries
		$wpdb->query("DELETE ts, tv FROM `$this->table_stats` ts, `$this->table_visits` tv WHERE ts.`dt` < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL $autopurge_interval DAY)) AND ts.`visit_id` = tv.`visit_id`");

		// Delete all the other entries with no matching visit records
		$wpdb->query("DELETE ts FROM `$this->table_stats` ts WHERE ts.`dt` < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL $autopurge_interval DAY))");
	}
	// end wp_slimstat_purge

	/**
	 * Loads a custom stylesheet file for the administration panels
	 */
	public function wp_slimstat_stylesheet(){
		$stylesheeth_url = $this->plugin_url . '/wp-slimstat/css/view.css';
		wp_register_style('wp-slimstat-view', $stylesheeth_url);
		wp_enqueue_style('wp-slimstat-view');
	}
	// end wp_slimstat_stylesheet

	/**
	 * Adds a new entry in the admin menu, to view the stats
	 */
	public function wp_slimstat_add_view_menu($_s){
		global $current_user;

		// Load localization files
		load_plugin_textdomain('wp-slimstat', WP_PLUGIN_DIR .'/wp-slimstat/lang', '/wp-slimstat/lang');

		$array_allowed_users = get_option('slimstat_can_view', array());
		$minimum_capability = get_option('slimstat_capability_can_view', 'read');
		$minimum_capability = empty($minimum_capability)?'read':$minimum_capability;
		$use_separate_menu = get_option('slimstat_use_separate_menu', 'no');

		if ((empty($array_allowed_users) && $minimum_capability == 'read') || in_array($current_user->user_login, $array_allowed_users) || current_user_can($minimum_capability)){
			if ($use_separate_menu == 'yes')
				add_menu_page('SlimStat', 'SlimStat', $minimum_capability, WP_PLUGIN_DIR.'/wp-slimstat/view/index.php', '', $this->plugin_url.'/wp-slimstat/images/wp-slimstat-menu.png');
			else
				add_submenu_page('index.php', 'SlimStat', 'SlimStat', $minimum_capability, WP_PLUGIN_DIR.'/wp-slimstat/view/index.php');
		}
		return $_s;
	}
	// end wp_slimstat_add_view_menu

	/**
	 * Adds a new entry in the admin menu, to manage SlimStat options
	 */
	public function wp_slimstat_add_config_menu($_s){
		global $current_user;

		$array_allowed_users = get_option('slimstat_can_admin', array());
		$use_separate_menu = get_option('slimstat_use_separate_menu', 'no');
		if (empty($array_allowed_users) || in_array($current_user->user_login, $array_allowed_users)){
			if ($use_separate_menu == 'yes' || !current_user_can('manage_options'))
				add_menu_page('SlimStat Config', 'SlimStat Config', 'edit_posts', WP_PLUGIN_DIR.'/wp-slimstat/options/index.php');
			else
				add_submenu_page('options-general.php', 'SlimStat', 'SlimStat', 'edit_posts', WP_PLUGIN_DIR.'/wp-slimstat/options/index.php');
		}
		return $_s;
	}
	// end wp_slimstat_add_config_menu

	/**
	 * Adds a javascript code to track users' screen resolution and other browser-based information
	 */
	public function wp_slimstat_javascript(){
		global $wpdb;
		if ($this->tid > 0){
			$intval_tid = intval($this->tid);
			$hexval_tid = base_convert($intval_tid, 10, 16);
			$my_secret_key = get_option('slimstat_secret', '123');
			$custom_slimstat_js_path = get_option('slimstat_custom_js_path', WP_PLUGIN_URL.'/wp-slimstat');
			$enable_footer_link = get_option('slimstat_enable_footer_link', 'yes');

			if ($enable_footer_link == 'yes') echo '<p id="statsbywpslimstat" style="text-align:center"><a href="http://www.duechiacchiere.it/wp-slimstat" title="A simple but powerful web analytics plugin for Wordpress"><img src="'.$this->plugin_url.'/wp-slimstat/images/wp-slimstat-antipixel.png" width="80" height="15" alt="WP SlimStat"/></a></p>';

			echo "<script type='text/javascript'>slimstat_tid='$hexval_tid';";
			echo "slimstat_path='$custom_slimstat_js_path';";
			$slimstat_blog_id = (function_exists('is_multisite') && is_multisite())?$wpdb->blogid:0;
			echo "slimstat_blog_id='$slimstat_blog_id';";
			echo 'slimstat_session_id=\''.md5($intval_tid.$my_secret_key).'\';</script>';
			echo "<script type='text/javascript' src='$this->plugin_url/wp-slimstat/wp-slimstat.js'></script>\n";
		}
	}
	// end wp_slimstat_javascript

	/**
	 * Contextual help (link to the support forum)
	 */
	public function wp_slimstat_contextual_help($contextual_help, $screen_id, $screen){
		if (($screen_id == 'wp-slimstat/view/index') || ($screen_id == 'wp-slimstat/options/index')){
			load_plugin_textdomain('wp-slimstat-view', WP_PLUGIN_URL .'/wp-slimstat/lang', '/wp-slimstat/lang');
			$contextual_help = __('Need help on how to use WP SlimStat? Visit the official','wp-slimstat-view').' <a href="http://wordpress.org/tags/wp-slimstat?forum_id=10" target="_blank">'.__('support forum','wp-slimstat-view').'</a>. ';
			$contextual_help .= __('Feeling generous?','wp-slimstat-view').' <a href="https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=Z732JS7KQ6RRL&lc=US&item_name=WP%20SlimStat&currency_code=USD&bn=PP%2dDonationsBF%3abtn_donate_SM%2egif%3aNonHosted" target="_blank">'.__('Donate a few bucks!','wp-slimstat-view').'</a>';
		}
		return $contextual_help;
	}
	// end wp_slimstat_contextual_help
}
// end of class declaration

// Ok, let's use every tool we defined here above
$wp_slimstat = new wp_slimstat();
