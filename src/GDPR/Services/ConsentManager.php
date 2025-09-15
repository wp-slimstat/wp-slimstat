<?php

namespace SlimStat\GDPR\Services;

use SlimStat\GDPR\Interfaces\ConsentManagerInterface;

/**
 * GDPR Consent Manager Service
 */
class ConsentManager implements ConsentManagerInterface
{
    private array $settings;
    private string $cookieName = 'slimstat_gdpr_consent';
    private int $cookieDuration = 365; // days

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Check if GDPR consent is enabled
     */
    public function isEnabled(): bool
    {
        return 'on' === ($this->settings['display_opt_out'] ?? '');
    }

    /**
     * Get current consent status
     */
    public function getConsentStatus(): string
    {
        if (!isset($_COOKIE[$this->cookieName])) {
            return 'not-set';
        }

        return sanitize_text_field($_COOKIE[$this->cookieName]);
    }

    /**
     * Set consent status
     */
    public function setConsent(string $consent): bool
    {
        if (!in_array($consent, ['accepted', 'denied'], true)) {
            return false;
        }

        $expires = time() + ($this->cookieDuration * 24 * 60 * 60);
        $path = COOKIEPATH;
        $domain = COOKIE_DOMAIN;
        $secure = is_ssl();
        $httponly = false;
        $samesite = 'Lax';

        return setcookie(
            $this->cookieName,
            $consent,
            [
                'expires' => $expires,
                'path' => $path,
                'domain' => $domain,
                'secure' => $secure,
                'httponly' => $httponly,
                'samesite' => $samesite,
            ]
        );
    }

    /**
     * Check if consent has been given
     */
    public function hasConsent(): bool
    {
        return isset($_COOKIE[$this->cookieName]);
    }

    /**
     * Get consent cookie name
     */
    public function getConsentCookieName(): string
    {
        return $this->cookieName;
    }

    /**
     * Get consent cookie duration in days
     */
    public function getConsentCookieDuration(): int
    {
        return $this->cookieDuration;
    }
}
