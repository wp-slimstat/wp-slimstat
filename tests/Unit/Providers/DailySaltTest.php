<?php
/**
 * The daily IP-hash salt must be minted once per UTC day, by one request (W5).
 *
 * The salt is what makes a hashed IP correlatable within a day and not across days,
 * so every request in a day has to agree on it. It was minted by an unlocked
 * read-check-write:
 *
 *     $salt = get_option('slimstat_daily_salt');
 *     $date = get_option('slimstat_daily_salt_date');
 *     if ($date !== $today || empty($salt)) {
 *         update_option('slimstat_daily_salt', wp_generate_password(32, false));
 *         update_option('slimstat_daily_salt_date', $today);
 *     }
 *
 * Two failures, and this runs on EVERY page load (wp-slimstat.php:1016), so the
 * window is hit by whatever traffic arrives at UTC midnight:
 *
 *   - Two concurrent requests both read yesterday's date, both mint, both write. The
 *     day's hashes split into two populations that cannot be correlated with each
 *     other — the exact property the salt exists to provide.
 *   - The salt and its date were two separate options, so a crash or timeout between
 *     the two writes left a salt stamped with yesterday, and the next request minted
 *     again. A torn write is not representable once they are one value.
 *
 * NOT a performance fix. The cost model originally offered for this — that the two
 * daily writes force a full alloptions re-read — is false: wp_cache_get()'s $force
 * parameter is documented "Unused." in core and the body never reads it. The salt
 * stays autoloaded deliberately, because it is read on every request.
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Providers;

use Brain\Monkey\Functions;
use SlimStat\Providers\IPHashProvider;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

class DailySaltTest extends WpSlimstatTestCase
{
    /** @var array<string,mixed> Stand-in for the options table. */
    private array $options = [];

    /** @var array<int,array{sql:string,args:array}> Raw statements the claim issued. */
    private array $statements = [];

    /** @var callable What a raw write does and reports. 0 rows = lost the race. */
    private $onQuery;

    /** @var string[] Cache groups/keys invalidated during the case. */
    private array $cacheDeletes = [];

    /** @var mixed The global handle as it stood before this case. */
    private $originalWpdb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->options      = [];
        $this->statements   = [];
        $this->onQuery      = static fn() => 1;
        $this->cacheDeletes = [];
        $this->originalWpdb = $GLOBALS['wpdb'];

        // get_option() is declared as a real function in tests/Unit/Tracker/stubs.php,
        // so it cannot be Brain-Monkeyed; it reads this store instead.
        $GLOBALS['slimstat_test_options'] = &$this->options;
        Functions\when('wp_cache_delete')->alias(function ($key, $group = '') {
            $this->cacheDeletes[] = $group . ':' . $key;
            return true;
        });
        Functions\when('maybe_serialize')->alias(
            static fn($v) => (is_array($v) || is_object($v)) ? serialize($v) : $v
        );

        // Distinct per call, so "both requests minted their own" is visible.
        $minted = 0;
        Functions\when('wp_generate_password')->alias(function () use (&$minted) {
            return 'minted-salt-' . (++$minted);
        });

        $wpdb          = \Mockery::mock(\wpdb::class);
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('suppress_errors')->andReturn(false);
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($sql, ...$args) {
            return $sql . ' -- ' . implode('|', array_map('strval', $args));
        });
        $wpdb->shouldReceive('query')->andReturnUsing(function ($sql) {
            $this->statements[] = $sql;
            return ($this->onQuery)();
        });

        $GLOBALS['wpdb'] = $wpdb;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['slimstat_test_options']);
        $GLOBALS['wpdb'] = $this->originalWpdb;
        parent::tearDown();
    }

    private function today(): string
    {
        return gmdate('Y-m-d');
    }

    // ── The race ────────────────────────────────────────────────────────────

    /**
     * THE DEFECT. Two requests cross UTC midnight together. Exactly one may win, and
     * the loser must adopt the winner's salt — not keep its own.
     */
    public function test_a_request_that_loses_the_mint_race_adopts_the_winners_salt(): void
    {
        $this->options['slimstat_daily_salt'] = ['date' => '2020-01-01', 'salt' => 'yesterday'];

        // The conditional write matches zero rows: someone else already swapped it.
        $this->onQuery = function () {
            $this->options['slimstat_daily_salt'] = ['date' => $this->today(), 'salt' => 'winner-salt'];
            return 0;
        };

        $this->assertSame(
            'winner-salt',
            IPHashProvider::generateDailySalt(),
            'Every request in a day must agree on the salt, or the day\'s hashes split in two'
        );
    }

    /**
     * The write is a compare-and-swap on what this request actually read, not a
     * blind overwrite — that is what makes exactly one winner possible.
     */
    public function test_the_mint_is_a_conditional_write_against_the_value_it_read(): void
    {
        $this->options['slimstat_daily_salt'] = ['date' => '2020-01-01', 'salt' => 'yesterday'];

        IPHashProvider::generateDailySalt();

        $this->assertNotEmpty($this->statements, 'the salt was replaced without a raw statement');
        $sql = $this->statements[0];

        $this->assertStringContainsString('UPDATE', $sql);
        $this->assertMatchesRegularExpression(
            '/WHERE\s+option_name\s*=\s*%s\s+AND\s+option_value\s*=\s*%s/i',
            $sql,
            'an unconditional UPDATE lets every concurrent request win, which is the defect'
        );
        $this->assertStringContainsString(
            serialize(['date' => '2020-01-01', 'salt' => 'yesterday']),
            $sql,
            'the swap must be conditional on the exact value this request read'
        );
    }

    /**
     * First ever mint: there is no row to compare against, so it is a bare INSERT and
     * the unique index picks the winner. add_option() cannot do this — it does a
     * PHP-level get_option() pre-check and then INSERT ... ON DUPLICATE KEY UPDATE,
     * which overwrites, so the index never rejects anyone.
     */
    public function test_the_first_ever_mint_is_a_bare_insert(): void
    {
        $salt = IPHashProvider::generateDailySalt();

        $this->assertNotEmpty($this->statements);
        $this->assertStringContainsString('INSERT INTO', $this->statements[0]);
        $this->assertStringNotContainsString('ON DUPLICATE KEY', $this->statements[0]);
        $this->assertSame('minted-salt-1', $salt);
    }

    // ── One value, so a torn write cannot happen ────────────────────────────

    /**
     * The written row's shape: one option, one write, autoloaded. A separate date
     * option could be lost between the two writes, stamping a salt with yesterday.
     */
    public function test_the_mint_writes_one_autoloaded_row_holding_both_facts(): void
    {
        IPHashProvider::generateDailySalt();

        $this->assertCount(1, $this->statements, 'two writes can tear; one cannot');
        $written = $this->statements[0];

        $this->assertStringContainsString('slimstat_daily_salt', $written);
        $this->assertStringContainsString($this->today(), $written);
        $this->assertMatchesRegularExpression(
            "/VALUES\s*\(%s,\s*%s,\s*'yes'\)/i",
            $written,
            'wp-slimstat.php calls this on every page load; a non-autoloaded row costs a SELECT per request'
        );
    }

    // ── Steady state ────────────────────────────────────────────────────────

    public function test_an_existing_salt_for_today_is_reused_without_writing(): void
    {
        $this->options['slimstat_daily_salt'] = ['date' => $this->today(), 'salt' => 'todays-salt'];

        $this->assertSame('todays-salt', IPHashProvider::generateDailySalt());
        $this->assertSame([], $this->statements, 'the steady-state path must not write at all');
    }

    public function test_get_daily_salt_returns_todays_salt_and_never_mints(): void
    {
        $this->options['slimstat_daily_salt'] = ['date' => $this->today(), 'salt' => 'todays-salt'];
        $this->assertSame('todays-salt', IPHashProvider::getDailySalt());

        $this->options['slimstat_daily_salt'] = ['date' => '2020-01-01', 'salt' => 'stale'];
        $this->assertSame('', IPHashProvider::getDailySalt(), 'a stale salt must not be handed out as current');
        $this->assertSame([], $this->statements, 'getDailySalt() is a reader');
    }

    /**
     * A write that HARD-ERRORED is not a lost race, and the difference is expensive.
     * On a read-only replica or a full disk the write can never land, and this runs on
     * every page load — so flushing alloptions here would make every request pay a full
     * rebuild (measured at 2.57 ms on this install) for as long as the fault lasts.
     * Nobody swapped the row, so nothing is stale and there is nothing to re-read.
     */
    public function test_a_hard_write_error_does_not_invalidate_caches_or_re_read(): void
    {
        $this->onQuery = static fn() => false;   // wpdb reports a failed query

        $salt = IPHashProvider::generateDailySalt();

        $this->assertSame(
            [],
            $this->cacheDeletes,
            'nothing was written, so nothing is stale — flushing alloptions here costs every request'
        );
        $this->assertNotSame('', $salt, 'the request still needs a salt to hash with');
    }

    /** A successful mint DOES invalidate, including the autoloaded blob it lives in. */
    public function test_a_successful_mint_invalidates_the_autoloaded_blob(): void
    {
        IPHashProvider::generateDailySalt();

        $this->assertContains('options:alloptions', $this->cacheDeletes,
            'the row is autoloaded, so the stale copy lives in alloptions');
        $this->assertContains('options:notoptions', $this->cacheDeletes,
            'the pre-write miss cached its non-existence');
    }

    // ── The upgrade window ──────────────────────────────────────────────────

    /**
     * An install upgrading mid-day still holds the legacy string plus its separate
     * date option. Rotating on sight would re-hash every visitor halfway through the
     * day — splitting the day exactly as the race does. The window closes by itself
     * at the next UTC midnight; it is a date comparison, never a version_compare.
     */
    public function test_a_legacy_salt_from_today_is_honoured_rather_than_rotated(): void
    {
        $this->options['slimstat_daily_salt']      = 'legacy-string-salt';
        $this->options['slimstat_daily_salt_date'] = $this->today();

        $this->assertSame('legacy-string-salt', IPHashProvider::generateDailySalt());
        $this->assertSame([], $this->statements, 'upgrading must not rotate the salt mid-day');
        $this->assertSame('legacy-string-salt', IPHashProvider::getDailySalt());
    }

    /**
     * Once the legacy pair has been superseded, its orphaned date option is removed.
     * It is autoloaded and nothing reads it afterwards, so leaving it behind would
     * park an unexplained row in alloptions on every upgraded install forever.
     */
    public function test_superseding_a_legacy_salt_retires_its_companion_option(): void
    {
        $this->options['slimstat_daily_salt']      = 'legacy-string-salt';
        $this->options['slimstat_daily_salt_date'] = '2020-01-01';

        IPHashProvider::generateDailySalt();

        $this->assertArrayNotHasKey(
            'slimstat_daily_salt_date',
            $this->options,
            'the legacy companion is orphaned the moment the new shape is written'
        );
    }

    /** Yesterday's legacy salt is not honoured — the window is one day, not forever. */
    public function test_a_legacy_salt_from_a_previous_day_is_replaced(): void
    {
        $this->options['slimstat_daily_salt']      = 'legacy-string-salt';
        $this->options['slimstat_daily_salt_date'] = '2020-01-01';

        $this->assertSame('minted-salt-1', IPHashProvider::generateDailySalt());
        $this->assertNotEmpty($this->statements);
    }
}
