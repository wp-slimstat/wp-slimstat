<?php
declare(strict_types=1);

namespace SlimStat\Services\Compliance\Integrations;

/**
 * Interface for third-party compliance integrations
 *
 * This interface allows integration with external compliance tools
 * like Cookiebot, OneTrust, etc.
 */
interface ComplianceIntegrationInterface
{
    /**
     * Check if the integration is enabled
     */
    public function isEnabled(): bool;

    /**
     * Get consent status from the integration
     */
    public function getConsentStatus(): array;

    /**
     * Register WordPress hooks for the integration
     */
    public function registerHooks(): void;

    /**
     * Get integration name
     */
    public function getName(): string;
}
