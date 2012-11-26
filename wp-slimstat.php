<?php
/*
Plugin Name: WP SlimStat
Plugin URI: http://wordpress.org/extend/plugins/wp-slimstat/
Description: A powerful real-time web analytics plugin for Wordpress.
version: 2.8.5
Author: Camu
Author URI: http://www.duechiacchiere.it/
*/

class wp_slimstat{

	protected static $tid = 0;
	
	public static $version = '2.8.5';
	public static $options = array();

	/**
	 * Initialize some common variables
	 */
	public static function init(){		
		// This check here below is done to keep backward compatibility and should be removed sooner or later
		if (!is_admin()){
			// Is tracking active?
			if (self::$options['is_tracking'] == 'yes'){
				add_action('wp', array(__CLASS__, 'slimtrack'), 5);
			}

			// WP SlimStat tracks screen resolutions, outbound links and other stuff using some javascript custom code
			if ((self::$options['enable_javascript'] == 'yes') && (self::$options['is_tracking'] == 'yes')){
				add_action('init', array(__CLASS__, 'wp_slimstat_register_tracking_script'));
				add_action('wp_footer', array(__CLASS__, 'wp_slimstat_js_data'), 10);
			}
		}

		// Add a dropdown menu to the admin bar
		add_action('admin_bar_menu', array(__CLASS__, 'wp_slimstat_adminbar'), 100);

		// Create a hook to use with the daily cron job
		add_action('wp_slimstat_purge', array(__CLASS__, 'wp_slimstat_purge'));
	}
	// end init

	/**
	 * Core tracking functionality
	 */
	public static function slimtrack($_argument = ''){
		// Do not track admin pages
		if ( is_admin() ||
				strpos($_SERVER['PHP_SELF'], 'wp-content/') !== FALSE ||
				strpos($_SERVER['PHP_SELF'], 'wp-cron.php') !== FALSE ||
				strpos($_SERVER['PHP_SELF'], 'xmlrpc.php') !== FALSE ||
				strpos($_SERVER['PHP_SELF'], 'wp-login.php') !== FALSE ||
				strpos($_SERVER['PHP_SELF'], 'wp-comments-post.php') !== FALSE ) return $_argument;

		$stat = array();

		// Should we ignore this user?
		if (is_user_logged_in()){
			// Don't track logged-in users, if the corresponding option is enabled
			if (self::$options['track_users'] == 'no' && !empty($GLOBALS['current_user']->user_login)) return $_argument;
			
			// Don't track users with given capabilities
			foreach(self::string_to_array(self::$options['ignore_capabilities']) as $a_capability){
				if (array_key_exists(strtolower($a_capability), $GLOBALS['current_user']->allcaps)) return $_argument;
			}

			if (stripos(self::$options['ignore_users'], $GLOBALS['current_user']->user_login) !== false) return $_argument;

			// Track commenters and logged-in users
			if (!empty($GLOBALS['current_user']->user_login)) $stat['user'] = $GLOBALS['current_user']->user_login;

			$not_spam = true;
		}
		elseif (isset($_COOKIE['comment_author_'.COOKIEHASH])){
			// Is this a spammer?
			$spam_comment = $GLOBALS['wpdb']->get_row("SELECT comment_author, COUNT(*) comment_count FROM {$GLOBALS['wpdb']->prefix}comments WHERE INET_ATON(comment_author_IP) = '$long_user_ip' AND comment_approved = 'spam' GROUP BY comment_author LIMIT 0,1", ARRAY_A);
			if (isset($spam_comment['comment_count']) && $spam_comment['comment_count'] > 0){
				if (self::$options['ignore_spammers'] == 'yes')
					return $_argument;
				else
					$stat['user'] .= "[spam] {$spam_comment['comment_author']}";
			}
			else
				$stat['user'] = $_COOKIE['comment_author_'.COOKIEHASH];
		}

		// User's IP address
		list($long_user_ip, $long_other_ip) = self::_get_ip2long_remote_ip();
		if ($long_user_ip == 0) return $_argument;

		// Should we ignore this IP address?
		foreach(self::string_to_array(self::$options['ignore_ip']) as $a_ip_range){
			list ($ip_to_ignore, $mask) = @explode("/", trim($a_ip_range));
			if (empty($mask)) $mask = 32;
			$long_ip_to_ignore = ip2long($ip_to_ignore);
			$long_mask = bindec( str_pad('', $mask, '1') . str_pad('', 32-$mask, '0') );
			$long_masked_user_ip = $long_user_ip & $long_mask;
			$long_masked_ip_to_ignore = $long_ip_to_ignore & $long_mask;
			if ($long_masked_user_ip == $long_masked_ip_to_ignore) return $_argument;
		}

		// Avoid PHP warnings
		$referer = array();
		if (self::$options['anonymize_ip'] == 'yes'){
			$long_user_ip = $long_user_ip&4294967040;
			$long_other_ip = $long_other_ip&4294967040;
		}
		$stat['ip'] = sprintf("%u", $long_user_ip);
		if (!empty($long_other_ip)) $stat['other_ip'] = sprintf("%u", $long_other_ip);
		$stat['language'] = self::_get_language();
		$stat['country'] = self::_get_country($stat['ip']);

		// Country table not initialized
		if ($stat['country'] === false) return $_argument;

		// Is this country blacklisted?
		if (stripos(self::$options['ignore_countries'], $stat['country']) !== false) return $_argument;

		if (isset( $_SERVER['HTTP_REFERER'])){
			$referer = @parse_url($_SERVER['HTTP_REFERER']);
			$stat['referer'] = $_SERVER['HTTP_REFERER'];

			// This must be a 'seriously malformed' URL
			if (!$referer){
				$referer = $_SERVER['HTTP_REFERER'];
			}
			else if (isset($referer['host'])){
				$stat['domain'] = $referer['host'];

				// Fix Google Images referring domain
				if ((strpos($stat['domain'], 'www.google') !== false) && (strpos($stat['referer'], '/imgres?') !== false))
					$stat['domain'] = str_replace('www.google', 'images.google', $stat['domain']);
			}
		}

		// Is this referer blacklisted?
		if (!empty($stat['referer'])){
			foreach(self::string_to_array(self::$options['ignore_referers']) as $a_filter){
				$pattern = str_replace( array('\*', '\!') , array('(.*)', '.'), preg_quote($a_filter, '/'));
				if (preg_match("/^$pattern$/i", $stat['referer'])) return $_argument;
			}
		}

		// We want to record both hits and searches (performed through the site search form)
		if (empty($_REQUEST['s'])){
			$stat['searchterms'] = self::_get_search_terms($referer);
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

		// Is this resource blacklisted?
		if (!empty($stat['resource'])){
			foreach(self::string_to_array(self::$options['ignore_resources']) as $a_filter){
				$pattern = str_replace( array('\*', '\!') , array('(.*)', '.'), preg_quote($a_filter, '/'));
				if (preg_match("/^$pattern$/i", $stat['resource'])) return $_argument;
			}
		}

		// Mark or ignore Firefox/Safari prefetching requests (X-Moz: Prefetch and X-purpose: Preview)
		if ((isset($_SERVER['HTTP_X_MOZ']) && (strtolower($_SERVER['HTTP_X_MOZ']) == 'prefetch')) ||
			(isset($_SERVER["HTTP_X_PURPOSE"]) && (strtolower($_SERVER['HTTP_X_PURPOSE']) == 'preview'))){
			if (self::$options['ignore_prefetch'] == 'yes'){
				return $_argument;
			}
			else{
				$stat['notes'] = '[pre]';
			}
		}

		// Information about this resource
		$content_info = array('content_type' => 'unknown');

		// Mark 404 pages
		if (is_404()) $content_info['content_type'] = '404';		

		// Type
		if (is_single()){
			if (($post_type = get_post_type()) != 'post') $post_type = 'cpt:'.$post_type;
			$content_info['content_type'] = $post_type;
			foreach (get_object_taxonomies($GLOBALS['post']) as $a_taxonomy){
				$terms = get_the_terms($GLOBALS['post']->ID, $a_taxonomy);
				if (is_array($terms)){
					$content_info['category'] = '';
					$the_terms = get_the_terms($GLOBALS['post']->ID, $a_taxonomy);
					foreach (get_the_terms($GLOBALS['post']->ID, $a_taxonomy) as $a_term)
						$content_info['category'] .= ",$a_term->term_id";

					// Remove the initial comma
					$content_info['category'] = substr($content_info['category'], 1);
				}
			}
		}
		elseif (is_page()){
			$content_info['content_type'] = 'page';
		}
		elseif (is_attachment()){
			$content_info['content_type'] = 'attachment';
		}
		elseif (is_singular()){
			$content_info['content_type'] = 'singular';
		}
		elseif (is_post_type_archive()){
			$content_info['content_type'] = 'post_type_archive';
		}
		elseif (is_tag()){
			$content_info['content_type'] = 'tag';
			$tag_info = array_pop(get_the_tags());
			if (!empty($tag_info)) $content_info['category'] = "$tag_info->term_id";
		}
		elseif (is_tax()){
			$content_info['content_type'] = 'taxonomy';
		}
		elseif (is_category()){
			$content_info['content_type'] = 'category';
			$cat_info = array_pop(get_the_category());
			if (!empty($cat_info)) $content_info['category'] = "$cat_info->term_id";
		}
		elseif (is_date()){
			$content_info['content_type']= 'date';
		}
		elseif (is_author()){
			$content_info['content_type'] = 'author';
		}
		elseif (is_archive()){
			$content_info['content_type'] = 'archive';
		}
		elseif (is_search()){
			$content_info['content_type'] = 'search';
		}
		elseif (is_feed()){
			$content_info['content_type'] = 'feed';
		}
		elseif (is_home()){
			$content_info['content_type'] = 'home';
		}

		if (is_paged()){
			$content_info['content_type'] .= ',paged';
		}

		// Author
		if (is_singular()){
			$content_info['author'] = get_the_author_meta('user_login', $GLOBALS['post']->post_author);
		}

		// Detect user agent
		$browser = self::_get_browser();

		// Is this browser blacklisted?
		foreach(self::string_to_array(self::$options['ignore_browsers']) as $a_filter){
			$pattern = str_replace( array('\*', '\!') , array('(.*)', '.'), preg_quote($a_filter, '/'));
			if (preg_match("/^$pattern$/i", $browser['browser'].'/'.$browser['version'])) return $_argument;
		}

		// Ignore bots?
		if ((self::$options['ignore_bots'] == 'yes') && ($browser['type'] == 1)) return $_argument;

		// Is this a returning visitor?
		if (isset($_COOKIE['slimstat_tracking_code'])){
			list($identifier, $control_code) = explode('.', $_COOKIE['slimstat_tracking_code']);

			// Make sure only authorized information is recorded
			if ($control_code == md5($identifier.self::$options['secret'])){

				// Set the visit_id for this session's first pageview
				if (strpos($identifier, 'id') !== false){
					// Emulate auto-increment on visit_id
					$GLOBALS['wpdb']->query($GLOBALS['wpdb']->prepare("
						UPDATE {$GLOBALS['wpdb']->prefix}slim_stats
						SET visit_id = (
							SELECT max_visit_id FROM ( SELECT MAX(visit_id) AS max_visit_id FROM {$GLOBALS['wpdb']->prefix}slim_stats ) AS sub_max_visit_id_table
						) + 1
						WHERE id = %d AND visit_id = 0", intval($identifier)));
					$stat['visit_id'] = $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare("SELECT visit_id FROM {$GLOBALS['wpdb']->prefix}slim_stats WHERE id = %d", intval($identifier)));
					@setcookie('slimstat_tracking_code', "{$stat['visit_id']}.".md5($stat['visit_id'].self::$options['secret']), time()+1800, '/');
				}
				else{
					$stat['visit_id'] = intval($identifier);
				}
			}
		}

		if (!empty($content_info)){
			$select_sql = "SELECT content_info_id
						FROM {$GLOBALS['wpdb']->base_prefix}slim_content_info
						WHERE ";
			foreach ($content_info as $a_key => $a_value){
				$select_sql .= "$a_key = %s AND ";
			}
			$select_sql = $GLOBALS['wpdb']->prepare(substr($select_sql, 0, -5), $content_info);

			// See if this content type is already in our lookup table
			$stat['content_info_id'] = $GLOBALS['wpdb']->get_var($select_sql);

			if (empty($stat['content_info_id'])){
				// Insert the new content information in the lookup table
				$GLOBALS['wpdb']->query($GLOBALS['wpdb']->prepare("
					INSERT IGNORE INTO {$GLOBALS['wpdb']->base_prefix}slim_content_info (".implode(", ", array_keys($content_info)).')
					VALUES ('.substr(str_repeat('%s,', count($content_info)), 0, -1).')', $content_info));

				$stat['content_info_id'] = $GLOBALS['wpdb']->insert_id;

				// This may happen if the new content type was added just before performing the INSERT here above
				if (empty($stat['content_info_id']))
					$stat['content_info_id'] = $GLOBALS['wpdb']->get_var($select_sql);
			}
		}

		// See if this browser is already in our lookup table
		$select_sql = $GLOBALS['wpdb']->prepare("
			SELECT browser_id
			FROM {$GLOBALS['wpdb']->base_prefix}slim_browsers
			WHERE browser = %s AND
				version = %s AND
				platform = %s AND
				css_version = %s AND
				type = %d LIMIT 1", $browser);

		$stat['browser_id'] = $GLOBALS['wpdb']->get_var($select_sql);

		if (empty($stat['browser_id'])){
			// Insert the new browser in the lookup table
			$GLOBALS['wpdb']->query($GLOBALS['wpdb']->prepare("
				INSERT IGNORE INTO {$GLOBALS['wpdb']->base_prefix}slim_browsers (browser, version, platform, css_version, type)
				VALUES (%s,%s,%s,%s,%d)", $browser));

			$stat['browser_id'] = $GLOBALS['wpdb']->insert_id;

			// This may happen if the browser was added just before performing the INSERT here above
			if (empty($stat['browser_id']))
				$stat['browser_id'] = $GLOBALS['wpdb']->get_var($select_sql);
		}

		$stat['dt'] = date_i18n('U');

		$GLOBALS['wpdb']->query($GLOBALS['wpdb']->prepare("
			INSERT INTO {$GLOBALS['wpdb']->prefix}slim_stats (".implode(", ", array_keys($stat)).')
			VALUES ('.substr(str_repeat('%s,', count($stat)), 0, -1).')', $stat));

		self::$tid = $GLOBALS['wpdb']->insert_id;

		// Is this a new visitor?
		if (!isset($_COOKIE['slimstat_tracking_code']) && !empty(self::$tid)){
			// Set a 30-minute cookie to track this visitor (Google and other non-human engines will just ignore it)
			@setcookie('slimstat_tracking_code', self::$tid.'id.'.md5(self::$tid.'id'.self::$options['secret']), time()+1800, '/');
		}

		return $_argument;
	}
	// end slimtrack

	/**
	 * Searches for country associated to a given IP address
	 */
	protected static function _get_country($_ip = ''){
		$sql = "SELECT country_code
					FROM {$GLOBALS['wpdb']->base_prefix}slim_countries
					WHERE ip_from <= $_ip AND ip_to >= $_ip
					LIMIT 1";

		$country_code = $GLOBALS['wpdb']->get_var($sql, 0 , 0);

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
	protected static function _get_ip2long_remote_ip(){
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
	protected static function _get_language(){
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
	protected static function _get_search_terms($_url = array()){
		if(!is_array($_url) || !isset($_url['host']) || !isset($_url['query'])) return '';

		$query_formats = array('daum' => 'q', 'eniro' => 'search_word', 'naver' => 'query', 'google' => 'q', 'www.google' => 'as_q', 'yahoo' => 'p', 'msn' => 'q', 'bing' => 'q', 'aol' => 'query', 'lycos' => 'q', 'ask' => 'q', 'cnn' => 'query', 'about' => 'q', 'mamma' => 'q', 'voila' => 'rdata', 'virgilio' => 'qs', 'baidu' => 'wd', 'yandex' => 'text', 'najdi' => 'q', 'seznam' => 'q', 'search' => 'q', 'onet' => 'qt', 'yam' => 'k', 'pchome' => 'q', 'kvasir' => 'q', 'mynet' => 'q', 'nova_rambler' => 'words');
		$charsets = array('baidu' => 'EUC-CN');

		parse_str($_url['query'], $query);
		preg_match("/(daum|eniro|naver|google|www.google|yahoo|msn|bing|aol|lycos|ask|cnn|about|mamma|voila|virgilio|baidu|yandex|najdi|seznam|search|onet|yam|pchome|kvasir|mynet|rambler)./", $_url['host'], $matches);

		if (isset($matches[1]) && isset($query[$query_formats[$matches[1]]])){
			// Test for encodings different from UTF-8
			if (function_exists('mb_check_encoding') && !mb_check_encoding($query[$query_formats[$matches[1]]], 'UTF-8') && !empty($charsets[$matches[1]]))
				return mb_convert_encoding(urldecode($query[$query_formats[$matches[1]]]), 'UTF-8', $charsets[$matches[1]]);

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
	 * Retrieves some information about the user agent; relies on browscap.php database (included)
	 */
	protected static function _get_browser(){
		// Load cache
		include_once(WP_PLUGIN_DIR.'/wp-slimstat/browscap/cache.php');

		$browser = array('browser' => 'Default Browser', 'version' => '1', 'platform' => 'unknown', 'css_version' => 1, 'type' => 1);
		$user_agent = isset($_SERVER['HTTP_USER_AGENT'])?$_SERVER['HTTP_USER_AGENT']:'';
		$search = array();
		foreach ($slimstat_patterns as $key => $pattern){
			if (preg_match($pattern . 'i', $user_agent)){
				$search = $value = $search + $slimstat_browsers[$key];
				while (array_key_exists(3, $value) && $value[3]) {
					$value = $slimstat_browsers[$value[3]];
					$search += $value;
				}
				break;
			}
		}

		// If a meaningful match was found, let's define some keys
		if ($search[5] != 'Default Browser'){
			$browser['browser'] = $search[5];
			$browser['version'] = $search[6];
			$browser['platform'] = strtolower($search[9]);
			$browser['css_version'] = $search[28];

			// browser Types:
			//		0: regular
			//		1: crawler
			//		2: mobile
			//		3: syndication reader
			if ($search[25] == 'true') $browser['type'] = 2;
			elseif ($search[26] == 'true') $browser['type'] = 3;
			elseif ($search[27] != 'true') $browser['type'] = 0;			

			return $browser;
		}

		// Let's try with the heuristic approach
		$browser['type'] = 1;

		// Googlebot 
		if (preg_match("#^Mozilla/\d\.\d\s\(compatible;\sGooglebot/(\d\.\d);[\s\+]+http\://www\.google\.com/bot\.html\)$#i", $_agent, $match)>0){
			$browser['browser'] = "Googlebot";
			$browser['version'] = $match[1];

		// Yahoo!Slurp
		} elseif (preg_match('#^Mozilla/\d\.\d\s\(compatible;\s(Yahoo\!\s([A-Z]{2})?\s?Slurp)/?(\d\.\d)?;\shttp\://help\.yahoo\.com/.*\)$#i', $_agent, $match)>0){
			$browser['browser'] = $match[1];
			if (!empty($match[3])) $browser['version'] = $match[3];

		// BingBot
		} elseif (preg_match('#^Mozilla/\d\.\d\s\(compatible;\sbingbot/(\d\.\d)[^a-z0-9]+http\://www\.bing\.com/bingbot\.htm.$#', $_agent, $match)>0){
			$browser['browser'] = 'BingBot';
			if (!empty($match[1])) $browser['browser'] .= $match[1];
			if (!empty($match[2])) $browser['version'] = $match[2];

		// IE 8|7|6 on Windows7|2008|Vista|XP|2003|2000
		} elseif (preg_match('#^Mozilla/\d\.\d\s\(compatible;\sMSIE\s(\d+)(?:\.\d+)+;\s(Windows\sNT\s\d\.\d(?:;\sW[inOW]{2}64)?)(?:;\sx64)?;?(?:\sSLCC1;?|\sSV1;?|\sGTB\d;|\sTrident/\d\.\d;|\sFunWebProducts;?|\s\.NET\sCLR\s[0-9\.]+;?|\s(Media\sCenter\sPC|Tablet\sPC)\s\d\.\d;?|\sInfoPath\.\d;?)*\)$#', $_agent, $match)>0){
			$browser['browser'] = 'IE';
			$browser['version'] = $match[1];
			$browser['type'] = 0;
			
			// Parse the OS string and update $browser accordingly
			self::_get_os_version($match[2], $browser);

		// Firefox and other Mozilla browsers on Windows
		} elseif (preg_match('#^Mozilla/\d\.\d\s\(Windows;\sU;\s(.+);\s([a-z]{2}(?:\-[A-Za-z]{2})?);\srv\:\d(?:\.\d+)+\)\sGecko/\d+\s([A-Za-z\-0-9]+)/(\d+(?:\.\d+)+)(?:\s\(.*\))?$#', $_agent, $match)>0){
			$browser['browser'] = $match[3];
			$browser['version'] = $match[4];
			$browser['type'] = 0;

			self::_get_os_version($match[1], $browser);

		// Firefox and Gecko browsers on Mac|*nix|OS/2
		} elseif (preg_match('#^Mozilla/\d\.\d\s\((Macintosh|X11|OS/2);\sU;\s(.+);\s([a-z]{2}(?:\-[A-Za-z]{2})?)(?:-mac)?;\srv\:\d(?:.\d+)+\)\sGecko/\d+\s([A-Za-z\-0-9]+)/(\d+(?:\.[0-9a-z\-\.]+))+(?:(\s\(.*\))(?:\s([A-Za-z\-0-9]+)/(\d+(?:\.\d+)+)))?$#', $_agent, $match)>0){
			$browser['browser'] = $match[4];
			$browser['version'] = $match[5];
			$os = $match[2];
			if (!empty($match[7])){ 
				$browser['browser'] = $match[7];
				$browser['version'] = $match[8];
				$os .= " {$match[4]} {$match[5]}";
			} elseif (!empty($match[6])) { 
				$os .= $match[6];
			}
			$browser['type'] = 0;
			self::_get_os_version($os, $browser);

		// Safari and Webkit-based browsers on all platforms
		} elseif (preg_match('#^Mozilla/\d\.\d\s\(([A-Za-z0-9/\.]+);\sU;?\s?(.*);\s?([a-z]{2}(?:\-[A-Za-z]{2})?)?\)\sAppleWebKit/[0-9\.]+\+?\s\((?:KHTML,\s)?like\sGecko\)(?:\s([a-zA-Z0-9\./]+(?:\sMobile)?)/?[A-Z0-9]*)?\sSafari/([0-9\.]+)$#', $_agent, $match)>0){
			$browser['browser'] = 'Safari';

			// version detection
			if (!empty($match[4]))
				$browser['version'] = $match[4];
			else
				$browser['version'] = $match[5];

			if (preg_match("#^([a-zA-Z]+)/([0-9]+(?:[A-Za-z\.0-9]+))(\sMobile)?#", $browser['version'], $match)>0){
				if ($match[1] != "version") { //Chrome, Iron, Shiira
					$browser['browser'] = $match[1];
				}
				$browser['version'] = $match[2];
				if ($browser['version'] == "0") $browser['version'] = '';
				if (!empty($match[3])) $browser['version'] = $match[3];
			}
			elseif (is_numeric($browser['version'])){
				$webkit_num = intval($browser['version']-0.5);
				if ($webkit_num > 533)
					$browser['version'] = '5';
				elseif ($webkit_num > 525)
					$browser['version'] = '4';
				elseif ($webkit_num > 419)
					$browser['version'] = '3';
				elseif ($webkit_num > 312)
					$browser['version'] = '2';
				elseif ($webkit_num > 85)
					$browser['version'] = '1';
				else 
					$browser['version'] = '';
			}

			if (empty($match[2]))
				$os = $match[1];
			else
				$os = $match[2];
			$browser['type'] = 0;
			self::_get_os_version($os, $browser);

		// Google Chrome browser on all platforms with or without language string
		} elseif (preg_match('#^Mozilla/\d+\.\d+\s(?:[A-Za-z0-9\./]+\s)?\((?:([A-Za-z0-9/\.]+);(?:\sU;)?\s?)?([^;]*)(?:;\s[A-Za-z]{3}64)?;?\s?([a-z]{2}(?:\-[A-Za-z]{2})?)?\)\sAppleWebKit/[0-9\.]+\+?\s\((?:KHTML,\s)?like\sGecko\)(?:\s([A-Za-z0-9_\-]+[^i])/([A-Za-z0-9\.]+)){1,3}(?:\sSafari/[0-9\.]+)?$#', $_agent, $match)>0){
			$browser['browser'] = $match[4];
			$browser['version'] = $match[5];
			if (empty($match[2]))
				$os = $match[1];
			else
				$os = $match[2];
			$browser['type'] = 0;
			self::_get_os_version($os, $browser);
		}

		// Simple alphanumeric strings usually identify a crawler
		elseif (preg_match("#^([a-z]+[\s_]?[a-z]*)[\-/]?([0-9\.]+)*$#", $_agent, $match)>0){
			$browser['browser'] = trim($match[1]);
			if (!empty($match[2]))
				$browser['version'] = $match[2];
		}

		if ($browser['platform'] == 'unknown') $browser['type'] = 1;

		return $browser;
	}
	// end _get_browser

	/**
	 * Parses the UserAgent string to get the operating system code
	 */
	protected static function _get_os_version($_os_string, &$_browser) {
		if (empty($_os_string)) return '';

		// Microsoft Windows
		$x64 = '';
		if (strstr($_os_string, 'WOW64') || strstr($_os_string, 'Win64') || strstr($_os_string, 'x64'))
			$x64 = ' x64';

		if (strstr($_os_string, 'Windows NT 6.2'))
			return ($_browser['platform'] = 'win8'.$x64);
		if (strstr($_os_string, 'Windows NT 6.1'))
			return ($_browser['platform'] = 'win7'.$x64);
		if (strstr($_os_string, 'Windows NT 6.0'))
			return ($_browser['platform'] = 'winvista'.$x64);
		if (strstr($_os_string, 'Windows NT 5.2'))
			return ($_browser['platform'] = 'win2003'.$x64);
		if (strstr($_os_string, 'Windows NT 5.1'))
			return ($_browser['platform'] = 'winxp'.$x64);
		if (strstr($_os_string, 'Windows NT 5.0') || strstr($_os_string, 'Windows 2000'))
			return ($_browser['platform'] = 'win2000'.$x64);
		if (strstr($_os_string, 'Windows ME'))
			return ($_browser['platform'] = 'winme');
		if (preg_match('/Win(?:dows\s)?NT\s?([0-9\.]+)?/', $_os_string)>0)
			return ($_browser['platform'] = 'winnt'.$x64);
		if (preg_match('/(?:Windows95|Windows 95|Win95|Win 95)/', $_os_string)>0)
			return ($_browser['platform'] = 'win95');
		if (preg_match('/(?:Windows98|Windows 98|Win98|Win 98|Win 9x)/', $_os_string)>0)
			return ($_browser['platform'] = 'win98');
		if (preg_match('/(?:WindowsCE|Windows CE|WinCE|Win CE)[^a-z0-9]+(?:.*version\s([0-9\.]+))?/i', $_os_string)>0)
			return ($_browser['platform'] = 'wince');
		if (preg_match('/(Windows|Win)\s?3\.\d[; )\/]/', $_os_string)>0)
			return ($_browser['platform'] = 'win3.x');
		if (preg_match('/(Windows|Win)[0-9; )\/]/', $_os_string)>0)
			return ($_browser['platform'] = 'windows');

		// Linux/Unix
		if (preg_match('/[^a-z0-9](Android|CentOS|Debian|Fedora|Gentoo|Mandriva|PCLinuxOS|SuSE|Kanotix|Knoppix|Mandrake|pclos|Red\s?Hat|Slackware|Ubuntu|Xandros)[^a-z]/i', $_os_string, $match)>0)
			return ($_browser['platform'] = strtolower($match[1]));
		if (preg_match('/((?:Free|Open|Net)BSD)\s?(?:[ix]?[386]+)?\s?([0-9\.]+)?/', $_os_string, $match)>0)
			return ($_browser['platform'] = strtolower($match[1]));

		// Portable devices
		if ((preg_match('/\siPhone\sOS\s(\d+)?(?:_\d)*/i', $_os_string)>0) || (strpos($_os_string, 'iPad') !== false)){
			$browser['type'] = 2;
			return ($_browser['platform'] = 'ios');
		}
		if (strpos($_os_string, 'Mac OS X') !== false){
			$browser['type'] = 2;
			return ($_browser['platform'] = 'macosx');
		}
		if (preg_match('/Android\s?([0-9\.]+)?/', $_os_string)>0){
			$browser['type'] = 2;
			return ($_browser['platform'] = 'android');
		}
		if ((strpos($_os_string, 'BlackBerry') !== false) || (strpos($_os_string, 'RIM') !== false)){
			$browser['type'] = 2;
			return ($_browser['platform'] = 'blackberry os');
		}
		if (preg_match('/SymbianOS\/([0-9\.]+)/i', $_os_string)>0){
			$browser['type'] = 2;
			return ($_browser['platform'] = 'symbianos');
		}

		// Rare operating systems
		if (preg_match('/[^a-z0-9](BeOS|BePC|Zeta)[^a-z0-9]/', $_os_string)>0)
			return ($_browser['platform'] = 'beos');
		if (preg_match('/[^a-z0-9](Commodore\s?64)[^a-z0-9]/i', $_os_string)>0)
			return ($_browser['platform'] = 'commodore64');
		if (preg_match('/[^a-z0-9]Darwin\/?([0-9\.]+)/i', $_os_string)>0)
			return ($_browser['platform'] = 'darwin');

		return ($_browser['platform'] = 'unknown');
	}
	// end os_version

	/**
	 * Converts a series of comma separated values into an array
	 */
	public static function string_to_array($_option = ''){
		if (empty($_option) || !is_string($_option))
			return array();
		else
			return array_map('trim', explode(',', $_option));
	}
	// end string_to_array

	/**
	 * Imports all the 'old' options into the new array, and saves them
	 */
	public static function import_old_options(){
		self::$options = array(
			// version is useful to see if we need to upgrade the database
			'version' => 0,

			// We need a secret key to make sure the js-php interaction is secure
			'secret' => get_option('slimstat_secret', md5(time())),

			// You can activate or deactivate tracking, but still be able to view reports
			'is_tracking' => get_option('slimstat_is_tracking', 'yes'),

			// Track screen resolutions, outbound links and other stuff using a javascript component
			'enable_javascript' => get_option('slimstat_enable_javascript', 'yes'),

			// Custom path to get to wp-slimstat-js.php
			'custom_js_path' => '/wp-content/plugins/wp-slimstat',

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
			'ip_lookup_service' => get_option('slimstat_ip_lookup_service', 'http://www.infosniper.net/?ip_address='),

			// Refresh the RAW DATA view every X seconds
			'refresh_interval' => get_option('slimstat_refresh_interval', '0'),

			// Don't ignore bots and spiders by default
			'ignore_bots' => get_option('slimstat_ignore_bots', 'no'),

			// Ignore spammers?
			'ignore_spammers' => get_option('slimstat_ignore_spammers', 'no'),

			// Ignore Link Prefetching?
			'ignore_prefetch' => get_option('slimstat_ignore_prefetch', 'no'),

			// List of IPs to ignore
			'ignore_ip' => get_option('slimstat_ignore_ip', ''),

			// List of Countries to ignore
			'ignore_countries' => get_option('slimstat_ignore_countries', ''),

			// List of local resources to ignore
			'ignore_resources' => get_option('slimstat_ignore_resources', ''),

			// List of domains to ignore
			'ignore_referers' => get_option('slimstat_ignore_referers', ''),

			// List of browsers to ignore
			'ignore_browsers' => get_option('slimstat_ignore_browsers', ''),

			// List of users to ignore
			'ignore_users' => get_option('slimstat_ignore_users', ''),

			// List of users who can view the stats: if empty, all users are allowed
			'can_view' => get_option('slimstat_can_view', ''),

			// List of capabilities needed to view the stats: if empty, all users are allowed
			'capability_can_view' => get_option('slimstat_capability_can_view', 'read'),

			// List of users who can administer this plugin's options: if empty, all users are allowed
			'can_admin' => get_option('slimstat_can_admin', '')
		);
		
		// Save these options in the database
		add_option('slimstat_options', self::$options, '', 'no');
	}
	// end import_old_options

	/**
	 * Enqueues a javascript to track users' screen resolution and other browser-based information
	 */
	public static function wp_slimstat_register_tracking_script(){
		if (self::$options['enable_cdn'] == 'yes')
			wp_register_script('wp_slimstat', 'http://cdn.jsdelivr.net/wp-slimstat/'.self::$options['version'].'/wp-slimstat.js', array(), false, true);
		else
			wp_register_script('wp_slimstat', plugins_url('/wp-slimstat.js', __FILE__), array(), false, true);
	}
	// end wp_slimstat_javascript

	/**
	 * Adds some javascript data specific to this pageview
	 */
	public static function wp_slimstat_js_data(){
		$intval_tid = intval(self::$tid);
		if ($intval_tid > 0){
			$hexval_tid = base_convert($intval_tid, 10, 16);
			$slimstat_blog_id = (function_exists('is_multisite') && is_multisite())?$GLOBALS['wpdb']->blogid:1;

			echo "<script type='text/javascript'>slimstat_tid='$hexval_tid';slimstat_path='".home_url(self::$options['custom_js_path'], __FILE__)."';slimstat_blog_id='$slimstat_blog_id';slimstat_session_id='".md5($intval_tid.self::$options['secret'])."';</script>";
			wp_print_scripts('wp_slimstat');
		}
	}
	// end wp_slimstat_js_data

	/**
	 * Removes old entries from the database
	 */
	public static function wp_slimstat_purge(){
		if (($autopurge_interval = intval(self::$options['auto_purge'])) <= 0) return;

		// Delete old entries
		$GLOBALS['wpdb']->query("DELETE ts FROM {$GLOBALS['wpdb']->prefix}slim_stats ts WHERE ts.dt < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL $autopurge_interval DAY))");

		// Optimize table
		$GLOBALS['wpdb']->query("OPTIMIZE TABLE {$GLOBALS['wpdb']->prefix}slim_stats");
	}
	// end wp_slimstat_purge

	/**
	 * Adds a new entry to the Wordpress Toolbar
	 */
	public static function wp_slimstat_adminbar(){
		if (!is_super_admin() || !is_admin_bar_showing()) return;
		load_plugin_textdomain('wp-slimstat-view', WP_PLUGIN_DIR .'/wp-slimstat/admin/lang', '/wp-slimstat/admin/lang');

		self::$options['capability_can_view'] = empty(self::$options['capability_can_view'])?'read':self::$options['capability_can_view'];

		if (empty(self::$options['can_view']) || stripos(self::$options['can_view'], $GLOBALS['current_user']->user_login) !== false || current_user_can('manage_options')){
			$slimstat_view_url = get_site_url($GLOBALS['blog_id'], '/wp-admin/index.php?page=wp-slimstat');
			$GLOBALS['wp_admin_bar']->add_menu(array('id' => 'slimstat-header', 'title' => 'SlimStat', 'href' => $slimstat_view_url));
			$GLOBALS['wp_admin_bar']->add_menu(array('id' => 'slimstat-panel1', 'href' => "$slimstat_view_url", 'parent' => 'slimstat-header', 'title' => __('Right Now', 'wp-slimstat-view')));
			$GLOBALS['wp_admin_bar']->add_menu(array('id' => 'slimstat-panel2', 'href' => "$slimstat_view_url&slimpanel=2", 'parent' => 'slimstat-header', 'title' => __('Overview', 'wp-slimstat-view')));
			$GLOBALS['wp_admin_bar']->add_menu(array('id' => 'slimstat-panel3', 'href' => "$slimstat_view_url&slimpanel=3", 'parent' => 'slimstat-header', 'title' => __('Visitors', 'wp-slimstat-view')));
			$GLOBALS['wp_admin_bar']->add_menu(array('id' => 'slimstat-panel4', 'href' => "$slimstat_view_url&slimpanel=4", 'parent' => 'slimstat-header', 'title' => __('Content', 'wp-slimstat-view')));
			$GLOBALS['wp_admin_bar']->add_menu(array('id' => 'slimstat-panel5', 'href' => "$slimstat_view_url&slimpanel=5", 'parent' => 'slimstat-header', 'title' => __('Traffic Sources', 'wp-slimstat-view')));
			$GLOBALS['wp_admin_bar']->add_menu(array('id' => 'slimstat-panel6', 'href' => "$slimstat_view_url&slimpanel=6", 'parent' => 'slimstat-header', 'title' => __('World Map', 'wp-slimstat-view')));
		}
	}
	// end wp_slimstat_adminbar
}
// end of class declaration

// Ok, let's go, Sparky!
if (function_exists('add_action')){

	// Initialize WP SlimStat's options
	wp_slimstat::$options = get_option('slimstat_options', array());
	if (empty(wp_slimstat::$options) && function_exists('is_network_admin') && !is_network_admin()) wp_slimstat::import_old_options();
	
	// Add the appropriate actions
	add_action('plugins_loaded', array('wp_slimstat', 'init'), 5);
	
	// Load the admin API, if needed
	if (is_admin()) include_once(WP_PLUGIN_DIR.'/wp-slimstat/admin/wp-slimstat-admin.php');
}