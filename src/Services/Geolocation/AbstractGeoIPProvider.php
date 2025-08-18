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
        return $this->getOption('precision', 'country');
    }

    protected function getLicense(): string
    {
        return (string) ($this->getOption('license', '') ?? '');
    }

    protected function getDbDir(): string
    {
        return rtrim((string) ($this->getOption('dbPath', WP_CONTENT_DIR . '/uploads/wp-slimstat') ?? (WP_CONTENT_DIR . '/uploads/wp-slimstat')), '/\\');
    }

    protected function ensureDirExists(string $dir): void
    {
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
    }
}
