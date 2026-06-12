<?php
declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Tracker;

use Brain\Monkey\Functions;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

/**
 * Regression tests for #306 — android-app:// (Google Discover) referers were
 * silently dropped because Ajax::handle() ran the referer through sanitize_url(),
 * which strips any scheme not in wp_allowed_protocols().
 *
 * Ajax::sanitizeReferer() is the seam extracted from handle() lines 102-130. It
 * now uses sanitize_text_field(), preserving app-scheme referers while the host
 * regex and the Processor scheme allowlist remain the security boundary.
 */
class AjaxRefererSanitizationTest extends WpSlimstatTestCase
{
    /** base64url-encode using the same scheme as Tracker::_base64_url_encode(). */
    private static function encode(string $url): string
    {
        return strtr(base64_encode($url), '+/=', '._-');
    }

    /** @test */
    public function test_preserves_android_app_referer(): void
    {
        // sanitize_text_field must be used (passthrough for a clean string);
        // sanitize_url must never be called for the referer.
        Functions\expect('sanitize_text_field')->once()->andReturnUsing(static fn($v) => $v);
        Functions\expect('sanitize_url')->never();

        $url    = 'android-app://com.google.android.googlequicksearchbox/';
        $result = \SlimStat\Tracker\Ajax::sanitizeReferer(self::encode($url));

        $this->assertSame($url, $result);
    }

    /** @test */
    public function test_preserves_ios_app_referer(): void
    {
        Functions\expect('sanitize_text_field')->once()->andReturnUsing(static fn($v) => $v);
        Functions\expect('sanitize_url')->never();

        $url    = 'ios-app://com.google.ios.app/';
        $result = \SlimStat\Tracker\Ajax::sanitizeReferer(self::encode($url));

        $this->assertSame($url, $result);
    }

    /** @test */
    public function test_preserves_http_referer_baseline(): void
    {
        Functions\expect('sanitize_text_field')->once()->andReturnUsing(static fn($v) => $v);
        Functions\expect('sanitize_url')->never();

        $url    = 'https://example.com/page?q=1';
        $result = \SlimStat\Tracker\Ajax::sanitizeReferer(self::encode($url));

        $this->assertSame($url, $result);
    }

    /** @test */
    public function test_strips_script_tags_from_referer(): void
    {
        // Tags are stripped by base64UrlDecode (strip_tags) before sanitize_text_field;
        // here we additionally model sanitize_text_field stripping any residual markup.
        Functions\expect('sanitize_text_field')->once()->andReturnUsing(
            static fn($v) => trim(preg_replace('/<[^>]*>/', '', (string) $v))
        );

        $payload = 'android-app://attacker/<script>alert(1)</script>';
        $result  = \SlimStat\Tracker\Ajax::sanitizeReferer(self::encode($payload));

        $this->assertIsString($result);
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringStartsWith('android-app://attacker/', $result);
    }

    /** @test */
    public function test_rejects_invalid_host(): void
    {
        // sanitize_text_field must never be reached when the host fails validation.
        Functions\expect('sanitize_text_field')->never();

        // Underscores are not valid in the host-format regex.
        $result = \SlimStat\Tracker\Ajax::sanitizeReferer(self::encode('http://bad_host!!/path'));

        $this->assertFalse($result);
    }

    /** @test */
    public function test_truncates_referer_above_2048(): void
    {
        Functions\expect('sanitize_text_field')->once()->andReturnUsing(static fn($v) => $v);

        $longPath = str_repeat('a', 4096);
        $url      = 'https://example.com/' . $longPath;
        $result   = \SlimStat\Tracker\Ajax::sanitizeReferer(self::encode($url));

        $this->assertIsString($result);
        $this->assertSame(2048, strlen($result));
    }
}
