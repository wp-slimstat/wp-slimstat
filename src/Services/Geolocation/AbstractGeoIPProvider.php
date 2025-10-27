<?php

namespace SlimStat\Services\Geolocation;

use SlimStat\Services\Geolocation\Provider\GeoServiceProviderInterface;

abstract class AbstractGeoIPProvider implements GeoServiceProviderInterface
{
    protected $options = [];

    protected $dbPath;

     // Full path to the mmdb file
    protected $dbFile;

    protected $dbUrl;

    protected $dbLicense;

    protected $dbType;

    protected $dbName;

    protected $dbVersion;

    protected $dbLastUpdate;

    protected $dbLastCheck;

    protected $dbStatus;

    public function __construct($options = [])
    {
        $this->options = $options;
        $this->init();
    }

    abstract protected function init();

    public function getOption($key, $default = null)
    {
        return $this->options[$key] ?? $default;
    }

    public function setOption($key, $value)
    {
        $this->options[$key] = $value;
    }

    public function getDbPath()
    {
        return $this->dbPath;
    }

    public function getDbFile()
    {
        return $this->dbFile;
    }

    public function getDbUrl()
    {
        return $this->dbUrl;
    }

    public function getDbLicense()
    {
        return $this->dbLicense;
    }

    public function getDbType()
    {
        return $this->dbType;
    }

    public function getDbName()
    {
        return $this->dbName;
    }

    public function getDbVersion()
    {
        return $this->dbVersion;
    }

    public function getDbLastUpdate()
    {
        return $this->dbLastUpdate;
    }

    public function getDbLastCheck()
    {
        return $this->dbLastCheck;
    }

    public function getDbStatus()
    {
        return $this->dbStatus;
    }

    protected function getPrecision(): string
    {
        $precision = $this->getOption('precision', '');
        if (empty($precision) && class_exists('\wp_slimstat')) {
            $precision = (\wp_slimstat::$settings['geolocation_country'] ?? 'on') === 'on' ? 'country' : 'city';
        }
        return $precision ?: 'country';
    }

    protected function getLicense(): string
    {
		$license = $this->getOption('license', '');
		if ('' === $license || $license === null) {
			$license = $this->getOption('license_key', '');
		}
		// Fallback to global settings if not provided in options
		if (('' === $license || $license === null) && class_exists('\wp_slimstat')) {
			$license = \wp_slimstat::$settings['maxmind_license_key'] ?? '';
		}
		return (string) ($license ?? '');
    }

    protected function getDbDir(): string
    {
        $dbPath = $this->getOption('dbPath', '');
        if (empty($dbPath) && class_exists('\wp_slimstat')) {
            $dbPath = \wp_slimstat::$upload_dir ?? (WP_CONTENT_DIR . '/uploads/wp-slimstat');
        }
        if (empty($dbPath)) {
            $dbPath = WP_CONTENT_DIR . '/uploads/wp-slimstat';
        }
        return rtrim((string) $dbPath, '/\\');
    }

    protected function ensureDirExists(string $dir): void
    {
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
    }
}
