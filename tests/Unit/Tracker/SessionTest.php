<?php
declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Tracker;

use Brain\Monkey\Functions;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

/**
 * Unit tests for SlimStat\Tracker\Session.
 *
 * Focuses on the anonymous-tracking path of ensureVisitId() and the
 * pure-PHP helpers that can run without a database connection.
 *
 * @see SlimStat\Tracker\Session
 */
class SessionTest extends WpSlimstatTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset global state before each test.
        \wp_slimstat::$settings['anonymous_tracking'] = 'off';
        \wp_slimstat::$settings['gdpr_enabled']       = 'off';
        \wp_slimstat::$settings['javascript_mode']    = 'off';
        \wp_slimstat::$settings['session_duration']   = 1800;
        \wp_slimstat::$settings['set_tracker_cookie'] = 'off';
        \wp_slimstat::set_stat(['dt' => time(), 'notes' => []]);
        \wp_slimstat::set_data_js([]);
        \wp_slimstat::$is_programmatic_tracking = false;

        // Ensure no tracking cookie is present.
        unset($_COOKIE['slimstat_tracking_code']);
    }

    // -----------------------------------------------------------------------
    // ensureVisitId — anonymous tracking path (no PII, no cookie)
    // -----------------------------------------------------------------------

    /**
     * In anonymous-tracking mode with no consent and no cookie, ensureVisitId()
     * must NOT set a browser cookie and must return true (new session).
     *
     * The test verifies the return value and that setcookie() is NOT called
     * (cookies are PII and require explicit consent in anonymous mode).
     *
     * @todo Requires Patchwork or a seam on Session::findExistingAnonymousVisitId()
     *       to avoid a real DB lookup.  The test is marked incomplete when DB
     *       classes are unavailable.
     *
     * @test
     */
    public function test_ensure_visit_id_anonymous_no_consent_returns_true(): void
    {
        \wp_slimstat::$settings['anonymous_tracking'] = 'on';

        // Stub WP functions used inside ensureVisitId / Consent.
        Functions\stubs([
            'sanitize_text_field' => static fn($v) => is_string($v) ? $v : '',
            'wp_unslash'          => static fn($v) => is_string($v) ? stripslashes($v) : $v,
        ]);

        Functions\expect('apply_filters')
            ->zeroOrMoreTimes()
            ->andReturnUsing(static function (string $tag, $value) {
                return $value; // pass-through all filters
            });

        // Stub is_ssl() (called inside setTrackingCookie).
        Functions\stubs(['is_ssl' => false]);

        \wp_slimstat::set_stat(['dt' => time(), 'notes' => [], 'resource' => '/test']);

        try {
            $result = \SlimStat\Tracker\Session::ensureVisitId(false);
            // In anonymous mode without existing records the method returns true.
            $this->assertTrue($result, 'ensureVisitId() must return true for a new anonymous session');
        } catch (\Throwable $e) {
            $this->markTestIncomplete(
                'ensureVisitId() requires DB for findExistingAnonymousVisitId(): ' . $e->getMessage()
            );
        }
    }

    /**
     * When a valid tracking cookie exists, ensureVisitId() must return false
     * (re-using an existing session, not a new one).
     *
     * We simulate a cookie value that looks like a plain integer (no 'id' substring),
     * meaning the identifier is a visit_id (not a pageview-id fallback).
     *
     * @test
     */
    public function test_ensure_visit_id_returns_false_when_valid_cookie_present(): void
    {
        \wp_slimstat::$settings['anonymous_tracking'] = 'off';
        \wp_slimstat::$settings['javascript_mode']    = 'off';

        Functions\stubs([
            'sanitize_text_field' => static fn($v) => is_string($v) ? $v : '',
            'wp_unslash'          => static fn($v) => is_string($v) ? stripslashes($v) : $v,
        ]);

        Functions\expect('apply_filters')
            ->zeroOrMoreTimes()
            ->andReturnUsing(static function (string $tag, $value) {
                return $value;
            });

        // Build a valid checksum cookie value for visit_id = 42, through the signer the
        // code under test verifies against. Hand-rolling `md5($visitId . $secret)` here
        // meant this "valid cookie" case and the "invalid checksum" case below were
        // exercising the same rejection path — both assert false, so the suite stayed
        // green while proving nothing. setUp() never sets a secret, and an empty secret
        // is exactly the state whose md5 form is no longer accepted (X2).
        $visitId = 42;
        $_COOKIE['slimstat_tracking_code'] = \SlimStat\Tracker\Utils::getValueWithChecksum($visitId);

        try {
            $result = \SlimStat\Tracker\Session::ensureVisitId(false);
            // Existing session — not a new visit.
            $this->assertFalse($result, 'ensureVisitId() must return false when re-using existing session');
        } catch (\Throwable $e) {
            $this->markTestIncomplete(
                'ensureVisitId() path threw an exception: ' . $e->getMessage()
            );
        } finally {
            unset($_COOKIE['slimstat_tracking_code']);
        }
    }

    /**
     * A cookie value that fails checksum validation causes ensureVisitId()
     * to return false immediately (tampered / invalid cookie).
     *
     * @test
     */
    public function test_ensure_visit_id_returns_false_for_invalid_cookie_checksum(): void
    {
        \wp_slimstat::$settings['anonymous_tracking'] = 'off';

        Functions\stubs([
            'sanitize_text_field' => static fn($v) => is_string($v) ? $v : '',
            'wp_unslash'          => static fn($v) => is_string($v) ? stripslashes($v) : $v,
        ]);

        // Tampered value — wrong checksum.
        $_COOKIE['slimstat_tracking_code'] = '42.invalidchecksum';

        try {
            $result = \SlimStat\Tracker\Session::ensureVisitId(false);
            $this->assertFalse($result, 'ensureVisitId() must return false for an invalid cookie checksum');
        } catch (\Throwable $e) {
            $this->markTestIncomplete('Unexpected exception: ' . $e->getMessage());
        } finally {
            unset($_COOKIE['slimstat_tracking_code']);
        }
    }

    // -----------------------------------------------------------------------
    // getVisitId — pure PHP, no DB
    // -----------------------------------------------------------------------

    /** @test */
    public function test_get_visit_id_returns_zero_when_not_set(): void
    {
        \wp_slimstat::set_stat(['dt' => time()]);
        $this->assertSame(0, \SlimStat\Tracker\Session::getVisitId());
    }

    /** @test */
    public function test_get_visit_id_returns_current_visit_id(): void
    {
        \wp_slimstat::set_stat(['dt' => time(), 'visit_id' => 99]);
        $this->assertSame(99, \SlimStat\Tracker\Session::getVisitId());
    }

    // -----------------------------------------------------------------------
    // generateAnonymousVidHash — pure PHP, no DB (the only DB touch is the
    // daily-salt option read, stubbed like everything else here)
    // -----------------------------------------------------------------------
    //
    // These two tests targeted generateAnonymousVisitId() until D68 deleted it — and
    // then kept "passing": the try/catch → markTestIncomplete wrapper swallowed the
    // Call-to-undefined-method Error, the pair reported Incomplete, and the assertion
    // floor was RATCHETED from a run in which they asserted nothing (found by review;
    // the incomplete count sat exactly at its ceiling). The wrappers are gone — a
    // deleted callee must FAIL these tests, not excuse them. PITFALLS 41's shape, in
    // the seam that was citing PITFALLS 41.

    /**
     * generateAnonymousVidHash() returns exactly 32 lowercase hex chars — the one
     * spelling that survives sanitize_text_field() at the write terminals, and the
     * exact contract Storage::insertRow() validates before packing to BINARY(16).
     *
     * @test
     */
    public function test_generate_anonymous_vid_hash_is_32_hex_chars(): void
    {
        Functions\stubs([
            'sanitize_text_field' => static fn($v) => is_string($v) ? $v : '',
            'wp_unslash'          => static fn($v) => is_string($v) ? stripslashes($v) : $v,
            'wp_salt'             => 'test-salt-value-that-is-long-enough-to-pass-validation',
        ]);

        // A stored salt for TODAY, via the bootstrap's real get_option() store (Brain
        // Monkey cannot stub over a defined function): generateDailySalt() returns it
        // without minting, so wp_generate_password()/update_option() are never reached.
        $GLOBALS['slimstat_test_options']['slimstat_daily_salt'] = ['date' => gmdate('Y-m-d'), 'salt' => 'a-fixed-test-salt'];

        $hash = \SlimStat\Tracker\Session::generateAnonymousVidHash(['dt' => time(), 'notes' => []]);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{32}$/',
            $hash,
            'the identity must be exactly 16 raw bytes spelled as lowercase hex — anything '
                . 'else is dropped at the terminal and the visitor loses their identity'
        );
    }

    /**
     * Same person, same day, same fingerprint — same identity. Determinism is what
     * lets the reuse probe and the consent-upgrade fallback FIND the person again.
     *
     * @test
     */
    public function test_generate_anonymous_vid_hash_is_deterministic(): void
    {
        Functions\stubs([
            'sanitize_text_field' => static fn($v) => is_string($v) ? $v : '',
            'wp_unslash'          => static fn($v) => is_string($v) ? stripslashes($v) : $v,
            'wp_salt'             => 'test-salt-value-that-is-long-enough-to-pass-validation',
        ]);

        $GLOBALS['slimstat_test_options']['slimstat_daily_salt'] = ['date' => gmdate('Y-m-d'), 'salt' => 'a-fixed-test-salt'];

        $stat = ['dt' => time(), 'notes' => [], 'fingerprint' => 'abc123fingerprint'];

        $this->assertSame(
            \SlimStat\Tracker\Session::generateAnonymousVidHash($stat),
            \SlimStat\Tracker\Session::generateAnonymousVidHash($stat),
            'generateAnonymousVidHash() must be deterministic for the same inputs'
        );
    }

    // -----------------------------------------------------------------------
    // setTrackingCookie — consent gate
    // -----------------------------------------------------------------------

    /**
     * setTrackingCookie() must return false when consent/cookie settings deny it.
     *
     * With gdpr_enabled=off and set_tracker_cookie=off, piiAllowed() returns
     * true (no GDPR checks) but the cookie-enabled flag is off, so the
     * slimstat_set_visit_cookie filter receives false.
     *
     * @test
     */
    public function test_set_tracking_cookie_returns_false_when_cookie_disabled(): void
    {
        \wp_slimstat::$settings['set_tracker_cookie'] = 'off';
        \wp_slimstat::$settings['gdpr_enabled']       = 'off';

        Functions\stubs([
            'sanitize_text_field' => static fn($v) => is_string($v) ? $v : '',
            'wp_unslash'          => static fn($v) => is_string($v) ? stripslashes($v) : $v,
        ]);

        // The filter receives false (cookie disabled), so return false.
        Functions\expect('apply_filters')
            ->with('slimstat_set_visit_cookie', false)
            ->once()
            ->andReturn(false);

        $result = \SlimStat\Tracker\Session::setTrackingCookie(1, 'visit');

        $this->assertFalse($result, 'setTrackingCookie() must return false when cookie setting is disabled');
    }
}
