<?php
namespace SlimStat\Modules;

/**
 * SlimStat Debug Logger
 *
 * This file provides comprehensive debugging for SlimStat date handling issues,
 * particularly the "Invalid Date, NaN" problem in 12-months range charts.
 *
 * @package SlimStat
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class SlimStat_Debug_Logger
{
    private static $log_file = '';
    private static $enabled = false;
    private static $log_entries = [];

    /**
     * Ensure logger is ready during AJAX (admin-ajax.php) even if init gating missed
     */
    public static function ensure_ready_for_ajax()
    {
        if (!self::$enabled) {
            self::$enabled = true;
        }
        if (empty(self::$log_file)) {
            $upload_dir = wp_upload_dir();
            self::$log_file = $upload_dir['basedir'] . '/debug-slimstat.log';
        }
    }

    /**
     * Initialize the debug logger
     */
    public static function init()
    {
        self::$enabled = true;

        // Set log file path in uploads directory
        $upload_dir = wp_upload_dir();
        self::$log_file = $upload_dir['basedir'] . '/debug-slimstat.log';

        if( file_exists(self::$log_file) ) {
            @unlink(self::$log_file);
        }
    }

    /**
     * Log a debug message
     */
    public static function log($message, $context = [])
    {
        if (!self::$enabled) {
            return;
        }

        $timestamp = current_time('Y-m-d H:i:s');
        $context_str = !empty($context) ? ' | Context: ' . json_encode($context) : '';
        $log_entry = "[{$timestamp}] {$message}{$context_str}" . PHP_EOL;

        self::$log_entries[] = $log_entry;

        // Write to file
        file_put_contents(self::$log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Log server/environment context
     */
    public static function log_server_context()
    {
        global $wpdb;

        $mysqlVersion = '';
        if (method_exists($wpdb, 'db_version')) {
            $mysqlVersion = $wpdb->db_version();
        } else {
            $mysqlVersion = (string) $wpdb->get_var('SELECT VERSION()');
        }

        self::log('Server Context', [
            'site_url' => get_site_url(),
            'home_url' => get_home_url(),
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'wp_timezone' => wp_timezone_string(),
            'wp_time' => current_time('Y-m-d H:i:s'),
            'server_time' => date('Y-m-d H:i:s'),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'mysql_version' => $mysqlVersion,
            'request_uri' => isset($_SERVER['REQUEST_URI']) ? sanitize_text_field((string) $_SERVER['REQUEST_URI']) : '',
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field((string) $_SERVER['HTTP_USER_AGENT']) : '',
        ]);
    }

    /**
     * Get all log entries
     */
    public static function get_logs()
    {
        return self::$log_entries;
    }

    /**
     * Get log file path
     */
    public static function get_log_file()
    {
        return self::$log_file;
    }

    /**
     * Check if logging is enabled
     */
    public static function is_enabled()
    {
        return self::$enabled;
    }
}
// No global hooks: logger is used only within Chart
