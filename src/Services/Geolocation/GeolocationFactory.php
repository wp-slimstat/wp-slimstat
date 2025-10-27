<?php

namespace SlimStat\Services\Geolocation;

use SlimStat\Services\Geolocation\Provider\CloudflareGeolocationProvider;
use SlimStat\Services\Geolocation\Provider\DbIpProvider;
use SlimStat\Services\Geolocation\Provider\MaxmindGeoIPProvider;

class GeolocationFactory
{
    public static function create($provider, $options = [])
    {
        switch ($provider) {
            case 'maxmind':
                return new MaxmindGeoIPProvider($options);
            case 'cloudflare':
                return new CloudflareGeolocationProvider($options);
            case 'dbip':
            default:
                return new DbIpProvider($options);
        }
    }
}
