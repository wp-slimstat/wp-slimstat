<?php

namespace SlimStat\Services;

use SlimStat\Dependencies\GeoIp2\Database\Reader;

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
        if (!empty($this->pack)) {
            return $this->pack;
        }
        return ('on' == \wp_slimstat::$settings['geolocation_country']) ? 'country' : 'city';
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
			$provider = \wp_slimstat::$settings['geolocation_provider'] ?? 'maxmind';
			if (in_array($provider, ['maxmind', 'dbip'], true)) {
                // GeolocationService reads settings automatically
                $service = new \SlimStat\Services\Geolocation\GeolocationService($provider, []);
                $ok      = $service->updateDatabase();
                return [
                    'status' => (bool) $ok,
                    'notice' => $ok ? __('GeoIP Database Successfully Updated!', 'wp-slimstat') : __('Failed to update GeoIP Database.', 'wp-slimstat'),
                ];
            }
            return [ 'status' => false, 'error' => __('GeoIP is disabled. Please choose a DB-based provider and save settings.', 'wp-slimstat') ];
        } catch (\Exception $exception) {
            $this->logError($exception->getMessage());
            return [ 'status' => false, 'error' => $exception->getMessage() ];
        }
    }

	/**
	 * @throws \Exception
	 */
	public function checkDatabase()
	{
		try {
			$provider = \wp_slimstat::$settings['geolocation_provider'] ?? 'maxmind';
            // GeolocationService reads settings automatically
            $service = new \SlimStat\Services\Geolocation\GeolocationService($provider, []);
            $dbPath  = $service->getProvider()->getDbPath();
            if (!file_exists($dbPath)) {
                throw new \Exception(__('GeoIP database not found!', 'wp-slimstat'));
            }

            $reader = new Reader($dbPath);
            $ip = $this->getUserIP();

            // Determine which method to use based on database type
            $precision = $this->getPack();
            if ('city' === $precision) {
                $reader->city($ip);
            } else {
                $reader->country($ip);
            }

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
		$provider = \wp_slimstat::$settings['geolocation_provider'] ?? 'maxmind';
        // GeolocationService reads settings automatically
        $service = new \SlimStat\Services\Geolocation\GeolocationService($provider, []);
        $dbPath  = $service->getProvider()->getDbPath();
        if (is_file($dbPath)) {
            @unlink($dbPath);
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
