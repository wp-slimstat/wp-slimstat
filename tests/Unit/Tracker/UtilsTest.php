<?php
declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Tracker;

use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

class UtilsTest extends WpSlimstatTestCase
{
    /** @test */
    public function test_dtrPton_returns_empty_string_for_empty_input(): void
    {
        $this->assertSame('', \SlimStat\Tracker\Utils::dtrPton(''));
    }

    /** @test */
    public function test_dtrPton_returns_empty_string_for_invalid_ip(): void
    {
        $this->assertSame('', \SlimStat\Tracker\Utils::dtrPton('not-an-ip'));
    }

    /** @test */
    public function test_dtrPton_returns_binary_string_for_valid_ipv4(): void
    {
        $result = \SlimStat\Tracker\Utils::dtrPton('192.168.1.1');
        $this->assertNotEmpty($result);
        $this->assertMatchesRegularExpression('/^[01]+$/', $result);
    }

    /** @test */
    public function test_get_value_without_checksum_returns_false_for_non_scalar_input(): void
    {
        $this->assertFalse(\SlimStat\Tracker\Utils::getValueWithoutChecksum(['bad']));
    }

    /** @test */
    public function test_get_value_without_checksum_returns_original_value_for_valid_signature(): void
    {
        \wp_slimstat::$settings['secret'] = 'test-secret';
        $value = '123';
        $signed = $value . '.' . hash_hmac('sha256', $value, 'test-secret');

        $this->assertSame($value, \SlimStat\Tracker\Utils::getValueWithoutChecksum($signed));
    }
}
