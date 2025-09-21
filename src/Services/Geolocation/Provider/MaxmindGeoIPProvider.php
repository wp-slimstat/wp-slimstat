<?php

namespace SlimStat\Services\Geolocation\Provider;

use MaxMind\Db\Reader;
use SlimStat\Utils\MaxMindReader;
use SlimStat\Services\Geolocation\AbstractGeoIPProvider;

class MaxmindGeoIPProvider extends AbstractGeoIPProvider
{
    protected function init()
    {
        $this->dbType = 'maxmind';
        $precision    = $this->getPrecision();
        $this->dbName = 'city' === $precision ? 'GeoLite2-City.mmdb' : 'GeoLite2-Country.mmdb';
        $dir          = $this->getDbDir();
        $this->ensureDirExists($dir);
        $this->dbPath = $dir . '/' . $this->dbName;
        $license      = $this->getLicense();
        $edition      = 'city' === $precision ? 'GeoLite2-City' : 'GeoLite2-Country';

        // Validate license key format
        if (!$this->isValidLicenseKey($license)) {
            \wp_slimstat::update_option('slimstat_geoip_error', [
                'time'  => time(),
                'error' => __('Invalid MaxMind license key format. License key should be 16-40 characters containing only letters, numbers, and underscores.', 'wp-slimstat'),
            ]);
        }

        // Direct download endpoint requires a license key
        $this->dbUrl = sprintf('https://download.maxmind.com/app/geoip_download?edition_id=%s&license_key=%s&suffix=tar.gz', $edition, rawurlencode($license));
    }

    public function locate($ip)
    {
        if (!file_exists($this->dbPath)) {
            return null;
        }

        // Try official MaxMind Reader first, then fallback to built-in reader
        if (class_exists('MaxMind\Db\Reader')) {
            try {
                $reader = new Reader($this->dbPath);
                $record = $reader->get($ip);
                $reader->close();
                return [
                    'country_code' => $record['country']['iso_code'] ?? null,
                    'ip'           => $ip,
                    'provider'     => 'maxmind',
                ];
            } catch (\Exception $e) {
                // Fall through to built-in reader
            }
        }

        // Use built-in MaxMind reader as fallback
        try {
            $reader = new MaxMindReader($this->dbPath);
            $record = $reader->get($ip);
            return [
                'country_code' => $record['country']['iso_code'] ?? null,
                'ip'           => $ip,
                'provider'     => 'maxmind',
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    public function updateDatabase()
    {
        // Validate license key before attempting download
        $license = $this->getLicense();
        if (!$this->isValidLicenseKey($license)) {
            \wp_slimstat::update_option('slimstat_geoip_error', [
                'time'  => time(),
                'error' => __('Invalid MaxMind license key format. License key should be 16-40 characters containing only letters, numbers, and underscores.', 'wp-slimstat'),
            ]);
            return false;
        }

        // Check network connectivity
        if (!$this->checkConnectivity()) {
            return false;
        }

        // Download and extract the MaxMind database from tar.gz
        $tmp = wp_tempnam('mmdb');
        if (!$tmp) {
            \wp_slimstat::update_option('slimstat_geoip_error', [
                'time'  => time(),
                'error' => __('Failed to create temporary file for MaxMind database download.', 'wp-slimstat'),
            ]);
            return false;
        }

        $response = wp_remote_get($this->dbUrl, [ 'timeout' => 300, 'decompress' => false, 'headers' => [ 'Accept-Encoding' => 'identity', 'User-Agent' => 'wp-slimstat (geolocation maxmind updater)' ] ]);
        if (is_wp_error($response)) {
            \wp_slimstat::update_option('slimstat_geoip_error', [
                'time'  => time(),
                'error' => sprintf(__('Network error downloading MaxMind database: %s', 'wp-slimstat'), $response->get_error_message()),
            ]);
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if (200 !== $response_code) {
            $response_body = wp_remote_retrieve_body($response);
            $error_msg = $this->getDetailedHttpError($response_code, $response_body);
            \wp_slimstat::update_option('slimstat_geoip_error', [
                'time'  => time(),
                'error' => $error_msg,
            ]);
            return false;
        }

        // Ensure tmp has .tgz so PharData can detect archive type
        $tgzPath = $tmp . '.tgz';
        file_put_contents($tgzPath, wp_remote_retrieve_body($response));
        // Log successful download for debugging
        $this->logDebug(sprintf('Successfully downloaded MaxMind database: %d bytes', strlen(wp_remote_retrieve_body($response))));

        // Try to extract mmdb from tar.gz using PharData if available
        if (!class_exists('PharData')) {
            // Store a helpful error for admins and bail gracefully
            \wp_slimstat::update_option('slimstat_geoip_error', [
                'time'  => time(),
                'error' => __('MaxMind update requires the PHP Phar extension (PharData class not found). Please enable Phar extension or upload the .mmdb file manually to wp-content/uploads/wp-slimstat/.', 'wp-slimstat'),
            ]);
            @unlink($tgzPath);
            return false;
        }

        try {
            $this->logDebug('Starting MaxMind database extraction...');
            $tgz     = new \PharData($tgzPath);
            $tarPath = $tmp . '.tar';
            $tgz->decompress(); // creates .tar
            $tar = new \PharData($tarPath);
            $mmdb_found = false;
            $files_in_archive = [];

            foreach (new \RecursiveIteratorIterator($tar) as $file) {
                $name = basename((string)$file);
                $files_in_archive[] = $name;
                $this->logDebug(sprintf('Found file in archive: %s', $name));

                if ('.mmdb' === substr($name, -5)) {
                    $this->logDebug(sprintf('Extracting .mmdb file: %s', $name));
                    $tar->extractTo(dirname($this->dbPath), (string)$file, true);
                    // Move to expected name/path if different
                    $found = dirname($this->dbPath) . '/' . $name;
                    if ($found !== $this->dbPath && file_exists($found)) {
                        if (@rename($found, $this->dbPath)) {
                            $this->logDebug(sprintf('Renamed %s to %s', $found, $this->dbPath));
                        } else {
                            $this->logDebug(sprintf('Failed to rename %s to %s', $found, $this->dbPath));
                        }
                    }
                    $mmdb_found = true;
                    break;
                }
            }

            @unlink($tarPath);
            @unlink($tgzPath);

            if (!$mmdb_found) {
                $file_list = implode(', ', $files_in_archive);
                \wp_slimstat::update_option('slimstat_geoip_error', [
                    'time'  => time(),
                    'error' => sprintf(__('No .mmdb file found in MaxMind database archive. Files found: %s', 'wp-slimstat'), $file_list),
                ]);
                return false;
            }

            $final_exists = file_exists($this->dbPath);
            $this->logDebug(sprintf('Final database file exists: %s (path: %s)', $final_exists ? 'yes' : 'no', $this->dbPath));

            if ($final_exists) {
                $file_size = filesize($this->dbPath);
                $this->logDebug(sprintf('Database file size: %d bytes', $file_size));

                // Clear any previous errors on successful update
                \wp_slimstat::update_option('slimstat_geoip_error', []);
            }

            return $final_exists;
        } catch (\Exception $exception) {
            @unlink($tarPath ?? '');
            @unlink($tgzPath);
            \wp_slimstat::update_option('slimstat_geoip_error', [
                'time'  => time(),
                'error' => sprintf(__('Error extracting MaxMind database: %s', 'wp-slimstat'), $exception->getMessage()),
            ]);
            return false;
        }
    }

    /**
     * Validate MaxMind license key format
     * MaxMind license keys can be various formats, typically 16-40 characters with letters, numbers, and underscores
     */
    protected function isValidLicenseKey($license)
    {
        if (empty($license)) {
            return false;
        }

        // MaxMind license keys: letters, numbers, underscores, typically 16-40 characters
        return preg_match('/^[a-zA-Z0-9_]{16,40}$/', $license);
    }

    /**
     * Check network connectivity to MaxMind servers
     */
    protected function checkConnectivity()
    {
        // Test DNS resolution for MaxMind download server
        $host = 'download.maxmind.com';
        $ip = gethostbyname($host);

        if ($ip === $host) {
            \wp_slimstat::update_option('slimstat_geoip_error', [
                'time'  => time(),
                'error' => sprintf(__('DNS resolution failed for %s. Please check your internet connection and DNS settings.', 'wp-slimstat'), $host),
            ]);
            return false;
        }

        // Test basic HTTP connectivity
        $test_response = wp_remote_get('https://download.maxmind.com/', ['timeout' => 30]);
        if (is_wp_error($test_response)) {
            \wp_slimstat::update_option('slimstat_geoip_error', [
                'time'  => time(),
                'error' => sprintf(__('Cannot connect to MaxMind servers. Network error: %s', 'wp-slimstat'), $test_response->get_error_message()),
            ]);
            return false;
        }

        return true;
    }

    /**
     * Get detailed HTTP error message based on response code and body
     */
    protected function getDetailedHttpError($response_code, $response_body)
    {
        $base_msg = sprintf(__('HTTP %d error downloading MaxMind database', 'wp-slimstat'), $response_code);

        switch ($response_code) {
            case 401:
                return $base_msg . ': ' . __('Unauthorized. Please check your MaxMind license key. The key may be invalid, expired, or not authorized for GeoLite2 downloads.', 'wp-slimstat');
            case 403:
                return $base_msg . ': ' . __('Forbidden. Your license key does not have permission to download this database, or you have exceeded the download limit.', 'wp-slimstat');
            case 404:
                return $base_msg . ': ' . __('Database not found. The requested edition may not exist or may not be available for your account type.', 'wp-slimstat');
            case 429:
                return $base_msg . ': ' . __('Too many requests. You have exceeded the download limit. Please wait before trying again.', 'wp-slimstat');
            case 500:
            case 502:
            case 503:
            case 504:
                return $base_msg . ': ' . __('MaxMind server error. Please try again later.', 'wp-slimstat');
            default:
                $error_msg = $base_msg;
                if (!empty($response_body)) {
                    // Try to extract meaningful error from response body
                    $body_preview = substr($response_body, 0, 200);
                    if (strpos($body_preview, 'license key') !== false || strpos($body_preview, 'authentication') !== false) {
                        $error_msg .= ': ' . __('License key authentication failed. Please verify your MaxMind license key.', 'wp-slimstat');
                    } else {
                        $error_msg .= ': ' . $body_preview;
                    }
                }
                return $error_msg;
        }
    }

    /**
     * Log debug information if WP_DEBUG is enabled
     */
    protected function logDebug($message)
    {
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log(sprintf('[WP-Slimstat MaxMind] %s', $message));
        }
    }
}
