<?php
declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Utils;

use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

/**
 * Unit tests for UADetector bot detection — verifies that Chrome-based
 * search engine crawlers are correctly identified as bots (browser_type=1).
 *
 * @see SlimStat\Utils\UADetector::get_browser()
 * @see https://github.com/wp-slimstat/wp-slimstat/issues/291
 */
class UADetectorBotTest extends WpSlimstatTestCase
{
    /** @test */
    public function test_old_desktop_googlebot_detected_as_crawler(): void
    {
        $ua = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);

        $this->assertSame(1, $browser['browser_type'], 'Old-style Googlebot must be browser_type=1');
    }

    /** @test */
    public function test_chrome_based_desktop_googlebot_detected_as_crawler(): void
    {
        $ua = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Googlebot/2.1; +http://www.google.com/bot.html) Chrome/130.0.6723.117 Safari/537.36';
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);

        $this->assertSame(1, $browser['browser_type'], 'Chrome-based Googlebot must be browser_type=1');
    }

    /** @test */
    public function test_mobile_googlebot_detected_as_crawler(): void
    {
        $ua = 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.6723.117 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);

        $this->assertSame(1, $browser['browser_type'], 'Mobile Googlebot must be browser_type=1');
    }

    /** @test */
    public function test_googlebot_image_detected_as_crawler(): void
    {
        $ua = 'Googlebot-Image/1.0';
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);

        $this->assertSame(1, $browser['browser_type'], 'Googlebot-Image must be browser_type=1');
    }

    /** @test */
    public function test_chrome_based_bingbot_detected_as_crawler(): void
    {
        $ua = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm) Chrome/116.0.1938.76 Safari/537.36';
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);

        $this->assertSame(1, $browser['browser_type'], 'Chrome-based Bingbot must be browser_type=1');
    }

    /** @test */
    public function test_real_chrome_not_flagged_as_crawler(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);

        $this->assertNotSame(1, $browser['browser_type'], 'Real Chrome must NOT be browser_type=1');
    }

    /** @test */
    public function test_real_firefox_not_flagged_as_crawler(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0';
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);

        $this->assertNotSame(1, $browser['browser_type'], 'Real Firefox must NOT be browser_type=1');
    }

    /** @test */
    public function test_real_safari_not_flagged_as_crawler(): void
    {
        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);

        $this->assertNotSame(1, $browser['browser_type'], 'Real Safari must NOT be browser_type=1');
    }

    /** @test */
    public function test_simple_googlebot_ua_detected_as_crawler(): void
    {
        $ua = 'Googlebot/2.1 (+http://www.google.com/bot.html)';
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);

        $this->assertSame(1, $browser['browser_type'], 'Simple Googlebot UA must be browser_type=1');
    }
}
