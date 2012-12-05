<?php

// Where is your wp-config.php located relatively to this file?
$wp_root_folder = '../../..';

// That's all, stop editing! Happy tracking. 

// Abort if config file cannot be found
if (!file_exists($wp_root_folder.'/wp-config.php')){
	exit('Error: wp-config not found');
}
// Parse config file
$wp_config = file_get_contents($wp_root_folder.'/wp-config.php');
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
if (empty($db_name) || empty($db_user) || empty($db_password) || empty($db_host) || empty($table_prefix)){
	exit('Error parsing wp-config');
}

// Let's see if we can connect to the database
$db_handle = mysql_connect($db_host, $db_user, $db_password);
if (!$db_handle){
	exit('Could not connect: '.mysql_error());
}
if (!mysql_select_db($db_name)){
	@mysql_close($db_handle);
	exit('Could not select the db: '.mysql_error());
}

$data_string = base64_decode($_REQUEST['data']);
if (!$data_string){
	exit('Invalid data format');
}

// Abort if WP SlimStat main table isn't in the database (plugin not activated?)
$db_list_tables = @mysql_query("SHOW TABLES");
$is_table_active = false;
$multisite_table_prefix = $table_prefix;

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
	
	while ($row = @mysql_fetch_row($db_list_tables)) {
		if ($is_table_active = ($row[0] == "{$table_prefix}slim_stats")){
			$multisite_table_prefix = $table_prefix;
			break;
		}
	}
	
	if (!$is_table_active){
		@mysql_close($db_handle);
		exit('SlimStat table not found');
	}
}

// Well, looks like we are ready to roll
$stat = array();

// This secret key is used to make sure the script only works when called from a legit referer (the blog itself!)
$slimstat_options = unserialize(slimstat_get_option('slimstat_options', ''));

if (empty($slimstat_options['secret'])){
	@mysql_close($db_handle);
	exit('Invalid private key');
}

// Blog URL detection
$site_url = slimstat_get_option('home');
if (empty($site_url)) $site_url = slimstat_get_option('siteurl');
if (empty($site_url)) $site_url = $_SERVER['HTTP_HOST'];

// This request is not coming from the same domain
if (empty($_SERVER['HTTP_REFERER']) || ((strpos($_SERVER['HTTP_REFERER'], $site_url) === false) && (strpos($_SERVER['HTTP_REFERER'], "http://" . $_SERVER['HTTP_HOST']) === false ))){
	@mysql_close($db_handle);
	exit('Invalid HTTP_REFERER');
}

// Is the ID valid?
$stat['id'] = empty($data['id'])?0:base_convert($data['id'], 16, 10);
if (empty($data['obr']) && (empty($data['id']) || ($data['sid'] != md5($stat['id'].$slimstat_options['secret'])))){
	@mysql_close($db_handle);
	exit('Invalid public key');
}

// This script can be called either to track outbound links (and downloads) or 'returning' visitors
$stat['outbound_domain'] = !empty($data['obd'])?mysql_real_escape_string(strip_tags($data['obd'])):'';
$stat['outbound_resource'] = !empty($data['obr'])?mysql_real_escape_string(strip_tags(trim($data['obr']))):'';
if (!empty($stat['outbound_resource']) && strpos($stat['outbound_resource'], '://') == false && substr($stat['outbound_resource'], 0, 1) != '/' && substr($stat['outbound_resource'], 0, 1) != '#') $stat['outbound_resource'] = '/'.$stat['outbound_resource'];
$stat['notes'] = !empty($data['no'])?mysql_real_escape_string(strip_tags(trim($data['no']))):'';
$stat['position'] = !empty($data['po'])?mysql_real_escape_string(strip_tags(trim($data['po']))):'';
$stat['type'] = isset($data['ty'])?intval($data['ty']):-1;

if (!empty($stat['outbound_resource']) && $stat['type'] >= 0){
	$timezone = slimstat_get_option('timezone_string');
	if (!empty($timezone)) date_default_timezone_set($timezone);
	$lt = localtime();
	if (!empty($timezone)) date_default_timezone_set('UTC');
	$stat['dt'] = mktime($lt[2], $lt[1], $lt[0], $lt[4]+1, $lt[3], $lt[5]+1900);
	
	@mysql_query(slimstat_prepare("INSERT INTO {$multisite_table_prefix}slim_outbound (".implode(', ', array_keys($stat)).') VALUES ('.substr(str_repeat('%s,', count($stat)), 0, -1).')', $screenres));
	@mysql_close($db_handle);
	exit(0);
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

// Update the visit_id for this session's first pageview
if (isset($_COOKIE['slimstat_tracking_code'])){
	list($identifier, $control_code) = explode('.', $_COOKIE['slimstat_tracking_code']);
			
	// Make sure only authorized information is recorded
	if ((strpos($identifier, 'id') !== false) && ($control_code == md5($identifier.$slimstat_options['secret']))){

		// Emulate auto-increment on visit_id
		mysql_query("UPDATE {$multisite_table_prefix}slim_stats 
						SET visit_id = (
							SELECT max_visit_id FROM ( SELECT MAX(visit_id) AS max_visit_id FROM {$multisite_table_prefix}slim_stats ) AS sub_max_visit_id_table
						) + 1
						WHERE id = {$stat['id']} AND visit_id = 0");

		$stat['visit_id'] = slimstat_get_var("SELECT visit_id FROM {$multisite_table_prefix}slim_stats WHERE id = ".intval($identifier));
		@setcookie('slimstat_tracking_code', "{$stat['visit_id']}.".md5($stat['visit_id'].$slimstat_options['secret']), time()+1800, '/');
	}
}

// Finally we can update the information about this visit
if (!empty($stat['screenres_id'])){
	@mysql_query("UPDATE {$multisite_table_prefix}slim_stats SET screenres_id = {$stat['screenres_id']}, plugins = '{$stat['plugins']}' WHERE id = {$stat['id']} AND screenres_id = 0");
}
@mysql_close($db_handle);
exit(0);

function slimstat_get_option($_option_name = '', $_default_value = '') {
	global $multisite_table_prefix;
	
	$resource = @mysql_query("SELECT option_value FROM {$multisite_table_prefix}options WHERE option_name = '{$_option_name}'");
	
	$result = @mysql_fetch_assoc($resource);
	if (!empty($result['option_value']))
		return $result['option_value'];
	else
		return $_default_value;
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