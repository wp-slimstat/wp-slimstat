<?php

namespace SlimStat\Tracker;

use SlimStat\Services\GDPRService;

/**
 * GDPR Tracker Trait
 *
 * Provides GDPR consent checking functionality for the tracker
 * following Single Responsibility Principle
 */
trait TrackerGDPRTrait
{
    /**
     * Check GDPR consent before tracking
     */
    protected static function checkGDPRConsent(): bool
    {
        if (!isset(self::$settings['enable_gdpr_consent']) ||
            self::$settings['enable_gdpr_consent'] !== 'on') {
            return true; // GDPR not enabled, allow tracking
        }

        $gdprService = new GDPRService(self::$settings);
        return $gdprService->shouldAllowTracking();
    }

    /**
     * Add GDPR consent check to tracking flow
     */
    protected static function applyGDPRConsentCheck(): bool
    {
        if (!self::checkGDPRConsent()) {
            return false; // Stop tracking if no consent
        }

        return true; // Continue with tracking
    }
}
