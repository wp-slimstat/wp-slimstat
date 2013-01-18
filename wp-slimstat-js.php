<?php

$abspath = dirname(__FILE__); // Same folder
if (file_exists($abspath.'/wp-slimstat-config.php')){
	include_once($abspath.'/wp-slimstat-config.php');
}
else{
	$abspath = dirname(dirname($abspath)); // Two folders up (default wp-content location)
	if (file_exists($abspath.'/wp-slimstat-config.php')){
		include_once($abspath.'/wp-slimstat-config.php');
	}
	else{
		$abspath = dirname($abspath); // Three folders up (default wp-config.php location)
		if (!file_exists($abspath.'/wp-config.php')){
			$abspath = dirname($abspath);
		}
		$wp_config_path = $abspath.'/wp-config.php';
	}
}

if (!file_exists($wp_config_path)){
	exit('-101 : wp-config not found');
}

// Parse config file
$wp_config = file_get_contents($wp_config_path);
$parsed_config = token_get_all($wp_config);
$db_name = $db_user = $db_password = $db_host = $table_prefix = '';

// Let's get rid of most of the unwanted crap
foreach($parsed_config as $a_token) {
	if (is_array($a_token) && ( ($a_token[0] == T_CONSTANT_ENCAPSED_STRING) || (($a_token[0] == T_VARIABLE))) ) $clean_tokens[] = str_replace("'", '', str_replace('"', '', $a_token[1]));
}

// Now I can retrieve the information I need to connect to the database
foreach($clean_tokens as $a_token_id => $a_token) {
	switch ($a_token) {
		case 'DB_NAME':
			$db_name = $clean_tokens[$a_token_id+1];
			break;
		case 'DB_USER':
			$db_user = $clean_tokens[$a_token_id+1];
			break;
		case 'DB_PASSWORD':
			$db_password = $clean_tokens[$a_token_id+1];
			break;
		case 'DB_HOST':
			$db_host = $clean_tokens[$a_token_id+1];
			break;
		case '$table_prefix':
			$table_prefix = $clean_tokens[$a_token_id+1];
			break;
		default:
			break;
	}
}

// This is odd, but it could happen...
if (empty($db_name) || empty($db_user) || empty($db_password) || empty($db_host)){
	exit('-102 : error parsing wp-config');
}
if (empty($table_prefix)){
	$table_prefix = 'wp_';
}

// Let's see if we can connect to the database
$db_handle = mysql_connect($db_host, $db_user, $db_password);
if (!$db_handle){
	exit('-103 : could not connect - '.mysql_error());
}
if (!mysql_select_db($db_name)){
	@mysql_close($db_handle);
	exit('-104 : could not select the db - '.mysql_error());
}

// Abort if WP SlimStat main table isn't in the database (plugin not activated?)
$db_list_tables = @mysql_query("SHOW TABLES");
$is_table_active = false;
$multisite_table_prefix = $table_prefix;

// Process the data received by the client
if (empty($_REQUEST['data'])){
	exit('-105 : invalid data format');
}
$data_string = base64_decode($_REQUEST['data']);
if ($data_string === false){
	exit('-106 : invalid data format');
}

// Parse the information we received
parse_str($data_string, $data);

// Multisite awareness
$blog_id = isset($data['bid'])?intval($data['bid']):1;
if (!empty($blog_id) && $blog_id > 1){
	while ($row = @mysql_fetch_row($db_list_tables)) {
		if ($is_table_active = ($row[0] == "{$table_prefix}{$blog_id}_slim_stats")){
			$multisite_table_prefix = "{$table_prefix}{$blog_id}_";
			break;
		}
	}
}
// Let's see if this is a stand-alone blog
if (!$is_table_active){
	@mysql_data_seek($db_list_tables, 0);
	while ($row = @mysql_fetch_row($db_list_tables)) {
		if ($is_table_active = ($row[0] == "{$table_prefix}slim_stats")){
			$multisite_table_prefix = $table_prefix;
			break;
		}
	}
	
	if (!$is_table_active){
		@mysql_close($db_handle);
		exit('-107 : slimStat table not found');
	}
}

// Well, it looks like we are ready to roll
$stat = array();

// This secret key is used to make sure the script only works when called from a legit referer (the blog itself!)
$slimstat_options = unserialize(slimstat_get_option('slimstat_options', ''));

if (empty($slimstat_options['secret'])){
	@mysql_close($db_handle);
	exit('-108 : invalid private key');
}

// Blog URL detection
$site_url = slimstat_get_option('home');
if (empty($site_url)) $site_url = slimstat_get_option('siteurl');
if (empty($site_url)) $site_url = $_SERVER['HTTP_HOST'];
$cookiepath = preg_replace('|https?://[^/]+|i', '', $site_url . '/' );

// This request is not coming from the same domain
if (empty($_SERVER['HTTP_REFERER']) || ((strpos($_SERVER['HTTP_REFERER'], $site_url) === false) && (strpos($_SERVER['HTTP_REFERER'], "http://" . $_SERVER['HTTP_HOST']) === false ))){
	@mysql_close($db_handle);
	exit('-109 : invalid HTTP_REFERER');
}

// This script can be called either to track outbound links (and downloads) or 'returning' visitors
$stat['outbound_domain'] = !empty($data['obd'])?mysql_real_escape_string(strip_tags($data['obd'])):'';
$stat['outbound_resource'] = !empty($data['obr'])?mysql_real_escape_string(strip_tags(trim($data['obr']))):'';
if (!empty($stat['outbound_resource']) && strpos($stat['outbound_resource'], '://') == false && substr($stat['outbound_resource'], 0, 1) != '/' && substr($stat['outbound_resource'], 0, 1) != '#') $stat['outbound_resource'] = '/'.$stat['outbound_resource'];
$stat['notes'] = !empty($data['no'])?mysql_real_escape_string(strip_tags(trim($data['no']))):'';
$stat['position'] = !empty($data['po'])?mysql_real_escape_string(strip_tags(trim($data['po']))):'';
$stat['type'] = isset($data['ty'])?intval($data['ty']):-1;

// Is the ID valid?
$stat['id'] = empty($data['id'])?0:base_convert($data['id'], 16, 10);

if (empty($data['obr']) && (empty($data['id']) || $data['sid'] != md5($stat['id'].$slimstat_options['secret'])) && $slimstat_options['javascript_mode'] != 'yes'){
	@mysql_close($db_handle);
	exit('-110 : invalid public key');
}

if (!empty($stat['outbound_resource']) && $stat['type'] >= 0){
	$timezone = slimstat_get_option('timezone_string');
	if (!empty($timezone)) date_default_timezone_set($timezone);
	$lt = localtime();
	if (!empty($timezone)) date_default_timezone_set('UTC');
	$stat['dt'] = mktime($lt[2], $lt[1], $lt[0], $lt[4]+1, $lt[3], $lt[5]+1900);

	@mysql_query(slimstat_prepare("INSERT INTO {$multisite_table_prefix}slim_outbound (".implode(', ', array_keys($stat)).') VALUES ('.substr(str_repeat('%s,', count($stat)), 0, -1).')', $stat));
	@mysql_close($db_handle);
	exit(0);
}

if ($slimstat_options['javascript_mode'] == 'yes'){
	include_once($wp_config_path);
	include_once('./wp-slimstat.php');

	// We can pass the information received from Javascript about content type and other resource-related information
	wp_slimstat::slimtrack($data);

	// Was this pageview tracked?
	if (wp_slimstat::$tid < 0) exit(wp_slimstat::$tid.' : Visit not tracked');

	$stat['id'] = wp_slimstat::$tid;
}

$stat['plugins'] = (!empty($data['pl']))?mysql_real_escape_string(substr(str_replace('|', ',', $data['pl']), 0, -1)):'';
$screenres['resolution'] = (!empty($data['sw']) && !empty($data['sh']))?mysql_real_escape_string( $data['sw'].'x'.$data['sh'] ):'';
$screenres['colordepth'] = (!empty($data['cd']))?mysql_real_escape_string( $data['cd'] ):'';
$screenres['antialias'] = (!empty($data['aa']) && $data['aa']=='1')?'1':'0';

// Now we insert the new screen resolution in the lookup table, if it doesn't exist
$select_sql = "SELECT screenres_id
				FROM {$table_prefix}slim_screenres
				WHERE resolution = '{$screenres['resolution']}' AND colordepth = '{$screenres['colordepth']}' AND antialias = {$screenres['antialias']}
				LIMIT 1";

$stat['screenres_id'] = slimstat_get_var($select_sql);
if ( empty($stat['screenres_id']) ) {
	@mysql_query(slimstat_prepare("INSERT IGNORE INTO {$table_prefix}slim_screenres (".implode(', ', array_keys($screenres)).') VALUES ('.substr(str_repeat('%s,', count($screenres)), 0, -1).')', $screenres));
	$stat['screenres_id'] = @mysql_insert_id();
	
	if ( empty($stat['screenres_id']) ) { // This can happen if another transaction had added the new line in the meanwhile
		$stat['screenres_id'] = slimstat_get_var($select_sql);
	}
}

// Update the visit_id for this session
if (isset($_COOKIE['slimstat_tracking_code'])){
	if (empty($slimstat_options['session_duration'])) $slimstat_options['session_duration'] = 1800;

	list($identifier, $control_code) = explode('.', $_COOKIE['slimstat_tracking_code']);
			
	// Make sure only authorized information is recorded
	if ($control_code == md5($identifier.$slimstat_options['secret'])){
		
		// Set the visit_id for this session's first pageview
		if (strpos($identifier, 'id') !== false){
			$stat['visit_id'] = slimstat_get_option('slimstat_visit_id', -1);
			if ($stat['visit_id'] == -1){
				$stat['visit_id'] = intval(slimstat_get_var("SELECT MAX(visit_id) FROM {$multisite_table_prefix}slim_stats"));
			}
			$stat['id'] = intval($identifier);
			$stat['visit_id']++;
			slimstat_update_option('slimstat_visit_id', $stat['visit_id']);

			setcookie('slimstat_tracking_code', "{$stat['visit_id']}.".md5($stat['visit_id'].$slimstat_options['secret']), time()+$slimstat_options['session_duration'], $cookiepath);
		}
		else{
			$stat['visit_id'] = intval($identifier);
			if ($slimstat_options['extend_session'] == 'yes'){
				setcookie('slimstat_tracking_code', $stat['visit_id'].'.'.md5($stat['visit_id'].$slimstat_options['secret']), time()+$slimstat_options['session_duration'], $cookiepath);
			}
		}
	}
}
elseif ($slimstat_options['javascript_mode'] == 'yes'){
	$stat['visit_id'] = slimstat_get_option('slimstat_visit_id', -1);
	if ($stat['visit_id'] == -1){
		$stat['visit_id'] = intval(slimstat_get_var("SELECT MAX(visit_id) FROM {$multisite_table_prefix}slim_stats"));
	}
	$stat['visit_id']++;
	slimstat_update_option('slimstat_visit_id', $stat['visit_id']);

	setcookie('slimstat_tracking_code', "{$stat['visit_id']}.".md5($stat['visit_id'].$slimstat_options['secret']), time()+$slimstat_options['session_duration'], $cookiepath);
}

// Finally we can update the information about this visit
if (!empty($stat['screenres_id']) || !empty($stat['visit_id'])){
	$update_screenres_id = !empty($stat['screenres_id'])?"screenres_id = {$stat['screenres_id']},":'';
	$update_visit_id = !empty($stat['visit_id'])?"visit_id = {$stat['visit_id']},":'';

	@mysql_query("UPDATE {$multisite_table_prefix}slim_stats SET $update_screenres_id $update_visit_id plugins = '{$stat['plugins']}' WHERE id = {$stat['id']}");
}
// Send the ID back to Javascript to track future interactions
echo base_convert($stat['id'], 10, 16);

// Close the connection to the database
@mysql_close($db_handle);
exit(0);

function slimstat_get_option($_option_name = '', $_default_value = '') {
	global $multisite_table_prefix;
	
	$resource = @mysql_query("SELECT option_value FROM {$multisite_table_prefix}options WHERE option_name = '$_option_name'");
	
	$result = @mysql_fetch_assoc($resource);
	if (!empty($result['option_value']))
		return $result['option_value'];
	else
		return $_default_value;
}
function slimstat_update_option($_option_name = '', $_option_value = '') {
	global $multisite_table_prefix;
	
	@mysql_query("UPDATE {$multisite_table_prefix}options SET option_value = '$_option_value' WHERE option_name = '$_option_name'");
	
	return $_option_value;
}
function slimstat_get_var($_sql_query = '') {	
	$resource = @mysql_query($_sql_query);
	$result = @mysql_fetch_row($resource);
	if (!empty($result[0]))
		return $result[0];
	else
		return false;
}
function slimstat_prepare( $query = null ) {
	if ( is_null( $query ) ) return;

	$args = func_get_args();
	array_shift( $args );

	// If args were passed as an array (as in vsprintf), move them up
	if ( isset( $args[0] ) && is_array($args[0]) ) $args = $args[0];
	
	$query = str_replace("'%s'", '%s', $query); // in case someone mistakenly already singlequoted it
	$query = str_replace('"%s"', '%s', $query); // doublequote unquoting
	$query = preg_replace('|(?<!%)%s|', "'%s'", $query); // quote the strings, avoiding escaped strings like %%s
	array_walk($args, 'slimstat_escape_by_ref');
	return @vsprintf( $query, $args );
}
function slimstat_escape_by_ref( &$string ) {
	if ( function_exists('mysql_real_escape_string') )
		$string = mysql_real_escape_string($string);
	else
		$string = addslashes($string);
}