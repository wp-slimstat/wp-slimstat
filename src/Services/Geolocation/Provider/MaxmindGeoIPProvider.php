<?php

namespace SlimStat\Services\Geolocation\Provider;

use MaxMind\Db\Reader;
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
        // Direct download endpoint requires a license key
        $this->dbUrl = sprintf('https://download.maxmind.com/app/geoip_download?edition_id=%s&license_key=%s&suffix=tar.gz', $edition, rawurlencode($license));
    }

    public function locate($ip)
    {
        if (!file_exists($this->dbPath)) {
            return null;
        }

        // Use MaxMind Reader if available
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
                return null;
            }
        }

        return null;
    }

    public function updateDatabase()
    {
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
                $error_msg = sprintf(__('HTTP %d error downloading MaxMind database', 'wp-slimstat'), $response_code);
                if (!empty($response_body)) {
                    $error_msg .= ': ' . substr($response_body, 0, 200);
                }
                \wp_slimstat::update_option('slimstat_geoip_error', [
                    'time'  => time(),
                    'error' => $error_msg,
                ]);
                return false;
            }

        // Ensure tmp has .tgz so PharData can detect archive type
        $tgzPath = $tmp . '.tgz';
        file_put_contents($tgzPath, wp_remote_retrieve_body($response));
        // Try to extract mmdb from tar.gz using PharData if available
        if (!class_exists('PharData')) {
            // Store a helpful error for admins and bail gracefully
            \wp_slimstat::update_option('slimstat_geoip_error', [
                'time'  => time(),
                'error' => __('MaxMind update requires the PHP Phar extension (PharData class not found). Please enable Phar or upload the .mmdb manually to wp-content/uploads/wp-slimstat/.', 'wp-slimstat'),
            ]);
            @unlink($tgzPath);
            return false;
        }

        try {
            $tgz     = new \PharData($tgzPath);
            $tarPath = $tmp . '.tar';
            $tgz->decompress(); // creates .tar
            $tar = new \PharData($tarPath);
            $mmdb_found = false;
            foreach (new \RecursiveIteratorIterator($tar) as $file) {
                $name = basename((string)$file);
                if ('.mmdb' === substr($name, -5)) {
                    $tar->extractTo(dirname($this->dbPath), (string)$file, true);
                    // Move to expected name/path if different
                    $found = dirname($this->dbPath) . '/' . $name;
                    if ($found !== $this->dbPath && file_exists($found)) {
                        @rename($found, $this->dbPath);
                    }
                    $mmdb_found = true;
                    break;
                }
            }

            @unlink($tarPath);
            @unlink($tgzPath);

            if (!$mmdb_found) {
                \wp_slimstat::update_option('slimstat_geoip_error', [
                    'time'  => time(),
                    'error' => __('No .mmdb file found in MaxMind database archive.', 'wp-slimstat'),
                ]);
                return false;
            }

            return file_exists($this->dbPath);
        } catch (\Exception $exception) {
            @unlink($tgzPath);
            \wp_slimstat::update_option('slimstat_geoip_error', [
                'time'  => time(),
                'error' => sprintf(__('Error extracting MaxMind database: %s', 'wp-slimstat'), $exception->getMessage()),
            ]);
            return false;
        }
    }
}
