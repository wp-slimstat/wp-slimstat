<?php

namespace SlimStat\Tracker;

use SlimStat\Services\Privacy;
use SlimStat\Services\Geolocation\GeolocationService;
use SlimStat\Providers\IPHashProvider;

class Processor
{
	public static function process()
	{
		\wp_slimstat::$stat['dt'] = \wp_slimstat::date_i18n('U');
		if (empty(\wp_slimstat::$stat['notes'])) {
			\wp_slimstat::$stat['notes'] = [];
		}
		\wp_slimstat::$stat = apply_filters('slimstat_filter_pageview_stat_init', \wp_slimstat::$stat);
		if (\wp_slimstat::$stat === [] || empty(\wp_slimstat::$stat['dt'])) {
			return false;
		}
		unset(\wp_slimstat::$stat['id']);

		if ('on' == \wp_slimstat::$settings['display_opt_out']) {
			$cookie_names = ['slimstat_optout_tracking' => 'true'];
			if (!empty(\wp_slimstat::$settings['opt_out_cookie_names'])) {
				$cookie_names = [];
				foreach (\wp_slimstat::string_to_array(\wp_slimstat::$settings['opt_out_cookie_names']) as $pair) {
					[$name, $value] = explode('=', $pair);
					if ('' !== $name && '0' !== $name && ('' !== $value && '0' !== $value)) {
						$cookie_names[$name] = $value;
					}
				}
			}
			foreach ($cookie_names as $n => $v) {
				if (isset($_COOKIE[$n]) && false !== strpos($_COOKIE[$n], $v)) {
					unset($_COOKIE['slimstat_tracking_code']);
					@setcookie('slimstat_tracking_code', '', ['expires' => time() - (15 * 60), 'path' => COOKIEPATH]);
					return false;
				}
			}
		}

		if (!empty(\wp_slimstat::$settings['opt_in_cookie_names'])) {
			$cookie_names        = [];
			$opt_in_cookie_names = \wp_slimstat::string_to_array(\wp_slimstat::$settings['opt_in_cookie_names']);
			foreach ($opt_in_cookie_names as $pair) {
				[$name, $value] = explode('=', $pair);
				if ('' !== $name && '0' !== $name && ('' !== $value && '0' !== $value)) {
					$cookie_names[$name] = $value;
				}
			}
			$cookie_found = false;
			foreach ($cookie_names as $n => $v) {
				if (isset($_COOKIE[$n]) && false !== strpos($_COOKIE[$n], $v)) {
					$cookie_found = true;
				}
			}
			if (!$cookie_found) {
				unset($_COOKIE['slimstat_tracking_code']);
				@setcookie('slimstat_tracking_code', '', ['expires' => time() - (15 * 60), 'path' => COOKIEPATH]);
				return false;
			}
		}

		[\wp_slimstat::$stat['ip'], \wp_slimstat::$stat['other_ip']] = Utils::getRemoteIp();
		if (empty(\wp_slimstat::$stat['ip']) || '0.0.0.0' == \wp_slimstat::$stat['ip']) {
			Utils::logError(202);
			return false;
		}

		foreach (\wp_slimstat::string_to_array(\wp_slimstat::$settings['ignore_ip']) as $ipRange) {
			$ipToIgnore = $ipRange;
			if (false !== strpos($ipToIgnore, '/')) {
				[$ipToIgnore, $cidr_mask] = explode('/', trim($ipToIgnore));
			} else {
				$cidr_mask = Utils::getMaskLength($ipToIgnore);
			}
			$longMaskedToIgnore  = substr(Utils::dtrPton($ipToIgnore), 0, $cidr_mask);
			$longMaskedUserIp    = substr(Utils::dtrPton(\wp_slimstat::$stat['ip']), 0, $cidr_mask);
			$longMaskedUserOther = substr(Utils::dtrPton(\wp_slimstat::$stat['other_ip']), 0, $cidr_mask);
			if ($longMaskedUserIp === $longMaskedToIgnore || $longMaskedUserOther === $longMaskedToIgnore) {
				return false;
			}
		}

		// Process IP address with anonymization and hashing
		\wp_slimstat::$stat = IPHashProvider::processIp(\wp_slimstat::$stat);

		if (!isset(\wp_slimstat::$stat['resource'])) {
			\wp_slimstat::$stat['resource'] = \wp_slimstat::get_request_uri();
		}
		\wp_slimstat::$stat['resource'] = sanitize_text_field(urldecode(\wp_slimstat::$stat['resource']));
		\wp_slimstat::$stat['resource'] = preg_replace_callback('/[^\x20-\x7E]/', fn ($m) => '%' . bin2hex($m[0]), \wp_slimstat::$stat['resource']);
		$parsed_url = parse_url(\wp_slimstat::$stat['resource']);
		if (!$parsed_url) {
			Utils::logError(203);
			return false;
		}
		\wp_slimstat::$stat['resource'] = $parsed_url['path'] . (empty($parsed_url['query']) ? '' : '?' . $parsed_url['query']) . (empty($parsed_url['fragment']) ? '' : '#' . $parsed_url['fragment']);
		if (!empty(\wp_slimstat::$settings['ignore_resources']) && Utils::isBlacklisted(\wp_slimstat::$stat['resource'], \wp_slimstat::$settings['ignore_resources'])) {
			return false;
		}

		if (empty(\wp_slimstat::$stat['referer']) && !empty($_SERVER['HTTP_REFERER'])) {
			\wp_slimstat::$stat['referer'] = sanitize_url(wp_unslash($_SERVER['HTTP_REFERER']));
		}
		if (!empty(\wp_slimstat::$stat['referer'])) {
			$parsed_url = parse_url(\wp_slimstat::$stat['referer']);
			if (!$parsed_url) {
				Utils::logError(201);
				return false;
			}
			if (isset($parsed_url['scheme']) && ('' !== $parsed_url['scheme'] && '0' !== $parsed_url['scheme']) && !in_array(strtolower($parsed_url['scheme']), ['http', 'https', 'android-app'])) {
				\wp_slimstat::$stat['notes'][] = sprintf(__('Attempted XSS Injection: %s', 'wp-slimstat'), \wp_slimstat::$stat['referer']);
				unset(\wp_slimstat::$stat['referer']);
			}
			if (!empty(\wp_slimstat::$settings['ignore_referers']) && Utils::isBlacklisted(\wp_slimstat::$stat['referer'], \wp_slimstat::$settings['ignore_referers'])) {
				return false;
			}
			\wp_slimstat::$stat['searchterms'] = Utils::getSearchTerms(\wp_slimstat::$stat['referer']);
			$parsed_site_url = parse_url(get_site_url(), PHP_URL_HOST);
			if (isset($parsed_url['host']) && ('' !== $parsed_url['host'] && '0' !== $parsed_url['host']) && $parsed_url['host'] == $parsed_site_url && 'on' != \wp_slimstat::$settings['track_same_domain_referers']) {
				unset(\wp_slimstat::$stat['referer']);
			}
		}

		if (empty(\wp_slimstat::$stat['searchterms']) && !empty($_POST['s'])) {
			\wp_slimstat::$stat['searchterms'] = sanitize_text_field(str_replace('\\', '', $_REQUEST['s']));
		}

		if (!isset(\wp_slimstat::$stat['content_type'])) {
			$content_info = Utils::getContentInfo();
			if (!empty(\wp_slimstat::$settings['ignore_content_types']) && Utils::isBlacklisted($content_info['content_type'], \wp_slimstat::$settings['ignore_content_types'])) {
				return false;
			}
			if (is_array($content_info)) {
				\wp_slimstat::$stat += $content_info;
			}
		}

		if ((is_archive() || is_search()) && !empty($GLOBALS['wp_query']->found_posts)) {
			\wp_slimstat::$stat['notes'][] = 'results:' . intval($GLOBALS['wp_query']->found_posts);
		}

		if ((isset(\wp_slimstat::$stat['resource']) && (\wp_slimstat::$stat['resource'] !== '' && \wp_slimstat::$stat['resource'] !== '0') && false !== strpos(\wp_slimstat::$stat['resource'], 'wp-admin/admin-ajax.php')) || (!empty($_GET['page']) && false !== strpos($_GET['page'], 'slimview'))) {
			return false;
		}

		if (!empty($GLOBALS['current_user']->ID)) {
			if ('on' == \wp_slimstat::$settings['ignore_wp_users']) {
				return false;
			}
			foreach ($GLOBALS['current_user']->roles as $capability) {
				if (Utils::isBlacklisted($capability, \wp_slimstat::$settings['ignore_capabilities'])) {
					return false;
				}
			}
			if (!empty(\wp_slimstat::$settings['ignore_users']) && Utils::isBlacklisted($GLOBALS['current_user']->data->user_login, \wp_slimstat::$settings['ignore_users'])) {
				return false;
			}
			\wp_slimstat::$stat['username'] = $GLOBALS['current_user']->data->user_login;
			\wp_slimstat::$stat['email']    = $GLOBALS['current_user']->data->user_email;
			\wp_slimstat::$stat['notes'][]  = 'user:' . $GLOBALS['current_user']->data->ID;
			$not_spam                        = true;
		} elseif (isset($_COOKIE['comment_author_' . COOKIEHASH])) {
			$spam_comment = \wp_slimstat::$wpdb->get_row(\wp_slimstat::$wpdb->prepare('\n                SELECT comment_author, comment_author_email, COUNT(*) comment_count\n                FROM `' . DB_NAME . "`.{$GLOBALS['wpdb']->comments}\n                WHERE comment_author_IP = %s AND comment_approved = 'spam'\n                GROUP BY comment_author\n                LIMIT 0,1", \wp_slimstat::$stat['ip']), ARRAY_A);
			if (!empty($spam_comment['comment_count'])) {
				if ('on' == \wp_slimstat::$settings['ignore_spammers']) {
					return false;
				} else {
					\wp_slimstat::$stat['notes'][]  = 'spam:yes';
					\wp_slimstat::$stat['username'] = $spam_comment['comment_author'];
					\wp_slimstat::$stat['email']    = $spam_comment['comment_author_email'];
				}
			} else {
				if (!empty($_COOKIE['comment_author_' . COOKIEHASH])) {
					\wp_slimstat::$stat['username'] = sanitize_user($_COOKIE['comment_author_' . COOKIEHASH]);
				}
				if (!empty($_COOKIE['comment_author_email_' . COOKIEHASH])) {
					\wp_slimstat::$stat['email'] = sanitize_email($_COOKIE['comment_author_email_' . COOKIEHASH]);
				}
			}
		}

		\wp_slimstat::$stat['language'] = Utils::getLanguage();
		if (!empty(\wp_slimstat::$stat['language']) && !empty(\wp_slimstat::$settings['ignore_languages']) && false !== stripos(\wp_slimstat::$settings['ignore_languages'], (string) \wp_slimstat::$stat['language'])) {
			return false;
		}

		// Determine which geolocation provider to use
		$provider = \wp_slimstat::$settings['geolocation_provider'] ?? 'dbip';
		$options  = [];

		if ('maxmind' === $provider) {
			$options['license_key'] = \wp_slimstat::$settings['maxmind_license_key'] ?? '';
			$options['precision']   = (\wp_slimstat::$settings['geolocation_country'] ?? 'on') === 'on' ? 'country' : 'city';
		}

		$geographicProvider = new GeolocationService($provider, $options);

		try {
			$geolocation_data = $geographicProvider->locate(\wp_slimstat::$stat['ip']);
		} catch (\Exception $e) {
			Utils::logError(205);
			return false;
		}

		if (!empty($geolocation_data['country_code']) && 'xx' != $geolocation_data['country_code']) {
			\wp_slimstat::$stat['country'] = strtolower($geolocation_data['country_code']);
			if (!empty($geolocation_data['city'])) {
				\wp_slimstat::$stat['city'] = $geolocation_data['city'];
			}
			if (!empty($geolocation_data['subdivision']) && !empty(\wp_slimstat::$stat['city'])) {
				\wp_slimstat::$stat['city'] .= ' (' . $geolocation_data['subdivision'] . ')';
			}
			if (!empty($geolocation_data['latitude']) && !empty($geolocation_data['longitude'])) {
				\wp_slimstat::$stat['location'] = $geolocation_data['latitude'] . ',' . $geolocation_data['longitude'];
			}
		}

		if (isset(\wp_slimstat::$stat['country']) && (\wp_slimstat::$stat['country'] !== '' && \wp_slimstat::$stat['country'] !== '0') && !empty(\wp_slimstat::$settings['ignore_countries']) && false !== stripos(\wp_slimstat::$settings['ignore_countries'], \wp_slimstat::$stat['country'])) {
			return false;
		}

		if ((isset($_SERVER['HTTP_X_MOZ']) && ('prefetch' === strtolower($_SERVER['HTTP_X_MOZ']))) || (isset($_SERVER['HTTP_X_PURPOSE']) && ('preview' === strtolower($_SERVER['HTTP_X_PURPOSE'])))) {
			if ('on' == \wp_slimstat::$settings['ignore_prefetch']) {
				return false;
			} else {
				\wp_slimstat::$stat['notes'][] = 'pre:yes';
			}
		}

		$browser = \SlimStat\Services\Browscap::get_browser();
		if ('on' == \wp_slimstat::$settings['ignore_bots'] && 1 == $browser['browser_type']) {
			return false;
		}
		if (!empty(\wp_slimstat::$settings['ignore_browsers']) && Utils::isBlacklisted([$browser['browser'], $browser['user_agent']], \wp_slimstat::$settings['ignore_browsers'])) {
			return false;
		}
		if (!empty(\wp_slimstat::$settings['ignore_platforms']) && Utils::isBlacklisted($browser['platform'], \wp_slimstat::$settings['ignore_platforms'])) {
			return false;
		}
		\wp_slimstat::$stat += $browser;

		$cookie_has_been_set = Session::ensureVisitId(false);

		\wp_slimstat::$stat = apply_filters('slimstat_filter_pageview_stat', \wp_slimstat::$stat);
		do_action('slimstat_track_pageview', \wp_slimstat::$stat);
		if (\wp_slimstat::$stat === [] || empty(\wp_slimstat::$stat['dt'])) {
			return false;
		}
		if (isset(\wp_slimstat::$stat['notes']) && \wp_slimstat::$stat['notes'] !== []) {
			\wp_slimstat::$stat['notes'] = '[' . implode('][', \wp_slimstat::$stat['notes']) . ']';
		}
		\wp_slimstat::$stat = array_filter(\wp_slimstat::$stat);

		\wp_slimstat::$stat['id'] = Storage::insertRow(\wp_slimstat::$stat, $GLOBALS['wpdb']->prefix . 'slim_stats');
		if (empty(\wp_slimstat::$stat['id'])) {
			include_once(SLIMSTAT_ANALYTICS_DIR . 'admin/index.php');
			\wp_slimstat_admin::init_environment();
			\wp_slimstat::$stat['id'] = Storage::insertRow(\wp_slimstat::$stat, $GLOBALS['wpdb']->prefix . 'slim_stats');
			if (empty(\wp_slimstat::$stat['id'])) {
				Utils::logError(200);
				return false;
			}
		}

		$set_cookie = apply_filters('slimstat_set_visit_cookie', (!empty(\wp_slimstat::$settings['set_tracker_cookie']) && 'on' == \wp_slimstat::$settings['set_tracker_cookie']));
		if ($set_cookie) {
			if (empty(\wp_slimstat::$stat['visit_id']) && !empty(\wp_slimstat::$stat['id'])) {
				@setcookie('slimstat_tracking_code', Utils::getValueWithChecksum(\wp_slimstat::$stat['id'] . 'id'), ['expires' => time() + 2678400, 'path' => COOKIEPATH]);
			} elseif (!$cookie_has_been_set && 'on' == \wp_slimstat::$settings['extend_session'] && \wp_slimstat::$stat['visit_id'] > 0) {
				@setcookie('slimstat_tracking_code', Utils::getValueWithChecksum(\wp_slimstat::$stat['visit_id']), ['expires' => time() + \wp_slimstat::$settings['session_duration'], 'path' => COOKIEPATH]);
			}
		}

		return \wp_slimstat::$stat['id'];
	}

	public static function updateContentType($status = 301, $location = '')
	{
		if ($status >= 300 && $status < 400) {
			\wp_slimstat::$stat['content_type'] = 'redirect:' . intval($status);
			Storage::updateRow(\wp_slimstat::$stat);
		}
		return $status;
	}
}
