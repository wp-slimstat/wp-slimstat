<?php
declare(strict_types=1);

namespace SlimStat\Utils;

/**
 * Centralized consent utility for tracking eligibility and PII handling.
 *
 * Implements multi-layered consent: Anonymous mode (no PII by default), Standard mode (CMP-based),
 * DNT header support, and CMP integrations (WP Consent API, Real Cookie Banner).
 * External plugins can override via 'slimstat_can_track' filter.
 *
 * @since 5.4.0
 */
class Consent
{
	/**
	 * Retrieve the configured consent integration, falling back to SlimStat's banner when enabled.
	 *
	 * @return string
	 */
	public static function getIntegrationKey(): string
	{
		$settings = \wp_slimstat::$settings;
		$integrationKey = $settings['consent_integration'] ?? '';

		// Normalize shorthand: JS accepts both 'slimstat' and 'slimstat_banner',
		// but PHP consent checks only handle 'slimstat_banner'. Map the alias so
		// canTrack() and piiAllowed() recognize it consistently.
		if ('slimstat' === $integrationKey) {
			$integrationKey = 'slimstat_banner';
		}

		if ('' === $integrationKey && 'on' === ($settings['use_slimstat_banner'] ?? 'off')) {
			$integrationKey = 'slimstat_banner';
		}

		return $integrationKey;
	}

	/**
	 * Build the context array for the slimstat_can_track filter.
	 *
	 * This context allows filter callbacks to distinguish between normal browser requests
	 * and programmatic/server-side tracking calls (e.g., from slimtrack_server()).
	 *
	 * @since 5.4.4
	 * @return array Context array with 'programmatic' flag and 'source' identifier
	 */
	public static function buildFilterContext(): array
	{
		return [
			'programmatic' => \wp_slimstat::$is_programmatic_tracking,
			'source'       => \wp_slimstat::$is_programmatic_tracking ? 'server' : 'browser',
		];
	}

	/**
	 * Apply the slimstat_can_track filter with tracking context.
	 *
	 * Centralizes the filter call so context is never accidentally omitted.
	 *
	 * @since 5.4.4
	 * @param bool $default Default tracking decision before filter override.
	 * @return bool Filtered tracking decision.
	 */
	private static function applyCanTrackFilter(bool $default): bool
	{
		return (bool) apply_filters('slimstat_can_track', $default, self::buildFilterContext());
	}

	/**
	 * Safe wrapper around wp_has_consent() that ensures wp_get_consent_type() is set.
	 *
	 * When no CMP has registered a consent type, wp_has_consent() defaults to true
	 * regardless of the user's actual consent cookie. This helper temporarily sets
	 * the consent type to 'optin' if unregistered, then cleans up the filter.
	 *
	 * @param string $category Consent category (e.g. 'statistics').
	 * @return bool Whether the user has granted consent for the given category.
	 */
	public static function wpHasConsentSafe(string $category): bool
	{
		if (!function_exists('wp_has_consent')) {
			return false;
		}

		$callback   = null;
		$needsFilter = function_exists('wp_get_consent_type') && ! wp_get_consent_type();

		if ($needsFilter) {
			$callback = static function () {
				return 'optin';
			};
			add_filter('wp_get_consent_type', $callback, 10, 1);
		}

		try {
			return (bool) \wp_has_consent($category);
		} finally {
			if ($needsFilter && $callback !== null) {
				remove_filter('wp_get_consent_type', $callback, 10);
			}
		}
	}

	/**
	 * Safe wrapper around the SlimStat-banner consent check.
	 *
	 * Mirrors wpHasConsentSafe() for the internal banner path: if GDPRService is
	 * unloadable (missing/corrupt class), degrade to "no consent" instead of
	 * throwing. A fatal here would white-screen the front page and wp-login
	 * during script enqueue and server-side tracking (issue #325). Fails closed.
	 *
	 * @param array $settings SlimStat settings passed to GDPRService.
	 * @return bool True only if the visitor has granted SlimStat-banner consent.
	 */
	public static function bannerHasConsentSafe(array $settings): bool
	{
		try {
			return (new \SlimStat\Services\GDPRService($settings))->hasConsent();
		} catch (\Throwable $e) {
			\wp_slimstat::record_degradation('banner_consent_check', $e);
			return false;
		}
	}

	/**
	 * Normalize consent data from various CMP formats to a standard structure.
	 *
	 * Converts different CMP consent formats (WP Consent API, Real Cookie Banner, etc.)
	 * into a standardized format for storage and processing.
	 *
	 * @param mixed $raw Raw consent data from CMP (array, object, boolean, etc.)
	 * @return array Normalized consent structure with categories as keys
	 */
	public static function normalizeConsent($raw): array
	{
		$normalized = [
			'functional'            => 'deny',
			'statistics'            => 'deny',
			'statistics_anonymous' => 'deny',
			'marketing'             => 'deny',
		];

		if (is_bool($raw)) {
			$value = $raw ? 'allow' : 'deny';
			$normalized['statistics'] = $value;
			return $normalized;
		}

		if (is_string($raw)) {
			if ('accepted' === $raw || 'allow' === $raw || 'grant' === $raw) {
				$normalized['statistics'] = 'allow';
			} elseif ('denied' === $raw || 'deny' === $raw || 'revoke' === $raw) {
				$normalized['statistics'] = 'deny';
			}
			return $normalized;
		}

		if (!is_array($raw) && !is_object($raw)) {
			return $normalized;
		}

		$data = (array) $raw;

		if (isset($data['allowed']) && is_array($data['allowed'])) {
			foreach ($data['allowed'] as $category) {
				if (isset($normalized[$category])) {
					$normalized[$category] = 'allow';
				}
			}
			return $normalized;
		}

		if (isset($data['denied']) && is_array($data['denied'])) {
			foreach ($data['denied'] as $category) {
				if (isset($normalized[$category])) {
					$normalized[$category] = 'deny';
				}
			}
		}

		$categories = ['functional', 'statistics', 'statistics_anonymous', 'marketing'];
		foreach ($categories as $category) {
			if (isset($data[$category])) {
				$value = $data[$category];
				if (is_bool($value)) {
					$normalized[$category] = $value ? 'allow' : 'deny';
				} elseif (is_string($value)) {
					$normalized[$category] = in_array($value, ['allow', 'accepted', 'grant', 'true'], true) ? 'allow' : 'deny';
				}
			} elseif (isset($data['groups'][$category])) {
				$value = $data['groups'][$category];
				if (is_bool($value)) {
					$normalized[$category] = $value ? 'allow' : 'deny';
				} elseif (is_string($value)) {
					$normalized[$category] = in_array($value, ['allow', 'accepted', 'grant', 'true'], true) ? 'allow' : 'deny';
				}
			} elseif (isset($data['decision'])) {
				if ('all' === $data['decision']) {
					$normalized[$category] = 'allow';
				} elseif (is_array($data['decision']) && isset($data['decision'][$category])) {
					$value = $data['decision'][$category];
					if (is_bool($value)) {
						$normalized[$category] = $value ? 'allow' : 'deny';
					} elseif (is_string($value)) {
						$normalized[$category] = in_array($value, ['allow', 'accepted', 'grant', 'true'], true) ? 'allow' : 'deny';
					}
				}
			}
		}

		return $normalized;
	}

	/** Whether the configured consent source can be checked on this request. */
	public static function hasConsentSource(): bool
	{
		$key = self::getIntegrationKey();
		if ('slimstat_banner' === $key) {
			return 'on' === (\wp_slimstat::$settings['use_slimstat_banner'] ?? 'off');
		}
		// Legacy RCB installs must expose consent through the WP Consent API.
		return in_array($key, ['wp_consent_api', 'real_cookie_banner'], true) && function_exists('wp_has_consent');
	}

	/** Whether this request may record a pageview. Missing CMPs allow only anonymous counts. */
	public static function canTrack(): bool
	{
		$settings = \wp_slimstat::$settings;
		if ('on' === ($settings['do_not_track'] ?? 'off') && ('1' === (isset($_SERVER['HTTP_DNT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_DNT'])) : '')
            || '1' === (isset($_SERVER['HTTP_SEC_GPC']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_SEC_GPC'])) : ''))) {
			return false;
		}
		if ('on' !== ($settings['gdpr_enabled'] ?? 'off') || \wp_slimstat::$is_programmatic_tracking) {
			return self::applyCanTrackFilter(true);
		}
		$anonymous = 'on' === ($settings['anonymous_tracking'] ?? 'off');
		$cookieless = 'on' !== ($settings['set_tracker_cookie'] ?? 'on')
			&& ('on' === ($settings['anonymize_ip'] ?? 'off') || 'on' === ($settings['hash_ip'] ?? 'off'));
		return self::applyCanTrackFilter($anonymous || $cookieless || !self::hasConsentSource() || self::piiAllowed());
	}

	/**
	 * Check the current consent source before collecting identifiers.
	 *
	 * @param bool $explicitConsentGiven Kept for caller compatibility; a request flag is never consent.
	 * @return bool
	 */
	public static function piiAllowed(bool $explicitConsentGiven = false): bool
	{
		$settings = \wp_slimstat::$settings;
		if ('on' === ($settings['do_not_track'] ?? 'off') && ('1' === (isset($_SERVER['HTTP_DNT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_DNT'])) : '')
            || '1' === (isset($_SERVER['HTTP_SEC_GPC']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_SEC_GPC'])) : ''))) {
			return false;
		}
		$anonymous = 'on' === ($settings['anonymous_tracking'] ?? 'off');
		if (\wp_slimstat::$is_programmatic_tracking) {
			return !$anonymous;
		}
		if ('on' !== ($settings['gdpr_enabled'] ?? 'off') && !$anonymous) {
			return true;
		}
		if (!self::hasConsentSource()) {
			return false;
		}
		if ('slimstat_banner' === self::getIntegrationKey()) {
			return self::bannerHasConsentSafe($settings);
		}
		try {
			return self::wpHasConsentSafe((string) ($settings['consent_level_integration'] ?? 'statistics'));
		} catch (\Throwable $e) {
			return false;
		}
	}
}
