<?php

namespace SlimStat\Services\Geolocation\Provider;

interface GeoServiceProviderInterface
{
    public function locate($ip);
}
