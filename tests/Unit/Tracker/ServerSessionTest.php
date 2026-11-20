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
        try {
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
            $new = Session::ensureVisitId($force);
            $stat = \wp_slimstat::get_stat();
            self::assertSame($expectedVisit, (int) ($stat['visit_id'] ?? 0));
            self::assertSame(123 === $expectedVisit, $new);
            self::assertSame(123 === $expectedVisit ? 1 : 0, $allocations);
            self::assertCount($expectedProbes, $probes);
            self::assertSame($existingVisit < 0, $expectedProbes > 0 && !isset($stat['vid_hash']), 'An identity column that could not be read must not be written.');
            if ($expectedProbes && $existingVisit >= 0) {
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
            'identity column unreadable' => ['off', 'on', [], null, -1, true, 123, 1],
            'pending token, identity column unreadable' => ['on', 'on', ['sid' => str_repeat('b', 32)], null, -1, true, 123, 1],
            'server enrichment with pending token' => ['off', 'on', ['sid' => str_repeat('b', 32)], null, 91, true, 91, 1],
            'valid cookie wins' => ['off', 'on', [], 42, 91, true, 42, 0],
            'consent upgrade starts cookie session' => ['off', 'on', ['consent_upgrade' => '1'], null, 91, true, 123, 0],
            'client without pending token' => ['on', 'on', [], null, 91, true, 123, 0],
            'client pending token preserved' => ['on', 'on', ['sid' => str_repeat('b', 32)], null, 91, true, 91, 1],
            'unforced server caller unchanged' => ['off', 'on', [], null, 91, false, 0, 0],
        ];
    }

    /** @dataProvider durationProvider */
    public function test_reuse_honors_the_fixed_deadline_and_fails_soft(
        string $extend, ?int $existing, ?int $started, bool $startFails, int $expected, int $queryCount
    ): void {
        $settings = \wp_slimstat::$settings;
        $globalDb = $GLOBALS['wpdb'] ?? null;
        $analyticsDb = \wp_slimstat::$wpdb;
        $db = \Mockery::mock(\wpdb::class);
        $db->prefix = 'wp_';
        $db->last_error = '';
        $db->shouldReceive('flush')->andReturnUsing(static function () use ($db) { $db->last_error = ''; });
        $db->shouldReceive('suppress_errors')->andReturn(false);
        $db->shouldReceive('prepare')->andReturnUsing(static function ($sql, $args) {
            return vsprintf(str_replace(['%s', '%d'], ["'%s'", '%d'], $sql), (array) $args);
        });
        $queries = [];
        $db->shouldReceive('get_var')->andReturnUsing(static function ($sql) use (&$queries, $existing, $started, $startFails, $db) {
            $queries[] = $sql;
            if (0 === strpos($sql, 'SELECT dt ')) {
                $db->last_error = $startFails ? 'Connection lost' : '';
                return $started;
            }
            return $existing;
        });
        try {
            $GLOBALS['wpdb'] = \wp_slimstat::$wpdb = $db;
            \wp_slimstat::$settings = ['session_duration' => 1800, 'extend_session' => $extend];
            $probe = new \ReflectionMethod(Session::class, 'findExistingAnonymousVisitId');
            $probe->setAccessible(true);
            self::assertSame($expected, $probe->invoke(null, str_repeat('a', 32), 1700002400));
            self::assertCount($queryCount, $queries);
            foreach ($queries as $sql) {
                self::assertStringContainsString("vid_hash = UNHEX('" . str_repeat('a', 32) . "')", $sql);
                self::assertStringEndsWith('LIMIT 1', $sql, 'Both lookups must be bounded.');
            }
            if (2 === $queryCount) {
                self::assertStringContainsString("visit_id = '91'", $queries[1]);
                self::assertStringContainsString('ORDER BY dt ASC', $queries[1]);
                self::assertStringNotContainsString('dt >=', $queries[1], 'The session start may be outside the idle window.');
            }
        } finally {
            \wp_slimstat::$settings = $settings;
            $GLOBALS['wpdb'] = $globalDb;
            \wp_slimstat::$wpdb = $analyticsDb;
        }
    }

    public static function durationProvider(): array
    {
        return [
            'fixed expired despite an intermediate hit' => ['off', 91, 1700000000, false, 0, 2],
            'fixed still open' => ['off', 91, 1700001200, false, 91, 2],
            'fixed exact boundary' => ['off', 91, 1700000600, false, 0, 2],
            'legacy no means fixed' => ['no', 91, 1700000000, false, 0, 2],
            'extend uses only the last-hit lookup' => ['on', 91, 1700000000, false, 91, 1],
            'idle timeout needs no start lookup' => ['off', null, null, false, 0, 1],
            'concurrent deletion opens a new visit' => ['off', 91, null, false, 0, 2],
            'start lookup failure drops identity safely' => ['off', 91, null, true, -1, 2],
        ];
    }
}
