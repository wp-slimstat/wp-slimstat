<?php
declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Tracker;

use Brain\Monkey\Functions;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

/**
 * Tests for Ajax::sanitizeReferer() — the seam extracted from handle() for #306.
 *
 * The referer is sanitized with sanitize_url() using an extended protocols
 * allow-list (Processor::REFERER_ALLOWED_SCHEMES = http/https/android-app):
 *   - #306: `android-app://…` (Google Discover) survives — it is on the allow-list;
 *   - #306 follow-up: percent-encoded query octets (%XX) are preserved so
 *     getSearchTerms() can still decode non-Latin / spaced terms downstream
 *     (sanitize_text_field would have stripped them);
 *   - L1: disallowed schemes (javascript:, data:, ios-app:, ftp:) are emptied at
 *     this boundary, so they cannot reach storage even on the follow-up-event path
 *     that skips Processor::process()'s post-storage scheme check.
 *
 * The host-format regex and the 2048-char cap are the seam's own guards and are
 * exercised here too.
 */
class AjaxRefererSanitizationTest extends WpSlimstatTestCase
{
    /** base64url-encode using the same scheme as Tracker::base64UrlEncode(). */
    private static function encode(string $url): string
    {
        return strtr(base64_encode($url), '+/=', '._-');
    }

    /**
     * Model WordPress sanitize_url(): drop any scheme not in $protocols, otherwise
     * return the URL unchanged (esc_url preserves %XX query octets). Tags are
     * already stripped upstream by Utils::base64UrlDecode().
     */
    private function stubSanitizeUrl(): void
    {
        Functions\when('sanitize_url')->alias(static function ($url, $protocols = null) {
            $allowed = $protocols ?: ['http', 'https'];
            $scheme  = strtolower((string) parse_url((string) $url, PHP_URL_SCHEME));
            if ($scheme !== '' && !in_array($scheme, $allowed, true)) {
                return '';
            }
            return (string) $url;
        });
    }

    /** @test */
    public function test_preserves_android_app_referer(): void
    {
        $this->stubSanitizeUrl();
        // sanitize_text_field must NOT be used for the referer anymore.
        Functions\expect('sanitize_text_field')->never();

        $url = 'android-app://com.google.android.googlequicksearchbox/';
        $this->assertSame($url, \SlimStat\Tracker\Ajax::sanitizeReferer(self::encode($url)));
    }

    /** @test */
    public function test_preserves_http_referer_baseline(): void
    {
        $this->stubSanitizeUrl();

        $url = 'https://example.com/page?q=1';
        $this->assertSame($url, \SlimStat\Tracker\Ajax::sanitizeReferer(self::encode($url)));
    }

    /**
     * #306 follow-up regression guard: percent-encoded query octets must survive,
     * or non-Latin / spaced search terms are lost in getSearchTerms(). This is the
     * exact case sanitize_text_field would have corrupted.
     *
     * @test
     */
    public function test_preserves_percent_encoded_query_octets(): void
    {
        $this->stubSanitizeUrl();

        $url    = 'https://yandex.ru/search/?text=%D0%BF%D1%80%D0%B8%D0%B2%D0%B5%D1%82&lr=1';
        $result = \SlimStat\Tracker\Ajax::sanitizeReferer(self::encode($url));

        $this->assertStringContainsString('%D0%BF', $result, 'percent-encoded octets must be preserved');
        $this->assertSame($url, $result);
    }

    /**
     * L1: a disallowed scheme is emptied at the sanitizer (not merely flagged later
     * by Processor), covering the follow-up-event path that bypasses Processor.
     *
     * @test
     * @dataProvider disallowedSchemeProvider
     */
    public function test_drops_disallowed_scheme_referer(string $url): void
    {
        $this->stubSanitizeUrl();

        $this->assertSame('', \SlimStat\Tracker\Ajax::sanitizeReferer(self::encode($url)));
    }

    public static function disallowedSchemeProvider(): array
    {
        return [
            'javascript' => ['javascript:alert(document.cookie)//'],
            'data'       => ['data:text/plain,hi'],
            // ios-app is NOT on the allow-list — it is dropped end-to-end, unlike
            // android-app. (A prior test wrongly asserted ios-app passthrough.)
            'ios-app'    => ['ios-app://com.google.ios.app/'],
            'ftp'        => ['ftp://files.example.com/x'],
        ];
    }

    /** @test */
    public function test_rejects_invalid_host(): void
    {
        // Host-format failure returns false BEFORE the sanitizer runs.
        Functions\expect('sanitize_url')->never();

        // Underscores are not valid in the host-format regex.
        $this->assertFalse(\SlimStat\Tracker\Ajax::sanitizeReferer(self::encode('http://bad_host!!/path')));
    }

    /** @test */
    public function test_truncates_referer_above_2048(): void
    {
        $this->stubSanitizeUrl();

        $url    = 'https://example.com/' . str_repeat('a', 4096);
        $result = \SlimStat\Tracker\Ajax::sanitizeReferer(self::encode($url));

        $this->assertIsString($result);
        $this->assertSame(2048, strlen($result));
    }
}
