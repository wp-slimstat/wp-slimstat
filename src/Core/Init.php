<?php
namespace SlimStat\Core;

// don't load directly.
if ( ! defined( 'ABSPATH' ) ) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit;
}

class Init {
    /**
     * Core directory path.
     *
     * @var string
     */
    public static $dir = null;

    /**
     * Singleton instance of the class.
     *
     * @var Init
     */
    private static $instance = null;

    /**
     * Get singleton instance of the class.
     *
     * @return Init
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Define constants used throughout the plugin.
     */
    private static function definitions() {
        self::$dir = __DIR__;
    }

    /**
     * Run the plugin core loading process.
     */
    public static function run() {
        // Define constants
        self::definitions();
        // Run all the providers
        self::runProviders();
    }

    /**
     * Run all the providers.
     */
    private static function runProviders() {
        // Call static run() on each provider
        \SlimStat\Core\Providers\RESTService::run();
    }
}
