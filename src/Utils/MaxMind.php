<?php

namespace SlimStat\Utils;

use wp_slimstat;

class MaxMind
{
    /**
     * @throws InvalidDatabaseException
     */
    public static function getGeolocationInfo($_ip_address = '')
    {
        $maxmind_path = wp_slimstat::$upload_dir . '/maxmind.mmdb';
        $geo_output   = array('country' => array('iso_code' => ''));

        // Is this a RFC1918 (local) IP?
        if (wp_slimstat::is_local_ip_address($_ip_address)) {
            $geo_output['country']['iso_code'] = 'xy';
        } else if (file_exists($maxmind_path) && is_file($maxmind_path)) {
            // Do we need to update our data file?
            if (false !== ($file_stat = stat($maxmind_path))) {
                // Is the database more than 30 days old?
                if (!empty($file_stat) && (date('U') - $file_stat['mtime'] > 2629740)) {
                    add_action('shutdown', array(__CLASS__, 'downloadDatabase'));
                }
            }

            $reader      = new MaxMindReader($maxmind_path);
            $geo_maxmind = $reader->get($_ip_address);

            if (!empty($geo_maxmind)) {
                $geo_output = $geo_maxmind;
            }
        } else if (!is_file($maxmind_path)) {
            return $geo_output;
        }

        return apply_filters('slimstat_get_country', $geo_output, $_ip_address);
    }

    /**
     * Downloads the MaxMind geolocation database archive from their repository.
     * The download file is a tar.gz-file containing a ".mmdb" tar file which contains the .mmdb db file in a subdirectory (with actual date)
     * Example: GeoLite2-City_20200121/GeoLite2-City.mmdb
     * So we:
     * 1. Download the tar.gz file
     * 2. Unpack the file we are interested in (GeoLite2-City.mmdb, GeoLite2-Country.mmdb)
     * 3. Move the mmdb file from the extracted subdir to maxmind.mmdb
     * 4. Delete the extracted subdir
     */
    public static function downloadDatabase(): ?string
    {
        $maxmind_path = wp_slimstat::$upload_dir . '/maxmind.mmdb';

        if (!class_exists('PharData')) {
            return __('Class <code>PharData</code> is not defined in your environment. Please use a PHP version which supports it.', 'wp-slimstat');
        }

        // Create the folder, if it doesn't exist
        if (!file_exists(dirname($maxmind_path))) {
            wp_slimstat::create_upload_directory();
        }

        // Download the most recent database directly from MaxMind's repository
        if (wp_slimstat::$settings['maxmind_license_key'] == '') {
            return __('No MaxMind GeoLite2 license key set. Please enter the MaxMind GeoLite2 license key in Slimstat Settings > Maintenance', 'wp-slimstat');
        }

        $maxmind_license_key = wp_slimstat::$settings['maxmind_license_key'];

        if (wp_slimstat::$settings['geolocation_country'] == 'on') {
            $maxmind_tmp = self::downloadUrl("https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-Country&license_key={$maxmind_license_key}&suffix=tar.gz");
            $database    = 'GeoLite2-Country.mmdb';
        } else {
            $maxmind_tmp = self::downloadUrl("https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-City&license_key={$maxmind_license_key}&suffix=tar.gz");
            $database    = 'GeoLite2-City.mmdb';
        }

        if (is_wp_error($maxmind_tmp)) {
            return __('There was an error downloading the MaxMind Geolite DB:', 'wp-slimstat') . ' ' . $maxmind_tmp->get_error_message();
        }

        $phar          = new \PharData($maxmind_tmp);
        $fileInArchive = trailingslashit($phar->current()->getFileName()) . $database;

        // Extract mmdb file in uploads directory (this includes the directory)
        try {
            $phar->extractTo(wp_slimstat::$upload_dir, $fileInArchive, true);
        } catch (\Exception $e) {
            @unlink($maxmind_tmp);
            return __('There was an error creating the MaxMind Geolite DB.', 'wp-slimstat') . $e->getMessage();
        }

        @rename(trailingslashit(wp_slimstat::$upload_dir) . $fileInArchive, $maxmind_path);

        // delete extracted dir
        @rmdir(trailingslashit(wp_slimstat::$upload_dir) . $phar->current()->getFileName());

        if (!is_file($maxmind_path)) {
            // Something went wrong, maybe a folder was created instead of a regular file
            @rmdir($maxmind_path);
            @unlink($maxmind_tmp);
            return __('There was an error creating the MaxMind Geolite DB.', 'wp-slimstat');
        }

        @unlink($maxmind_tmp);

        return '';
    }

    public static function downloadUrl($url)
    {
        // Include the FILE API, if it's not defined
        if (!function_exists('download_url')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }

        if (!$url) {
            return new \WP_Error('http_no_url', __('The provided URL is invalid.', 'wp-slimstat'));
        }

        $url_filename = basename(parse_url($url, PHP_URL_PATH));

        $filename = wp_tempnam($url_filename);
        if (!$filename) {
            return new \WP_Error('http_no_file', __("A temporary file could not be created. Please check your server's file permissions and try again.", 'wp-slimstat'));
        }

        $response = wp_safe_remote_get($url, array('timeout' => 300, 'stream' => true, 'filename' => $filename, 'user-agent' => 'Slimstat Analytics/' . SLIMSTAT_ANALYTICS_VERSION . '; ' . home_url()));

        if (is_wp_error($response)) {
            unlink($filename);
            return $response;
        }

        if (200 != wp_remote_retrieve_response_code($response)) {
            unlink($filename);
            return new \WP_Error('http_404', trim(wp_remote_retrieve_response_message($response)));
        }

        return $filename;
    }
}
