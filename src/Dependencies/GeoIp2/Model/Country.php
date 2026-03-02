<?php

declare(strict_types=1);

namespace SlimStat\Dependencies\GeoIp2\Model;

/**
 * Model class for the data returned by GeoIP2 Country web service and database.
 *
 * See https://dev.maxmind.com/geoip/docs/web-services?lang=en for more details.
 *
 * @property-read \SlimStat\Dependencies\GeoIp2\Record\Continent $continent Continent data for the
 * requested IP address.
 * @property-read \SlimStat\Dependencies\GeoIp2\Record\Country $country Country data for the requested
 * IP address. This object represents the country where MaxMind believes the
 * end user is located.
 * @property-read \SlimStat\Dependencies\GeoIp2\Record\MaxMind $maxmind Data related to your MaxMind
 * account.
 * @property-read \SlimStat\Dependencies\GeoIp2\Record\Country $registeredCountry Registered country
 * data for the requested IP address. This record represents the country
 * where the ISP has registered a given IP block and may differ from the
 * user's country.
 * @property-read \SlimStat\Dependencies\GeoIp2\Record\RepresentedCountry $representedCountry
 * Represented country data for the requested IP address. The represented
 * country is used for things like military bases. It is only present when
 * the represented country differs from the country.
 * @property-read \SlimStat\Dependencies\GeoIp2\Record\Traits $traits Data for the traits of the
 * requested IP address.
 * @property-read array $raw The raw data from the web service.
 */
class Country extends AbstractModel
{
    /**
     * @var \SlimStat\Dependencies\GeoIp2\Record\Continent
     */
    protected $continent;

    /**
     * @var \SlimStat\Dependencies\GeoIp2\Record\Country
     */
    protected $country;

    /**
     * @var array<string>
     */
    protected $locales;

    /**
     * @var \SlimStat\Dependencies\GeoIp2\Record\MaxMind
     */
    protected $maxmind;

    /**
     * @var \SlimStat\Dependencies\GeoIp2\Record\Country
     */
    protected $registeredCountry;

    /**
     * @var \SlimStat\Dependencies\GeoIp2\Record\RepresentedCountry
     */
    protected $representedCountry;

    /**
     * @var \SlimStat\Dependencies\GeoIp2\Record\Traits
     */
    protected $traits;

    /**
     * @ignore
     */
    public function __construct(array $raw, array $locales = ['en'])
    {
        parent::__construct($raw);

        $this->continent = new \SlimStat\Dependencies\GeoIp2\Record\Continent(
            $this->get('continent'),
            $locales
        );
        $this->country = new \SlimStat\Dependencies\GeoIp2\Record\Country(
            $this->get('country'),
            $locales
        );
        $this->maxmind = new \SlimStat\Dependencies\GeoIp2\Record\MaxMind($this->get('maxmind'));
        $this->registeredCountry = new \SlimStat\Dependencies\GeoIp2\Record\Country(
            $this->get('registered_country'),
            $locales
        );
        $this->representedCountry = new \SlimStat\Dependencies\GeoIp2\Record\RepresentedCountry(
            $this->get('represented_country'),
            $locales
        );
        $this->traits = new \SlimStat\Dependencies\GeoIp2\Record\Traits($this->get('traits'));

        $this->locales = $locales;
    }
}
