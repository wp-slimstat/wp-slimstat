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
$blog_id = isset($_GET['bid'])?intval($_GET['bid']):0;
if (!empty($blog_id)){
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
if (($secret_key = slimstat_get_option('slimstat_secret')) === false){
	echo 'Secret key not initialized';
	exit;
}

// Blog URL detection
$site_url = slimstat_get_option('home');
if (empty($site_url)) $site_url = slimstat_get_option('siteurl');
if (empty($site_url)) $site_url = $_SERVER['HTTP_HOST'];

// This request is not coming from the same domain
if (empty($_SERVER['HTTP_REFERER']) || ((strpos($_SERVER['HTTP_REFERER'], $site_url) === false) && (strpos($_SERVER['HTTP_REFERER'], "http://" . $_SERVER['HTTP_HOST']) === false ))){
	echo 'Invalid referer';
	exit;
}

// Is the ID valid?
$stat['id'] = empty($_GET['id'])?0:base_convert($_GET['id'], 16, 10);
if ( empty($_GET['obr']) && (empty($_GET['id']) || ($_GET['sid'] != md5($stat['id'].$secret_key)))){
	echo 'Invalid key';
	exit;
}

// This script can be called either to track outbound links (and downloads) or 'returning' visitors
$stat['outbound_domain'] = !empty($_GET['obd'])?mysql_real_escape_string( strip_tags($_GET['obd']) ):'';
$stat['outbound_resource'] = !empty($_GET['obr'])?mysql_real_escape_string( ((substr($_GET['obr'], 0, 1) != '/')?'/':'').$_GET['obr'] ):'';

if (!empty($stat['outbound_domain']) && !empty($stat['outbound_resource'])){
	$stat['type'] = isset($_GET['ty'])?intval($_GET['ty']):1; // type=1 stands for download

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
	if (empty($_GET['go']) || $_GET['go'] == 'y') header('Location: '.$stat['outbound_domain'].$stat['outbound_resource']);
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

// Is this a human visitor? Does s/he have a cookie set by slimstat?
$stat['visit_id'] = 0;
if (!empty($_COOKIE['slimstat_tracking_code']) && strlen($_COOKIE['slimstat_tracking_code']) == 32){
	$clean_tracking_code = mysql_real_escape_string( $_COOKIE['slimstat_tracking_code'] );
	$select_sql = "SELECT `visit_id` FROM {$multisite_table_prefix}slim_visits WHERE `tracking_code` = '$clean_tracking_code'";
	$stat['visit_id'] = slimstat_get_var($select_sql);
	
	// Yes, we don't check that this is a 'legit' tracking code
	if ( empty($stat['visit_id']) ) {
		$insert_sql = "INSERT INTO `{$multisite_table_prefix}slim_visits` ( `tracking_code` )
						SELECT '$clean_tracking_code'
						FROM DUAL
						WHERE NOT EXISTS ( $select_sql )";
		@mysql_query($insert_sql);
		$stat['visit_id'] = @mysql_insert_id();
	
		if ( empty($stat['visit_id']) ) { // This can happen if another transaction had added the new line in the meanwhile
			$stat['visit_id'] = slimstat_get_var($select_sql);
		}
	}
}

// Finally we can update the information about this visit
$update_sql = "UPDATE `{$multisite_table_prefix}slim_stats`
				SET `screenres_id` = {$stat['screenres_id']}, `plugins` = '{$stat['plugins']}', `visit_id` = '{$stat['visit_id']}'
				WHERE `id` = {$stat['id']} AND `screenres_id` = 0";

mysql_query($update_sql);
mysql_close($db_handle);

function slimstat_get_option($_option_name = '') {
	global $multisite_table_prefix;
	
	$resource = @mysql_query("SELECT `option_value` FROM `{$multisite_table_prefix}options` WHERE `option_name` = '{$_option_name}'");
	
	$result = @mysql_fetch_assoc($resource);
	if (!empty($result['option_value']))
		return $result['option_value'];
	else
		return false;
}
function slimstat_get_var($_sql_query = '') {	
	$resource = @mysql_query($_sql_query);
	$result = @mysql_fetch_row($resource);
	if (!empty($result[0]))
		return $result[0];
	else
		return false;
}

?>