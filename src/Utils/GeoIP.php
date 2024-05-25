<?php

namespace SlimStat\Utils;

class GeoIP
{
    /**
     * List Geo ip Library
     *
     * @var array
     */
    public static $library = array(
        'country' => array(
            'source'     => 'https://cdn.jsdelivr.net/npm/geolite2-country/GeoLite2-Country.mmdb.gz',
            'userSource' => 'https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-Country&license_key=&suffix=tar.gz',
            'file'       => 'GeoLite2-Country',
            'opt'        => 'geoip',
            'cache'      => 31536000 //1 Year
        ),
        'city'    => array(
            'source'     => 'https://cdn.jsdelivr.net/npm/geolite2-city/GeoLite2-City.mmdb.gz',
            'userSource' => 'https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-City&license_key=&suffix=tar.gz',
            'file'       => 'GeoLite2-City',
            'opt'        => 'geoip_city',
            'cache'      => 6998000 //3 Month
        ),
    );

    /**
     * @throws InvalidDatabaseException
     */
    public static function insightFromFileDB($ip, $scope = 'city')
    {
        $url      = self::$library[$scope]['source'];
        $filePath = self::downloadDatabaseFile($url);
        if (file_exists($filePath)) {
            try {
                $reader = new MaxMindReader($filePath);
                return $reader->get(sanitize_text_field($ip));
            } catch (\Exception $e) {
                return false;
            }
        } else {
            return false;
        }
    }

    private static function downloadDatabaseFile($file_url)
    {
        // Directory where the file will be saved
        $upload_dir = wp_upload_dir();
        $target_dir = $upload_dir['basedir'] . '/wp-slimstat/';

        // Create directory if it doesn't exist
        if (!file_exists($target_dir)) {
            wp_mkdir_p($target_dir);
        }

        // File name
        $file_name = basename($file_url, '.gz');

        $new_file_path = $target_dir . $file_name;

        if (file_exists($new_file_path)) {
            return $new_file_path;
        }

        // Download .gz file
        $temp_file = download_url($file_url);

        if (is_wp_error($temp_file)) {
            // Handle error
            @unlink($temp_file);
            return false;
        }

        // Unzip .gz file
        $gzip_file = gzopen($temp_file, 'rb');

        if ($gzip_file) {
            $new_file = fopen($new_file_path, 'wb');

            while (!gzeof($gzip_file)) {
                fwrite($new_file, gzread($gzip_file, 4096));
            }

            fclose($new_file);
            gzclose($gzip_file);
        }

        // Delete the temporary .gz file
        @unlink($temp_file);

        return $new_file_path;
    }

    /**
     * @throws InvalidDatabaseException
     */
    public static function getCountry($ip = false)
    {
        $reader = self::insightFromFileDB($ip);

        $country = false;

        if ($reader) {
            if (!empty($reader['country']['iso_code']) && $reader['country']['iso_code'] != 'xx') {
                $country = $reader['country']['iso_code'];
            }
        }

        return $country;
    }

    /**
     * @throws InvalidDatabaseException
     */
    public static function getCity($ip = false)
    {
        $reader = self::insightFromFileDB($ip);

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
        $reader = self::insightFromFileDB($ip);

        $location = false;

        if ($reader) {
            if (!empty($reader['location']['latitude']) && !empty($reader['location']['longitude'])) {
                $location = $reader['location']['latitude'] . ',' . $reader['location']['longitude'];
            }
        }

        return $location;
    }
}