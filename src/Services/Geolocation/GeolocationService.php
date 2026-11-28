<?php

namespace SlimStat\Services\Geolocation;

use SlimStat\Tracker\Utils;

class GeolocationService
{
	protected $provider;
	protected $options = [];

	public function __construct($provider = 'dbip', $options = [])
	{
		$this->options = $options;
		$this->provider = GeolocationFactory::create($provider, $options);
	}

	public function locate($ip)
	{
		return $this->provider->locate($ip);
	}

	public function updateDatabase()
	{
		if (method_exists($this->provider, 'updateDatabase')) {
			$updated = $this->provider->updateDatabase();
			if (true === $updated) {
				Utils::clearDiagnostic('slimstat_geoip_error');
			}
			return $updated;
		}

		return false;
	}

	public function getProvider()
	{
		return $this->provider;
	}
}
