<?php
declare(strict_types=1);

namespace SlimStat\Services;

/**
 * GDPR Consent Management Service
 *
 * Handles GDPR consent banner functionality and cookie management
 * for SlimStat's internal banner system.
 *
 * @since 5.4.0
 */
class GDPRService
{
	/**
	 * @var array
	 */
	private $settings;

	/**
	 * @var string
	 */
	public const CONSENT_COOKIE_NAME = 'slimstat_gdpr_consent';

	/**
	 * @var int Cookie duration (1 year)
	 */
	private const COOKIE_DURATION = 365 * DAY_IN_SECONDS;

	public function __construct(array $settings)
	{
		$this->settings = $settings;
	}

	/**
	 * Check if SlimStat banner is enabled
	 *
	 * @return bool
	 */
	public function isBannerEnabled(): bool
	{
		return isset($this->settings['use_slimstat_banner']) &&
			   'on' === $this->settings['use_slimstat_banner'];
	}

	/**
	 * Check if user has given consent
	 *
	 * @return bool
	 */
	public function hasConsent(): bool
	{
		return isset($_COOKIE[self::CONSENT_COOKIE_NAME]) &&
			   'accepted' === $_COOKIE[self::CONSENT_COOKIE_NAME];
	}

	/**
	 * Check if user has denied consent
	 *
	 * @return bool
	 */
	public function hasDeniedConsent(): bool
	{
		return isset($_COOKIE[self::CONSENT_COOKIE_NAME]) &&
			   'denied' === $_COOKIE[self::CONSENT_COOKIE_NAME];
	}

	/**
	 * Check if user has made any consent decision
	 *
	 * @return bool
	 */
	public function hasConsentDecision(): bool
	{
		return isset($_COOKIE[self::CONSENT_COOKIE_NAME]);
	}

	/**
	 * Get current consent status
	 *
	 * @return string 'accepted', 'denied', or 'not_set'
	 */
	public function getConsentStatus(): string
	{
		return $_COOKIE[self::CONSENT_COOKIE_NAME] ?? 'not_set';
	}

	/**
	 * Set consent cookie
	 *
	 * @param string $consent 'accepted' or 'denied'
	 * @return bool
	 */
	public function setConsent(string $consent): bool
	{
		if (!in_array($consent, ['accepted', 'denied'], true)) {
			return false;
		}

		$cookieOptions = [
			'expires'  => time() + self::COOKIE_DURATION,
			'path'     => COOKIEPATH,
			'domain'   => COOKIE_DOMAIN,
			'secure'   => is_ssl(),
			'httponly' => false,
			'samesite' => 'Lax',
		];

		$result = setcookie(self::CONSENT_COOKIE_NAME, $consent, $cookieOptions);

		if ($result) {
			// Force set the cookie in $_COOKIE array for immediate access
			$_COOKIE[self::CONSENT_COOKIE_NAME] = $consent;
		}

		return $result;
	}

	/**
	 * Clear consent cookie
	 *
	 * @return bool
	 */
	public function clearConsent(): bool
	{
		$cookieOptions = [
			'expires'  => time() - 3600,
			'path'     => COOKIEPATH,
			'domain'   => COOKIE_DOMAIN,
			'secure'   => is_ssl(),
			'httponly' => false,
			'samesite' => 'Lax',
		];

		$result = setcookie(self::CONSENT_COOKIE_NAME, '', $cookieOptions);

		if ($result) {
			unset($_COOKIE[self::CONSENT_COOKIE_NAME]);
		}

		return $result;
	}

	/**
	 * Get consent banner HTML
	 *
	 * @return string HTML markup for the banner
	 */
	public function getBannerHtml(): string
	{
		// Don't show banner if user already made a decision
		if ($this->hasConsentDecision()) {
			return '';
		}

		// Get banner message from settings (with fallback)
		$message = stripslashes($this->settings['opt_out_message'] ?? '');

		// If message is empty, use default message
		if (empty($message)) {
			$message = __('This website uses cookies to analyze site traffic and improve your experience. By continuing to use this site, you consent to our use of cookies.', 'wp-slimstat');
		}

		// Allow only basic HTML tags for formatting while maintaining security
		$allowed_tags = [
			'p'      => [],
			'br'     => [],
			'b'      => [],
			'i'      => [],
			'strong' => [],
			'em'     => [],
			'a'      => [
				'href' => [],
			],
		];
		$message = wp_kses($message, $allowed_tags);

		// Get button text from settings
		$acceptText = empty($this->settings['gdpr_accept_button_text'])
			? __('Accept', 'wp-slimstat')
			: $this->settings['gdpr_accept_button_text'];
		$denyText = empty($this->settings['gdpr_decline_button_text'])
			? __('Deny', 'wp-slimstat')
			: $this->settings['gdpr_decline_button_text'];

		$acceptButton = sprintf(
			'<button type="button" class="slimstat-gdpr-accept" data-consent="accepted">%s</button>',
			esc_html($acceptText)
		);

		$denyButton = sprintf(
			'<button type="button" class="slimstat-gdpr-deny" data-consent="denied">%s</button>',
			esc_html($denyText)
		);

		return sprintf(
			'<div id="slimstat-gdpr-banner" class="slimstat-gdpr-banner">
				<div class="slimstat-gdpr-content">
					<div class="slimstat-gdpr-message">%s</div>
					<div class="slimstat-gdpr-buttons">%s%s</div>
				</div>
			</div>',
			wp_kses_post($message),
			$denyButton,
			$acceptButton
		);
	}
}
