<?php
namespace Slimstat\Core;

// don't load directly.
if ( ! defined( 'ABSPATH' ) ) {
    header('Status: 403 Forbidden');
    header('HTTP/1.1 403 Forbidden');
    exit;
}

class Init {

    /**
     * Base directory path for the plugin.
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
        if ( null === self::$instance ) {
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
        // Load dependencies
        self::load_dependencies();
        // Run all the providers
        self::run_providers();
    }

    /**
     * Load all required files from the includes folder.
     */
    private static function load_dependencies() {
        // Load the plugin providers
    }

    /**
     * Run all the providers.
     */
    private static function run_providers() {
        // Get all the providers
        $providers = array(
        );

        // Loop each provider and run it
        foreach ( $providers as $provider ) {
            $provider = new $provider();
            if ( method_exists( $provider, 'run' ) ) {
                $provider->run();
            }
        }
    }
}
