<?php
declare(strict_types=1);
namespace WpSlimstat\Tests\Unit\Tracker;

use Brain\Monkey\Functions;
use SlimStat\Services\GeoService;
use SlimStat\Tracker\Tracker;
use SlimStat\Tracker\Utils;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

final class LegacyProxyTest extends WpSlimstatTestCase
{
    public function test_checksum_proxy_preserves_both_cookie_formats_and_rejects_bad_input(): void
    {
        $settings = \wp_slimstat::$settings;
        Functions\expect('_deprecated_function')->times(4);
        try {
            \wp_slimstat::$settings['secret'] = 'compatibility-test';
            self::assertSame('42', Tracker::_get_value_without_checksum(Utils::getValueWithChecksum('42')));
            self::assertSame('42', Tracker::_get_value_without_checksum('42.' . md5('42compatibility-test')));
            self::assertFalse(Tracker::_get_value_without_checksum('42.invalid'));
            self::assertFalse(Tracker::_get_value_without_checksum(['invalid']));
        } finally {
            \wp_slimstat::$settings = $settings;
        }
    }

    public function test_legacy_geo_setter_is_fluent_and_instance_local(): void
    {
        $settings = \wp_slimstat::$settings;
        Functions\expect('_deprecated_function')->times(6);
        try {
            \wp_slimstat::$settings['maxmind_license_key'] = '';
            $service = new GeoService();
            $before = \wp_slimstat::$settings;
            foreach (['on', 'no', false] as $value) {
                self::assertSame($service, $service->setEnableMaxmind($value));
                self::assertSame($value, $service->getEnableMaxmind());
                self::assertSame($before, \wp_slimstat::$settings);
            }
        } finally {
            \wp_slimstat::$settings = $settings;
        }
    }
}
