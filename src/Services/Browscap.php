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
                return [4, __('The Browscap Cache folder could not be opened on your filesystem. Please check your server permissions and try again.', 'wp-slimstat')];
            } else {
                self::$browscap_local_version = trim(self::$browscap_local_version);
            }
        }

        // Visitor requests only schedule work; downloads and extraction run in WP-Cron.
        if ('on' === (wp_slimstat::$settings['enable_browscap'] ?? '')
            && time() - (int) (wp_slimstat::$settings['browscap_last_modified'] ?? 0) > 604800
            && !wp_next_scheduled('wp_slimstat_update_browscap')) {
            wp_schedule_single_event(time() + 10, 'wp_slimstat_update_browscap');
        }

        return null;
    }

    /**
     * Converts the USER AGENT string into a more user-friendly browser data structure, with name, version and operating system
     */
    public static function get_browser($_user_agent = '')
    {
        // Memoize: UA doesn't change within a PHP request, so avoid repeated
        // Browscap file I/O and regex matching on follow-up AJAX calls. #291
        static $cached = null;

        $browser = [
            'browser'         => 'Default Browser',
            'browser_version' => '',
            'browser_type'    => 0,
            'platform'        => 'unknown',
            'user_agent'      => self::_get_user_agent(),
        ];

        if (empty($browser['user_agent'])) {
            return $browser;
        }

        if (null !== $cached && $cached['user_agent'] === $browser['user_agent']) {
            return $cached;
        }

        if ('on' == wp_slimstat::$settings['enable_browscap'] && PHP_VERSION_ID >= 70400 && extension_loaded('fileinfo')) {
            $cache_path = wp_slimstat::$upload_dir . '/browscap-cache-master/';
            if (!is_readable($cache_path . 'version.txt')) {
                $cache_path = wp_slimstat::$upload_dir . '/browscap-cache-backup/';
            }
            $browser = self::get_browser_from_browscap($browser, $cache_path);
        }

        if ('Default Browser' == $browser['browser']) {
            $browser = UADetector::get_browser($browser['user_agent']);
        } elseif (empty($browser['browser_version'])) {
            $browser_version            = UADetector::get_browser($browser['user_agent']);
            $browser['browser_version'] = $browser_version['browser_version'];
        }

        // Safety net: re-check any non-crawler type (desktop/mobile/touch) against
        // BOT_GENERIC_REGEX. Browscap misclassifies Chrome-based Googlebot mobile
        // UAs as type=2 because Android/Mobile signals match before the bot suffix.
        if (1 !== (int) $browser['browser_type']) {
            $browser = self::apply_bot_safety_net($browser);
        }

        // Let third-party tools manipulate the data
        $browser = apply_filters('slimstat_filter_browscap', $browser);

        $cached = $browser;

        return $browser;
    }

    // end get_browser

    /**
     * Post-resolution bot safety net. Checks the UA string for known bot
     * indicators when Browscap resolved a browser but did not flag it as
     * a crawler (e.g., Chrome-based Googlebot identified as "Chrome").
     *
     * Only fires on the Browscap-resolved path — when UADetector runs
     * full detection, its own generic bot regex already covers this.
     *
     * @since 5.4.9
     * @param array $browser Browser data with 'user_agent' key.
     * @return array Browser data with browser_type=1 if bot detected.
     */
    public static function apply_bot_safety_net(array $browser): array
    {
        if (empty($browser['user_agent'])) {
            return $browser;
        }

        if (preg_match(UADetector::BOT_GENERIC_REGEX, $browser['user_agent']) > 0) {
            $browser['browser_type'] = 1;
        }

        return $browser;
    }

    /**
     * Whether ext-fileinfo is available, asked from inside this namespace.
     *
     * The admin notice in admin/view/index.php used to call extension_loaded('fileinfo')
     * itself. That file has no namespace, so the call resolved straight to the PHP
     * built-in and nothing in userland could answer it differently: the E2E simulation
     * of a fileinfo-less host could never make the notice render, and the companion
     * "no notice when Browscap is off" assertion passed vacuously on every host that
     * has the extension. Admin code asks Browscap, which owns the requirement.
     */
    public static function has_fileinfo(): bool
    {
        return extension_loaded('fileinfo');
    }

    public static function get_browser_from_browscap($_browser = [], $_cache_path = '')
    {
        // Flysystem's LocalFilesystemAdapter eagerly constructs FinfoMimeTypeDetector,
        // which calls `new finfo(...)` and fatals on hosts without ext-fileinfo (#303).
        if (!extension_loaded('fileinfo') || !is_readable(rtrim($_cache_path, '/') . '/version.txt')) {
            return $_browser;
        }

        try {
            $file_cache = new LocalFilesystemAdapter($_cache_path);
            $filesystem = new Filesystem($file_cache);
            $cache      = new SimpleCache(
                new Flysystem($filesystem)
            );
            $logger        = new NullLogger();
            $browscap      = new \SlimStat\Dependencies\BrowscapPHP\Browscap($cache, $logger);
            $search_object = $browscap->getBrowser();
        } catch (\Throwable $exception) {
            \wp_slimstat::log('Browscap path failed: ' . $exception->getMessage(), 'error');
            $search_object = '';
        }

        if (is_object($search_object) && 'Default Browser' != $search_object->browser && 'unknown' != $search_object->browser) {
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
            } elseif ($search_object->ismobiledevice || $search_object->istablet) {
                $_browser['browser_type'] = 2;
            } elseif (false !== stripos($search_object->device_pointing_method, 'touch')) {
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
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return [2, __('No updates are performed during AJAX requests.', 'wp-slimstat')];
        }

        if (defined('FS_METHOD') && FS_METHOD != 'direct') {
            return [3, __('Please set your <code>FS_METHOD</code> variable to "direct" in your wp-config.php file, or contact our support to obtain a copy of our Browscap Library.', 'wp-slimstat')];
        }

        if (!$_force_download && 'on' !== (wp_slimstat::$settings['enable_browscap'] ?? '')) {
            return [0, __('The Browscap Library is disabled.', 'wp-slimstat')];
        }
        $current_timestamp = time();
        if (!$_force_download && $current_timestamp - (int) (wp_slimstat::$settings['browscap_last_modified'] ?? 0) <= 604800) {
            return [0, __('Your version of the library does not need to be updated.', 'wp-slimstat')];
        }
        if (!file_exists(wp_slimstat::$upload_dir)) {
            wp_slimstat::create_upload_directory();
        }

        // A nonblocking filesystem lock serializes cron/manual replacements and releases on process death.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- WP_Filesystem exposes no file handle for a nonblocking flock; the OS releases this lock on process death.
        $lock = @fopen(wp_slimstat::$upload_dir . '/browscap-update.lock', 'c');
        if (false === $lock) {
            return [6, __('The Browscap update could not lock its data folder. Please check your server permissions.', 'wp-slimstat')];
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the handle required by flock; WP_Filesystem has no equivalent.
            fclose($lock);
            return [11, __('A Browscap update is already running. Please try again later.', 'wp-slimstat')];
        }
        try {
            return self::install_database($_force_download, $current_timestamp);
        } finally {
            flock($lock, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the handle required by flock; WP_Filesystem has no equivalent.
            fclose($lock);
        }
    }

    private static function install_database($force, $current_timestamp)
    {
        global $wp_filesystem;
        $stored = get_option('slimstat_options', []);
        if (!$force && ('on' !== ($stored['enable_browscap'] ?? '') || $current_timestamp - (int) ($stored['browscap_last_modified'] ?? 0) <= 604800)) {
            return [0, __('Your version of the library does not need to be updated.', 'wp-slimstat')];
        }
        $active = wp_slimstat::$upload_dir . '/browscap-cache-master';
        $backup = wp_slimstat::$upload_dir . '/browscap-cache-backup';
        $staging = wp_slimstat::$upload_dir . '/browscap-staging';
        $browscap_zip = wp_slimstat::$upload_dir . '/browscap-db.zip';
        // Persist only the retry timestamp; consent/runtime settings must never be written here.
        wp_slimstat::$settings['browscap_last_modified'] = $current_timestamp;
        $_stored_for_browscap = $stored;
        $_stored_for_browscap['browscap_last_modified'] = $current_timestamp;
        wp_slimstat::update_option('slimstat_options', $_stored_for_browscap);
        unset($_stored_for_browscap);
        // Ensure WP File API is loaded (not auto-loaded on frontend init hook)
        if (!function_exists('unzip_file')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        // Initialize WP_Filesystem — required by unzip_file() (see file.php:1595)
        if (!WP_Filesystem(false, wp_slimstat::$upload_dir)) {
            wp_delete_file($browscap_zip);
            return [10, __('Could not initialize the WordPress filesystem. Please check your server permissions or set <code>FS_METHOD</code> to "direct" in wp-config.php.', 'wp-slimstat')];
        }

        // Recover an interrupted rename before attempting another replacement.
        if (!is_dir($active) && is_dir($backup) && !$wp_filesystem->move($backup, $active)) {
            return [9, __('The previous Browscap data could not be restored. Please check your server permissions.', 'wp-slimstat')];
        }
        $download_remote_file = $force || !is_readable($active . '/version.txt');
        if (!$download_remote_file) {
            // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- version metadata for an opted-in, locally stored browser database.
            $response = wp_remote_get('https://raw.githubusercontent.com/slimstat/browscap-cache/master/version.txt');
            if (is_wp_error($response) || 200 != wp_remote_retrieve_response_code($response)) {
                return [5, __('There was an error checking the remote library version. Please try again later.', 'wp-slimstat')];
            }
            $download_remote_file = trim((string) file_get_contents($active . '/version.txt')) !== trim(wp_remote_retrieve_body($response));
        }

        // Download the most recent version of our pre-processed Browscap database
        if ($download_remote_file) {
            // wp_remote_get (not wp_safe_remote_get) — GitHub archive URLs redirect to
            // codeload.github.com; wp_safe_remote_get blocks external redirects on some hosts.
            $response = wp_remote_get('https://github.com/slimstat/browscap-cache/archive/master.zip', ['timeout' => 300, 'stream' => true, 'filename' => $browscap_zip]);

            if (!file_exists($browscap_zip)) {
                return [6, __('There was an error saving the Browscap data file on your server. Please check your folder permissions.', 'wp-slimstat')];
            }

            if (is_wp_error($response) || 200 != wp_remote_retrieve_response_code($response)) {
                $http_code = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response);
                wp_delete_file($browscap_zip);
                /* translators: %s: HTTP status code or download error message. */
                return [7, sprintf(__('There was an error downloading the Browscap data file (%s). Please try again later.', 'wp-slimstat'), $http_code)];
            }

            // Validate the downloaded file is actually a ZIP archive
            $header = file_get_contents($browscap_zip, false, null, 0, 4);
            if (empty($header) || $header !== "PK\x03\x04") {
                wp_delete_file($browscap_zip);
                return [8, __('The downloaded Browscap file is not a valid ZIP archive. Your host may be blocking the download.', 'wp-slimstat')];
            }

            // Extract completely before moving the working cache; never delete it on an unzip failure.
            $wp_filesystem->delete($staging, true, 'd');
            $result = unzip_file($browscap_zip, $staging);
            wp_delete_file($browscap_zip);
            $replacement = $staging . '/browscap-cache-master';
            if (is_wp_error($result) || !is_readable($replacement . '/version.txt')
                || !preg_match('/^\d+$/', trim((string) file_get_contents($replacement . '/version.txt')))) {
                $wp_filesystem->delete($staging, true, 'd');
                if (is_wp_error($result)) {
                    /* translators: %s: archive extraction error message. */
                    return [9, sprintf(__('There was an error uncompressing the Browscap data file: %s', 'wp-slimstat'), $result->get_error_message())];
                }
                return [9, __('The Browscap data could not be extracted and verified. Your existing data has been preserved.', 'wp-slimstat')];
            }
            $wp_filesystem->delete($backup, true, 'd');
            if (is_dir($active) && !$wp_filesystem->move($active, $backup)) {
                $wp_filesystem->delete($staging, true, 'd');
                return [9, __('The Browscap data folder could not be replaced. Your existing data has been preserved.', 'wp-slimstat')];
            }
            if (!$wp_filesystem->move($replacement, $active)) {
                if (is_dir($backup)) {
                    $wp_filesystem->move($backup, $active);
                }
                $wp_filesystem->delete($staging, true, 'd');
                return [9, __('The Browscap data folder could not be replaced. Your existing data has been preserved.', 'wp-slimstat')];
            }
            $wp_filesystem->delete($backup, true, 'd');
            $wp_filesystem->delete($staging, true, 'd');

            wp_delete_file($browscap_zip);
        }

        return [0, __('The Browscap data file has been installed on your server.', 'wp-slimstat')];
    }

    protected static function _get_user_agent()
    {
        // CVE-2026-7634: sanitize at the source so a malicious UA cannot reach
        // storage or render layers as raw HTML. Mirrors the pattern used in
        // Session.php and IPHashProvider.php. Bot/crawler regex matching downstream
        // (UADetector::BOT_GENERIC_REGEX, BrowscapPHP) operates on alphanumerics
        // and punctuation that sanitize_text_field preserves.
        $user_agent = empty($_SERVER['HTTP_USER_AGENT'])
            ? ''
            : trim(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])));
        $real_user_agent = '';
        if (!empty($_SERVER['HTTP_X_DEVICE_USER_AGENT'])) {
            $real_user_agent = trim(sanitize_text_field(wp_unslash($_SERVER['HTTP_X_DEVICE_USER_AGENT'])));
        } elseif (!empty($_SERVER['HTTP_X_ORIGINAL_USER_AGENT'])) {
            $real_user_agent = trim(sanitize_text_field(wp_unslash($_SERVER['HTTP_X_ORIGINAL_USER_AGENT'])));
        } elseif (!empty($_SERVER['HTTP_X_MOBILE_UA'])) {
            $real_user_agent = trim(sanitize_text_field(wp_unslash($_SERVER['HTTP_X_MOBILE_UA'])));
        } elseif (!empty($_SERVER['HTTP_X_OPERAMINI_PHONE_UA'])) {
            $real_user_agent = trim(sanitize_text_field(wp_unslash($_SERVER['HTTP_X_OPERAMINI_PHONE_UA'])));
        }

        if ('' !== $real_user_agent && '0' !== $real_user_agent && (strlen($real_user_agent) >= 5 || ('' === $user_agent || '0' === $user_agent))) {
            return $real_user_agent;
        }

        return $user_agent;
    }
}
