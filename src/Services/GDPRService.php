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
        return isset($this->settings['display_opt_out']) &&
               $this->settings['display_opt_out'] === 'on';
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

        $duration = 365 * DAY_IN_SECONDS; // Default to 1 year

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
        // Use opt_out_message setting for backward compatibility
        $message = stripslashes($this->settings['opt_out_message'] ?? '');

        // Check if this is the old default message and convert it
        $old_default_message = '<p style="display:block;position:fixed;left:0;bottom:0;margin:0;padding:1em 2em;background-color:#eee;width:100%;z-index:99999;">This website stores cookies on your computer. These cookies are used to provide a more personalized experience and to track your whereabouts around our website in compliance with the European General Data Protection Regulation. If you decide to to opt-out of any future tracking, a cookie will be setup in your browser to remember this choice for one year.<br><br><a href="#" onclick="javascript:SlimStat.optout(event, false);">Accept</a> or <a href="#" onclick="javascript:SlimStat.optout(event, true);">Deny</a></p>';

        // Check if message contains the full HTML banner structure (new default that needs fixing)
        if (strpos($message, '<div id="slimstat-gdpr-banner">') !== false) {
            // Extract text content from the banner structure
            $message = strip_tags($message);
            $message = trim($message);

            // Update the database with the clean message
            $this->updateOptOutMessage($message);
        } elseif ($message === $old_default_message) {
            // Convert to new format - extract just the text content
            $new_message = 'This website stores cookies on your computer. These cookies are used to provide a more personalized experience and to track your whereabouts around our website in compliance with the European General Data Protection Regulation. If you decide to opt-out of any future tracking, a cookie will be setup in your browser to remember this choice for one year.';

            // Update the database with the new message
            $this->updateOptOutMessage($new_message);

            $message = $new_message;
        }

        // Allow only basic HTML tags for formatting while maintaining security
        $allowed_tags = array(
            'p' => array(),
            'br' => array(),
            'b' => array(),
            'i' => array(),
            'strong' => array(),
            'em' => array(),
        );
        $message = wp_kses($message, $allowed_tags);

        // If message is empty, use default message
        if (empty($message)) {
            $message = __('This website uses cookies to analyze site traffic and improve your experience. By continuing to use this site, you consent to our use of cookies.', 'wp-slimstat');
        }

        // Create a modern banner with safe content
        $acceptText = !empty($this->settings['gdpr_accept_button_text'])
            ? $this->settings['gdpr_accept_button_text']
            : __('Accept', 'wp-slimstat');
        $denyText = !empty($this->settings['gdpr_decline_button_text'])
            ? $this->settings['gdpr_decline_button_text']
            : __('Deny', 'wp-slimstat');

        $acceptButton = sprintf(
            '<button type="button" class="slimstat-gdpr-accept" data-consent="accepted">%s</button>',
            esc_html($acceptText)
        );

        $denyButton = sprintf(
            '<button type="button" class="slimstat-gdpr-deny" data-consent="denied">%s</button>',
            esc_html($denyText)
        );

        return sprintf(
            '<div id="slimstat-gdpr-banner">
                <div class="banner-content">
                    <div class="banner-message">%s</div>
                    <div class="banner-buttons">%s%s</div>
                </div>
            </div>',
            $message,
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
        $acceptText = !empty($this->settings['gdpr_accept_button_text'])
            ? $this->settings['gdpr_accept_button_text']
            : __('Accept', 'wp-slimstat');
        $denyText = !empty($this->settings['gdpr_decline_button_text'])
            ? $this->settings['gdpr_decline_button_text']
            : __('Deny', 'wp-slimstat');

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

    /**
     * Update the opt_out_message setting in the database
     */
    private function updateOptOutMessage(string $message): void
    {
        // Update the global and local settings arrays
        \wp_slimstat::$settings['opt_out_message'] = $message;
        $this->settings['opt_out_message'] = $message;

        // Persist via WordPress options API (network-aware via wrapper)
        \wp_slimstat::update_option('slimstat_options', \wp_slimstat::$settings);
    }

    /**
     * Migrate old opt_out_message to new format
     */
    public static function migrateOptOutMessage(): void
    {
        $settings = \wp_slimstat::$settings;
        $message = stripslashes($settings['opt_out_message'] ?? '');
        $old_default_message = stripslashes('<p style=\"display:block;position:fixed;left:0;bottom:0;margin:0;padding:1em 2em;background-color:#eee;width:100%;z-index:99999;\">This website stores cookies on your computer. These cookies are used to provide a more personalized experience and to track your whereabouts around our website in compliance with the European General Data Protection Regulation. If you decide to to opt-out of any future tracking, a cookie will be setup in your browser to remember this choice for one year.<br><br><a href=\"#\" onclick=\"javascript:SlimStat.optout(event, false);\">Accept</a> or <a href=\"#\" onclick=\"javascript:SlimStat.optout(event, true);\">Deny</a></p>');
        // Check if message is the old default message
        if ($message === $old_default_message) {
            // Set the new default message
            $new_message = esc_html__('This website stores cookies on your computer. These cookies are used to provide a more personalized experience and to track your whereabouts around our website in compliance with the European General Data Protection Regulation. If you decide to to opt-out of any future tracking, a cookie will be setup in your browser to remember this choice for one year.', 'wp-slimstat');
            // Update the global settings
            \wp_slimstat::$settings['opt_out_message'] = $new_message;
            \wp_slimstat::update_option('slimstat_options', \wp_slimstat::$settings);
        }
    }
}
