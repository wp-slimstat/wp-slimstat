<?php
declare(strict_types=1);

namespace SlimStat\Services\Compliance;

use SlimStat\Services\Compliance\Regulations\CCPA\CCPAServiceProvider;
use SlimStat\Services\Compliance\Regulations\LGPD\LGPDServiceProvider;

/**
 * Compliance Manager for handling multiple privacy regulations
 */
class ComplianceManager
{
    private array $settings;
    private array $regulations = [];

    public function __construct(array $settings)
    {
        $this->settings = $settings;
        $this->initializeRegulations();
    }

    /**
     * Initialize available regulations
     */
    private function initializeRegulations(): void
    {
        // Initialize CCPA
        if ($this->isCCPAEnabled()) {
            $this->regulations['ccpa'] = new CCPAServiceProvider($this->settings);
        }

        // Initialize LGPD
        if ($this->isLGPDEnabled()) {
            $this->regulations['lgpd'] = new LGPDServiceProvider($this->settings);
        }
    }

    /**
     * Check if GDPR is enabled
     */
    // GDPR internal management removed; consent handled via external CMP hooks.

    /**
     * Check if CCPA is enabled
     */
    private function isCCPAEnabled(): bool
    {
        return 'on' === ($this->settings['ccpa_enabled'] ?? '');
    }

    /**
     * Check if LGPD is enabled
     */
    private function isLGPDEnabled(): bool
    {
        return 'on' === ($this->settings['lgpd_enabled'] ?? '');
    }

    /**
     * Get GDPR service provider
     */
    public function getGDPR()
    {
        return $this->regulations['gdpr'] ?? null;
    }

    /**
     * Get CCPA service provider
     */
    public function getCCPA()
    {
        return $this->regulations['ccpa'] ?? null;
    }

    /**
     * Get LGPD service provider
     */
    public function getLGPD()
    {
        return $this->regulations['lgpd'] ?? null;
    }

    /**
     * Get all active regulations
     */
    public function getActiveRegulations(): array
    {
        return array_keys($this->regulations);
    }

    /**
     * Check if any regulation requires consent
     */
    public function requiresConsent(): bool
    {
        foreach ($this->regulations as $regulation) {
            if (method_exists($regulation, 'getConsentManager') &&
                $regulation->getConsentManager()->isEnabled()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get consent status across all regulations
     */
    public function getConsentStatus(): array
    {
        $status = [];
        foreach ($this->regulations as $name => $regulation) {
            if (method_exists($regulation, 'getConsentManager')) {
                $status[$name] = $regulation->getConsentManager()->getConsentStatus();
            }
        }
        return $status;
    }

    /**
     * Register WordPress hooks for all regulations
     */
    public function registerHooks(): void
    {
        foreach ($this->regulations as $regulation) {
            if (method_exists($regulation, 'registerHooks')) {
                $regulation->registerHooks();
            }
        }
    }
}
