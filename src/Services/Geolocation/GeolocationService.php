<?php

namespace SlimStat\Services\Geolocation;

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
			return $this->provider->updateDatabase();
		}

		return false;
	}

	public function getProvider()
	{
		return $this->provider;
	}
}
