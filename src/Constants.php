<?php

if (!defined('ABSPATH')) {
    exit;
} // Exit if accessed directly

/**
 * Check get_plugin_data function exist
 */
if (!function_exists('get_plugin_data')) {
    require_once(ABSPATH . 'wp-admin/includes/plugin.php');
}

// Set Plugin path and url defines.
define('SLIMSTAT_ANALYTICS_URL', plugin_dir_url(dirname(__FILE__)));
define('SLIMSTAT_ANALYTICS_DIR', plugin_dir_path(dirname(__FILE__)));

// Set another useful Plugin defines.
define('SLIMSTAT_ANALYTICS_ADMIN_URL', get_admin_url());
define('SLIMSTAT_ANALYTICS_SITE', 'https://wp-slimstat.com');