<?php
declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Services;

use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

/**
 * Unit tests for Browscap::apply_bot_safety_net() — the defense-in-depth
 * check that catches bots when Browscap misidentifies them as regular browsers.
 *
 * @see SlimStat\Services\Browscap::apply_bot_safety_net()
 * @see https://github.com/wp-slimstat/wp-slimstat/issues/291
 */
class BrowscapBotSafetyNetTest extends WpSlimstatTestCase
{
    /** @test */
    public function test_catches_chrome_googlebot_when_type_zero(): void
    {
        $browser = [
            'browser'         => 'Chrome',
            'browser_version' => 130.0,
            'browser_type'    => 0,
            'platform'        => 'unknown',
            'user_agent'      => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Googlebot/2.1; +http://www.google.com/bot.html) Chrome/130.0.6723.117 Safari/537.36',
        ];

        $result = \SlimStat\Services\Browscap::apply_bot_safety_net($browser);

        $this->assertSame(1, $result['browser_type'], 'Chrome-based Googlebot with browser_type=0 must be corrected to 1');
    }

    /** @test */
    public function test_catches_chrome_bingbot_when_type_zero(): void
    {
        $browser = [
            'browser'         => 'Chrome',
            'browser_version' => 116.0,
            'browser_type'    => 0,
            'platform'        => 'unknown',
            'user_agent'      => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm) Chrome/116.0.1938.76 Safari/537.36',
        ];

        $result = \SlimStat\Services\Browscap::apply_bot_safety_net($browser);

        $this->assertSame(1, $result['browser_type'], 'Chrome-based Bingbot with browser_type=0 must be corrected to 1');
    }

    /** @test */
    public function test_no_false_positive_real_chrome(): void
    {
        $browser = [
            'browser'         => 'Chrome',
            'browser_version' => 130.0,
            'browser_type'    => 0,
            'platform'        => 'win10',
            'user_agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
        ];

        $result = \SlimStat\Services\Browscap::apply_bot_safety_net($browser);

        $this->assertSame(0, $result['browser_type'], 'Real Chrome must NOT be flagged as bot');
    }

    /** @test */
    public function test_no_false_positive_real_firefox(): void
    {
        $browser = [
            'browser'         => 'Firefox',
            'browser_version' => 121.0,
            'browser_type'    => 0,
            'platform'        => 'win10',
            'user_agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
        ];

        $result = \SlimStat\Services\Browscap::apply_bot_safety_net($browser);

        $this->assertSame(0, $result['browser_type'], 'Real Firefox must NOT be flagged as bot');
    }

    /** @test */
    public function test_no_false_positive_real_safari(): void
    {
        $browser = [
            'browser'         => 'Safari',
            'browser_version' => 17.0,
            'browser_type'    => 0,
            'platform'        => 'macosx',
            'user_agent'      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
        ];

        $result = \SlimStat\Services\Browscap::apply_bot_safety_net($browser);

        $this->assertSame(0, $result['browser_type'], 'Real Safari must NOT be flagged as bot');
    }

    /** @test */
    public function test_no_false_positive_real_edge(): void
    {
        $browser = [
            'browser'         => 'Edge',
            'browser_version' => 130.0,
            'browser_type'    => 0,
            'platform'        => 'win10',
            'user_agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0',
        ];

        $result = \SlimStat\Services\Browscap::apply_bot_safety_net($browser);

        $this->assertSame(0, $result['browser_type'], 'Real Edge must NOT be flagged as bot');
    }

    /** @test */
    public function test_preserves_existing_crawler_flag(): void
    {
        $browser = [
            'browser'         => 'Googlebot',
            'browser_version' => 2.1,
            'browser_type'    => 1,
            'platform'        => 'unknown',
            'user_agent'      => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        ];

        $result = \SlimStat\Services\Browscap::apply_bot_safety_net($browser);

        $this->assertSame(1, $result['browser_type'], 'Already-flagged crawler must remain browser_type=1');
    }

    /** @test */
    public function test_handles_empty_user_agent(): void
    {
        $browser = [
            'browser'         => 'Default Browser',
            'browser_version' => '',
            'browser_type'    => 0,
            'platform'        => 'unknown',
            'user_agent'      => '',
        ];

        $result = \SlimStat\Services\Browscap::apply_bot_safety_net($browser);

        $this->assertSame(0, $result['browser_type'], 'Empty UA must NOT be flagged as bot by safety net');
    }
}
