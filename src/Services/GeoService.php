<?php

namespace SlimStat\Services;

use SlimStat\Dependencies\GeoIp2\Database\Reader;

class GeoService
{
    /** All supported geolocation providers */
    const ALL_PROVIDERS = ['maxmind', 'dbip', 'cloudflare'];

    /** Providers that require a local GeoIP database file */
    const DB_PROVIDERS = ['maxmind', 'dbip'];

    private $update = false;

    private $pack = '';

    private $maxmindLicense = '';
    private $legacyMaxmind;
    private $legacyMaxmindSet = false;

    public function __construct()
    {
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
        return \wp_slimstat::get_geolocation_precision();
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

    /** @deprecated Use the geolocation_provider setting for provider selection. */
    public function setEnableMaxmind($enableMaxmind = false)
    {
        _deprecated_function(__METHOD__, '6.0.0', 'the geolocation_provider setting');
        // The legacy setter was instance-local; never change a site's saved provider here.
        $this->legacyMaxmind = $enableMaxmind;
        $this->legacyMaxmindSet = true;
        return $this;
    }

    /** @deprecated Use wp_slimstat::resolve_geolocation_provider(). */
    public function getEnableMaxmind()
    {
        _deprecated_function(__METHOD__, '6.0.0', 'wp_slimstat::resolve_geolocation_provider()');
        if ($this->legacyMaxmindSet) {
            return $this->legacyMaxmind;
        }
        $provider = \wp_slimstat::resolve_geolocation_provider();
        return 'maxmind' === $provider ? 'on' : ('dbip' === $provider ? 'no' : 'disable');
    }

    public function isGeoIPEnabled()
    {
        $provider = \wp_slimstat::resolve_geolocation_provider();
        return false !== $provider;
    }

    public function isMaxMindEnabled()
    {
        return 'maxmind' === \wp_slimstat::resolve_geolocation_provider();
    }

    public function isJsDelivrEnabled()
    {
        return 'dbip' === \wp_slimstat::resolve_geolocation_provider();
    }

    public function getUserIP()
    {
        [$peer, $forwarded] = \SlimStat\Tracker\Utils::getRemoteIp();
        return \SlimStat\Tracker\Utils::getCfClientIp() ?: ($forwarded ?: ($peer ?: false));
    }

	public function download()
	{
		try {
			$provider = \wp_slimstat::resolve_geolocation_provider();
			if (false === $provider) {
				return ['status' => false, 'error' => __('Geolocation is disabled.', 'wp-slimstat')];
			}
			if (in_array($provider, self::DB_PROVIDERS, true)) {
                // GeolocationService reads settings automatically
                $service = new \SlimStat\Services\Geolocation\GeolocationService($provider, []);
                $ok      = $service->updateDatabase();
                $reason  = !$ok && method_exists($service->getProvider(), 'getLastError') ? $service->getProvider()->getLastError() : '';
                return [
                    'status' => (bool) $ok,
                    'notice' => $ok ? __('Geolocation database updated.', 'wp-slimstat') : ($reason ?: __('The geolocation database could not be updated.', 'wp-slimstat')),
                ];
            }
            return [ 'status' => true, 'notice' => __('This provider does not use a local database.', 'wp-slimstat') ];
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
			$provider = \wp_slimstat::resolve_geolocation_provider();
			if (false === $provider) {
				return ['status' => false, 'notice' => __('Geolocation is disabled.', 'wp-slimstat')];
			}
			if (!in_array($provider, self::DB_PROVIDERS, true)) {
				return ['status' => true, 'notice' => __('This provider does not use a local database.', 'wp-slimstat')];
			}
            // GeolocationService reads settings automatically
            $service = new \SlimStat\Services\Geolocation\GeolocationService($provider, []);
            $dbPath  = $service->getProvider()->getDbPath();
            if (!file_exists($dbPath)) {
                return ['status' => false, 'notice' => __('The geolocation database was not found. Click "Update Database" to download it.', 'wp-slimstat')];
            }
            if (!is_readable($dbPath)) {
                return ['status' => false, 'notice' => __('The geolocation database cannot be read. Ask your host to check file permissions, then try again.', 'wp-slimstat')];
            }

            $reader = new Reader($dbPath);
            try {
                // Local/private IPs and uncovered cities say nothing about database health.
                $type = $reader->metadata()->databaseType;
                $compatible = false !== stripos($type, 'City') || ('country' === $this->getPack() && false !== stripos($type, 'Country'));
            } finally {
                $reader->close();
            }
            if (!$compatible) {
                return ['status' => false, 'notice' => __('The database does not support the selected precision. Click "Update Database" to download the correct file.', 'wp-slimstat')];
            }

            $response = [
                'status' => true,
                'notice' => __('The geolocation database is readable and supports the selected precision. Private addresses have no location; city coverage varies. New visits use this database.', 'wp-slimstat'),
            ];
        } catch (\Exception $exception) {
            $this->logError($exception->getMessage());

            $response = [
                'status' => false,
                'notice' => __('The geolocation database file is damaged. Click "Update Database" to download a fresh copy.', 'wp-slimstat'),
            ];
        }

        return $response;
    }

    /** Queue a prompt download without displacing the weekly refresh. */
    public static function scheduleDatabaseUpdate(): bool
    {
        $now = time();
        $next = wp_next_scheduled('wp_slimstat_update_geoip_database');
        if (!$next) {
            $interval = \wp_slimstat::get_schedule_interval('weekly');
            wp_schedule_event($now + $interval, 'weekly', 'wp_slimstat_update_geoip_database');
        }
        // WordPress rejects duplicate single events within ten minutes of any event.
        if ($next && $next <= $now + 11 * MINUTE_IN_SECONDS) {
            return true;
        }
        return (bool) wp_schedule_single_event($now + MINUTE_IN_SECONDS, 'wp_slimstat_update_geoip_database');
    }

    /** Repair the initial download on existing installs, from an admin request only. */
    public static function scheduleMissingDatabaseUpdate(): void
    {
        $provider = \wp_slimstat::resolve_geolocation_provider();
        if (!in_array($provider, self::DB_PROVIDERS, true)) {
            return;
        }
        try {
            $service = new \SlimStat\Services\Geolocation\GeolocationService($provider, []);
            if (!is_readable($service->getProvider()->getDbPath())) {
                self::scheduleDatabaseUpdate();
            }
        } catch (\Throwable $e) {
            \wp_slimstat::log('Geolocation database scheduling failed: ' . $e->getMessage(), 'error');
        }
    }

    public function clearScheduledEvent()
    {
        wp_clear_scheduled_hook('wp_slimstat_update_geoip_database');
    }

	public function deleteDatabaseFile()
	{
		$provider = \wp_slimstat::resolve_geolocation_provider();
		if (false === $provider) {
			return;
		}
		if (!in_array($provider, self::DB_PROVIDERS, true)) {
			return;
		}
        // GeolocationService reads settings automatically
        $service = new \SlimStat\Services\Geolocation\GeolocationService($provider, []);
        $dbPath  = $service->getProvider()->getDbPath();
        if (is_file($dbPath)) {
            wp_delete_file($dbPath);
        }
    }

    public function updateLastUpdateTime($lastUpdate = false)
    {
        update_option('slimstat_last_geoip_dl', $lastUpdate);
    }

    public function logError($error = '')
    {
        \SlimStat\Tracker\Utils::logGeoIpError((string) $error);
    }
}
