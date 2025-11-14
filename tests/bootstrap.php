<?php
/**
 * PHPUnit bootstrap file for WP-SlimStat tests.
 *
 * @package SlimStat
 * @since 5.1.0
 */

// Composer autoloader
$autoload_file = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($autoload_file)) {
    require_once $autoload_file;
}

// WordPress test library path
$_tests_dir = getenv('WP_TESTS_DIR');
if (!$_tests_dir) {
    $_tests_dir = rtrim(sys_get_temp_dir(), '/\\') . '/wordpress-tests-lib';
}

// Give access to tests_add_filter() function
if (file_exists($_tests_dir . '/includes/functions.php')) {
    require_once $_tests_dir . '/includes/functions.php';
}

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin()
{
    // Load WordPress database class
    require_once ABSPATH . 'wp-includes/wp-db.php';
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Load main plugin file
    require dirname(__DIR__) . '/wp-slimstat.php';
}

if (function_exists('tests_add_filter')) {
    tests_add_filter('muplugins_loaded', '_manually_load_plugin');
}

// Start up the WP testing environment
if (file_exists($_tests_dir . '/includes/bootstrap.php')) {
    require $_tests_dir . '/includes/bootstrap.php';
} else {
    // Fallback for local development without WordPress test library
    echo "Warning: WordPress test library not found. Some tests may not work properly.\n";

    // Define WordPress constants for basic testing
    if (!defined('ABSPATH')) {
        define('ABSPATH', dirname(__DIR__, 5) . '/');
    }

    if (!defined('DAY_IN_SECONDS')) {
        define('DAY_IN_SECONDS', 86400);
    }

    // Load WordPress if available
    if (file_exists(ABSPATH . 'wp-load.php')) {
        require_once ABSPATH . 'wp-load.php';
    }

    // Load plugin
    _manually_load_plugin();
}
