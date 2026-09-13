<?php
declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Migration;

use Brain\Monkey\Functions;
use SlimStat\Migration\MigrationInterface;
use SlimStat\Migration\MigrationManager;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

class MigrationRunLockTest extends WpSlimstatTestCase
{
    private $originalWpdb;
    private array $sql = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['slimstat_test_options'] = [];
        Functions\stubs([
            '__'               => static fn($text) => $text,
            'update_option'    => static function ($name, $value) {
                $GLOBALS['slimstat_test_options'][$name] = $value;
                return true;
            },
            'get_transient'    => static fn() => false,
            'set_transient'    => static fn() => true,
            'delete_transient' => static fn() => true,
            'wp_cache_delete'  => static fn() => true,
        ]);
    }

    protected function tearDown(): void
    {
        \wp_slimstat::$wpdb = null;
        if (null === $this->originalWpdb) {
            unset($GLOBALS['wpdb']);
        } else {
            $GLOBALS['wpdb'] = $this->originalWpdb;
        }
        parent::tearDown();
    }

    private function connection($acquire, bool &$owned, int &$retries): \wpdb
    {
        $db = \Mockery::mock(\wpdb::class);
        $db->dbname = 'analytics';
        $db->prefix = 'external_';
        $db->options = 'wp_options';
        $db->reconnect_retries = 5;
        $db->shouldReceive('__get')->andReturnUsing(static function ($name) use (&$retries) {
            return 'dbname' === $name ? 'analytics' : ('reconnect_retries' === $name ? $retries : null);
        });
        $db->shouldReceive('__set')->andReturnUsing(static function ($name, $value) use (&$retries) {
            if ('reconnect_retries' === $name) {
                $retries = (int) $value;
            }
        });
        $db->shouldReceive('prepare')->andReturnUsing(static fn($sql, ...$args) => $sql . ' -- ' . implode('|', $args));
        $db->shouldReceive('suppress_errors')->andReturn(false);
        $db->shouldReceive('query')->andReturnUsing(function ($sql) {
            $this->sql[] = $sql;
            return 1;
        });
        $db->shouldReceive('get_var')->andReturnUsing(function ($sql) use ($acquire, &$owned) {
            $this->sql[] = $sql;
            if (false !== strpos($sql, 'GET_LOCK')) {
                $owned = 1 === $acquire || '1' === $acquire;
                return $acquire;
            }
            if (false !== strpos($sql, 'IS_USED_LOCK')) {
                return $owned ? '1' : '0';
            }
            if (false !== strpos($sql, 'RELEASE_LOCK')) {
                $owned = false;
                return '1';
            }
            return null;
        });

        $core = \Mockery::mock(\wpdb::class);
        $core->prefix = 'wp_';
        $core->options = 'wp_options';
        $core->shouldReceive('prepare')->andReturnUsing(static fn($sql, ...$args) => $sql . ' -- ' . implode('|', $args));
        $core->shouldReceive('suppress_errors')->andReturn(false);
        $core->shouldReceive('query')->andReturnUsing(function ($sql) {
            $this->sql[] = $sql;
            return 1;
        });

        $GLOBALS['wpdb'] = $core;
        \wp_slimstat::$wpdb = $db;
        return $db;
    }

    private function migration(callable $run): MigrationInterface
    {
        return new class($run) implements MigrationInterface {
            private $run;
            public function __construct(callable $run) { $this->run = $run; }
            public function getName(): string { return 'Test'; }
            public function getId(): string { return 'test'; }
            public function getDescription(): string { return 'Test'; }
            public function shouldRun(): bool { return true; }
            public function isOptional(): bool { return false; }
            public function getDiagnostics(): array { return []; }
            public function run(): bool { return ($this->run)(); }
        };
    }

    public function test_session_lock_owns_the_run_and_all_protected_writes(): void
    {
        $owned = false;
        $retries = 5;
        $db = $this->connection('1', $owned, $retries);
        $manager = new MigrationManager();
        $manager->register($this->migration(function () use (&$retries, &$owned) {
            $this->assertTrue($owned);
            $this->assertSame(0, $retries);
            return true;
        }));
        $GLOBALS['slimstat_test_options']['slimstat_migration_run_claim'] = 'stale-observational-hint';

        $this->assertTrue($manager->runOne('test'));
        $this->assertSame(5, $retries);
        $this->assertTrue(get_option('slimstat_migration_status')['test']);
        $this->assertCount(1, array_filter($this->sql, static fn($sql) => false !== strpos($sql, 'RELEASE_LOCK')));
        $this->assertNotEmpty(array_filter($this->sql, static fn($sql) => false !== strpos($sql, 'DELETE FROM') && false !== strpos($sql, 'option_value')));
        $this->assertNotEmpty(array_filter($this->sql, static fn($sql) => false !== strpos($sql, 'wpss_migrate_' . md5('analytics|wp_'))));
    }

    public function test_contention_and_failed_acquisition_are_readable_and_do_not_run(): void
    {
        foreach ([['0', 'already running'], [null, 'Could not acquire']] as [$result, $message]) {
            $owned = false;
            $retries = 5;
            $this->connection($result, $owned, $retries);
            $ran = false;
            $manager = new MigrationManager();
            $manager->register($this->migration(function () use (&$ran) { $ran = true; return true; }));

            $this->assertNull($manager->runOne('test'));
            $this->assertFalse($ran);
            $this->assertStringContainsString($message, $manager->getRunRefusal());
        }
    }

    public function test_lock_loss_aborts_without_status_and_without_reconnect(): void
    {
        $owned = false;
        $retries = 5;
        $this->connection(1, $owned, $retries);
        $manager = new MigrationManager();
        $manager->register($this->migration(function () use (&$owned) {
            $owned = false;
            return true;
        }));

        $this->assertNull($manager->runOne('test'));
        $this->assertArrayNotHasKey('slimstat_migration_status', $GLOBALS['slimstat_test_options']);
        $this->assertSame(5, $retries);
        $this->assertCount(1, array_filter($this->sql, static fn($sql) => false !== strpos($sql, 'RELEASE_LOCK')));
    }

    public function test_general_truthy_lock_result_is_not_ownership(): void
    {
        $owned = false;
        $retries = 5;
        $this->connection(true, $owned, $retries);
        $this->assertNull((new MigrationManager())->runOne('missing'));
    }
}
