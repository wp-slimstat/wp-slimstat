<?php

// Please change this path if your plugins' folder is not in its standard location. Do not include a trailing slash.
// Where is your wp-config.php located relatively to this file?
$wp_root_folder = '../../..';

// Ok, you don't need to edit anything below this line. Thank you

// Abort execution if config file cannot be located
if (!file_exists($wp_root_folder.'/wp-config.php')){
	echo 'wp-config not found';
	exit;
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
	echo 'Error parsing wp-config';
	exit;
}

// Let's see if we can connect to the database
$db_handle = mysql_connect($db_host, $db_user, $db_password);
if (!$db_handle || !mysql_select_db($db_name)){
	echo 'Could not connect to the db';
	exit;
}

// Abort if WP SlimStat main table isn't in the database (plugin not activated?)
$db_list_tables = @mysql_query("SHOW TABLES");
$is_table_active = false;
$multisite_table_prefix = $table_prefix;

// Multisite awareness
$blog_id = isset($_GET['bid'])?intval($_GET['bid']):1;
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
		echo 'SlimStat table not found';
		exit;
	}
}

// Well, looks like we are ready to roll
$stat = array();

// This secret key is used to make sure the script only works when called from a legit referer (the blog itself!)
$slimstat_options = unserialize(slimstat_get_option('slimstat_options', ''));

if (empty($slimstat_options['secret'])){
	echo 'Secret key not initialized';
	exit;
}

// Blog URL detection
$site_url = slimstat_get_option('home');
if (empty($site_url)) $site_url = slimstat_get_option('siteurl');
if (empty($site_url)) $site_url = $_SERVER['HTTP_HOST'];

// This request is not coming from the same domain
//if (empty($_SERVER['HTTP_REFERER']) || ((strpos($_SERVER['HTTP_REFERER'], $site_url) === false) && (strpos($_SERVER['HTTP_REFERER'], "http://" . $_SERVER['HTTP_HOST']) === false ))){
//	echo 'Invalid referer';
//	exit;
//}

// Is the ID valid?
$stat['id'] = empty($_GET['id'])?0:base_convert($_GET['id'], 16, 10);
if (empty($_GET['obr']) && (empty($_GET['id']) || ($_GET['sid'] != md5($stat['id'].$slimstat_options['secret'])))){
	echo 'Invalid key';
	exit;
}

// This script can be called either to track outbound links (and downloads) or 'returning' visitors
$stat['outbound_domain'] = !empty($_GET['obd'])?mysql_real_escape_string(strip_tags($_GET['obd'])):'';
$stat['outbound_resource'] = !empty($_GET['obr'])?mysql_real_escape_string(strip_tags(trim($_GET['obr']))):'';
if (!empty($stat['outbound_resource']) && strpos($stat['outbound_resource'], '://') == false && substr($stat['outbound_resource'], 0, 1) != '/' && substr($stat['outbound_resource'], 0, 1) != '#') $stat['outbound_resource'] = '/'.$stat['outbound_resource'];
$stat['notes'] = !empty($_GET['no'])?mysql_real_escape_string(strip_tags(trim($_GET['no']))):'';
$stat['position'] = !empty($_GET['po'])?mysql_real_escape_string(strip_tags(trim($_GET['po']))):'';
$stat['type'] = isset($_GET['ty'])?intval($_GET['ty']):-1;

if (!empty($stat['outbound_resource']) && $stat['type'] >= 0){
	$timezone = slimstat_get_option('timezone_string');
	if (!empty($timezone)) date_default_timezone_set($timezone);
	$lt = localtime();
	if (!empty($timezone)) date_default_timezone_set('UTC');
	$stat['dt'] = mktime($lt[2], $lt[1], $lt[0], $lt[4]+1, $lt[3], $lt[5]+1900);
	
	$insert_new_outbound_sql = "
INSERT INTO `{$multisite_table_prefix}slim_outbound` ( `" . implode( "`, `", array_keys( $stat ) ) . "` )
	SELECT '" . implode( "', '", array_values( $stat ) ) . "'
	FROM DUAL
		WHERE NOT EXISTS (
			SELECT `outbound_id`
			FROM `{$multisite_table_prefix}slim_outbound`
			WHERE ";
	foreach ($stat as $a_key => $a_value) {
		$insert_new_outbound_sql .= "`$a_key` = '$a_value'" . (($a_key != 'dt')?" AND ":" LIMIT 1 ");
	}
	$insert_new_outbound_sql .= ")";

	@mysql_query($insert_new_outbound_sql);
	@mysql_close($db_handle);
	exit;
}
$stat['plugins'] = (!empty($_GET['pl']))?mysql_real_escape_string(substr(str_replace('|', ', ', $_GET['pl']), 0, -2)):'';
$screenres['resolution'] = (!empty($_GET['sw']) && !empty($_GET['sh']))?mysql_real_escape_string( $_GET['sw'].'x'.$_GET['sh'] ):'';
$screenres['colordepth'] = (!empty($_GET['cd']))?mysql_real_escape_string( $_GET['cd'] ):'';
$screenres['antialias'] = (!empty($_GET['aa']) && $_GET['aa']=='1')?'1':'0';

// Now we insert the new screen resolution in the lookup table, if it doesn't exist
$select_sql = "SELECT `screenres_id`
				FROM `{$table_prefix}slim_screenres`
				WHERE `resolution` = '{$screenres['resolution']}' AND `colordepth` = '{$screenres['colordepth']}' AND `antialias` = {$screenres['antialias']}
				LIMIT 1";

$stat['screenres_id'] = slimstat_get_var($select_sql);
if ( empty($stat['screenres_id']) ) {
	$insert_sql = "INSERT INTO `{$table_prefix}slim_screenres` ( `" . implode( "`, `", array_keys( $screenres ) ) . "` )
					SELECT '" . implode( "', '", array_values( $screenres ) ) . "'
					FROM DUAL
					WHERE NOT EXISTS ( $select_sql )";
	@mysql_query($insert_sql);
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
$update_sql = "UPDATE {$multisite_table_prefix}slim_stats
				SET screenres_id = {$stat['screenres_id']}, plugins = '{$stat['plugins']}'
				WHERE id = {$stat['id']} AND screenres_id = 0";

@mysql_query($update_sql);
@mysql_close($db_handle);

function slimstat_get_option($_option_name = '', $_default_value = '') {
	global $multisite_table_prefix;
	
	$resource = @mysql_query("SELECT `option_value` FROM `{$multisite_table_prefix}options` WHERE `option_name` = '{$_option_name}'");
	
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