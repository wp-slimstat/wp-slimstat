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
        $this->assertSame('BingBot', $browser['browser'], 'Chrome-based Bingbot must be identified by specific regex, not generic fallback');
    }

    /** @test */
    public function test_old_bingbot_detected_as_crawler(): void
    {
        $ua = 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)';
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);

        $this->assertSame(1, $browser['browser_type'], 'Old-style Bingbot must be browser_type=1');
        $this->assertSame('BingBot', $browser['browser'], 'Old-style Bingbot browser name');
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

    /**
     * Extended bot coverage — UAs that previously slipped through
     * BOT_GENERIC_REGEX because they lacked a bot keyword or URL.
     *
     * @test
     * @dataProvider vendorBotProvider
     */
    public function test_vendor_bot_detected_as_crawler(string $label, string $ua): void
    {
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);
        $this->assertSame(1, $browser['browser_type'], "{$label} must be browser_type=1");
    }

    public function vendorBotProvider(): array
    {
        return [
            'Mediapartners-Google'          => ['Mediapartners-Google', 'Mediapartners-Google'],
            'Google-InspectionTool desktop' => ['Google-InspectionTool desktop', 'Mozilla/5.0 (compatible; Google-InspectionTool/1.0)'],
            'Google-InspectionTool mobile'  => ['Google-InspectionTool mobile', 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.6723.117 Mobile Safari/537.36 (compatible; Google-InspectionTool/1.0)'],
            'Google-Site-Verification'      => ['Google-Site-Verification', 'Mozilla/5.0 (compatible; Google-Site-Verification/1.0)'],
            'Google Favicon'                => ['Google Favicon', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/49.0.2623.75 Safari/537.36 Google Favicon'],
            'GoogleOther'                   => ['GoogleOther', 'GoogleOther'],
            'GoogleOther-Image'             => ['GoogleOther-Image', 'GoogleOther-Image/1.0'],
            'GoogleAgent-Mariner'           => ['GoogleAgent-Mariner', 'GoogleAgent-Mariner'],
            'Google-Safety'                 => ['Google-Safety', 'Google-Safety'],
            'DuplexWeb-Google'              => ['DuplexWeb-Google', 'Mozilla/5.0 (Linux; Android 11; Pixel 2) AppleWebKit/537.36 DuplexWeb-Google/1.0'],
            'BingPreview'                   => ['BingPreview', 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2272.118 Safari/537.36 BingPreview/1.0b'],
            'YandexDirect'                  => ['YandexDirect', 'YandexDirect/3.0'],
            'YandexFavicons'                => ['YandexFavicons', 'YandexFavicons/1.0'],
            'WhatsApp preview'              => ['WhatsApp preview', 'WhatsApp/2.19.81 A'],
            'SkypeUriPreview'               => ['SkypeUriPreview', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 SkypeUriPreview Preview/0.5'],
            'anthropic-ai'                  => ['anthropic-ai', 'anthropic-ai/1.0'],
            'cohere-ai'                     => ['cohere-ai', 'cohere-ai'],
        ];
    }

    /**
     * Real browsers must never be flagged as crawlers. Guards against regex
     * false positives introduced by future vendor-token additions.
     *
     * @test
     * @dataProvider realBrowserProvider
     */
    public function test_real_browser_not_flagged(string $label, string $ua): void
    {
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);
        $this->assertNotSame(1, $browser['browser_type'], "{$label} must NOT be browser_type=1");
    }

    public function realBrowserProvider(): array
    {
        return [
            'Edge'            => ['Real Edge', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0'],
            'Safari iOS'      => ['Real Safari iOS', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'],
            'Chrome Android'  => ['Real Chrome Android', 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36'],
        ];
    }
}
