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

    // ── The tracking cookie's two checksum schemes must key on the same secret (X2/W3) ──

    /**
     * An empty secret must not make either scheme publicly computable.
     * The reasoning lives once, on Utils::resolveSecret().
     */
    public function test_an_empty_secret_does_not_make_the_legacy_checksum_publicly_computable(): void
    {
        \wp_slimstat::$settings['secret'] = '';

        $this->assertFalse(
            \SlimStat\Tracker\Utils::getValueWithoutChecksum('42.' . md5('42')),
            'md5($value . "") is computable by anyone; it must never authenticate a visit id'
        );
        $this->assertFalse(
            \SlimStat\Tracker\Utils::getValueWithoutChecksum('42.' . str_repeat('0', 64)),
            'and a garbage checksum is still a garbage checksum'
        );
    }

    /**
     * The only case covering legacy md5 compatibility under a REAL secret — which is
     * the state every shipped install is actually in. Measured: it is what fails if
     * the acceptor is gated on an empty secret, and the positive half of what fails
     * if the acceptor is deleted outright.
     */
    public function test_a_legacy_md5_cookie_is_still_accepted_when_the_secret_is_set(): void
    {
        \wp_slimstat::$settings['secret'] = 'test-secret';

        $this->assertSame(
            '42',
            \SlimStat\Tracker\Utils::getValueWithoutChecksum('42.' . md5('42test-secret')),
            'sessions signed before v5.4.2 must survive the upgrade'
        );
    }

    /**
     * Both schemes resolve the key the same way, so an empty secret falls back to
     * AUTH_KEY on BOTH branches rather than only one.
     */
    public function test_both_schemes_fall_back_to_the_same_key(): void
    {
        \wp_slimstat::$settings['secret'] = '';
        $key = AUTH_KEY;

        $this->assertSame(
            '42',
            \SlimStat\Tracker\Utils::getValueWithoutChecksum('42.' . hash_hmac('sha256', '42', $key)),
            'the HMAC branch keys on AUTH_KEY when the secret is empty'
        );
        $this->assertSame(
            '42',
            \SlimStat\Tracker\Utils::getValueWithoutChecksum('42.' . md5('42' . $key)),
            'so must the legacy branch — one resolver, not two'
        );
    }

    /** Signing and verifying are the same function's two directions. */
    public function test_a_freshly_signed_value_verifies_with_an_empty_secret(): void
    {
        \wp_slimstat::$settings['secret'] = '';

        $signed = \SlimStat\Tracker\Utils::getValueWithChecksum('99');

        $this->assertSame('99', \SlimStat\Tracker\Utils::getValueWithoutChecksum($signed));
    }
}
