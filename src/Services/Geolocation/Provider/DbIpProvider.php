<?php

namespace SlimStat\Services\Geolocation\Provider;

use SlimStat\Dependencies\GeoIp2\Database\Reader;
use SlimStat\Services\Geolocation\AbstractGeoIPProvider;

class DbIpProvider extends AbstractGeoIPProvider
{
	protected function init()
	{
		$this->dbType = 'dbip';
		$this->dbName = 'dbip-city-lite.mmdb';
		$dir          = $this->getDbDir();
		$this->ensureDirExists($dir);
		$this->dbPath = $dir . '/' . $this->dbName;
		// Primary: official npm package via jsDelivr; fallbacks handled in updateDatabase()
		// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- downloads a GeoIP data file for local lookups, never executable code or browser assets.
		$this->dbUrl = 'https://cdn.jsdelivr.net/npm/dbip-city-lite/dbip-city-lite.mmdb.gz';
	}

	public function locate($ip)
	{
		if (!file_exists($this->dbPath)) {
			return null;
		}

		try {
			$reader = new Reader($this->dbPath);
			$ip = sanitize_text_field($ip);
			$record = $reader->city($ip);

		$precision = $this->getPrecision();
		$result = [
			'country_code' => $record->country->isoCode ?? null,
			'ip'           => $ip,
			'provider'     => 'dbip',
		];

		// Only include city-level data when precision is set to 'city'
		if ('city' === $precision) {
			$result['city']         = $record->city->name ?? null;
			$result['subdivision']  = $record->mostSpecificSubdivision->isoCode ?? null;
			$result['latitude']     = $record->location->latitude ?? null;
			$result['longitude']    = $record->location->longitude ?? null;
		}

		return $result;
		} catch (\Exception $exception) {
			return null;
		}
	}

	public function updateDatabase()
	{
		// Stream download the DB-IP database (.mmdb.gz), auto-detect gzip, and validate the resulting mmdb
		$urls = [
			// Primary npm package via jsDelivr
			// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- downloads a GeoIP data file for local lookups, never executable code or browser assets.
			'https://cdn.jsdelivr.net/npm/dbip-city-lite/dbip-city-lite.mmdb.gz',
		];

		$this->ensureDirExists(dirname($this->dbPath));

		$this->ensureWpFileApiLoaded();

		foreach ($urls as $url) {
			// Prepare a temp file for streaming
			$tmp = wp_tempnam($url);
			if (!$tmp) {
				continue;
			}

			$args = [
				'timeout'  => 300,
				'stream'   => true,
				'filename' => $tmp,
				'headers'  => [
					// Avoid server transfer-encoding gzip so we can handle the file gzip reliably
					'Accept-Encoding' => 'identity',
					'User-Agent'      => 'wp-slimstat (geolocation dbip updater)',
				],
				'decompress' => false,
			];

			$response = wp_remote_get($url, $args);
			$code     = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
			if (is_wp_error($response) || 200 !== $code) {
				wp_delete_file($tmp);
				continue;
			}

			// Validate non-empty file
			$size = @filesize($tmp);
			if (!$size || $size < 1024 * 1024) { // < 1MB likely an error page
				wp_delete_file($tmp);
				continue;
			}

			// Detect gzip magic bytes
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- bounded local binary stream and atomic replacement; WP_Filesystem has no streaming API.
			$fh    = @fopen($tmp, 'rb');
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- bounded local binary stream and atomic replacement; WP_Filesystem has no streaming API.
			$magic = $fh ? @fread($fh, 2) : '';
			if ($fh) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- bounded local binary stream and atomic replacement; WP_Filesystem has no streaming API.
				@fclose($fh);
			}

			$isGz = ("\x1f\x8b" === $magic);

			$destTmp = $this->dbPath . '.tmp';
			$ok      = false;

			if ($isGz && function_exists('gzopen')) {
				$gz = @gzopen($tmp, 'rb');
				if ($gz) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- bounded local binary stream and atomic replacement; WP_Filesystem has no streaming API.
					$out = @fopen($destTmp, 'wb');
					if ($out) {
						// Stream copy to avoid loading the entire file in memory.
						$ok = true;
						while (!gzeof($gz)) {
							$chunk = gzread($gz, 8192);
							if (false === $chunk || ('' === $chunk && !gzeof($gz))) {
								$ok = false;
								break;
							}

							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- bounded local binary stream and atomic replacement; WP_Filesystem has no streaming API.
							if (strlen($chunk) !== fwrite($out, $chunk)) {
								$ok = false;
								break;
							}
						}

						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- bounded local binary stream and atomic replacement; WP_Filesystem has no streaming API.
						$ok = fclose($out) && $ok;
					}

					gzclose($gz);
				}
			} else {
				// Either not gz or zlib is missing; try direct copy as already-decompressed mmdb
				$ok = @copy($tmp, $destTmp);
			}

			wp_delete_file($tmp);

			if (!$ok) {
				wp_delete_file($destTmp);
				continue;
			}

			// Basic sanity: resulting file should be > 5MB
			$outSize = @filesize($destTmp);
			if (!$outSize || $outSize < 5 * 1024 * 1024) {
				wp_delete_file($destTmp);
				continue;
			}

			// Validate by opening with Reader and doing a trivial lookup
			$valid = false;
			try {
				$reader = new Reader($destTmp);
				// Try a common public IP; ignore result content, we just need to ensure it doesn't throw
				$reader->city('8.8.8.8');
				$valid = true;
			} catch (\Exception $e) {
				$valid = false;
			}

			// A refused replacement must leave the previous database intact and report failure.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- bounded local binary stream and atomic replacement; WP_Filesystem has no streaming API.
			if ($valid && @rename($destTmp, $this->dbPath)) {
				return true;
			}

			wp_delete_file($destTmp);
		}

		return false;
	}
}
