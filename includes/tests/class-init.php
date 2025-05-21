<?php
namespace Slimstat\Tests;

class Init
{
    /**
     * Singleton instance
     *
     * @var Init|null
     */
    private static $instance = null;

    /**
     * Private constructor to prevent direct instantiation.
     */
    private function __construct()
    {
        // Load all test files here
        $this->load_tests();
    }

    /**
     * Get the singleton instance
     *
     * @return Init
     */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Load and run all test files in the tests directory
     */
    private function load_tests()
    {
        $tests_dir = __DIR__;
        foreach (glob($tests_dir . '/*.php') as $file) {
            if (basename($file) !== basename(__FILE__)) {
                require_once $file;
            }
        }
    }
}
