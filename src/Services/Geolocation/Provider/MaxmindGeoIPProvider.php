<?php

namespace SlimStat\Services\Geolocation\Provider;

use SlimStat\Dependencies\GeoIp2\Database\Reader;
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

		try {
			$reader = new Reader($this->dbPath);
			$ip = sanitize_text_field($ip);

			// Determine which method to use based on database type
			$precision = $this->getPrecision();
			if ('city' === $precision) {
				$record = $reader->city($ip);
			} else {
				$record = $reader->country($ip);
			}

			return [
				'country_code' => $record->country->isoCode ?? $record->registeredCountry->isoCode ?? null,
				'city'         => $record->city->name ?? null,
				'subdivision'  => $record->mostSpecificSubdivision->isoCode ?? null,
				'continent'    => $record->continent->code ?? null,
				'latitude'     => $record->location->latitude ?? null,
				'longitude'    => $record->location->longitude ?? null,
				'postal_code'  => $record->postal->code ?? null,
				'provider'     => 'maxmind',
			];
		} catch (\Exception $e) {
			return null;
		}
	}

	public function updateDatabase()
	{
		try {
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

		// Download and extract the MaxMind database from tar.gz using WP APIs
		if (!function_exists('download_url')) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if (!function_exists('WP_Filesystem')) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;

		$tmp = wp_tempnam('mmdb');
		if (!$tmp) {
			\wp_slimstat::update_option('slimstat_geoip_error', [
				'time'  => time(),
				'error' => __('Failed to create temporary file for MaxMind database download.', 'wp-slimstat'),
			]);
			return false;
		}

		// Always target a .tgz path so PharData can detect archive type
		$tgzPath = $tmp . '.tgz';

		// Attempt 1: Stream download via WP HTTP API (mitigates cURL 56 by avoiding large in-memory buffers)
		$http_args = [
			'timeout'      => 90,
			'redirection'  => 5,
			'httpversion'  => '1.0',
			'headers'      => [
				'User-Agent' => 'WP-Slimstat MaxMind Updater',
				'Connection' => 'close',
			],
			'sslverify'    => true,
			'stream'       => true,
			'filename'     => $tgzPath,
		];
		$response = wp_remote_get($this->dbUrl, $http_args);
		if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
			// Attempt 2: Fallback to download_url helper
			$downloaded_file = download_url($this->dbUrl, 300);
			if (is_wp_error($downloaded_file)) {
				\wp_slimstat::update_option('slimstat_geoip_error', [
					'time'  => time(),
					'error' => sprintf(__('Network error downloading MaxMind database: %s', 'wp-slimstat'), $downloaded_file->get_error_message()),
				]);
				return false;
			}

			// Stage the downloaded file to $tgzPath
			if (!$wp_filesystem->move($downloaded_file, $tgzPath, true)) {
				$contents = $wp_filesystem->get_contents($downloaded_file);
				if ($contents === false || !$wp_filesystem->put_contents($tgzPath, $contents, FS_CHMOD_FILE)) {
					\wp_slimstat::update_option('slimstat_geoip_error', [
						'time'  => time(),
						'error' => __('Failed to stage downloaded MaxMind archive.', 'wp-slimstat'),
					]);
					$wp_filesystem->delete($downloaded_file);
					return false;
				}
				$wp_filesystem->delete($downloaded_file);
			}
			$this->logDebug('Successfully downloaded MaxMind database (fallback helper).');
		} else {
			$this->logDebug('Successfully downloaded MaxMind database (streamed).');
		}

		// Try to extract mmdb from tar.gz using PharData if available
		if (!class_exists('PharData')) {
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
			$tgz->decompress();
			$tar = new \PharData($tarPath);

			$baseDir = dirname($this->dbPath);
			$this->ensureDirExists($baseDir);
			$extractDir = $baseDir . '/.mmdb_extract_' . wp_generate_password(8, false, false);
			@wp_mkdir_p($extractDir);

			$this->logDebug(sprintf('Extracting archive to (uploads): %s', $extractDir));
			$tar->extractTo($extractDir, null, true);

			$mmdb_found = false;
			$files_in_archive = [];

			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($extractDir, \FilesystemIterator::SKIP_DOTS)
			);

			foreach ($iterator as $file) {
				if (!$file->isFile()) {
					continue;
				}
				$name = $file->getFilename();
				$files_in_archive[] = $name;
				if ('.mmdb' === substr($name, -5)) {
					$source = $file->getPathname();
					$this->logDebug(sprintf('Found extracted .mmdb: %s', $source));
					$this->ensureDirExists(dirname($this->dbPath));
					if ($wp_filesystem->move($source, $this->dbPath, true) || @copy($source, $this->dbPath)) {
						$this->logDebug(sprintf('Placed database at: %s', $this->dbPath));
						$mmdb_found = true;
						break;
					}
				}
			}

			if (is_dir($extractDir)) {
				$wp_filesystem->delete($extractDir, true);
			}

			$wp_filesystem->delete($tarPath);
			$wp_filesystem->delete($tgzPath);

			if (!$mmdb_found) {
				$file_list = implode(', ', array_unique($files_in_archive));
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
		} catch (\Exception $e) {
			// Catch any fatal errors in the entire updateDatabase method
			\wp_slimstat::update_option('slimstat_geoip_error', [
				'time'  => time(),
				'error' => sprintf(__('Fatal error updating MaxMind database: %s', 'wp-slimstat'), $e->getMessage()),
			]);
			return false;
		}
	}

	protected function isValidLicenseKey($license)
	{
		if (empty($license)) {
			return false;
		}
		return (bool) preg_match('/^[A-Za-z0-9_]{16,40}$/', (string) $license);
	}

	protected function checkConnectivity()
	{
		try {
			$host = 'download.maxmind.com';
			$ip = gethostbyname($host);
			if ($ip === $host) {
				\wp_slimstat::update_option('slimstat_geoip_error', [
					'time'  => time(),
					'error' => sprintf(__('DNS resolution failed for %s. Please check your internet connection and DNS settings.', 'wp-slimstat'), $host),
				]);
				return false;
			}
			$test_response = wp_remote_get('https://download.maxmind.com/', ['timeout' => 30]);
			if (is_wp_error($test_response)) {
				\wp_slimstat::update_option('slimstat_geoip_error', [
					'time'  => time(),
					'error' => sprintf(__('Cannot connect to MaxMind servers. Network error: %s', 'wp-slimstat'), $test_response->get_error_message()),
				]);
				return false;
			}
			return true;
		} catch (\Exception $e) {
			\wp_slimstat::update_option('slimstat_geoip_error', [
				'time'  => time(),
				'error' => sprintf(__('Network connectivity check failed: %s', 'wp-slimstat'), $e->getMessage()),
			]);
			return false;
		}
	}

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

	protected function logDebug($message)
	{
		if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
			error_log(sprintf('[WP-Slimstat MaxMind] %s', $message));
		}
	}
}
