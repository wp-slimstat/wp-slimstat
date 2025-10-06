<?php
declare(strict_types=1);

namespace SlimStat\Services\Compliance\Regulations\GDPR\Factories;

use SlimStat\Services\Compliance\Regulations\GDPR\Providers\GDPRServiceProvider;

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
        if (!self::$instance instanceof GDPRServiceProvider) {
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
