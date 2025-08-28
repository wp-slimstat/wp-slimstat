<?php

namespace SlimStat\Services;

/**
 * GDPR Consent Management Service
 *
 * Handles GDPR consent banner functionality and cookie management
 * following Single Responsibility Principle
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
    private const CONSENT_COOKIE_NAME = 'slimstat_gdpr_consent';

    /**
     * @var string
     */
    private const OPT_OUT_COOKIE_NAME = 'slimstat_optout_tracking';

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Check if GDPR consent is enabled
     */
    public function isEnabled(): bool
    {
        return isset($this->settings['enable_gdpr_consent']) &&
               $this->settings['enable_gdpr_consent'] === 'on';
    }

    /**
     * Check if user has given consent
     */
    public function hasConsent(): bool
    {
        return isset($_COOKIE[self::CONSENT_COOKIE_NAME]) &&
               $_COOKIE[self::CONSENT_COOKIE_NAME] === 'accepted';
    }

    /**
     * Check if user has denied consent
     */
    public function hasDeniedConsent(): bool
    {
        return isset($_COOKIE[self::CONSENT_COOKIE_NAME]) &&
               $_COOKIE[self::CONSENT_COOKIE_NAME] === 'denied';
    }

    /**
     * Check if user has made any consent decision
     */
    public function hasConsentDecision(): bool
    {
        return isset($_COOKIE[self::CONSENT_COOKIE_NAME]);
    }

    /**
     * Get current consent status
     */
    public function getConsentStatus(): string
    {
        return $_COOKIE[self::CONSENT_COOKIE_NAME] ?? 'not_set';
    }

    /**
     * Set consent cookie
     */
    public function setConsent(string $consent): bool
    {
        if (!in_array($consent, ['accepted', 'denied'])) {
            return false;
        }

        $duration = intval($this->settings['gdpr_consent_cookie_duration'] ?? 365) * DAY_IN_SECONDS;

        $cookieOptions = [
            'expires' => time() + $duration,
            'path' => COOKIEPATH,
            'domain' => COOKIE_DOMAIN,
            'secure' => is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax'
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
     */
    public function clearConsent(): bool
    {
        $cookieOptions = [
            'expires' => time() - 3600,
            'path' => COOKIEPATH,
            'domain' => COOKIE_DOMAIN,
            'secure' => is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax'
        ];

        $result = setcookie(self::CONSENT_COOKIE_NAME, '', $cookieOptions);

        if ($result) {
            unset($_COOKIE[self::CONSENT_COOKIE_NAME]);
        }

        return $result;
    }

    /**
     * Get consent banner HTML
     */
    public function getBannerHtml(): string
    {
        $message = stripslashes($this->settings['gdpr_consent_message'] ?? '');
        $acceptText = stripslashes($this->settings['gdpr_consent_accept_text'] ?? 'Accept');
        $denyText = stripslashes($this->settings['gdpr_consent_deny_text'] ?? 'Deny');

        $acceptButton = sprintf(
            '<button type="button" class="slimstat-gdpr-accept" data-consent="accepted">%s</button>',
            esc_html($acceptText)
        );

        $denyButton = sprintf(
            '<button type="button" class="slimstat-gdpr-deny" data-consent="denied">%s</button>',
            esc_html($denyText)
        );

        $message = str_replace('{{accept_button}}', $acceptButton, $message);
        $message = str_replace('{{deny_button}}', $denyButton, $message);

        return sprintf(
            '<div id="slimstat-gdpr-banner">
                <div class="banner-content">
                    <div class="banner-message">%s</div>
                    <div class="banner-buttons">%s%s</div>
                </div>
            </div>',
            wp_kses_post($message),
            $denyButton,
            $acceptButton
        );
    }

    /**
     * Get consent management shortcode HTML
     */
    public function getConsentManagementHtml(): string
    {
        $currentConsent = $this->getConsentStatus();
        $acceptText = stripslashes($this->settings['gdpr_consent_accept_text'] ?? 'Accept');
        $denyText = stripslashes($this->settings['gdpr_consent_deny_text'] ?? 'Deny');

        $statusMessage = $this->getConsentStatusMessage($currentConsent);

        return sprintf(
            '<div class="slimstat-consent-management">
                <h3>%s</h3>
                %s
                <div class="slimstat-consent-buttons">
                    <button type="button" data-consent-update="accepted" class="slimstat-consent-accept">%s</button>
                    <button type="button" data-consent-update="denied" class="slimstat-consent-deny">%s</button>
                </div>
                <p><small>%s</small></p>
            </div>',
            esc_html__('Cookie Consent Management', 'wp-slimstat'),
            wp_kses_post($statusMessage),
            esc_html($acceptText),
            esc_html($denyText),
            esc_html__('You can change your consent at any time. The page will reload to apply your new settings.', 'wp-slimstat')
        );
    }

    /**
     * Get consent status message
     */
    private function getConsentStatusMessage(string $status): string
    {
        switch ($status) {
            case 'not_set':
                return '<p>' . esc_html__('You have not yet made a choice regarding cookie consent.', 'wp-slimstat') . '</p>';
            case 'accepted':
                return '<p>' . esc_html__('You have accepted analytics cookies.', 'wp-slimstat') . '</p>';
            case 'denied':
                return '<p>' . esc_html__('You have denied analytics cookies.', 'wp-slimstat') . '</p>';
            default:
                return '<p>' . esc_html__('You have not yet made a choice regarding cookie consent.', 'wp-slimstat') . '</p>';
        }
    }

    /**
     * Check if tracking should be allowed based on consent
     */
    public function shouldAllowTracking(): bool
    {
        if (!$this->isEnabled()) {
            return true; // GDPR not enabled, allow tracking
        }

        // Check if user has explicitly denied consent
        if ($this->hasDeniedConsent()) {
            return false;
        }

        // Check if user has given consent
        if ($this->hasConsent()) {
            return true;
        }

        // If no decision has been made, don't track until consent is given
        return false;
    }
}
