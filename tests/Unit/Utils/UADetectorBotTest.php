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

    // ─────────────────────────────────────────────────────────────────────
    // Extended bot coverage (#14843 v2) — UAs that previously slipped
    // through BOT_GENERIC_REGEX because they lacked a bot keyword or URL.
    // ─────────────────────────────────────────────────────────────────────

    /** @test */
    public function test_mediapartners_google_detected_as_crawler(): void
    {
        $ua = 'Mediapartners-Google';
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);
        $this->assertSame(1, $browser['browser_type'], 'Mediapartners-Google must be browser_type=1');
    }

    /** @test */
    public function test_google_inspection_tool_desktop_detected_as_crawler(): void
    {
        $ua = 'Mozilla/5.0 (compatible; Google-InspectionTool/1.0)';
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);
        $this->assertSame(1, $browser['browser_type'], 'Google-InspectionTool desktop must be browser_type=1');
    }

    /** @test */
    public function test_google_inspection_tool_mobile_detected_as_crawler(): void
    {
        $ua = 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.6723.117 Mobile Safari/537.36 (compatible; Google-InspectionTool/1.0)';
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);
        $this->assertSame(1, $browser['browser_type'], 'Google-InspectionTool mobile must be browser_type=1');
    }

    /** @test */
    public function test_google_site_verification_detected_as_crawler(): void
    {
        $ua = 'Mozilla/5.0 (compatible; Google-Site-Verification/1.0)';
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);
        $this->assertSame(1, $browser['browser_type'], 'Google-Site-Verification must be browser_type=1');
    }

    /** @test */
    public function test_google_favicon_detected_as_crawler(): void
    {
        $ua = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/49.0.2623.75 Safari/537.36 Google Favicon';
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);
        $this->assertSame(1, $browser['browser_type'], 'Google Favicon must be browser_type=1');
    }

    /** @test */
    public function test_googleother_detected_as_crawler(): void
    {
        $ua = 'GoogleOther';
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);
        $this->assertSame(1, $browser['browser_type'], 'GoogleOther must be browser_type=1');
    }

    /** @test */
    public function test_googleother_image_detected_as_crawler(): void
    {
        $ua = 'GoogleOther-Image/1.0';
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);
        $this->assertSame(1, $browser['browser_type'], 'GoogleOther-Image must be browser_type=1');
    }

    /** @test */
    public function test_googleagent_mariner_detected_as_crawler(): void
    {
        $ua = 'GoogleAgent-Mariner';
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);
        $this->assertSame(1, $browser['browser_type'], 'GoogleAgent-Mariner must be browser_type=1');
    }

    /** @test */
    public function test_google_safety_detected_as_crawler(): void
    {
        $ua = 'Google-Safety';
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);
        $this->assertSame(1, $browser['browser_type'], 'Google-Safety must be browser_type=1');
    }

    /** @test */
    public function test_duplexweb_google_detected_as_crawler(): void
    {
        $ua = 'Mozilla/5.0 (Linux; Android 11; Pixel 2) AppleWebKit/537.36 DuplexWeb-Google/1.0';
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);
        $this->assertSame(1, $browser['browser_type'], 'DuplexWeb-Google must be browser_type=1');
    }

    /** @test */
    public function test_bingpreview_detected_as_crawler(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2272.118 Safari/537.36 BingPreview/1.0b';
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);
        $this->assertSame(1, $browser['browser_type'], 'BingPreview must be browser_type=1');
    }

    /** @test */
    public function test_yandexdirect_detected_as_crawler(): void
    {
        $ua = 'YandexDirect/3.0';
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);
        $this->assertSame(1, $browser['browser_type'], 'YandexDirect must be browser_type=1');
    }

    /** @test */
    public function test_yandexfavicons_detected_as_crawler(): void
    {
        $ua = 'YandexFavicons/1.0';
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);
        $this->assertSame(1, $browser['browser_type'], 'YandexFavicons must be browser_type=1');
    }

    /** @test */
    public function test_whatsapp_preview_detected_as_crawler(): void
    {
        $ua = 'WhatsApp/2.19.81 A';
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);
        $this->assertSame(1, $browser['browser_type'], 'WhatsApp preview fetcher must be browser_type=1');
    }

    /** @test */
    public function test_skypeuripreview_detected_as_crawler(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 SkypeUriPreview Preview/0.5';
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);
        $this->assertSame(1, $browser['browser_type'], 'SkypeUriPreview must be browser_type=1');
    }

    /** @test */
    public function test_anthropic_ai_detected_as_crawler(): void
    {
        $ua = 'anthropic-ai/1.0';
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);
        $this->assertSame(1, $browser['browser_type'], 'anthropic-ai must be browser_type=1');
    }

    /** @test */
    public function test_cohere_ai_detected_as_crawler(): void
    {
        $ua = 'cohere-ai';
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);
        $this->assertSame(1, $browser['browser_type'], 'cohere-ai must be browser_type=1');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Real browser guards — ensure extended regex hasn't introduced false
    // positives. These must pass BEFORE and AFTER the regex extension.
    // ─────────────────────────────────────────────────────────────────────

    /** @test */
    public function test_real_edge_not_flagged(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0';
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);
        $this->assertNotSame(1, $browser['browser_type'], 'Real Edge must NOT be browser_type=1');
    }

    /** @test */
    public function test_real_safari_ios_not_flagged(): void
    {
        $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);
        $this->assertNotSame(1, $browser['browser_type'], 'Real Safari iOS must NOT be browser_type=1');
    }

    /** @test */
    public function test_real_chrome_android_not_flagged(): void
    {
        $ua = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36';
        $browser = \SlimStat\Utils\UADetector::get_browser($ua);
        $this->assertNotSame(1, $browser['browser_type'], 'Real Chrome Android must NOT be browser_type=1');
    }
}
