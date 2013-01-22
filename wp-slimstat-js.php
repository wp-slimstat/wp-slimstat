<?php
define('WP_SLIMSTAT_JS', true);
class wp_slimstat_js{
	protected static $abspath = '';
	protected static $wp_config_parsed = '';
	protected static $clean_tokens = array();
	protected static $wp_config_params = array();
	protected static $db_handle = null;	
	protected static $cookiepath = '';

	protected static $stat = array();
	protected static $screenres = array();
	
	public static $wp_config_path = '';
	public static $data_js = array();
	public static $options = array();
	
	public static function parse_js_data(){
		// Process the data received by the client
		if (empty($_REQUEST['data'])){
			exit('-105 : invalid data format');
		}
		$data_string = base64_decode($_REQUEST['data']);
		if ($data_string === false){
			exit('-106 : invalid data format');
		}

		// Parse the information we received
		parse_str($data_string, self::$data_js);
		return true;
	}
	
	public static function init_environment(){
		self::$abspath = dirname(__FILE__); // Same folder
		self::$wp_config_path = 'wp-config.php';

		if (file_exists(self::$abspath.'/wp-slimstat-config.php')){
			include_once(self::$abspath.'/wp-slimstat-config.php');
			self::$wp_config_path = $wp_config_path;
		}
		else{
			self::$abspath = dirname(dirname(self::$abspath)); // Two folders up (default wp-content location)
			if (file_exists(self::$abspath.'/wp-slimstat-config.php')){
				include_once(self::$abspath.'/wp-slimstat-config.php');
				self::$wp_config_path = $wp_config_path;
			}
			else{
				self::$abspath = dirname(self::$abspath); // Three folders up (default wp-config.php location)
				if (!file_exists(self::$abspath.'/wp-config.php')){
					self::$abspath = dirname(self::$abspath);
				}
				self::$wp_config_path = self::$abspath.'/wp-config.php';
			}
		}

		if (!file_exists(self::$wp_config_path)){
			exit('-101 : wp-config.php not found');
		}

		// Parse config file
		self::$wp_config_parsed = token_get_all(file_get_contents(self::$wp_config_path));

		// Let's get rid of most of the unwanted crap
		foreach(self::$wp_config_parsed as $a_token) {
			if (is_array($a_token) && ( ($a_token[0] == T_CONSTANT_ENCAPSED_STRING) || (($a_token[0] == T_VARIABLE))) ) self::$clean_tokens[] = str_replace("'", '', str_replace('"', '', $a_token[1]));
		}

		// Now I can retrieve the information I need to connect to the database
		foreach(self::$clean_tokens as $a_token_id => $a_token) {
			switch ($a_token) {
				case 'DB_NAME':
					self::$wp_config_params['db_name'] = self::$clean_tokens[$a_token_id+1];
					break;
				case 'DB_USER':
					self::$wp_config_params['db_user'] = self::$clean_tokens[$a_token_id+1];
					break;
				case 'DB_PASSWORD':
					self::$wp_config_params['db_password'] = self::$clean_tokens[$a_token_id+1];
					break;
				case 'DB_HOST':
					self::$wp_config_params['db_host'] = self::$clean_tokens[$a_token_id+1];
					break;
				case '$table_prefix':
					self::$wp_config_params['table_prefix'] = self::$clean_tokens[$a_token_id+1];
					break;
				default:
					break;
			}
		}

		// This is odd, but it could happen...
		if (empty(self::$wp_config_params['db_name']) || empty(self::$wp_config_params['db_user']) || empty(self::$wp_config_params['db_password']) || empty(self::$wp_config_params['db_host'])){
			exit('-102 : error parsing wp-config');
		}
		if (empty(self::$wp_config_params['table_prefix'])){
			self::$wp_config_params['table_prefix'] = 'wp_';
		}

		// Let's see if we can connect to the database
		self::$db_handle = mysql_connect(self::$wp_config_params['db_host'], self::$wp_config_params['db_user'], self::$wp_config_params['db_password']);
		if (!self::$db_handle){
			exit('-103 : could not connect - '.mysql_error());
		}
		if (!mysql_select_db(self::$wp_config_params['db_name'])){
			@mysql_close(self::$db_handle);
			exit('-104 : could not select the db - '.mysql_error());
		}

		// Abort if WP SlimStat main table isn't in the database (plugin not activated?)
		$db_list_tables = @mysql_query("SHOW TABLES");
		$is_table_active = false;
		self::$wp_config_params['multisite_table_prefix'] = self::$wp_config_params['table_prefix'];
		
		self::parse_js_data();
		
		// Multisite awareness
		$blog_id = isset(self::$data_js['bid'])?intval(self::$data_js['bid']):1;
		if (!empty($blog_id) && $blog_id > 1){
			while ($row = @mysql_fetch_row($db_list_tables)) {
				if ($is_table_active = ($row[0] == self::$wp_config_params['table_prefix'].$blog_id.'_slim_stats')){
					self::$wp_config_params['multisite_table_prefix'] = self::$wp_config_params['table_prefix'].$blog_id.'_';
					break;
				}
			}
		}
		// Let's see if this is a stand-alone blog
		if (!$is_table_active){
			@mysql_data_seek($db_list_tables, 0);
			while ($row = @mysql_fetch_row($db_list_tables)) {
				if ($is_table_active = ($row[0] == self::$wp_config_params['table_prefix'].'slim_stats')){
					self::$wp_config_params['multisite_table_prefix'] = self::$wp_config_params['table_prefix'];
					break;
				}
			}
			
			if (!$is_table_active){
				@mysql_close(self::$db_handle);
				exit('-107 : slimStat table not found');
			}
		}
		
		// Load the plugin's options
		self::$options = unserialize(self::get_option('slimstat_options', ''));
		
		// A secret key is used to make sure the script only works when called from a legit referer (the blog itself!)
		if (empty(self::$options['secret'])){
			@mysql_close($db_handle);
			exit('-108 : invalid private key');
		}

		// Blog URL detection
		$site_url = self::get_option('home');
		if (empty($site_url)) $site_url = self::get_option('siteurl');
		if (empty($site_url)) $site_url = $_SERVER['HTTP_HOST'];
		self::$cookiepath = preg_replace('|https?://[^/]+|i', '', $site_url . '/' );
		
		// This request is not coming from the same domain
		if (empty($_SERVER['HTTP_REFERER']) || ((strpos($_SERVER['HTTP_REFERER'], $site_url) === false) && (strpos($_SERVER['HTTP_REFERER'], "http://" . $_SERVER['HTTP_HOST']) === false ))){
			@mysql_close($db_handle);
			exit('-109 : invalid HTTP_REFERER');
		}
		
		return true;
	}

	public static function slimtrack_js(){
		// This script can be called either to track outbound links (and downloads) or 'returning' visitors
		self::$stat['outbound_domain'] = !empty(self::$data_js['obd'])?mysql_real_escape_string(strip_tags(self::$data_js['obd'])):'';
		self::$stat['outbound_resource'] = !empty(self::$data_js['obr'])?mysql_real_escape_string(strip_tags(trim(self::$data_js['obr']))):'';
		if (!empty(self::$stat['outbound_resource']) && strpos(self::$stat['outbound_resource'], '://') == false && substr(self::$stat['outbound_resource'], 0, 1) != '/' && substr(self::$stat['outbound_resource'], 0, 1) != '#') self::$stat['outbound_resource'] = '/'.self::$stat['outbound_resource'];
		self::$stat['notes'] = !empty(self::$data_js['no'])?mysql_real_escape_string(strip_tags(trim(self::$data_js['no']))):'';
		self::$stat['position'] = !empty(self::$data_js['po'])?mysql_real_escape_string(strip_tags(trim(self::$data_js['po']))):'';
		self::$stat['type'] = isset(self::$data_js['ty'])?intval(self::$data_js['ty']):-1;

		// Is the ID valid?
		self::$stat['id'] = empty(self::$data_js['id'])?-1:base_convert(self::$data_js['id'], 16, 10);

		if (empty(self::$data_js['obr']) && (empty(self::$data_js['id']) || self::$data_js['sid'] != md5(self::$stat['id'].self::$options['secret'])) && self::$options['javascript_mode'] != 'yes'){
			@mysql_close($db_handle);
			exit('-110 : invalid public key');
		}

		if (!empty(self::$stat['outbound_resource']) && self::$stat['type'] >= 0){
			$timezone = self::get_option('timezone_string');
			if (!empty($timezone)) date_default_timezone_set($timezone);
			$lt = localtime();
			if (!empty($timezone)) date_default_timezone_set('UTC');
			self::$stat['dt'] = mktime($lt[2], $lt[1], $lt[0], $lt[4]+1, $lt[3], $lt[5]+1900);

			@mysql_query(self::prepare('INSERT INTO '.self::$wp_config_params['multisite_table_prefix'].' ('.implode(', ', array_keys(self::$stat)).') VALUES ('.substr(str_repeat('%s,', count(self::$stat)), 0, -1).')', self::$stat));
			@mysql_close($db_handle);
			return self::$stat['id'];
		}

		self::$stat['plugins'] = (!empty(self::$data_js['pl']))?mysql_real_escape_string(substr(str_replace('|', ',', self::$data_js['pl']), 0, -1)):'';
		self::$screenres['resolution'] = (!empty(self::$data_js['sw']) && !empty(self::$data_js['sh']))?mysql_real_escape_string( self::$data_js['sw'].'x'.self::$data_js['sh'] ):'';
		self::$screenres['colordepth'] = (!empty(self::$data_js['cd']))?mysql_real_escape_string( self::$data_js['cd'] ):'';
		self::$screenres['antialias'] = (!empty(self::$data_js['aa']) && self::$data_js['aa']=='1')?'1':'0';

		// Now we insert the new screen resolution in the lookup table, if it doesn't exist
		$select_sql = '
			SELECT screenres_id
			FROM '.self::$wp_config_params['multisite_table_prefix'].'slim_screenres
			WHERE resolution = %s AND colordepth = %s AND antialias = %s
			LIMIT 1';

		self::$stat['screenres_id'] = self::get_var(self::prepare($select_sql, self::$screenres['resolution'], self::$screenres['colordepth'], self::$screenres['antialias']));
		if ( empty(self::$stat['screenres_id']) ) {
			@mysql_query(self::prepare('INSERT IGNORE INTO '.self::$wp_config_params['multisite_table_prefix'].'slim_screenres ('.implode(', ', array_keys(self::$screenres)).') VALUES ('.substr(str_repeat('%s,', count(self::$screenres)), 0, -1).')', self::$screenres));
			self::$stat['screenres_id'] = @mysql_insert_id();
			
			if ( empty(self::$stat['screenres_id']) ) { // This can happen if another transaction had added the new line in the meanwhile
				self::$stat['screenres_id'] = self::get_var($select_sql);
			}
		}

		// Update the visit_id for this session
		if (isset($_COOKIE['slimstat_tracking_code'])){
			if (empty(self::$options['session_duration'])) self::$options['session_duration'] = 1800;

			list($identifier, $control_code) = explode('.', $_COOKIE['slimstat_tracking_code']);
					
			// Make sure only authorized information is recorded
			if ($control_code == md5($identifier.self::$options['secret'])){
				
				// Set the visit_id for this session's first pageview
				if (strpos($identifier, 'id') !== false){
					self::$stat['visit_id'] = self::get_option('slimstat_visit_id', -1);
					if (self::$stat['visit_id'] == -1){
						self::$stat['visit_id'] = intval(self::get_var('SELECT MAX(visit_id) FROM '.self::$wp_config_params['multisite_table_prefix'].'slim_stats'));
					}
					self::$stat['visit_id']++;
					self::update_option('slimstat_visit_id', self::$stat['visit_id']);

					@mysql_query(self::prepare('
						UPDATE '.self::$wp_config_params['multisite_table_prefix'].'slim_stats
						SET visit_id = %d
						WHERE id = %d AND visit_id = 0', self::$stat['visit_id'], intval($identifier)));	

					@setcookie('slimstat_tracking_code', self::$stat['visit_id'].'.'.md5(self::$stat['visit_id'].self::$options['secret']), time()+self::$options['session_duration'], self::$cookiepath);
				}
			}
		}

		// Finally we can update the information about this visit
		if (!empty(self::$stat['screenres_id'])){
			@mysql_query(self::prepare('
				UPDATE '.self::$wp_config_params['multisite_table_prefix'].'slim_stats
				SET screenres_id = %s, plugins = %s
				WHERE id = %d', self::$stat['screenres_id'], self::$stat['plugins'], self::$stat['id']));
		}
		// Send the ID back to Javascript to track future interactions
		echo base_convert(self::$stat['id'], 10, 16);

		// Close the connection to the database
		@mysql_close($db_handle);
		return self::$stat['id'];
	}

	protected static function get_option($_option_name = '', $_default_value = '') {
		global $multisite_table_prefix;
		
		$resource = @mysql_query('SELECT option_value FROM '.self::$wp_config_params['multisite_table_prefix']."options WHERE option_name = '$_option_name'");
		
		$result = @mysql_fetch_assoc($resource);
		if (!empty($result['option_value']))
			return $result['option_value'];
		else
			return $_default_value;
	}

	protected static function update_option($_option_name = '', $_option_value = '') {
		global $multisite_table_prefix;
		
		@mysql_query('UPDATE '.self::$wp_config_params['multisite_table_prefix']."options SET option_value = '$_option_value' WHERE option_name = '$_option_name'");
		
		return $_option_value;
	}

	protected static function get_var($_sql_query = '') {	
		$resource = @mysql_query($_sql_query);
		$result = @mysql_fetch_row($resource);
		if (!empty($result[0]))
			return $result[0];
		else
			return false;
	}

	protected static function prepare( $query = null ) {
		if ( is_null( $query ) ) return;

		$args = func_get_args();
		array_shift( $args );

		// If args were passed as an array (as in vsprintf), move them up
		if ( isset( $args[0] ) && is_array($args[0]) ) $args = $args[0];
		
		$query = str_replace("'%s'", '%s', $query); // in case someone mistakenly already singlequoted it
		$query = str_replace('"%s"', '%s', $query); // doublequote unquoting
		$query = preg_replace('|(?<!%)%s|', "'%s'", $query); // quote the strings, avoiding escaped strings like %%s
		array_walk($args, array(__CLASS__, 'escape_by_ref'));
		return @vsprintf( $query, $args );
	}

	protected static function escape_by_ref( &$string ) {
		if ( function_exists('mysql_real_escape_string') )
			$string = mysql_real_escape_string($string);
		else
			$string = addslashes($string);
	}
}

wp_slimstat_js::init_environment();
if (wp_slimstat_js::$options['javascript_mode'] == 'yes'){

	// Trick Wordpress into thinking this is the activation routine, so that no plugins are loaded
	include_once(wp_slimstat_js::$wp_config_path);
	include_once('./wp-slimstat.php');

	// We can pass the information received from Javascript about content type and other resource-related information
	wp_slimstat::$data_js_mode = wp_slimstat_js::$data_js;
	wp_slimstat::slimtrack();

	// Was this pageview tracked?
	if (wp_slimstat::$tid < 0) exit(wp_slimstat::$tid.' : Visit not tracked');

	wp_slimstat_js::$data_js['id'] = base_convert(wp_slimstat::$tid, 10, 16);
	wp_slimstat_js::$data_js['sid'] = md5(wp_slimstat::$tid.wp_slimstat_js::$options['secret']);
	wp_slimstat_js::slimtrack_js();
}
else{
	wp_slimstat_js::slimtrack_js();
}