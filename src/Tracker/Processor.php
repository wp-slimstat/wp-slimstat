<?php

namespace SlimStat\Tracker;

use SlimStat\Utils\Query;
use SlimStat\Services\Browscap;
use SlimStat\Services\Privacy;
use SlimStat\Services\GeoService;
use SlimStat\Services\GeoIP;
use SlimStat\Providers\IPHashProvider;
use SlimStat\Utils\Consent;

class Processor
{
	public static function process()
	{
		// Consent gate: delegate to external CMPs via filter; SlimStat does not manage consent.
		if (!Consent::canTrack()) {
			return false;
		}

		\wp_slimstat::$stat['dt'] = \wp_slimstat::date_i18n('U');
		if (empty(\wp_slimstat::$stat['notes'])) {
			\wp_slimstat::$stat['notes'] = [];
		}

		\wp_slimstat::$stat = apply_filters('slimstat_filter_pageview_stat_init', \wp_slimstat::$stat);
		if (\wp_slimstat::$stat === [] || empty(\wp_slimstat::$stat['dt'])) {
			return false;
		}

		unset(\wp_slimstat::$stat['id']);

		// Remove legacy cookie-based opt-in/opt-out handling. CMPs should control tracking via hooks.

		[\wp_slimstat::$stat['ip'], \wp_slimstat::$stat['other_ip']] = Utils::getRemoteIp();
		if (empty(\wp_slimstat::$stat['ip']) || '0.0.0.0' == \wp_slimstat::$stat['ip']) {
			Utils::logError(202);
			return false;
		}

		// Store original IP for GeoIP lookup (before hashing)
		$originalIpForGeo = \wp_slimstat::$stat['ip'];

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

		// Process IP address with anonymization and hashing (for GDPR compliance)
		\wp_slimstat::$stat = IPHashProvider::processIp(\wp_slimstat::$stat);

		if (!isset(\wp_slimstat::$stat['resource'])) {
			\wp_slimstat::$stat['resource'] = \wp_slimstat::get_request_uri();
		}

		\wp_slimstat::$stat['resource'] = sanitize_text_field(urldecode(\wp_slimstat::$stat['resource']));
		\wp_slimstat::$stat['resource'] = preg_replace_callback('/[^\x20-\x7E]/', function($m) { return '%' . bin2hex($m[0]); }, \wp_slimstat::$stat['resource']);
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

		// Only collect PII (username, email) if consent allows it
		$piiAllowed = Consent::piiAllowed();

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

			// Only store username/email if PII is allowed (consent granted in anonymous mode)
			if ($piiAllowed) {
				\wp_slimstat::$stat['username'] = $GLOBALS['current_user']->data->user_login;
				\wp_slimstat::$stat['email']    = $GLOBALS['current_user']->data->user_email;
				\wp_slimstat::$stat['notes'][]  = 'user:' . $GLOBALS['current_user']->data->ID;
			}
			$not_spam = true;
		} elseif ($piiAllowed && isset($_COOKIE['comment_author_' . COOKIEHASH])) {
			// Only check comment cookies if PII is allowed
			// Use original IP (before hashing) for spam check with Query builder
			$spam_comment = Query::select('comment_author, comment_author_email, COUNT(*) as comment_count')
				->from(DB_NAME . '.' . $GLOBALS['wpdb']->comments)
				->where('comment_author_IP', '=', $originalIpForGeo)
				->where('comment_approved', '=', 'spam')
				->groupBy('comment_author')
				->limit(1)
				->getRow();

			if (!empty($spam_comment)) {
				if ('on' == \wp_slimstat::$settings['ignore_spammers']) {
					return false;
				}

				\wp_slimstat::$stat['notes'][]  = 'spam:yes';
				\wp_slimstat::$stat['username'] = $spam_comment->comment_author;
				\wp_slimstat::$stat['email']    = $spam_comment->comment_author_email;
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

		$geographicProvider = new GeoService();
		if ($geographicProvider->isGeoIPEnabled()) {
			try {
				// Use original IP (before hashing) for GeoIP lookup
				$geolocation_data = GeoIP::loader($originalIpForGeo);
			} catch (\Exception $e) {
				Utils::logError(205);
				return false;
			}


			if (!empty($geolocation_data['country']['iso_code']) && 'xx' != $geolocation_data['country']['iso_code']) {
				\wp_slimstat::$stat['country'] = strtolower($geolocation_data['country']['iso_code']);
				if (!empty($geolocation_data['city']['names']['en'])) {
					\wp_slimstat::$stat['city'] = $geolocation_data['city']['names']['en'];
				}

				if (!empty($geolocation_data['subdivisions'][0]['iso_code']) && !empty(\wp_slimstat::$stat['city'])) {
					\wp_slimstat::$stat['city'] .= ' (' . $geolocation_data['subdivisions'][0]['iso_code'] . ')';
				}

				if (!empty($geolocation_data['location']['latitude']) && !empty($geolocation_data['location']['longitude'])) {
					\wp_slimstat::$stat['location'] = $geolocation_data['location']['latitude'] . ',' . $geolocation_data['location']['longitude'];
				}
			}

			if (isset(\wp_slimstat::$stat['country']) && (\wp_slimstat::$stat['country'] !== '' && \wp_slimstat::$stat['country'] !== '0') && !empty(\wp_slimstat::$settings['ignore_countries']) && false !== stripos(\wp_slimstat::$settings['ignore_countries'], (string) \wp_slimstat::$stat['country'])) {
				return false;
			}
		}

		if ((isset($_SERVER['HTTP_X_MOZ']) && ('prefetch' === strtolower($_SERVER['HTTP_X_MOZ']))) || (isset($_SERVER['HTTP_X_PURPOSE']) && ('preview' === strtolower($_SERVER['HTTP_X_PURPOSE'])))) {
			if ('on' == \wp_slimstat::$settings['ignore_prefetch']) {
				return false;
			} else {
				\wp_slimstat::$stat['notes'][] = 'pre:yes';
			}
		}

		$browser = Browscap::get_browser();
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

		// Handle cookie setting using centralized Session class
		// Cookies are ONLY set when consent allows (handled internally by Session::setTrackingCookie)
		if (empty(\wp_slimstat::$stat['visit_id']) && !empty(\wp_slimstat::$stat['id'])) {
			// No visit ID assigned yet - set cookie with pageview ID
			// Cookie expires in 31 days (2678400 seconds)
			Session::setTrackingCookie(\wp_slimstat::$stat['id'], 'id', 2678400);
		} elseif (!$cookie_has_been_set && 'on' == \wp_slimstat::$settings['extend_session'] && \wp_slimstat::$stat['visit_id'] > 0) {
			// Extend existing session cookie
			Session::setTrackingCookie(\wp_slimstat::$stat['visit_id'], 'visit');
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
