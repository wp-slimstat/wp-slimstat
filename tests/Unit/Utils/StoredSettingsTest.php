<?php
declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Utils;

use PHPUnit\Framework\TestCase;
use SlimStat\Utils\StoredSettings;

/** Pro's runtime Advanced Whois URL must never become the stored IP lookup service. */
class StoredSettingsTest extends TestCase
{
    private const OVERRIDE = 'https://example.test/wp-admin/admin-ajax.php?action=slimstat_ip2location_iframe_content&_wpnonce=abc&ip=';

    public function test_a_normal_save_passes_through(): void
    {
        $options = ['ip_lookup_service' => 'https://whois.example/', 'other' => 'on'];
        self::assertSame($options, StoredSettings::withoutRuntimeOverrides($options, ['ip_lookup_service' => 'https://old.example/']));
    }

    public function test_the_override_is_replaced_by_the_stored_url(): void
    {
        $saved = StoredSettings::withoutRuntimeOverrides(['ip_lookup_service' => self::OVERRIDE, 'other' => 'on'], ['ip_lookup_service' => 'https://whois.example/']);
        self::assertSame(['ip_lookup_service' => 'https://whois.example/', 'other' => 'on'], $saved);
    }

    public function test_an_already_corrupted_value_falls_back_to_the_default(): void
    {
        self::assertSame(StoredSettings::DEFAULT_LOOKUP, StoredSettings::withoutRuntimeOverrides(['ip_lookup_service' => self::OVERRIDE], ['ip_lookup_service' => self::OVERRIDE])['ip_lookup_service']);
        self::assertSame(StoredSettings::DEFAULT_LOOKUP, StoredSettings::withoutRuntimeOverrides(['ip_lookup_service' => self::OVERRIDE])['ip_lookup_service']);
        self::assertSame('https://ip-api.com/#', StoredSettings::DEFAULT_LOOKUP);
    }

    /** The settings help text quotes the real default (it once named whatismyipaddress.com). */
    public function test_default_matches_the_option_default_and_its_help_text(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertStringContainsString("'ip_lookup_service'               => '" . StoredSettings::DEFAULT_LOOKUP . "'", (string) file_get_contents($root . '/wp-slimstat.php'));
        self::assertStringContainsString('Default value: <code>' . StoredSettings::DEFAULT_LOOKUP . '</code>', (string) file_get_contents($root . '/admin/config/index.php'));
    }
}
