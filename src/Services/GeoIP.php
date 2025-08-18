<?php

namespace SlimStat\Services;

use SlimStat\Utils\InvalidDatabaseException;
use SlimStat\Utils\MaxMindReader;

class GeoIP
{
    /**
     * Supported providers aligned with WP-Statistics:
     * - maxmind (default)
     * - dbip (DB-IP City Lite)
     * - cloudflare (header-based; no database)
     */
    public const PROVIDER_MAXMIND    = 'maxmind';

    public const PROVIDER_DBIP       = 'dbip';

    public const PROVIDER_CLOUDFLARE = 'cloudflare';

    /**
     * List Geo ip Library
     *
     * @var array
     */
    public static $library = [
        // MaxMind sources (default)
        'maxmind' => [
            'country' => [
                'source'     => 'https://cdn.jsdelivr.net/npm/geolite2-country/GeoLite2-Country.mmdb.gz',
                'userSource' => 'https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-Country&license_key=&suffix=tar.gz',
                'file'       => 'GeoLite2-Country',
                'opt'        => 'geoip',
                'cache'      => 31536000, // 1 Year
            ],
            'city' => [
                'source'     => 'https://cdn.jsdelivr.net/npm/geolite2-city/GeoLite2-City.mmdb.gz',
                'userSource' => 'https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-City&license_key=&suffix=tar.gz',
                'file'       => 'GeoLite2-City',
                'opt'        => 'geoip_city',
                'cache'      => 6998000, // ~3 Months
            ],
        ],

        // DB-IP City Lite via WP-Statistics CDN mirror on jsDelivr
        // We always use the City database (it also contains country-level info)
        'dbip' => [
            'country' => [
                'source' => 'https://cdn.jsdelivr.net/gh/wp-statistics/DbIP-City-lite/dbip-city-lite.mmdb.gz',
                'file'   => 'DbIP-City-Lite',
                'opt'    => 'geoip_city',
                'cache'  => 6998000,
            ],
            'city' => [
                'source' => 'https://cdn.jsdelivr.net/gh/wp-statistics/DbIP-City-lite/dbip-city-lite.mmdb.gz',
                'file'   => 'DbIP-City-Lite',
                'opt'    => 'geoip_city',
                'cache'  => 6998000,
            ],
        ],
    ];

    /**
     * Geo IP file Extension
     *
     * @var string
     */
    public static $file_extension = 'mmdb';

    /**
     * Get current provider from settings.
     */
    public static function get_provider()
    {
        // Enforce WP-Statistics-like default: DB-IP City Lite only
        return self::PROVIDER_DBIP;
    }

    /**
     * @param $pack
     *
     * @return string
     */
    public static function get_geo_ip_path($pack)
    {
        $pack     = strtolower($pack);
        $provider = self::get_provider();

        // Default to MaxMind file naming if provider not recognized
        $meta = self::$library[self::PROVIDER_MAXMIND][$pack] ?? null;
        if (isset(self::$library[$provider][$pack])) {
            $meta = self::$library[$provider][$pack];
        }

        // Fallback guard
        if (!$meta) {
            $meta = self::$library[self::PROVIDER_MAXMIND]['country'];
        }

        return wp_normalize_path(path_join(\wp_slimstat::$upload_dir, $meta['file'] . '.' . self::$file_extension));
    }

    /**
     * @return string
     */
    public static function get_pack()
    {
        return 'on' == \wp_slimstat::$settings['geolocation_country'] ? 'country' : 'city';
    }

    /**
     * @return string
     */
    public static function get_database_file($pack = false)
    {
        if (self::maxmind_database_exists()) {
            return self::get_maxmind_database_file();
        }

        $geo_pack = ($pack ?: self::get_pack());
        return self::get_geo_ip_path($geo_pack);
    }

    /**
     * @return bool
     */
    public static function database_exists($pack = false)
    {
        if (self::maxmind_database_exists()) {
            return true;
        }

        $filePath = self::get_database_file($pack);
        return file_exists($filePath);
    }

    /**
     * Get MaxMind Database File Path
     *
     * @return string
     */
    public static function get_maxmind_database_file()
    {
        return wp_normalize_path(path_join(\wp_slimstat::$upload_dir, 'maxmind.mmdb'));
    }

    /**
     * Check if MaxMind Database Exists
     *
     * @return bool
     */
    public static function maxmind_database_exists()
    {
        return file_exists(self::get_maxmind_database_file());
    }

    /**
     * @throws InvalidDatabaseException
     */
    public static function loader($ip)
    {
        // Cloudflare provider doesn't require a database
        if (self::PROVIDER_CLOUDFLARE === self::get_provider()) {
            $country = null;
            // Cloudflare and CloudFront headers
            if (!empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
                $country = strtoupper(sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_IPCOUNTRY'])));
            } elseif (!empty($_SERVER['HTTP_CLOUDFRONT_VIEWER_COUNTRY'])) {
                $country = strtoupper(sanitize_text_field(wp_unslash($_SERVER['HTTP_CLOUDFRONT_VIEWER_COUNTRY'])));
            }

            if ($country && 'XX' !== $country) {
                return [
                    'country' => [
                        'iso_code' => $country,
                    ],
                ];
            }

            // No header? Fallback to DB if present
            if (!self::database_exists()) {
                return false;
            }
        }

        if (self::database_exists()) {
            try {
                $reader     = new MaxMindReader(self::get_database_file());
                $record     = $reader->get(sanitize_text_field($ip));
                return self::normalizeRecord($record);
            } catch (\Exception $e) {
                error_log('Slimstat Error - ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
                return false;
            }
        }

        return false;
    }

    /**
     * Normalize different MMDB vendor formats to a common structure
     */
    protected static function normalizeRecord($record)
    {
        if (!is_array($record)) {
            return $record;
        }

        // Normalize city as names.en if city is a string
        if (!empty($record['city']) && is_string($record['city']) && empty($record['city']['names']['en'])) {
            $record['city'] = [
                'names' => [
                    'en' => $record['city'],
                ],
            ];
        }

        // Ensure country.iso_code is uppercase 2-letter
        if (!empty($record['country']['iso_code'])) {
            $record['country']['iso_code'] = strtoupper($record['country']['iso_code']);
        }

        return $record;
    }

    /**
     * @param $pack
     *
     * @return array
     */
    public static function download($pack, $args = [])
    {
        $args = wp_parse_args($args, [
            'update'              => false,
            'enable_maxmind'      => false,
            'maxmind_license_key' => false,
        ]);

        try {
            // Cloudflare provider doesn't need DB files
            if (self::PROVIDER_CLOUDFLARE === self::get_provider()) {
                return [
                    'status' => true,
                    'notice' => __('Cloudflare geolocation enabled. No database download is required.', 'wp-slimstat'),
                ];
            }

            if (!function_exists('WP_Filesystem')) {
                include_once ABSPATH . 'wp-admin/includes/file.php';
            }

            WP_Filesystem();
            global $wp_filesystem;

            // Create Empty Return Function
            $result['status'] = false;

            // Sanitize Pack name
            $pack = strtolower($pack);

            // Create a variable with the name of the database file to download (by provider)
            $DBFile = self::get_geo_ip_path($pack);

            if (!$args['update'] && file_exists($DBFile)) {
                $result['status'] = true;
                return array_merge($result, ['notice' => __('GeoIP Database Already Exists!', 'wp-slimstat')]);
            }

            // Get the upload directory from WordPress.
            $upload_dir = wp_upload_dir();

            // We need the gzopen() function
            if (false === function_exists('gzopen')) {
                return array_merge($result, ['notice' => __('Error: <code>gzopen()</code> Function Not Found!', 'wp-slimstat')]);
            }

            $isMaxmind = false;

            // Determine the download source based on provider and settings
            $provider = self::get_provider();
            $pack     = strtolower($pack);
            $library  = self::$library[$provider][$pack] ?? self::$library[self::PROVIDER_MAXMIND][$pack];

            // MaxMind: optionally use licensed tarball
            if (self::PROVIDER_MAXMIND === $provider && 'on' == $args['enable_maxmind'] && $args['maxmind_license_key']) {
                $download_url = add_query_arg([
                    'license_key' => $args['maxmind_license_key'],
                ], $library['userSource']);
                $isMaxmind = true;
            } else {
                $download_url = $library['source'];
            }

            // Check to see if the subdirectory we're going to upload to exists, if not create it.
            if (!file_exists(\wp_slimstat::$upload_dir) && !$wp_filesystem->mkdir(\wp_slimstat::$upload_dir, 0755)) {
                return array_merge($result, ['notice' => sprintf(__('Error Creating GeoIP Database Directory. Ensure Web Server Has Directory Creation Permissions in: %s', 'wp-slimstat'), $upload_dir['basedir'])]);
            }

            if (!$wp_filesystem->is_writable(\wp_slimstat::$upload_dir)) {
                return array_merge($result, ['notice' => sprintf(__('Error Setting Permissions for GeoIP Database Directory. Check Write Permissions for Directories in: %s', 'wp-slimstat'), $upload_dir['basedir'])]);
            }

            // Download the file from MaxMind, this places it in a temporary location.
            $TempFile = self::downloadUrl($download_url);

            // If we failed, through a message, otherwise proceed.
            if (is_wp_error($TempFile)) {
                return array_merge($result, ['notice' => sprintf(__('Error Downloading GeoIP Database from: %1$s - %2$s', 'wp-slimstat'), $download_url, $TempFile->get_error_message())]);
            } else {
                // Delete Old Database
                if (self::database_exists()) {
                    wp_delete_file(self::get_database_file());
                }

                // Check if the file is a MaxMind file
                if ($isMaxmind) {
                    $phar          = new \PharData($TempFile);
                    $database      = $library['file'] . '.' . self::$file_extension;
                    $fileInArchive = trailingslashit($phar->current()->getFileName()) . $database;
                    $phar->extractTo(\wp_slimstat::$upload_dir, $fileInArchive, true);

                    @rename(trailingslashit(\wp_slimstat::$upload_dir) . $fileInArchive, $DBFile);
                    @rmdir(trailingslashit(\wp_slimstat::$upload_dir) . $phar->current()->getFileName());

                    if (!is_file($DBFile)) {
                        // Something went wrong, maybe a folder was created instead of a regular file
                        @rmdir($DBFile);
                        wp_delete_file($TempFile);
                        return array_merge($result, ['notice' => __('There was an error creating the GeoIP database file.', 'wp-slimstat')]);
                    }
                } else {
                    // Open the downloaded file to unzip it.
                    $ZipHandle = gzopen($TempFile, 'rb');

                    // Create th new file to unzip to.
                    $DBfh = fopen($DBFile, 'wb'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

                    // If we failed to open the downloaded file, through an error and remove the temporary file. Otherwise, do the actual unzip.
                    if (!$ZipHandle) {
                        wp_delete_file($TempFile);
                        return array_merge($result, ['notice' => sprintf(__('Error Opening Downloaded GeoIP Database for Reading: %s', 'wp-slimstat'), $TempFile)]);
                    } else {
                        while (($data = gzread($ZipHandle, 4096)) != false) {
                            fwrite($DBfh, $data); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
                        }

                        // Close the files.
                        gzclose($ZipHandle);
                        fclose($DBfh); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                    }
                }

                // Delete the temporary file.
                wp_delete_file($TempFile);

                // Display the success message.
                $result['status'] = true;
                $result['notice'] = __('GeoIP Database Successfully Updated!', 'wp-slimstat');
            }

        } catch (\Exception $exception) {
            $result['notice'] = sprintf(__('Error: %1$s', 'wp-slimstat'), $exception->getMessage());
        }

        return $result;
    }

    public static function downloadUrl($url)
    {
        // Load Require Function
        if (!function_exists('download_url')) {
            include(ABSPATH . 'wp-admin/includes/file.php');
        }

        if (!function_exists('wp_generate_password')) {
            include(ABSPATH . 'wp-includes/pluggable.php');
        }

        if (!$url) {
            return new \WP_Error('http_no_url', __('The provided URL is invalid.', 'wp-slimstat'));
        }

        $url_filename = basename(parse_url($url, PHP_URL_PATH));

        $tmpfname = wp_tempnam($url_filename);
        if (!$tmpfname) {
            return new \WP_Error('http_no_file', __("A temporary file could not be created. Please check your server's file permissions and try again.", 'wp-slimstat'));
        }

        ini_set('max_execution_time', '300');

        $response = wp_safe_remote_get($url, [
            'timeout'    => 300,
            'stream'     => true,
            'filename'   => $tmpfname,
            'user-agent' => 'Slimstat Analytics/' . SLIMSTAT_ANALYTICS_VERSION . '; ' . home_url(),
        ]);

        if (is_wp_error($response)) {
            unlink($tmpfname);
            return $response;
        }

        if (200 != wp_remote_retrieve_response_code($response)) {
            unlink($tmpfname);
            return new \WP_Error('http_404', trim(wp_remote_retrieve_response_message($response)));
        }

        return $tmpfname;
    }

    public static function extractMaxmin()
    {

    }

    /**
     * @throws InvalidDatabaseException
     */
    public static function getCountry($ip = false)
    {
        $reader = self::loader($ip);

        $country = false;

        if ($reader && (!empty($reader['country']['iso_code']) && 'xx' != $reader['country']['iso_code'])) {
            $country = $reader['country']['iso_code'];
        }

        return $country;
    }

    /**
     * @throws InvalidDatabaseException
     */
    public static function getCity($ip = false)
    {
        $reader = self::loader($ip);

        $city = false;

        if ($reader) {
            if (!empty($reader['city']['names']['en'])) {
                $city = $reader['city']['names']['en'];
            }

            if (!empty($reader['subdivisions'][0]['iso_code']) && !empty($city)) {
                $city .= ' (' . $reader['subdivisions'][0]['iso_code'] . ')';
            }
        }

        return $city;
    }

    /**
     * @throws InvalidDatabaseException
     */
    public static function getLocation($ip = false)
    {
        $reader = self::loader($ip);

        $location = false;

        if ($reader && (!empty($reader['location']['latitude']) && !empty($reader['location']['longitude']))) {
            $location = $reader['location']['latitude'] . ',' . $reader['location']['longitude'];
        }

        return $location;
    }
}
