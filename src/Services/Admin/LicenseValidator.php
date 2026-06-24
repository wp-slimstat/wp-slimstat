<?php

namespace SlimStat\Services\Admin;

/**
 * Daily Pro-license revalidation. Free is the authority so this runs regardless
 * of the installed Pro version.
 *
 * Runs on the slimstat_daily_license_check action so an expired or revoked
 * license is detected automatically, without the admin re-saving the License
 * tab. Designed to NEVER disable a valid paying customer on a transient outage:
 * the remote check is tri-state and only an explicit "invalid" verdict from the
 * server downgrades the cached status. Network errors, timeouts and 5xx
 * responses are treated as "unknown" and keep the last-known-good status.
 */
class LicenseValidator
{
	/** Remote validation endpoint. */
	private const ENDPOINT = 'https://wp-slimstat.com/wp-json/plugins/v1/validate';

	/** Minimum seconds between remote checks. */
	private const THROTTLE = DAY_IN_SECONDS;

	/** Option key holding the merged Slimstat settings. */
	private const OPTION = 'slimstat_options';

	/**
	 * Revalidate the stored license against the remote endpoint, throttled to
	 * once per day. No-op (and no external call) when no key is present.
	 *
	 * @return void
	 */
	public static function maybeRevalidate()
	{
		$settings = \wp_slimstat::$settings;
		$key      = isset($settings['slimstat_pro_license_key']) ? \trim((string) $settings['slimstat_pro_license_key']) : '';

		// Free-only sites (no key) never call out.
		if ('' === $key) {
			return;
		}

		$last = isset($settings['slimstat_pro_license_last_verified_at'])
			? (int) $settings['slimstat_pro_license_last_verified_at']
			: 0;
		if ($last > 0 && (\time() - $last) < self::THROTTLE) {
			return;
		}

		$verdict = self::remoteVerify($key);

		// "unknown" (network/timeout/5xx) keeps the last-known-good status and
		// does NOT advance last_verified_at, so the next cron run retries.
		if ('unknown' === $verdict) {
			return;
		}

		self::persistStatus('valid' === $verdict);
	}

	/**
	 * Query the remote endpoint and classify the result.
	 *
	 * Only a parseable, deliberate server verdict changes a license's status:
	 *  - valid:   HTTP < 500 with a parseable body whose status is 200
	 *  - invalid: HTTP < 500 with a parseable body whose status is a client
	 *             error (the server explicitly says the key is invalid/expired)
	 *  - unknown: WP_Error, timeout, 5xx, empty/unparseable body, or any
	 *             ambiguous response — never downgrades a valid customer
	 *
	 * @param string $key
	 * @return string 'valid'|'invalid'|'unknown'
	 */
	public static function remoteVerify($key)
	{
		$url = \add_query_arg(
			[
				'plugin-name' => 'wp-slimstat-pro',
				'license_key' => $key,
				'website'     => \get_bloginfo('url'),
			],
			self::ENDPOINT
		);

		$response = \wp_remote_get($url, ['timeout' => 10]);

		if (\is_wp_error($response)) {
			return 'unknown';
		}

		$code = (int) \wp_remote_retrieve_response_code($response);
		if (0 === $code || $code >= 500) {
			return 'unknown';
		}

		$body = \json_decode((string) \wp_remote_retrieve_body($response));
		if (!\is_object($body) || !isset($body->status)) {
			return 'unknown';
		}

		$status = (int) $body->status;
		if (200 === $status) {
			return 'valid';
		}

		// A deliberate client-error verdict (e.g. 401/403/410) means the key is
		// genuinely invalid or expired. Anything else stays "unknown" so a quirky
		// response can never silently disable a paying customer.
		if ($status >= 400 && $status < 500) {
			return 'invalid';
		}

		return 'unknown';
	}

	/**
	 * Persist the resolved status to the option store that actually holds the
	 * license key (per-site or network), merging into a freshly-read copy so a
	 * concurrent settings edit is never clobbered. Always stamps
	 * last_verified_at to keep the once-per-day throttle honest.
	 *
	 * @param bool $valid
	 * @return void
	 */
	private static function persistStatus($valid)
	{
		$store = self::resolveStore();
		$opts  = $store['options'];

		$opts['slimstat_pro_license_status']           = $valid;
		$opts['slimstat_pro_license_last_verified_at'] = \time();

		if ('network' === $store['scope']) {
			\update_site_option(self::OPTION, $opts);
		} else {
			\update_option(self::OPTION, $opts);
		}

		// Keep the in-memory copy consistent for the rest of this request, as the
		// slimstat_daily_license_check contract requires.
		\wp_slimstat::$settings['slimstat_pro_license_status']           = $valid;
		\wp_slimstat::$settings['slimstat_pro_license_last_verified_at'] = $opts['slimstat_pro_license_last_verified_at'];
	}

	/**
	 * Find which option store currently holds the license key so reads and
	 * writes target the same place (network option when network-activated,
	 * per-site otherwise). Falls back to the per-site store.
	 *
	 * @return array{scope:string, options:array}
	 */
	private static function resolveStore()
	{
		if (\is_multisite()) {
			$network = \get_site_option(self::OPTION, []);
			if (\is_array($network) && !empty($network['slimstat_pro_license_key'])) {
				return ['scope' => 'network', 'options' => $network];
			}
		}

		$site = \get_option(self::OPTION, []);
		return ['scope' => 'site', 'options' => \is_array($site) ? $site : []];
	}
}
