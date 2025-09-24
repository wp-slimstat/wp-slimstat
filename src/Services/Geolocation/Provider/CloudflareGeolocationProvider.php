<?php

namespace SlimStat\Services\Geolocation\Provider;

class CloudflareGeolocationProvider implements GeoServiceProviderInterface
{
    protected $options = [];

    public function __construct($options = [])
    {
        $this->options = $options;
    }

    protected function getPrecision(): string
    {
        return $this->options['precision'] ?? 'country';
    }

    public function locate($ip)
    {
        // Build a lowercased map of server headers for case-insensitive access
        $server = [];
        foreach (($_SERVER ?? []) as $k => $v) {
            $server[strtolower($k)] = $v;
        }

        $get = function ($key, $filter = null) use ($server) {
            $k = strtolower($key);
            if (!isset($server[$k])) {
                return null;
            }

            $val = $server[$k];
            // Basic sanitization similar to WordPress' sanitize_text_field for strings
            if ('float' === $filter) {
                $val = filter_var($val, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
            } else {
                $val = is_string($val) ? wp_kses_data(wp_unslash($val)) : $val;
            }

            return $val;
        };

        $country   = $get('HTTP_CF_IPCOUNTRY');
        $continent = $get('HTTP_CF_IPCONTINENT');
        $region    = $get('HTTP_CF_REGION');
        $city      = $get('HTTP_CF_IPCITY');
        $latitude  = $get('HTTP_CF_IPLATITUDE', 'float');
        $longitude = $get('HTTP_CF_IPLONGITUDE', 'float');
        $postal    = $get('HTTP_CF_POSTAL_CODE');

        // Normalize values
        $country = $country ? strtoupper(trim($country)) : null;
        if ('XX' === $country || '' === $country) {
            $country = null;
        }

        $continent = $continent ? strtoupper(trim($continent)) : null;
        $region    = $region ? trim($region) : null;
        $city      = $city ? trim($city) : null;
        $postal    = $postal ? trim($postal) : null;

        $precision = $this->getPrecision();
        $result = [
            'provider'     => 'cloudflare',
            'ip'           => $ip,
            'country_code' => $country,
            'continent'    => $continent,
        ];

        // Only include city-level data when precision is set to 'city'
        if ('city' === $precision) {
            $result['region']       = $region;
            $result['city']         = $city;
            $result['latitude']     = $latitude;
            $result['longitude']    = $longitude;
            $result['postal_code']  = $postal;
        }

        return $result;
    }
}
