<?php

namespace SlimStat\Tracker;

use SlimStat\Utils\Consent;
use SlimStat\Utils\Query;

class Utils
{
	public static function logError($errorCode = 0)
	{
		\wp_slimstat::update_option('slimstat_tracker_error', [$errorCode, \wp_slimstat::date_i18n('U')]);
		do_action('slimstat_track_exit_' . abs($errorCode), \wp_slimstat::$stat);
		return -$errorCode;
	}

	public static function getValueWithChecksum($value = 0)
	{
		return $value . '.' . md5($value . (\wp_slimstat::$settings['secret'] ?? ''));
	}

	public static function getValueWithoutChecksum($valueWithChecksum = '')
	{
		[$value, $checksum] = explode('.', $valueWithChecksum);
		if ($checksum === md5($value . (\wp_slimstat::$settings['secret'] ?? ''))) {
			return $value;
		}

		return false;
	}

	public static function isBlacklisted($needles = [], $haystackString = '')
	{
		foreach (\wp_slimstat::string_to_array($haystackString) as $item) {
			$pattern = str_replace(['\\*', '\\!'], ['(.*)', '.'], preg_quote($item, '@'));
			if (!is_array($needles)) {
				$needles = [$needles];
			}

			foreach ($needles as $needle) {
				if (preg_match(sprintf('@^%s$@i', $pattern), $needle)) {
					return true;
				}
			}
		}

		return false;
	}

	public static function isNewVisitor($fingerprint = '')
	{
		if ('on' == (\wp_slimstat::$settings['hash_ip'] ?? 'off')) {
			return false;
		}

		if ('on' == \wp_slimstat::$settings['anonymize_ip']) {
			return false;
		}

		$table = $GLOBALS['wpdb']->prefix . 'slim_stats';
		$query = Query::select('COUNT(id) as cnt')->from($table)->where('fingerprint', '=', $fingerprint);
		$today = date('Y-m-d');
		if (!empty(\wp_slimstat::$stat['dt']) && date('Y-m-d', \wp_slimstat::$stat['dt']) < $today) {
			$query->allowCaching(true);
		}

		$countFingerprint = $query->getVar();
		return 0 == $countFingerprint;
	}

	public static function dtrPton($ip)
	{
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			$unpacked = unpack('A4', inet_pton($ip));
		} elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && defined('AF_INET6')) {
			$unpacked = unpack('A16', inet_pton($ip));
		}

		$binaryIp = '';
		if ([] !== $unpacked && false !== $unpacked) {
			$unpacked = str_split($unpacked[1]);
			foreach ($unpacked as $char) {
				$binaryIp .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
			}
		}

		return $binaryIp;
	}

	public static function getMaskLength($ip)
	{
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			return 32;
		} elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			return 128;
		}

		return false;
	}

	public static function base64UrlEncode($input = '')
	{
		return strtr(base64_encode($input), '+/=', '._-');
	}

	public static function base64UrlDecode($input = '')
	{
		return strip_tags(trim(base64_decode(strtr($input, '._-', '+/='))));
	}

	public static function getRemoteIp()
	{
		$ipArray = ['', ''];

		if (!empty($_SERVER['REMOTE_ADDR']) && false !== filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP)) {
			$ipArray[0] = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
		}

		$originatingIpHeaders = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR', 'HTTP_CLIENT_IP', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_X_REAL_IP', 'HTTP_INCAP_CLIENT_IP'];
		foreach ($originatingIpHeaders as $header) {
			if (!empty($_SERVER[$header])) {
				foreach (explode(',', $_SERVER[$header]) as $ip) {
					if (false !== filter_var($ip, FILTER_VALIDATE_IP) && $ip != $ipArray[0]) {
						$ipArray[1] = $ip;
						break;
					}
				}
			}
		}

		return apply_filters('slimstat_filter_ip_address', $ipArray);
	}

	public static function getLanguage()
	{
		if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
			preg_match('/([^,;]*)/', $_SERVER['HTTP_ACCEPT_LANGUAGE'], $arrayLanguages);
			return str_replace('_', '-', strtolower($arrayLanguages[0]));
		}

		return '';
	}

	public static function getSearchTerms($url = '')
	{
		if (empty($url)) {
			return '';
		}

		$searchterms = '';

		$search_engines = file_get_contents(SLIMSTAT_ANALYTICS_DIR . 'admin/assets/data/matomo-searchengine.json');
		$search_engines = json_decode($search_engines, true);

		$parsed_url = @parse_url($url);
		if (empty($search_engines) || empty($parsed_url) || empty($parsed_url['host'])) {
			return '';
		}

		$sek = \wp_slimstat::get_lossy_url($parsed_url['host']);
		if (!empty($search_engines[$sek])) {
			if (empty($search_engines[$sek]['params'])) {
				$search_engines[$sek]['params'] = ['q'];
			}

			foreach ($search_engines[$sek]['params'] as $param) {
				if (!empty($parsed_url['query'])) {
					$searchterms = self::getParamFromQueryString($parsed_url['query'], $param);
					if (!empty($searchterms)) {
						break;
					}
				}
			}

			if (!empty($searchterms) && (!empty($search_engines['charsets']) && function_exists('iconv'))) {
				$charset = $search_engines['charsets'][0];
				if (count($search_engines['charsets']) > 1 && function_exists('mb_detect_encoding')) {
					$charset = mb_detect_encoding($searchterms, $search_engines['charsets']);
					if (false === $charset) {
						$charset = $search_engines['charsets'][0];
					}
				}

				$new_searchterms = @iconv($charset, 'UTF-8//IGNORE', $searchterms);
				if (!('' === $new_searchterms || '0' === $new_searchterms || false === $new_searchterms)) {
					$searchterms = $new_searchterms;
				}
			}
		} elseif (!empty($parsed_url['query'])) {
			foreach (['ask', 'k', 'q', 'qs', 'qt', 'query', 's', 'string'] as $param) {
				$searchterms = self::getParamFromQueryString($parsed_url['query'], $param);
				if (!empty($searchterms)) {
					break;
				}
			}
		}

		return sanitize_text_field($searchterms);
	}

	public static function getParamFromQueryString($query = '', $parameter = '')
	{
		if (empty($query)) {
			return '';
		}

		@parse_str($query, $values);
		return empty($values[$parameter]) ? '' : $values[$parameter];
	}

	public static function getContentInfo()
	{
		$content_info = ['content_type' => ''];
		if (is_404()) {
			$content_info['content_type'] = '404';
		} elseif (is_single()) {
			if (($post_type = get_post_type()) != 'post') {
				$post_type = 'cpt:' . $post_type;
			}

			$content_info['content_type'] = $post_type;
			$category_ids                 = [];
			foreach (get_object_taxonomies($GLOBALS['post']) as $taxonomy) {
				$terms = get_the_terms($GLOBALS['post']->ID, $taxonomy);
				if (is_array($terms)) {
					foreach ($terms as $term) {
						$category_ids[] = $term->term_id;
					}

					$content_info['category'] = implode(',', $category_ids);
				}
			}

			$content_info['content_id'] = $GLOBALS['post']->ID;
		} elseif (is_page()) {
			$content_info['content_type'] = 'page';
			$content_info['content_id']   = $GLOBALS['post']->ID;
		} elseif (is_attachment()) {
			$content_info['content_type'] = 'attachment';
		} elseif (is_singular()) {
			$content_info['content_type'] = 'singular';
		} elseif (is_post_type_archive()) {
			$content_info['content_type'] = 'post_type_archive';
		} elseif (is_tag()) {
			$content_info['content_type'] = 'tag';
			$list_tags                    = get_the_tags();
			if (is_array($list_tags)) {
				$tag_info = array_pop($list_tags);
				if (!empty($tag_info)) {
					$content_info['category'] = $tag_info->term_id;
				}
			}
		} elseif (is_tax()) {
			$content_info['content_type'] = 'taxonomy';
		} elseif (is_category()) {
			$content_info['content_type'] = 'category';
			$list_categories              = get_the_category();
			if (is_array($list_categories)) {
				$cat_info = array_pop($list_categories);
				if (!empty($cat_info)) {
					$content_info['category'] = $cat_info->term_id;
				}
			}
		} elseif (is_date()) {
			$content_info['content_type'] = 'date';
		} elseif (is_author()) {
			$content_info['content_type'] = 'author';
		} elseif (is_archive()) {
			$content_info['content_type'] = 'archive';
		} elseif (is_search()) {
			$content_info['content_type'] = 'search';
		} elseif (is_feed()) {
			$content_info['content_type'] = 'feed';
		} elseif (is_home() || is_front_page()) {
			$content_info['content_type'] = 'home';
		} elseif (!empty($GLOBALS['pagenow']) && 'wp-login.php' == $GLOBALS['pagenow']) {
			$content_info['content_type'] = 'login';
		} elseif (!empty($GLOBALS['pagenow']) && 'wp-register.php' == $GLOBALS['pagenow']) {
			$content_info['content_type'] = 'registration';
		} elseif (is_admin() && (!defined('DOING_AJAX') || !DOING_AJAX)) {
			$content_info['content_type'] = 'admin';
		}

		if (is_paged()) {
			$content_info['content_type'] .= ':paged';
		}

		if (is_singular()) {
			$author = get_the_author_meta('user_login', $GLOBALS['post']->post_author);
			if (!empty($author)) {
				$content_info['author'] = $author;
			}
		}

		return $content_info;
	}

	public static function getClientInfo($dataJs = [], $stat = [])
	{
		if (!empty($dataJs['bw'])) {
			$stat['resolution'] = strip_tags(trim($dataJs['bw'] . 'x' . $dataJs['bh']));
		}

		if (!empty($dataJs['sw'])) {
			$stat['screen_width'] = intval($dataJs['sw']);
		}

		if (!empty($dataJs['sh'])) {
			$stat['screen_height'] = intval($dataJs['sh']);
		}

		if (!empty($dataJs['sl']) && $dataJs['sl'] > 0 && $dataJs['sl'] < 60000) {
			$stat['server_latency'] = intval($dataJs['sl']);
		}

		if (!empty($dataJs['pp']) && $dataJs['pp'] > 0 && $dataJs['pp'] < 60000) {
			$stat['page_performance'] = intval($dataJs['pp']);
		}

		if (!empty($dataJs['fh']) && 'on' != \wp_slimstat::$settings['anonymize_ip']) {
			// Only store fingerprint when PII is allowed; otherwise skip to reduce identifiability pre-consent
			try {
				if (Consent::piiAllowed()) {
					$stat['fingerprint'] = sanitize_text_field($dataJs['fh']);
				}
			} catch (\Throwable $e) {}
		}

		if (!empty($dataJs['tz'])) {
			$stat['tz_offset'] = intval($dataJs['tz']);
		}

		return $stat;
	}
}
