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
			'https://cdn.jsdelivr.net/npm/dbip-city-lite/dbip-city-lite.mmdb.gz',
		];

		$this->ensureDirExists(dirname($this->dbPath));

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
				@unlink($tmp);
				continue;
			}

			// Validate non-empty file
			$size = @filesize($tmp);
			if (!$size || $size < 1024 * 1024) { // < 1MB likely an error page
				@unlink($tmp);
				continue;
			}

			// Detect gzip magic bytes
			$fh    = @fopen($tmp, 'rb');
			$magic = $fh ? @fread($fh, 2) : '';
			if ($fh) {
				@fclose($fh);
			}

			$isGz = ("\x1f\x8b" === $magic);

			$destTmp = $this->dbPath . '.tmp';
			$ok      = false;

			if ($isGz && function_exists('gzopen')) {
				$gz = @gzopen($tmp, 'rb');
				if ($gz) {
					$out = @fopen($destTmp, 'wb');
					if ($out) {
						// Stream copy to avoid loading the entire file in memory
						while (!gzeof($gz)) {
							$chunk = gzread($gz, 8192);
							if (false === $chunk) {
								break;
							}

							fwrite($out, $chunk);
						}

						fclose($out);
						$ok = true;
					}

					gzclose($gz);
				}
			} else {
				// Either not gz or zlib is missing; try direct copy as already-decompressed mmdb
				$ok = @copy($tmp, $destTmp);
			}

			@unlink($tmp);

			if (!$ok) {
				@unlink($destTmp);
				continue;
			}

			// Basic sanity: resulting file should be > 5MB
			$outSize = @filesize($destTmp);
			if (!$outSize || $outSize < 5 * 1024 * 1024) {
				@unlink($destTmp);
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

			if ($valid) {
				// Atomically move into place
				@rename($destTmp, $this->dbPath);
				return true;
			}

			@unlink($destTmp);
		}

		return false;
	}
}
