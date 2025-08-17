<?php

namespace SlimStat\Services;

use SlimStat\Utils\MaxMindReader;

class GeoService
{
    private $update = false;

    private $pack = '';

    private $enableMaxmind = '';

    private $maxmindLicense = '';

    public function __construct()
    {
        $this->enableMaxmind  = \wp_slimstat::$settings['enable_maxmind'];
        $this->maxmindLicense = \wp_slimstat::$settings['maxmind_license_key'];
    }

    public function setUpdate($update = false)
    {
        $this->update = $update;
        return $this;
    }

    public function setPack($pack = '')
    {
        $this->pack = $pack;
        return $this;
    }

    public function getPack()
    {
        return empty($this->pack) ? GeoIP::get_pack() : $this->pack;
    }

    public function setEnableMaxmind($enableMaxmind = false)
    {
        $this->enableMaxmind = $enableMaxmind;
        return $this;
    }

    public function getEnableMaxmind()
    {
        return $this->enableMaxmind;
    }

    public function setMaxmindLicense($maxmindLicense = '')
    {
        $this->maxmindLicense = $maxmindLicense;
        return $this;
    }

    public function getMaxMindLicenseKey()
    {
        return $this->maxmindLicense;
    }

    public function isGeoIPEnabled()
    {
        return 'disable' != $this->enableMaxmind;
    }

    public function isMaxMindEnabled()
    {
        return 'on' == $this->enableMaxmind;
    }

    public function isJsDelivrEnabled()
    {
        return 'no' == $this->enableMaxmind;
    }

    public function getUserIP()
    {
        if (!empty($_SERVER['REMOTE_ADDR']) && false !== filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP)) {
            return sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }

        $originating_ip_headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR', 'HTTP_CLIENT_IP', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_X_REAL_IP', 'HTTP_INCAP_CLIENT_IP'];
        foreach ($originating_ip_headers as $a_header) {
            if (!empty($_SERVER[$a_header])) {
                foreach (explode(',', $_SERVER[$a_header]) as $ip) {
                    if (false !== filter_var($ip, FILTER_VALIDATE_IP)) {
                        return $ip;
                    }
                }
            }
        }

        return false;
    }

    public function download()
    {
        try {
            if ($this->isGeoIPEnabled()) {

                $args = [
                    'update' => $this->update,
                ];

                if ($this->isMaxMindEnabled() && !empty($this->getMaxMindLicenseKey())) {
                    $args['enable_maxmind']      = 'on';
                    $args['maxmind_license_key'] = $this->getMaxMindLicenseKey();
                }

                $response = GeoIP::download($this->getPack(), $args);
            } else {
                $response = [
                    'status' => false,
                    'error'  => __('GeoIP is disabled. Please first choose GeoIP Database Source and save settings!', 'wp-slimstat'),
                ];
            }
        } catch (\Exception $exception) {
            $this->logError($exception->getMessage());

            $response = [
                'status' => false,
                'error'  => $exception->getMessage(),
            ];
        }

        return $response;
    }

    /**
     * @throws \Exception
     */
    public function checkDatabase()
    {
        try {
            if (!GeoIP::database_exists()) {
                throw new \Exception(__('GeoIP database not found!', 'wp-slimstat'));
            }

            $reader = new MaxMindReader(GeoIP::get_database_file());
            $reader->get($this->getUserIP());

            $response = [
                'status' => true,
                'notice' => __('GeoIP database is working fine!', 'wp-slimstat'),
            ];
        } catch (\Exception $exception) {
            $this->logError($exception->getMessage());

            $response = [
                'status' => false,
                'notice' => __('GeoIP database file is corrupt. Please click on the "Update Database" button to download a fresh copy.', 'wp-slimstat'),
            ];
        }

        return $response;
    }

    public function clearScheduledEvent()
    {
        wp_clear_scheduled_hook('wp_slimstat_update_geoip_database');
    }

    public function deleteDatabaseFile()
    {
        if (GeoIP::database_exists()) {
            $databaseFilePath = GeoIP::get_database_file();
            @unlink($databaseFilePath);
        }
    }

    public function updateLastUpdateTime($lastUpdate = false)
    {
        update_option('slimstat_last_geoip_dl', $lastUpdate);
    }

    public function logError($error = '')
    {
        update_option('slimstat_geoip_error', [
            'time'  => time(),
            'error' => $error,
        ]);
    }
}
