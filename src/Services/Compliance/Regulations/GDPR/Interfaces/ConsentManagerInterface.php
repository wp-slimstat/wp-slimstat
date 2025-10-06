<?php
declare(strict_types=1);

namespace SlimStat\Services\Compliance\Regulations\GDPR\Interfaces;

/**
 * Interface for managing GDPR consent
 */
interface ConsentManagerInterface
{
    /**
     * Check if GDPR consent is enabled
     */
    public function isEnabled(): bool;

    /**
     * Get current consent status
     */
    public function getConsentStatus(): string;

    /**
     * Set consent status
     */
    public function setConsent(string $consent): bool;

    /**
     * Check if consent has been given
     */
    public function hasConsent(): bool;

    /**
     * Get consent cookie name
     */
    public function getConsentCookieName(): string;

    /**
     * Get consent cookie duration in days
     */
    public function getConsentCookieDuration(): int;
}
