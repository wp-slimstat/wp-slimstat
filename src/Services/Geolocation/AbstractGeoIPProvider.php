<?php

namespace SlimStat\Services\Geolocation;

use SlimStat\Services\Geolocation\Provider\GeoServiceProviderInterface;

/**
 * Shared base for GeoIP database providers (MaxMind, DB-IP, …).
 *
 * Holds the resolved database metadata — on-disk path, download URL, licence,
 * type, version and freshness timestamps — behind read-only accessors, and
 * provides the WordPress integration helpers (upload directory, filesystem
 * API) that every concrete provider needs. Subclasses implement {@see init()}
 * to populate this metadata from their own options and plugin settings.
 */
abstract class AbstractGeoIPProvider implements GeoServiceProviderInterface
{
    /** @var array Provider options supplied to the constructor. */
    protected $options = [];

    /** @var string|null Directory that holds the database file. */
    protected $dbPath;

    /** @var string|null Full path to the mmdb file. */
    protected $dbFile;

    /** @var string|null Remote URL the database is downloaded from. */
    protected $dbUrl;

    /** @var string|null Licence key used for the download, when the provider requires one. */
    protected $dbLicense;

    /** @var string|null Database edition/type identifier (e.g. Country vs City). */
    protected $dbType;

    /** @var string|null Human-readable database name. */
    protected $dbName;

    /** @var string|null Installed database version or build identifier. */
    protected $dbVersion;

    /** @var int|string|null Timestamp of the last successful database update. */
    protected $dbLastUpdate;

    /** @var int|string|null Timestamp of the last update check. */
    protected $dbLastCheck;

    /** @var string|null Current database status flag. */
    protected $dbStatus;

    /**
     * @param array $options Provider options; retained and passed to {@see init()}.
     */
    public function __construct($options = [])
    {
        $this->options = $options;
        $this->init();
    }

    /**
     * Resolve this provider's database metadata from its options and settings.
     *
     * Called once from the constructor. Implementations populate the $db*
     * properties so the accessors below return meaningful values.
     *
     * @return void
     */
    abstract protected function init();

    /**
     * Read a single provider option.
     *
     * @param string $key     Option name.
     * @param mixed  $default Value to return when the option is not set.
     * @return mixed Stored option value, or $default.
     */
    public function getOption($key, $default = null)
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * Set a single provider option.
     *
     * @param string $key   Option name.
     * @param mixed  $value Value to store.
     * @return void
     */
    public function setOption($key, $value)
    {
        $this->options[$key] = $value;
    }

    /**
     * @return string|null Directory that holds the database file.
     */
    public function getDbPath()
    {
        return $this->dbPath;
    }

    /**
     * @return string|null Full path to the mmdb file.
     */
    public function getDbFile()
    {
        return $this->dbFile;
    }

    /**
     * @return string|null Remote URL the database is downloaded from.
     */
    public function getDbUrl()
    {
        return $this->dbUrl;
    }

    /**
     * @return string|null Licence key used for the download.
     */
    public function getDbLicense()
    {
        return $this->dbLicense;
    }

    /**
     * @return string|null Database edition/type identifier.
     */
    public function getDbType()
    {
        return $this->dbType;
    }

    /**
     * @return string|null Human-readable database name.
     */
    public function getDbName()
    {
        return $this->dbName;
    }

    /**
     * @return string|null Installed database version or build identifier.
     */
    public function getDbVersion()
    {
        return $this->dbVersion;
    }

    /**
     * @return int|string|null Timestamp of the last successful database update.
     */
    public function getDbLastUpdate()
    {
        return $this->dbLastUpdate;
    }

    /**
     * @return int|string|null Timestamp of the last update check.
     */
    public function getDbLastCheck()
    {
        return $this->dbLastCheck;
    }

    /**
     * @return string|null Current database status flag.
     */
    public function getDbStatus()
    {
        return $this->dbStatus;
    }

    /**
     * Resolve the geolocation precision for this provider.
     *
     * Falls back to the plugin's geolocation_country setting, and finally to
     * 'country' when nothing is configured.
     *
     * @return string Either 'country' or 'city'.
     */
    protected function getPrecision(): string
    {
        $precision = $this->getOption('precision', '');
        if (empty($precision) && class_exists('\wp_slimstat')) {
            $precision = (\wp_slimstat::$settings['geolocation_country'] ?? 'on') === 'on' ? 'country' : 'city';
        }
        return $precision ?: 'country';
    }

    /**
     * Resolve the licence key for database downloads.
     *
     * Checks the 'license' and 'license_key' options in turn, then falls back
     * to the plugin's stored maxmind_license_key setting.
     *
     * @return string Licence key, or an empty string when none is available.
     */
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

    /**
     * Resolve the directory that stores the GeoIP database.
     *
     * Prefers the 'dbPath' option, then the plugin's upload directory, and
     * finally the default wp-slimstat uploads folder. Trailing slashes are
     * stripped.
     *
     * @return string Absolute directory path without a trailing slash.
     */
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

    /**
     * Create the given directory when it does not already exist.
     *
     * @param string $dir Absolute directory path.
     * @return void
     */
    protected function ensureDirExists(string $dir): void
    {
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
    }

    /**
     * Ensure the WordPress filesystem API is loaded.
     *
     * Loads wp-admin/includes/file.php when wp_tempnam() is not yet available,
     * so providers can download databases outside the admin request lifecycle.
     *
     * @return void
     */
    protected function ensureWpFileApiLoaded(): void
    {
        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
    }
}
