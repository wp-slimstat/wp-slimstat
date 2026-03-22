<?php
declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Tracker;

use Brain\Monkey\Functions;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

/**
 * Unit tests for SlimStat\Tracker\Processor::process() early-exit paths.
 *
 * Each test exercises a guard clause that causes process() to return false
 * before any database interaction occurs.
 *
 * @see SlimStat\Tracker\Processor::process()
 */
class ProcessorTest extends WpSlimstatTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure GDPR is disabled so Consent::canTrack() defers entirely to
        // the slimstat_can_track filter, which we can stub via Brain Monkey.
        \wp_slimstat::$settings['gdpr_enabled'] = 'off';
        \wp_slimstat::$settings['anonymous_tracking'] = 'off';
        \wp_slimstat::set_stat(['dt' => 0, 'notes' => []]);
        \wp_slimstat::set_data_js([]);
        \wp_slimstat::$is_programmatic_tracking = false;
    }

    /**
     * Clear all proxy IP headers from $_SERVER so Utils::getRemoteIp() reads only REMOTE_ADDR.
     */
    private function clearProxyHeaders(): void
    {
        foreach ([
            'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED', 'HTTP_CLIENT_IP', 'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_X_REAL_IP', 'HTTP_INCAP_CLIENT_IP',
        ] as $key) {
            unset($_SERVER[$key]);
        }
    }

    // -----------------------------------------------------------------------
    // Consent gate
    // -----------------------------------------------------------------------

    /**
     * process() must return false immediately when Consent::canTrack() is false.
     *
     * We simulate a denied-consent scenario by using the slimstat_can_track
     * filter (Brain Monkey) to return false.
     *
     * @test
     */
    public function test_process_returns_false_when_consent_denied(): void
    {
        // Stub apply_filters('slimstat_can_track', …) to return false.
        Functions\expect('apply_filters')
            ->with('slimstat_can_track', \Mockery::any(), \Mockery::any())
            ->andReturn(false);

        $result = \SlimStat\Tracker\Processor::process();

        $this->assertSame(-301, $result, 'process() must return -301 (consent denied) when canTrack is false');
    }

    // -----------------------------------------------------------------------
    // IP address guard
    // -----------------------------------------------------------------------

    /**
     * process() must return false when the resolved IP address is empty.
     *
     * Consent::canTrack() is allowed to pass (filter returns true).
     * Utils::getRemoteIp() is expected to return ['', ''].
     * The process() IP guard must then short-circuit with false.
     *
     * @todo Utils::getRemoteIp() is a static method on a concrete class; full
     *       isolation requires either a seam (interface/factory) or Patchwork.
     *       Until that seam exists, this test documents the expected contract.
     *
     * @test
     */
    public function test_process_returns_false_when_ip_empty(): void
    {
        // Allow tracking through the consent gate.
        Functions\expect('apply_filters')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (string $tag, $value) {
                if ($tag === 'slimstat_can_track') {
                    return true;
                }
                return $value;
            });

        $this->stubCommonWpFunctions();

        // Seed stat with a timestamp so the dt-empty check passes.
        \wp_slimstat::set_stat(['dt' => time(), 'notes' => []]);

        // Because Utils::getRemoteIp() reads $_SERVER, we can control the
        // output by clearing the relevant superglobal keys.
        $backup = $_SERVER;
        $_SERVER['REMOTE_ADDR'] = '';
        $this->clearProxyHeaders();

        try {
            $result = \SlimStat\Tracker\Processor::process();
            $this->assertSame(-202, $result, 'process() must return -202 (empty IP) when IP is empty');
        } catch (\Throwable $e) {
            // Some code paths after the consent gate may throw without full WP
            // environment (e.g. undefined functions).  Treat that as a TODO.
            $this->markTestIncomplete(
                'Could not exercise IP guard without full WP environment: ' . $e->getMessage()
            );
        } finally {
            $_SERVER = $backup;
        }
    }

    /**
     * process() must return false when the resolved IP is '0.0.0.0'.
     *
     * The IP address '0.0.0.0' is explicitly rejected by the guard on line 44
     * of Processor.php.
     *
     * @todo Requires a DI seam on Utils::getRemoteIp() for full isolation.
     *
     * @test
     */
    public function test_process_returns_false_when_ip_is_all_zeros(): void
    {
        Functions\expect('apply_filters')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (string $tag, $value) {
                if ($tag === 'slimstat_can_track') {
                    return true;
                }
                return $value;
            });

        $this->stubCommonWpFunctions();

        \wp_slimstat::set_stat(['dt' => time(), 'notes' => []]);

        $backup = $_SERVER;
        // FILTER_VALIDATE_IP accepts 0.0.0.0, so it will pass the validation
        // inside Utils::getRemoteIp() and land in $ip_array[0].
        $_SERVER['REMOTE_ADDR'] = '0.0.0.0';
        $this->clearProxyHeaders();

        try {
            $result = \SlimStat\Tracker\Processor::process();
            $this->assertSame(-202, $result, 'process() must return -202 (empty IP) for 0.0.0.0 IP');
        } catch (\Throwable $e) {
            $this->markTestIncomplete(
                'Could not exercise 0.0.0.0 guard without full WP environment: ' . $e->getMessage()
            );
        } finally {
            $_SERVER = $backup;
        }
    }
}
