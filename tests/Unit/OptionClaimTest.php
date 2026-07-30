<?php
/**
 * SlimStat\Utils\OptionClaim — single-flight claims on a wp_options row.
 *
 * The two facts this class exists to encode, both learned the hard way:
 *
 *   - `add_option()` is NOT atomic. It does a PHP-level get_option() pre-check and then
 *     INSERT ... ON DUPLICATE KEY UPDATE, which overwrites — so the unique index never
 *     rejects anyone and every concurrent caller believes it won. A lock was shipped
 *     with a docblock asserting the opposite until core was read.
 *   - Which caches to drop depends on the autoload flag. An autoloaded value is served
 *     from the `alloptions` blob, so not dropping that returns pre-write bytes; a
 *     non-autoloaded row is never in the blob, so dropping it there is a needless
 *     cache-wide invalidation. The two hand-rolled copies had already diverged here.
 *
 * @package WpSlimstat
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit;

use Brain\Monkey\Functions;
use SlimStat\Utils\OptionClaim;

class OptionClaimTest extends WpSlimstatTestCase
{
    /** @var string[] "group:key" for each cache invalidation. */
    private array $flushed = [];

    /** @var string[] Statements issued. */
    private array $statements = [];

    /** @var mixed What the next write reports. */
    private $writeResult = 1;

    /** @var mixed */
    private $originalWpdb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->flushed      = [];
        $this->statements   = [];
        $this->writeResult  = 1;
        $this->originalWpdb = $GLOBALS['wpdb'];

        Functions\when('wp_cache_delete')->alias(function ($key, $group = '') {
            $this->flushed[] = $group . ':' . $key;
            return true;
        });

        $wpdb          = \Mockery::mock(\wpdb::class);
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('suppress_errors')->andReturn(false);
        $wpdb->shouldReceive('prepare')->andReturnUsing(
            static fn($sql, ...$args) => $sql . ' -- ' . implode('|', array_map('strval', $args))
        );
        $wpdb->shouldReceive('query')->andReturnUsing(function ($sql) {
            $this->statements[] = $sql;
            return $this->writeResult;
        });

        $GLOBALS['wpdb'] = $wpdb;
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->originalWpdb;
        parent::tearDown();
    }

    // ── insert() ────────────────────────────────────────────────────────────

    /** A bare INSERT, so the unique index can reject the loser. */
    public function test_insert_is_a_bare_insert_not_an_upsert(): void
    {
        $this->assertTrue(OptionClaim::insert('lock', 'v'));

        $this->assertStringContainsString('INSERT INTO', $this->statements[0]);
        $this->assertStringNotContainsString(
            'ON DUPLICATE KEY',
            $this->statements[0],
            'an upsert overwrites, so every concurrent caller wins — which is what add_option() does'
        );
    }

    /** Losing the race is the mechanism working; it must report false. */
    public function test_insert_reports_false_when_the_row_already_exists(): void
    {
        $this->writeResult = false;   // duplicate-key rejection

        $this->assertFalse(OptionClaim::insert('lock', 'v'));
        $this->assertSame([], $this->flushed, 'nothing was written, so nothing is stale');
    }

    // ── compareAndSwap() ────────────────────────────────────────────────────

    public function test_swap_is_conditional_on_the_value_the_caller_read(): void
    {
        $this->assertTrue(OptionClaim::compareAndSwap('k', 'old', 'new'));

        $this->assertMatchesRegularExpression(
            '/WHERE\s+option_name\s*=\s*%s\s+AND\s+option_value\s*=\s*%s/i',
            $this->statements[0],
            'an unconditional UPDATE lets every concurrent caller win'
        );
        $this->assertStringContainsString('old', $this->statements[0]);
    }

    public function test_swap_reports_false_when_someone_else_got_there_first(): void
    {
        $this->writeResult = 0;   // zero rows matched

        $this->assertFalse(OptionClaim::compareAndSwap('k', 'old', 'new'));
        $this->assertSame([], $this->flushed);
    }

    /**
     * A hard error is not a lost race, and conflating them is expensive: on an
     * unwritable database, invalidating alloptions on every attempt turns a fault into
     * a per-request cache rebuild.
     */
    public function test_a_hard_write_error_invalidates_nothing(): void
    {
        $this->writeResult = false;

        $this->assertFalse(OptionClaim::compareAndSwap('k', 'old', 'new', 'yes'));
        $this->assertSame([], $this->flushed);
    }

    // ── invalidation follows the autoload flag ──────────────────────────────

    public function test_an_autoloaded_row_drops_the_alloptions_blob(): void
    {
        OptionClaim::insert('k', 'v', 'yes');

        $this->assertContains('options:alloptions', $this->flushed,
            'an autoloaded value is served from that blob; without this the next read is pre-write');
        $this->assertContains('options:notoptions', $this->flushed);
    }

    public function test_a_non_autoloaded_row_leaves_the_blob_alone(): void
    {
        OptionClaim::insert('k', 'v', 'no');

        $this->assertNotContains('options:alloptions', $this->flushed,
            'the row is never in that blob, so dropping it is a cache-wide invalidation for nothing');
        $this->assertContains('options:notoptions', $this->flushed,
            'a get_option() miss before the write cached the row as non-existent');
    }

    /** The autoload flag reaches the statement, not just the invalidation. */
    public function test_the_autoload_flag_is_written(): void
    {
        OptionClaim::insert('k', 'v', 'yes');

        $this->assertStringContainsString('yes', $this->statements[0]);
    }
}
