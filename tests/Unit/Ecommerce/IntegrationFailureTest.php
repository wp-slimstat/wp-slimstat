<?php
/**
 * B1 (release review 2026-10-13): a failed Ecommerce setup looped the admin back to the same
 * "Set up Ecommerce" button with no reason. These pin the three parts of the fix: the reason is
 * kept, a refused online index build gets one bare retry, and a MyISAM analytics table is named
 * before the foreign key is attempted.
 *
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Ecommerce;

use Brain\Monkey\Functions;
use SlimStat\Ecommerce\Integration;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

class IntegrationFailureTest extends WpSlimstatTestCase
{
    /** @var array<string,mixed> */
    private $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        $this->stubCommonWpFunctions();
        $saved = &$this->saved;
        // get_option() is a real stub (tests/Unit/Tracker/stubs.php), seeded through this store.
        $GLOBALS['slimstat_test_options'][Integration::STATE] = ['version' => 1, 'error' => false];
        Functions\when('update_option')->alias(static function ($name, $value) use (&$saved) {
            $saved[$name] = $value;
            return true;
        });
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['slimstat_test_options'][Integration::STATE]);
        parent::tearDown();
    }

    private static function call(string $method, ...$args)
    {
        $ref = new \ReflectionMethod(Integration::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke(null, ...$args);
    }

    public function test_setup_failure_keeps_the_reason(): void
    {
        self::call('failure', new \RuntimeException('The Ecommerce reporting table could not be created.'));

        self::assertTrue($this->saved[Integration::STATE]['error']);
        self::assertSame('The Ecommerce reporting table could not be created.', $this->saved[Integration::STATE]['error_message']);
    }

    public function test_an_unexpected_error_is_not_shown_verbatim(): void
    {
        self::call('failure', new \TypeError('/var/www/secret/path.php:12'));

        self::assertStringNotContainsString('/var/www', $this->saved[Integration::STATE]['error_message']);
        self::assertStringContainsString('stopped unexpectedly', $this->saved[Integration::STATE]['error_message']);
    }

    public function test_background_failures_leave_the_setup_reason_alone(): void
    {
        self::call('failure');

        self::assertTrue($this->saved[Integration::STATE]['error']);
        self::assertArrayNotHasKey('error_message', $this->saved[Integration::STATE]);
    }

    public function test_a_refused_online_build_is_retried_once_without_the_hint(): void
    {
        $db = \Mockery::mock(\wpdb::class);
        $db->shouldReceive('query')->once()->with('CREATE INDEX x ON t (a) ALGORITHM=INPLACE LOCK=NONE')->andReturn(false);
        $db->shouldReceive('query')->once()->with('CREATE INDEX x ON t (a)')->andReturn(0);

        self::assertTrue(self::call('createIndexOnline', $db, 'CREATE INDEX x ON t (a)'));
    }

    public function test_an_accepted_online_build_is_not_repeated(): void
    {
        $db = \Mockery::mock(\wpdb::class);
        $db->shouldReceive('query')->once()->with('CREATE INDEX x ON t (a) ALGORITHM=INPLACE LOCK=NONE')->andReturn(0);

        self::assertTrue(self::call('createIndexOnline', $db, 'CREATE INDEX x ON t (a)'));
    }

    public function test_both_attempts_failing_reports_failure(): void
    {
        $db = \Mockery::mock(\wpdb::class);
        $db->shouldReceive('query')->twice()->andReturn(false);

        self::assertFalse(self::call('createIndexOnline', $db, 'CREATE INDEX x ON t (a)'));
    }

    /** @return \Mockery\MockInterface */
    private function engineDb(?array $status)
    {
        $db = \Mockery::mock(\wpdb::class);
        $db->shouldReceive('esc_like')->andReturnUsing(static fn($v) => addcslashes($v, '_%\\'));
        $db->shouldReceive('prepare')->with('SHOW TABLE STATUS LIKE %s', 'wp\_slim\_stats')->andReturn('probe');
        $db->shouldReceive('get_row')->once()->with('probe', ARRAY_A)->andReturn($status);
        $db->shouldNotReceive('query');
        return $db;
    }

    public function test_a_myisam_analytics_table_is_named_before_any_ddl(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MyISAM');

        self::call('requireInnoDb', $this->engineDb(['Name' => 'wp_slim_stats', 'Engine' => 'MyISAM']), 'wp_slim_stats');
    }

    public function test_innodb_or_an_unreadable_status_does_not_block_setup(): void
    {
        self::call('requireInnoDb', $this->engineDb(['Name' => 'wp_slim_stats', 'Engine' => 'InnoDB']), 'wp_slim_stats');
        self::call('requireInnoDb', $this->engineDb(null), 'wp_slim_stats');
        $this->addToAssertionCount(1);
    }
}
