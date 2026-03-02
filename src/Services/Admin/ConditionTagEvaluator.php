<?php

namespace SlimStat\Services\Admin;

class ConditionTagEvaluator
{
	/**
	 * Array mapping condition tags to their respective methods.
	 *
	 * @var array
	 */
	private static $tags = [
		'is-admin'   => 'isAdminUser',
		'is-premium' => 'isPremiumUser',
		'no-premium' => 'noPremiumUser',
	];

	/**
	 * Check if the current user is an administrator.
	 *
	 * @return bool True if the user is an admin, false otherwise.
	 */
	public static function isAdminUser()
	{
		return \current_user_can('administrator');
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
	public static function checkConditions($tag, $version = null)
	{
		if (\strpos($tag, 'is-version-') === 0) {
			$versionNumber = \substr($tag, \strlen('is-version-'));
			if ($versionNumber) {
				return self::isVersionOrHigher($versionNumber);
			}
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

		return false;
	}
}
