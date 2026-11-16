<?php
declare(strict_types=1);
namespace WpSlimstat\Tests\Unit\Tracker;

use SlimStat\Tracker\Utils;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

final class ResourceParametersTest extends WpSlimstatTestCase
{
    /** @dataProvider resources */
    public function test_only_explicit_query_keys_are_removed(string $configured, string $input, string $expected): void
    {
        $settings = \wp_slimstat::$settings;
        try {
            \wp_slimstat::$settings['ignore_resource_parameters'] = $configured;
            self::assertSame($expected, Utils::normalizeResourceParameters($input));
        } finally {
            \wp_slimstat::$settings = $settings;
        }
    }

    public static function resources(): array
    {
        return [
            ['', '/?fbclid=a&p=7', '/?fbclid=a&p=7'],
            ['fbclid', '/?fbclid=a&p=7', '/?p=7'],
            ['fbclid', '/?fbclid=b&p=7', '/?p=7'],
            ['fbclid,gclid', '/?p=7&fbclid=a&gclid=b#part', '/?p=7#part'],
            ['utm_source', '/?utm_source=email&product=a%26b&size=0&size=1', '/?product=a%26b&size=0&size=1'],
            ['fbclid', '/?%66bclid=abc&Fbclid=keep', '/?Fbclid=keep'],
            ['fbclid', '/?fbclid=a&fbclid=b', '/'],
            ['fbclid', '/page#section?fbclid=a', '/page#section?fbclid=a'],
            ['fbclid', '/?q=fbclid%3Dx#fbclid=keep', '/?q=fbclid%3Dx#fbclid=keep'],
        ];
    }
}
