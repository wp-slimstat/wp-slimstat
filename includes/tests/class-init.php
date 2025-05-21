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
        self::register_admin_menu();
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
        foreach (glob($tests_dir . '/**/*.php') as $file) {
            if (basename($file) !== basename(__FILE__)) {
                require_once $file;
            }
        }
        self::register_test_submenus();
    }

    /**
     * Register a WordPress admin menu for running/viewing tests.
     */
    public static function register_admin_menu() {
        add_action('admin_menu', function() {
            add_menu_page(
                'Slimstat Tests', // Page title
                'Slimstat Tests', // Menu title
                'manage_options', // Capability
                'slimstat-tests', // Menu slug
                [self::class, 'render_tests_page'], // Callback
                'dashicons-testimonial', // Icon
                80 // Position
            );
        });
    }

    /**
     * Register all test submenus (including AJAX performance)
     */
    public static function register_test_submenus() {
        // \Slimstat\Tests\Performance\AjaxPerformanceTest::register_submenu();
    }

    /**
     * Render the test runner/admin page.
     */
    public static function render_tests_page() {
        echo '<div class="wrap"><h1>Slimstat Tests</h1>';
        do_action('slimstat_render_tests'); // Future tests can hook here to display their output
        echo '</div>';
    }
}

Init::instance();