<?php
declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Utils;

use Brain\Monkey\Functions;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;
use SlimStat\Utils\Consent;

/**
 * Unit tests for Consent::canTrack() and Consent::piiAllowed() SlimStat-banner guard.
 *
 * Regression for: visitors not tracked after upgrading from 5.3.x because
 * use_slimstat_banner defaulted to 'on' after the 5.4.0 GDPR changes, silently
 * blocking all anonymous visitors who had never seen or accepted the banner.
 *
 * @see wp-slimstat/src/Utils/Consent.php
 */
class ConsentTest extends WpSlimstatTestCase
{
    /** @var array<string,mixed> */
    private array $baseSettings = [
        'gdpr_enabled'         => 'on',
        'consent_integration'  => 'slimstat_banner',
        'use_slimstat_banner'  => 'off',
        'set_tracker_cookie'   => 'on',   // PII-collecting (cookie on)
        'anonymize_ip'         => 'off',  // PII-collecting (full IP)
        'hash_ip'              => 'off',
        'anonymous_tracking'   => 'off',
        'do_not_track'         => 'off',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->stubCommonWpFunctions();

        \wp_slimstat::$settings              = $this->baseSettings;
        \wp_slimstat::$is_programmatic_tracking = false;

        $_COOKIE = [];
        unset($_SERVER['HTTP_DNT']);
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        unset($_SERVER['HTTP_DNT']);
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // canTrack() — banner guard
    // -----------------------------------------------------------------------

    /**
     * canTrack() must return true when the banner is disabled, even when PII-collecting
     * settings are active (cookies on, IPs not anonymized).
     *
     * This is the core upgrade regression: users migrating from 5.3.x kept cookies=on
     * but inherited use_slimstat_banner=on as a new default. With the fix, tracking is
     * only blocked when the banner is explicitly enabled — giving visitors a mechanism
     * to actually grant or deny consent.
     *
     * @test
     */
    public function test_can_track_allows_tracking_when_banner_disabled(): void
    {
        \wp_slimstat::$settings['use_slimstat_banner'] = 'off';

        // Pass the default value through unchanged.
        Functions\expect('apply_filters')
            ->with('slimstat_can_track', \Mockery::any(), \Mockery::any())
            ->once()
            ->andReturnUsing(static fn ($tag, $value) => $value);

        $this->assertTrue(
            Consent::canTrack(),
            'canTrack() must return true when use_slimstat_banner = off'
        );
    }

    /**
     * canTrack() must return false when the banner is enabled and no consent cookie is set.
     *
     * @test
     */
    public function test_can_track_blocks_when_banner_enabled_no_consent(): void
    {
        \wp_slimstat::$settings['use_slimstat_banner'] = 'on';
        $_COOKIE = [];

        Functions\expect('apply_filters')
            ->with('slimstat_can_track', \Mockery::any(), \Mockery::any())
            ->once()
            ->andReturnUsing(static fn ($tag, $value) => $value);

        $this->assertFalse(
            Consent::canTrack(),
            'canTrack() must return false when banner is on and no consent cookie is set'
        );
    }

    /**
     * canTrack() must return true when the banner is enabled and the visitor accepted.
     *
     * @test
     */
    public function test_can_track_allows_tracking_when_banner_enabled_consent_accepted(): void
    {
        \wp_slimstat::$settings['use_slimstat_banner'] = 'on';
        $_COOKIE[\SlimStat\Services\GDPRService::CONSENT_COOKIE_NAME] = 'accepted';

        Functions\expect('apply_filters')
            ->with('slimstat_can_track', \Mockery::any(), \Mockery::any())
            ->once()
            ->andReturnUsing(static fn ($tag, $value) => $value);

        $this->assertTrue(
            Consent::canTrack(),
            'canTrack() must return true when banner is on and visitor accepted consent'
        );
    }

    // -----------------------------------------------------------------------
    // piiAllowed() — banner guard
    // -----------------------------------------------------------------------

    /**
     * piiAllowed() must return true when the banner is disabled, preserving pre-upgrade
     * PII behaviour for sites that never configured GDPR explicitly.
     *
     * @test
     */
    public function test_pii_allowed_when_banner_disabled(): void
    {
        \wp_slimstat::$settings['use_slimstat_banner'] = 'off';

        $this->assertTrue(
            Consent::piiAllowed(),
            'piiAllowed() must return true when use_slimstat_banner = off'
        );
    }

    /**
     * piiAllowed() must return false when the banner is enabled and no consent cookie is set.
     *
     * @test
     */
    public function test_pii_blocked_when_banner_enabled_no_consent(): void
    {
        \wp_slimstat::$settings['use_slimstat_banner'] = 'on';
        $_COOKIE = [];

        $this->assertFalse(
            Consent::piiAllowed(),
            'piiAllowed() must return false when banner is on and no consent cookie is set'
        );
    }

    /**
     * piiAllowed() must return true when the banner is enabled and consent is accepted.
     *
     * @test
     */
    public function test_pii_allowed_when_banner_enabled_consent_accepted(): void
    {
        \wp_slimstat::$settings['use_slimstat_banner'] = 'on';
        $_COOKIE[\SlimStat\Services\GDPRService::CONSENT_COOKIE_NAME] = 'accepted';

        $this->assertTrue(
            Consent::piiAllowed(),
            'piiAllowed() must return true when banner is on and visitor accepted consent'
        );
    }
}
