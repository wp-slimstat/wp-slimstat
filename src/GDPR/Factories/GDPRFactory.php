<?php

namespace SlimStat\GDPR\Factories;

use SlimStat\GDPR\Providers\GDPRServiceProvider;

/**
 * Factory for creating GDPR services
 */
class GDPRFactory
{
    private static ?GDPRServiceProvider $instance = null;

    /**
     * Get GDPR Service Provider instance
     */
    public static function create(array $settings): GDPRServiceProvider
    {
        if (self::$instance === null) {
            self::$instance = new GDPRServiceProvider($settings);
        }

        return self::$instance;
    }

    /**
     * Reset instance (for testing)
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
