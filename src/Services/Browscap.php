<?php

namespace SlimStat\Services;

use Exception;
use SlimStat\Dependencies\League\Flysystem\Filesystem;
use SlimStat\Dependencies\League\Flysystem\Local\LocalFilesystemAdapter;
use SlimStat\Dependencies\MatthiasMullie\Scrapbook\Adapters\Flysystem;
use SlimStat\Dependencies\MatthiasMullie\Scrapbook\Psr16\SimpleCache;
use SlimStat\Dependencies\Psr\Log\NullLogger;
use SlimStat\Utils\UADetector;
use wp_slimstat;
use wp_slimstat_admin;

class Browscap
{
    // Path to the Browscap data and library
    public static $browscap_local_version = 0;

    public static function init()
    {
        // Determine the local version of the data file
        if (file_exists(wp_slimstat::$upload_dir . '/browscap-cache-master/version.txt')) {
            self::$browscap_local_version = @file_get_contents(wp_slimstat::$upload_dir . '/browscap-cache-master/version.txt');
            if (false === self::$browscap_local_version) {
                return array(4, __('The Browscap Cache folder could not be opened on your filesystem. Please check your server permissions and try again.', 'wp-slimstat'));
            } else {
                self::$browscap_local_version = trim(self::$browscap_local_version);
            }
        }

        if (version_compare(PHP_VERSION, '7.4', '>=')) {
            self::update_browscap_database(false);
            // require_once( plugin_dir_path( __FILE__ ) . 'browscap-php/composer/autoload_real.php' );
        }
    }

    /**
     * Converts the USER AGENT string into a more user-friendly browser data structure, with name, version and operating system
     */
    public static function get_browser($_user_agent = '')
    {
        $browser = array(
            'browser'         => 'Default Browser',
            'browser_version' => '',
            'browser_type'    => 0,
            'platform'        => 'unknown',
            'user_agent'      => self::_get_user_agent()
        );

        if (empty($browser['user_agent'])) {
            return $browser;
        }

        if (wp_slimstat::$settings['enable_browscap'] == 'on' && version_compare(PHP_VERSION, '7.4', '>=')) {
            $browser = self::get_browser_from_browscap($browser, wp_slimstat::$upload_dir . '/browscap-cache-master/');
        }

        if ($browser['browser'] == 'Default Browser') {
            $browser = UADetector::get_browser($browser['user_agent']);
        } else if (empty($browser['browser_version'])) {
            $browser_version            = UADetector::get_browser($browser['user_agent']);
            $browser['browser_version'] = $browser_version['browser_version'];
        }

        // Let third-party tools manipulate the data
        $browser = apply_filters('slimstat_filter_browscap', $browser);

        return $browser;
    }

    // end get_browser

    public static function get_browser_from_browscap($_browser = array(), $_cache_path = '')
    {
        try {
            $file_cache    = new LocalFilesystemAdapter($_cache_path);
            $filesystem    = new Filesystem($file_cache);
            $cache         = new SimpleCache(
                new Flysystem($filesystem)
            );
            $logger        = new NullLogger();
            $browscap      = new \SlimStat\Dependencies\BrowscapPHP\Browscap($cache, $logger);
            $search_object = $browscap->getBrowser();
        } catch (Exception $e) {
            $search_object = '';
        }

        if (is_object($search_object) && $search_object->browser != 'Default Browser' && $search_object->browser != 'unknown') {
            $_browser['browser']         = $search_object->browser;
            $_browser['browser_version'] = floatval($search_object->version);
            $_browser['platform']        = strtolower($search_object->platform);

            // Browser Types:
            // 	0: default (desktop, not touch)
            // 	1: crawler
            // 	2: mobile
            //	3: touch, not mobile
            if ($search_object->crawler) {
                $_browser['browser_type'] = 1;
            } else if ($search_object->ismobiledevice || $search_object->istablet) {
                $_browser['browser_type'] = 2;
            } else if (stripos($search_object->device_pointing_method, 'touch') !== false) {
                $_browser['browser_type'] = 3;
            }
        }

        return $_browser;
    }

    /**
     * Downloads the Browscap User Agent database from our repository
     */
    public static function update_browscap_database($_force_download = false)
    {
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            return array(1, __('This library requires at least PHP 7.4. Please ask your service provider to upgrade your server accordingly.', 'wp-slimstat'));
        }

        if (defined('DOING_AJAX') && DOING_AJAX) {
            return array(2, __('No updates are performed during AJAX requests.', 'wp-slimstat'));
        }

        if (defined('FS_METHOD') && FS_METHOD != 'direct') {
            return array(3, __('Please set your <code>FS_METHOD</code> variable to "direct" in your wp-config.php file, or contact our support to obtain a copy of our Browscap Library.', 'wp-slimstat'));
        }

        // Create the folder, if it doesn't exist
        if (!file_exists(wp_slimstat::$upload_dir)) {
            wp_slimstat::create_upload_directory();
        }

        $download_remote_file = $_force_download;
        $current_timestamp    = intval(date('U'));
        $browscap_zip         = wp_slimstat::$upload_dir . '/browscap-db.zip';

        if (empty(wp_slimstat::$settings['browscap_last_modified'])) {

            if (file_exists(wp_slimstat::$upload_dir . '/browscap-cache-master/version.txt')) {
                $file_stat = @stat(wp_slimstat::$upload_dir . '/browscap-cache-master/version.txt');
                if (false !== $file_stat) {
                    wp_slimstat::$settings['browscap_last_modified'] = intval($file_stat['mtime']);
                }
            }

            // The variable could be still empty if the file does not exist or stat failed to open it
            if (empty(wp_slimstat::$settings['browscap_last_modified'])) {
                wp_slimstat::$settings['browscap_last_modified'] = $current_timestamp;
            }
        }

        // Check for updates once a week ( 604800 seconds ), if $_force_download is not true
        if (file_exists(wp_slimstat::$upload_dir . '/browscap-cache-master/version.txt')) {
            if ($current_timestamp - wp_slimstat::$settings['browscap_last_modified'] > 604800) {

                // No matter what the outcome is, we'll check again in one week
                wp_slimstat::$settings['browscap_last_modified'] = $current_timestamp;
                wp_slimstat::update_option('slimstat_options', wp_slimstat::$settings);

                // Now check the version number on the server
                $response = wp_remote_get('https://raw.githubusercontent.com/slimstat/browscap-cache/master/version.txt');
                if (!is_array($response) || is_wp_error($response) || 200 != wp_remote_retrieve_response_code($response)) {
                    return array(5, __('There was an error checking the remote library version. Please try again later.', 'wp-slimstat'));
                }

                $download_remote_file = (self::$browscap_local_version != trim(wp_remote_retrieve_body($response)));
            } else {
                return array(0, __('Your version of the library does not need to be updated.', 'wp-slimstat'));
            }
        }

        // Download the most recent version of our pre-processed Browscap database
        if ($download_remote_file) {
            $response = wp_safe_remote_get('https://github.com/slimstat/browscap-cache/archive/master.zip', array('timeout' => 300, 'stream' => true, 'filename' => $browscap_zip));

            if (!file_exists($browscap_zip)) {
                return array(6, __('There was an error saving the Browscap data file on your server. Please check your folder permissions.', 'wp-slimstat'));
            }

            if (is_wp_error($response) || 200 != wp_remote_retrieve_response_code($response)) {
                @unlink($browscap_zip);
                return array(7, __('There was an error downloading the Browscap data file from our server. Please try again later.', 'wp-slimstat'));
            }

            // Delete the folder, if it exists
            wp_slimstat_admin::rmdir(wp_slimstat::$upload_dir . '/browscap-cache-master/');

            // We're ready to unzip the file
            $result = unzip_file($browscap_zip, wp_slimstat::$upload_dir);
            if (is_wp_error($result)) {
                return array(9, __('There was an error uncompressing the Browscap data file on your server. Please check your folder permissions and PHP configuration.', 'wp-slimstat'));
            }

            if (file_exists($browscap_zip)) {
                @unlink($browscap_zip);
            }
        }

        return array(0, __('The Browscap data file has been installed on your server.', 'wp-slimstat'));
    }

    protected static function _get_user_agent()
    {
        $user_agent = (!empty($_SERVER['HTTP_USER_AGENT']) ? trim($_SERVER['HTTP_USER_AGENT']) : '');

        if (!empty($_SERVER['HTTP_X_DEVICE_USER_AGENT'])) {
            $real_user_agent = trim($_SERVER['HTTP_X_DEVICE_USER_AGENT']);
        } elseif (!empty($_SERVER['HTTP_X_ORIGINAL_USER_AGENT'])) {
            $real_user_agent = trim($_SERVER['HTTP_X_ORIGINAL_USER_AGENT']);
        } elseif (!empty($_SERVER['HTTP_X_MOBILE_UA'])) {
            $real_user_agent = trim($_SERVER['HTTP_X_MOBILE_UA']);
        } elseif (!empty($_SERVER['HTTP_X_OPERAMINI_PHONE_UA'])) {
            $real_user_agent = trim($_SERVER['HTTP_X_OPERAMINI_PHONE_UA']);
        }

        if (!empty($real_user_agent) && (strlen($real_user_agent) >= 5 || empty($user_agent))) {
            return $real_user_agent;
        }

        return $user_agent;
    }
}
