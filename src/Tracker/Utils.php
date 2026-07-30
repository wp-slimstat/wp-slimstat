<?php

namespace SlimStat\Tracker;

use SlimStat\Utils\Consent;
use SlimStat\Utils\Query;

class Utils
{
	/**
	 * How often an unchanged diagnostic refreshes its stored timestamp, in seconds.
	 *
	 * These records answer two questions: *what* went wrong, and *when* it last
	 * happened. The code is what support acts on, and it is written the instant it
	 * changes. The timestamp only has to separate "still happening" from "long over",
	 * so it is refreshed at most this often.
	 *
	 * Refreshing it per occurrence is what made these options a per-request write:
	 * the second ticks over, `update_option()`'s unchanged-value short-circuit stops
	 * firing, and every rejected hit writes `wp_options`. On a bot-exposed site that
	 * is roughly a quarter of all traffic. (D30)
	 *
	 * @var int
	 */
	private const DIAGNOSTIC_REFRESH = 900;

	/**
	 * Autoload policy for the diagnostic options.
	 *
	 * `null` leaves the decision to WordPress, which autoloads them — and that is the
	 * measured right answer, against first instinct. These four records total ~240
	 * bytes in an `alloptions` blob that a tracked hit loads anyway, so reading them
	 * costs nothing while they are autoloaded, and one non-autoloaded read is a
	 * dedicated `SELECT` on a path the tracker takes on every rejected hit.
	 *
	 * De-autoloading was aimed at the `alloptions` invalidation each write causes. The
	 * throttle above already removes almost all of those writes — at most one per code
	 * per DIAGNOSTIC_REFRESH — so the invalidation is no longer worth paying a query
	 * per hit to avoid. Fixing the write frequency was the fix; moving the rows was
	 * treating the symptom, and it made the hot path slower. Measured both ways with
	 * tests/bench/hit-cost.sh. (D30)
	 *
	 * @var bool|null
	 */
	private const DIAGNOSTIC_AUTOLOAD = null;

	/**
	 * Whether a diagnostic whose identity has not changed is due a fresh timestamp.
	 *
	 * Debug mode always refreshes, so support sees a live reproduction rather than a
	 * record that could be a quarter of an hour old.
	 *
	 * @param mixed $storedTime Timestamp currently on record.
	 * @param int   $now        Reading of the SAME clock that wrote it. These records
	 *                          do not agree on one: the tracker options carry
	 *                          date_i18n('U'), which is current_time('timestamp') and
	 *                          so includes the site's GMT offset, while the GeoIP
	 *                          option carries a plain UTC time() written from a dozen
	 *                          call sites. Mixing them would measure the offset
	 *                          instead of the elapsed interval.
	 * @return bool
	 */
	private static function diagnosticIsStale($storedTime, int $now): bool
	{
		if (self::isDebugMode()) {
			return true;
		}

		return ($now - (int) $storedTime) >= self::DIAGNOSTIC_REFRESH;
	}

	/**
	 * Record an `[identity, timestamp]` diagnostic, unless it says nothing new.
	 *
	 * The single mechanism behind every tracker diagnostic record, so that "only write
	 * when the condition actually changes" is implemented once. Callers outside the
	 * tracker — notably the Pro external-database add-on, whose failure path runs on
	 * *every* request through the `slimstat_custom_wpdb` filter — must come through
	 * here rather than carrying their own copy of the write.
	 *
	 * @param string     $option   Option name.
	 * @param int|string $identity The thing that must change to be worth recording:
	 *                             an error code for the tracker, a message for the
	 *                             database add-on.
	 * @return bool Whether the record was written.
	 */
	public static function recordDiagnostic(string $option, $identity): bool
	{
		$stored = \get_option($option, []);
		// One clock reading, used for both the staleness comparison and the stored
		// value, so "compared against the same clock that wrote it" is structural.
		$now = (int) \wp_slimstat::date_i18n('U');

		// Compared as strings so an int code and a string message go through the same
		// path without a loose comparison.
		$same = !empty($stored[0]) && (string) $stored[0] === (string) $identity;

		if ($same && !self::diagnosticIsStale($stored[1] ?? 0, $now)) {
			return false;
		}

		\wp_slimstat::update_option($option, [$identity, (string) $now], self::DIAGNOSTIC_AUTOLOAD);
		return true;
	}

	public static function logError($errorCode = 0)
	{
		// Only write when there is something new to say. This used to cover the 3xx
		// exclusion band alone, which left the codes that repeat hardest — 429 from
		// the rate limiter, 500 from a failing insert — writing on every occurrence.
		//
		// Code 200 means the insert itself failed, and Processor has just written the
		// database error into the detail — clearing it there would throw that away.
		if (self::recordDiagnostic('slimstat_tracker_error', (int) $errorCode) && 200 !== (int) $errorCode) {
			self::clearErrorDetail();
		}

		do_action('slimstat_track_exit_' . abs($errorCode), \wp_slimstat::get_stat());
		return -$errorCode;
	}

	/**
	 * Store a non-fatal tracker warning without marking the last pageview as failed.
	 *
	 * @param int $warningCode Warning code defined in languages/index.php.
	 * @return void
	 */
	public static function logWarning(int $warningCode): void
	{
		self::recordDiagnostic('slimstat_tracker_warning', $warningCode);
		do_action('slimstat_track_warning_' . abs($warningCode), \wp_slimstat::get_stat());
	}

	/**
	 * Store a GeoIP-specific warning without polluting tracker failure diagnostics.
	 *
	 * @param string $message Human-readable GeoIP error.
	 * @return void
	 */
	public static function logGeoIpError(string $message): void
	{
		$stored      = \get_option('slimstat_geoip_error', []);
		$sameMessage = !empty($stored['error']) && $stored['error'] === $message;
		// A plain UTC clock here, not date_i18n('U'): a dozen call sites across the
		// GeoIP providers write this record with time(), so the stored value has to
		// stay comparable with theirs.
		$now = time();

		if ($sameMessage && !self::diagnosticIsStale($stored['time'] ?? 0, $now)) {
			return;
		}

		\wp_slimstat::update_option('slimstat_geoip_error', [
			'time'  => $now,
			'error' => sanitize_text_field($message),
		], self::DIAGNOSTIC_AUTOLOAD);
	}

	/**
	 * Wipe a `[code, timestamp]` diagnostic record.
	 *
	 * @param string $option Tracker error, warning, or GeoIP error option name.
	 * @return void
	 */
	public static function clearDiagnostic(string $option): void
	{
		\wp_slimstat::update_option($option, [], self::DIAGNOSTIC_AUTOLOAD);
	}

	/**
	 * Drop any stale database-error detail left by a failed insert.
	 *
	 * Separate from clearDiagnostic() because the detail is a plain string everywhere
	 * it is read, not the `[code, timestamp]` array the other records use — writing
	 * `[]` here would hand the config screen and the health endpoint the wrong type.
	 *
	 * @return void
	 */
	public static function clearErrorDetail(): void
	{
		if ('' !== (string) \get_option('slimstat_tracker_error_detail', '')) {
			\wp_slimstat::update_option('slimstat_tracker_error_detail', '', self::DIAGNOSTIC_AUTOLOAD);
		}
	}

	/**
	 * Resolve a tracker code to a human-readable label when translations are loaded.
	 *
	 * @param int|null $code Tracker error or warning code.
	 * @return string
	 */
	public static function getTrackerCodeLabel(?int $code): string
	{
		if ($code === null || !class_exists('\wp_slimstat_i18n')) {
			return '';
		}

		if (method_exists('\wp_slimstat_i18n', 'init_dynamic_strings')) {
			\wp_slimstat_i18n::init_dynamic_strings();
		}

		$lookupKey = 'e-' . $code;
		$rawLabel = \wp_slimstat_i18n::get_string($lookupKey);

		return ($rawLabel !== $lookupKey && $rawLabel !== '') ? $rawLabel : '';
	}

	/**
	 * Check if tracker debug mode is active.
	 *
	 * @return bool
	 */
	public static function isDebugMode(): bool
	{
		return (defined('WP_DEBUG') && WP_DEBUG)
			|| ('on' === (\wp_slimstat::$settings['slimstat_debug'] ?? 'off'));
	}

	/**
	 * Send debug response headers for tracking requests.
	 * Only emits when debug mode is active.
	 *
	 * @param string    $transport The transport method (rest, ajax, adblock_bypass).
	 * @param string|int $result   The tracking result.
	 */
	public static function sendTrackingHeaders(string $transport, $result): void
	{
		if (!self::isDebugMode() || headers_sent()) {
			return;
		}

		// $result may be a checksummed string like "123.<hmac>" — extract numeric prefix
		$numericResult = is_string($result) && strpos($result, '.') !== false
			? strstr($result, '.', true)
			: $result;
		$code = is_numeric($numericResult) ? (int) $numericResult : 0;
		header('X-SlimStat-Transport: ' . sanitize_text_field($transport));
		header('X-SlimStat-Outcome: ' . ($code > 0 ? 'success' : 'error'));

		if ($code <= 0) {
			header('X-SlimStat-Error-Code: ' . intval($code));
		}
	}

	/**
	 * The key both cookie checksum schemes are computed with.
	 *
	 * One resolver. There were four copies of this resolution and two had drifted: the
	 * legacy md5 branch below, and Tracker::_get_value_with_checksum(), which had no
	 * AUTH_KEY fallback at all. Both read `settings['secret']` raw, so an empty secret
	 * left them keyed on `md5($value . '')` — publicly computable, and enough to claim
	 * any visit id. What that grants is not read-only: Processor keys its UPDATE on
	 * visit_id AND ip across the session window, so a forged cookie widens a write.
	 *
	 * No shipped install reaches that state — init_options() mints a random secret on
	 * every invocation, so the stored value is never empty in the tree — which is why
	 * this was latent rather than live.
	 *
	 * NOT the same question as Session::getSecureKey() or IPHashProvider's key, and
	 * they must not be merged. Those derive a PSEUDONYMISATION key and fall back to
	 * per-call randomness; sign/verify needs a key that is stable across requests, and
	 * a random one would invalidate every cookie on the next hit. Conversely this
	 * resolver accepts whatever is in the options row, which those reject on purpose.
	 */
	private static function resolveSecret(): string
	{
		$secret = \wp_slimstat::$settings['secret'] ?? '';

		if (empty($secret)) {
			$secret = defined('AUTH_KEY') ? AUTH_KEY : 'slimstat_default_key';
		}

		return $secret;
	}

	public static function getValueWithChecksum($value = 0)
	{
		return $value . '.' . hash_hmac('sha256', (string) $value, self::resolveSecret());
	}

	public static function getValueWithoutChecksum($valueWithChecksum = '')
	{
		if (!is_scalar($valueWithChecksum)) {
			return false;
		}

		$valueWithChecksum = (string) $valueWithChecksum;
		$parts = explode('.', $valueWithChecksum);
		if (count($parts) !== 2) {
			return false;
		}
		[$value, $checksum] = $parts;
		$secret = self::resolveSecret();

		if (hash_equals($checksum, hash_hmac('sha256', (string) $value, $secret))) {
			return $value;
		}

		// Legacy scheme, keyed through the same resolver as the branch above. It covers
		// cookies from before v5.4.2 AND any still minted through the legacy
		// Tracker::_* surface, so retiring it is not simply a matter of waiting out an
		// upgrade window — and the two E2E specs that claim to cover it assert only
		// `status < 500`, so they pass with it deleted. Sunset tracked as W7.
		if (hash_equals($checksum, md5($value . $secret))) {
			return $value;
		}

		return false;
	}

	public static function isBlacklisted($needles = [], $haystackString = '')
	{
		if (!is_array($needles)) {
			$needles = [$needles];
		}

		foreach (\wp_slimstat::string_to_array($haystackString) as $item) {
			$pattern = str_replace(['\\*', '\\!'], ['(.*)', '.'], preg_quote($item, '@'));

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

		// An existence probe, so the cost cannot grow with a visitor's history. This
		// counted every row the visitor had ever generated in order to learn whether
		// they had generated any, on every follow-up event. (D43)
		//
		// Not cached, deliberately: the builder's cache key hashes the prepared SQL,
		// which carries the fingerprint, so caching this wrote two wp_options rows per
		// distinct visitor from the tracking path — measured at 5 queries on a miss to
		// avoid a single 0.06 ms index lookup.
		$table = $GLOBALS['wpdb']->prefix . 'slim_stats';

		return !Query::select('id')
			->from($table)
			->where('fingerprint', '=', $fingerprint)
			->exists();
	}

	public static function dtrPton($ip)
	{
		if (empty($ip)) {
			return '';
		}

		$unpacked = false;

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

		// CF-Connecting-IP is handled separately via getCfClientIp() with CF-Ray validation.
		// Including it here would bypass that check and allow IP spoofing on non-CF origins.
		$originatingIpHeaders = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR', 'HTTP_CLIENT_IP', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_X_REAL_IP', 'HTTP_INCAP_CLIENT_IP'];
		foreach ($originatingIpHeaders as $header) {
			if (!empty($_SERVER[$header])) {
				$headerValue = sanitize_text_field(wp_unslash($_SERVER[$header]));
				foreach (explode(',', $headerValue) as $ip) {
					$ip = trim($ip);
					if (false !== filter_var($ip, FILTER_VALIDATE_IP) && $ip != $ipArray[0]) {
						$ipArray[1] = $ip;
						break 2;
					}
				}
			}
		}

		return apply_filters('slimstat_filter_ip_address', $ipArray);
	}

	/**
	 * Returns the validated Cloudflare client IP when the request is verified as coming
	 * through Cloudflare (CF-Ray header present). Returns null for non-CF requests.
	 *
	 * @return string|null Validated IP address, or null if not a CF request.
	 */
	public static function getCfClientIp(): ?string
	{
		if (empty($_SERVER['HTTP_CF_RAY']) || empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
			return null;
		}

		$cfIp = filter_var(
			sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP'])),
			FILTER_VALIDATE_IP
		);

		return $cfIp ?: null;
	}

	public static function getLanguage()
	{
		if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
			$acceptLanguage = sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT_LANGUAGE']));
			preg_match('/([^,;]*)/', $acceptLanguage, $arrayLanguages);
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

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file, WP_Filesystem not needed
		$search_engines = file_get_contents(SLIMSTAT_ANALYTICS_DIR . 'admin/assets/data/matomo-searchengine.json');
		$search_engines = json_decode($search_engines, true);

		$parsed_url = @parse_url($url ?: '');
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
			$content_info['content_type'] = 'cpt:attachment';
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
			// Store fingerprint in two cases:
			// 1. When PII is allowed (normal tracking with consent)
			// 2. When Anonymous Tracking Mode is enabled (for session detection without cookies)
			//    This allows tracking the same user across pages without cookies
			try {
				$isAnonymousTracking = ('on' === (\wp_slimstat::$settings['anonymous_tracking'] ?? 'off'));
				$piiAllowed = Consent::piiAllowed();

				if ($piiAllowed || $isAnonymousTracking) {
					// Guard against array injection (e.g. fh[]=...) from untrusted input
					$rawFh = is_scalar($dataJs['fh']) ? (string) $dataJs['fh'] : '';
					$fingerprint = preg_replace('/[^a-zA-Z0-9\-_]/', '', $rawFh);
					if (strlen($fingerprint) > 256) {
						$fingerprint = substr($fingerprint, 0, 256);
					}
					$stat['fingerprint'] = sanitize_text_field($fingerprint);
				}
			} catch (\Throwable $e) {
				// Fingerprint not stored when consent check fails (GDPR-safe default)
			}
		}

		if (!empty($dataJs['tz'])) {
			$stat['tz_offset'] = intval($dataJs['tz']);
		}

		return $stat;
	}
}
