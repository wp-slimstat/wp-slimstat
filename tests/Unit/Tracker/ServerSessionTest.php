<?php
declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Tracker;

use SlimStat\Tracker\Session;
use SlimStat\Tracker\Utils;
use SlimStat\Tracker\VisitIdGenerator;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

class ServerSessionTest extends WpSlimstatTestCase
{
    /** @dataProvider sessionProvider */
    public function test_cookieless_server_session(
        string $mode, string $cookies, array $data, ?int $cookieVisit,
        int $existingVisit, bool $force, int $expectedVisit, int $expectedProbes
    ): void {
        $settings = \wp_slimstat::$settings;
        $server = $_SERVER;
        $cookie = $_COOKIE;
        $options = $GLOBALS['slimstat_test_options'] ?? [];
        $this->stubCommonWpFunctions();
        \Brain\Monkey\Functions\stubs(['apply_filters' => static fn($tag, $value) => $value]);
        \wp_slimstat::$settings = array_merge($settings, [
            'anonymous_tracking' => 'off', 'gdpr_enabled' => 'off',
            'javascript_mode' => $mode, 'set_tracker_cookie' => $cookies,
            'consent_integration' => '', 'session_duration' => 1800,
        ]);
        $_SERVER['REMOTE_ADDR'] = '192.0.2.17';
        $_SERVER['HTTP_USER_AGENT'] = 'ExampleBrowser/1.0';
        unset($_COOKIE['slimstat_tracking_code']);
        if (null !== $cookieVisit) {
            $_COOKIE['slimstat_tracking_code'] = Utils::getValueWithChecksum($cookieVisit);
        }
        $GLOBALS['slimstat_test_options']['slimstat_daily_salt'] = ['date' => gmdate('Y-m-d'), 'salt' => 'server-session-test'];
        \wp_slimstat::set_data_js($data);
        \wp_slimstat::set_stat(['dt' => 1700000000, 'fingerprint' => 'enriched-later']);

        class_exists(Session::class);
        class_exists(VisitIdGenerator::class);
        $probes = [];
        \Patchwork\redefine(Session::class . '::findExistingAnonymousVisitId', static function ($hash, $dt) use (&$probes, $existingVisit) {
            $probes[] = [$hash, $dt];
            return $existingVisit;
        });
        $allocations = 0;
        \Patchwork\redefine(VisitIdGenerator::class . '::generateNextVisitId', static function () use (&$allocations) {
            $allocations++;
            return 123;
        });
        $cookieVisits = [];
        \Patchwork\redefine(Session::class . '::setTrackingCookie', static function ($visit) use (&$cookieVisits) {
            $cookieVisits[] = $visit;
            return true;
        });
        try {
            $new = Session::ensureVisitId($force);
            $stat = \wp_slimstat::get_stat();
            self::assertSame($expectedVisit, (int) ($stat['visit_id'] ?? 0));
            self::assertSame(123 === $expectedVisit, $new);
            self::assertSame(123 === $expectedVisit ? 1 : 0, $allocations);
            self::assertCount($expectedProbes, $probes);
            if ($expectedProbes) {
                self::assertSame(1700000000, $probes[0][1]);
                self::assertSame($probes[0][0], $stat['vid_hash']);
                if ('off' === $mode) {
                    self::assertSame(Session::generateAnonymousVidHash([]), $stat['vid_hash'], 'Server identity must survive later JS fingerprint enrichment.');
                } else {
                    self::assertNotSame(Session::generateAnonymousVidHash([]), $stat['vid_hash'], 'Client pending sessions keep their browser token.');
                }
            }
            self::assertSame(null !== $cookieVisit || 0 === $expectedVisit ? [] : [$expectedVisit], $cookieVisits);
        } finally {
            \wp_slimstat::$settings = $settings;
            \wp_slimstat::set_data_js([]);
            \wp_slimstat::set_stat([]);
            $_SERVER = $server;
            $_COOKIE = $cookie;
            $GLOBALS['slimstat_test_options'] = $options;
        }
    }

    public static function sessionProvider(): array
    {
        return [
            'cookies blocked' => ['off', 'on', [], null, 91, true, 91, 1],
            'cookies disabled' => ['off', 'off', [], null, 91, true, 91, 1],
            'first server hit' => ['off', 'on', [], null, 0, true, 123, 1],
            'server enrichment with pending token' => ['off', 'on', ['sid' => str_repeat('b', 32)], null, 91, true, 91, 1],
            'valid cookie wins' => ['off', 'on', [], 42, 91, true, 42, 0],
            'consent upgrade starts cookie session' => ['off', 'on', ['consent_upgrade' => '1'], null, 91, true, 123, 0],
            'client without pending token' => ['on', 'on', [], null, 91, true, 123, 0],
            'client pending token preserved' => ['on', 'on', ['sid' => str_repeat('b', 32)], null, 91, true, 91, 1],
            'unforced server caller unchanged' => ['off', 'on', [], null, 91, false, 0, 0],
        ];
    }
}
