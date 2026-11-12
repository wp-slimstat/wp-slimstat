<?php

namespace SlimStat\Services\Admin;

use SlimStat\Services\ProLicense;

class ConditionTagEvaluator
{
	/**
	 * Historical minimum for the original three license tags.
	 * New targeting tags have their own introduction version below.
	 */
	public const LICENSE_TAGS_MIN_VERSION = '5.4.0';

	/**
	 * Tags that require version gating for backward compatibility.
	 * Old plugin versions evaluate unknown tags as true (fail-open),
	 * so these tags must be paired with is-version-* to prevent mis-targeting.
	 *
	 * @var array<string, string>
	 */
	private static $versionGatedTags = [
		'is-license-active' => '5.4.0',
		'is-license-inactive' => '5.4.0',
		'no-license' => '5.4.0',
		'is-license-invalid' => '6.1.1',
		'is-free' => '6.1.1',
		'has-addon' => '6.1.1',
		'has-addon-wp-slimstat-pro' => '6.1.1',
		'no-addon-wp-slimstat-pro' => '6.1.1',
	];

	/**
	 * Array mapping condition tags to their respective methods.
	 *
	 * @var array
	 */
	private static $tags = [
		'is-admin'            => 'isAdminUser',
		'is-premium'          => 'isPremiumUser',
		'no-premium'          => 'noPremiumUser',
		'is-free'             => 'noPremiumUser',
		'has-addon'           => 'hasProPlugin',
		'has-addon-wp-slimstat-pro' => 'hasProPlugin',
		'no-addon-wp-slimstat-pro' => 'hasNoProPlugin',
		'is-license-active'   => 'isLicenseActive',
		'is-license-inactive' => 'isLicenseInactive',
		'is-license-invalid'  => 'isLicenseInvalid',
		'no-license'          => 'hasNoLicense',
	];

	/**
	 * Check if the current user is an administrator.
	 *
	 * @return bool True if the user is an admin, false otherwise.
	 */
	public static function isAdminUser()
	{
		return \current_user_can('manage_options');
	}

	/**
	 * Check if the user has a premium license.
	 *
	 * @return bool True if a premium license is available, false otherwise.
	 */
	public static function isPremiumUser()
	{
		return \wp_slimstat::pro_is_installed();
	}

	/**
	 * Checks if the user does not have a premium license.
	 *
	 * @return bool
	 */
	public static function noPremiumUser()
	{
		return !\wp_slimstat::pro_is_installed();
	}

	/**
	 * Match the same verified entitlement that unlocks Pro, including outage grace.
	 *
	 * @return bool
	 */
	public static function isLicenseActive()
	{
		return \wp_slimstat::pro_is_installed();
	}

	/**
	 * Check if the user has an inactive license.
	 *
	 * True when a license key exists but is not currently validated:
	 * Pro is inactive, the verdict is negative, or verification has not succeeded.
	 *
	 * @return bool
	 */
	public static function isLicenseInactive()
	{
		return '' !== ProLicense::key() && !self::isLicenseActive();
	}

	/**
	 * Check if the user has no license at all.
	 *
	 * A missing key does not imply that the site owner has never purchased Pro.
	 *
	 * @return bool
	 */
	public static function hasNoLicense()
	{
		return '' === ProLicense::key();
	}

	/** A definitive negative verdict, never an outage or a site awaiting activation. */
	public static function isLicenseInvalid(): bool
	{
		return 'invalid' === ProLicense::state();
	}

	/** Addon tags describe plugin activation, independently of license entitlement. */
	public static function hasProPlugin(): bool
	{
		return \wp_slimstat::pro_plugin_active();
	}

	public static function hasNoProPlugin(): bool
	{
		return !self::hasProPlugin();
	}

	/**
	 * Get the list of tags that require version gating.
	 *
	 * @return array
	 */
	public static function getVersionGatedTags()
	{
		return array_keys(self::$versionGatedTags);
	}

	/**
	 * The newest targeting capability required by an AND-combined tag list.
	 * @param string[] $tags
	 */
	public static function requiredVersion(array $tags): ?string
	{
		$minimum = null;
		foreach ($tags as $tag) {
			$version = self::$versionGatedTags[$tag] ?? null;
			if ($version && (!$minimum || version_compare($version, $minimum, '>'))) $minimum = $version;
		}
		return $minimum;
	}

	/**
	 * Check if the current plugin version is equal to or higher than the specified version.
	 *
	 * @param string $version The version to compare against.
	 * @return bool True if the current version is equal to or higher, false otherwise.
	 */
	public static function isVersionOrHigher($version)
	{
		$currentVersion = SLIMSTAT_ANALYTICS_VERSION;

		if (\version_compare($currentVersion, $version, '>=')) {
			return true;
		}

		return false;
	}

	/**
	 * Checks if the current WordPress site language matches the given language.
	 *
	 * @param string $siteLanguage The language code to check (e.g., 'en_US', 'fr_FR').
	 *
	 * @return bool Returns true if the site language matches, otherwise false.
	 */
	public static function isSiteLanguage($siteLanguage)
	{
		$locale = \get_locale();

		if ($locale === $siteLanguage) {
			return true;
		}

		return false;
	}

	/**
	 * Checks if the provided country code matches the timezone string set in WordPress.
	 *
	 * @param string $country The ISO 3166-1 alpha-2 country code to check against the WordPress timezone.
	 *
	 * @return bool True if a matching country and timezone are found, false otherwise.
	 */
	public static function isCountry($country)
	{
		$timezone = \get_option('timezone_string');

		if (empty($timezone)) {
			return false;
		}

		// Get country code from timezone using proper timezone location detection
		$countryCode = self::getTimezoneCountry($timezone);

		if ($countryCode === \strtoupper($country)) {
			return true;
		}

		return false;
	}

	/**
	 * Retrieve the country code from a given timezone string.
	 *
	 * @param string $timezone The timezone string (e.g., 'Europe/London').
	 * @return string The country code corresponding to the timezone, or empty string if not found.
	 */
	private static function getTimezoneCountry($timezone)
	{
		$countryCode = '';
		$timezones = \timezone_identifiers_list();

		if (\in_array($timezone, $timezones)) {
			$location = \timezone_location_get(new \DateTimeZone($timezone));
			$countryCode = $location['country_code'] ?? '';
		}

		return $countryCode;
	}

	/**
	 * Check if the current user's email matches the specified email address.
	 *
	 * @param string $email The email address to check against.
	 * @return bool True if the current user's email matches, false otherwise.
	 */
	public static function isUserEmail($email)
	{
		if (!\is_user_logged_in()) {
			return false;
		}

		$currentUser = \wp_get_current_user();

		if (empty($currentUser->user_email)) {
			return false;
		}

		// Normalize both email addresses for comparison
		$currentEmail = \strtolower(\trim($currentUser->user_email));
		$targetEmail = \strtolower(\trim($email));

		return $currentEmail === $targetEmail;
	}

	/**
	 * Evaluate a given condition tag and return whether it is met.
	 *
	 * @param string $tag The condition tag to check.
	 * @param string|null $version Optional version number for version-related checks.
	 * @return bool True if the condition is met, false otherwise.
	 */
	public static function checkConditions($tag, ?string $version = null)
	{
		if (!is_string($tag) || '' === $tag) return false;

		if (\strpos($tag, 'is-version-') === 0) {
			$versionNumber = \substr($tag, \strlen('is-version-'));
			return (bool) preg_match('/^\d+\.\d+(\.\d+)?$/', $versionNumber) && self::isVersionOrHigher($versionNumber);
		}

		if (\strpos($tag, 'is-locale-') === 0) {
			$locale = \substr($tag, \strlen('is-locale-'));
			if ($locale) {
				return self::isSiteLanguage($locale);
			}
		}

		if (\strpos($tag, 'is-country-') === 0) {
			$country = \substr($tag, \strlen('is-country-'));
			if ($country) {
				return self::isCountry($country);
			}
		}

		if (\strpos($tag, 'is-email-') === 0) {
			$email = \substr($tag, \strlen('is-email-'));
			if ($email) {
				return self::isUserEmail($email);
			}
		}

		if (\array_key_exists($tag, self::$tags)) {
			$method = self::$tags[$tag];
			return self::$method();
		}

		// An unsupported audience must not send a targeted notice to every customer.
		if (\defined('WP_DEBUG') && WP_DEBUG) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic logging is guarded by WP_DEBUG; normal production requests do not log here.
			\error_log(\sprintf('SlimStat: Unknown condition tag "%s", notification hidden.', $tag));
		}
		return false;
	}
}
