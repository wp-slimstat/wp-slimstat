<?php
/**
 * Portable unit tests for the #298 server-side filter search/cache pure logic in
 * wp_slimstat_admin::get_filter_options() — extracted into private static helpers
 * so the SQL/cache behavior is guarded by a deterministic CI gate (composer
 * test:unit) instead of only the live-DB Playwright spec.
 *
 * Covers:
 *  - filter_search_is_substring(): the %needle% vs needle% anchor decision;
 *  - build_filter_search_like(): esc_like escaping + correct anchoring;
 *  - build_filter_options_cache_key(): every result-set-affecting input changes
 *    the key, identical inputs collide, and the time range is hour-bucketed;
 *  - filter_options_cache_ttl(): historical ranges cache longer than live ones.
 *
 * @package WpSlimstat
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit;

use Mockery;

class FilterOptionsSearchTest extends WpSlimstatTestCase
{
    /** @var array<string,mixed> snapshot of wp_slimstat::$settings to restore */
    private $settingsBackup;
    /** @var object|null snapshot of wp_slimstat::$wpdb to restore */
    private $wpdbBackup;

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists('wp_slimstat_admin', false)) {
            require_once dirname(__DIR__, 2) . '/admin/index.php';
        }

        $this->settingsBackup = \wp_slimstat::$settings;
        $this->wpdbBackup      = \wp_slimstat::$wpdb;

        // esc_like models WP wpdb::esc_like(): backslash-escape _ % and \.
        $wpdb = Mockery::mock('wpdb');
        $wpdb->shouldReceive('esc_like')->andReturnUsing(
            static fn(string $text): string => addcslashes($text, '_%\\')
        );
        $wpdb->dbhost = 'localhost';
        \wp_slimstat::$wpdb = $wpdb;
    }

    protected function tearDown(): void
    {
        \wp_slimstat::$settings = $this->settingsBackup;
        \wp_slimstat::$wpdb      = $this->wpdbBackup;
        parent::tearDown();
    }

    /** Invoke a private static method of wp_slimstat_admin via reflection. */
    private static function call(string $method, ...$args)
    {
        $m = new \ReflectionMethod(\wp_slimstat_admin::class, $method);
        $m->setAccessible(true);
        return $m->invoke(null, ...$args);
    }

    // ── Anchor decision: substring (%needle%) vs prefix (needle%) ─────

    /** @test */
    public function test_substring_dimensions_use_unanchored_like(): void
    {
        foreach (['notes', 'searchterms', 'content_type', 'category', 'author', 'outbound_resource', 'user_agent'] as $dim) {
            $this->assertTrue(self::call('filter_search_is_substring', $dim), "$dim should use %needle% substring search");
        }
    }

    /** @test */
    public function test_other_dimensions_use_left_anchored_prefix(): void
    {
        foreach (['ip', 'browser', 'resource', 'referer', 'country'] as $dim) {
            $this->assertFalse(self::call('filter_search_is_substring', $dim), "$dim should use left-anchored prefix search");
        }
    }

    // ── LIKE pattern: esc_like + anchoring ───────────────────────────

    /** @test */
    public function test_prefix_like_pattern_escapes_metacharacters(): void
    {
        // ip is a prefix dimension; % and _ in the term must be escaped literally.
        $this->assertSame('a\%b\_c%', self::call('build_filter_search_like', 'ip', 'a%b_c'));
    }

    /** @test */
    public function test_substring_like_pattern_wraps_and_escapes(): void
    {
        $this->assertSame('%Mozilla\_5%', self::call('build_filter_search_like', 'user_agent', 'Mozilla_5'));
    }

    // ── Cache key composition ────────────────────────────────────────

    /** @test */
    public function test_cache_key_is_stable_for_identical_inputs(): void
    {
        $a = self::call('build_filter_options_cache_key', 'ip', 1000, 2000, 'foo', 500);
        $b = self::call('build_filter_options_cache_key', 'ip', 1000, 2000, 'foo', 500);
        $this->assertSame($a, $b);
        $this->assertStringStartsWith('fopts_', $a);
    }

    /**
     * @test
     * @dataProvider cacheKeyDifferentiatorProvider
     */
    public function test_cache_key_differs_when_an_input_changes(array $args): void
    {
        $base = self::call('build_filter_options_cache_key', 'ip', 1000, 2000, 'foo', 500);
        $this->assertNotSame($base, self::call('build_filter_options_cache_key', ...$args));
    }

    public static function cacheKeyDifferentiatorProvider(): array
    {
        return [
            'dimension' => [['referer', 1000, 2000, 'foo', 500]],
            'search'    => [['ip', 1000, 2000, 'bar', 500]],
            'limit'     => [['ip', 1000, 2000, 'foo', 1000]],
            // 9000 lands in a different hour bucket than 2000 (3600s buckets).
            'time_end_hour' => [['ip', 1000, 9000, 'foo', 500]],
        ];
    }

    /** @test */
    public function test_cache_key_buckets_time_range_by_hour(): void
    {
        // Two end timestamps within the same hour bucket collide; the search is the
        // same so the result set is identical — caching them together is correct.
        $within = self::call('build_filter_options_cache_key', 'ip', 0, 100, 'foo', 500);
        $sameHr = self::call('build_filter_options_cache_key', 'ip', 0, 3599, 'foo', 500);
        $this->assertSame($within, $sameHr);
    }

    /** @test */
    public function test_cache_key_reflects_capability_gate(): void
    {
        \wp_slimstat::$settings['can_view'] = 'alice';
        $a = self::call('build_filter_options_cache_key', 'ip', 1000, 2000, 'foo', 500);
        \wp_slimstat::$settings['can_view'] = 'bob';
        $b = self::call('build_filter_options_cache_key', 'ip', 1000, 2000, 'foo', 500);
        $this->assertNotSame($a, $b, 'different visibility gate must not share a cache entry');
    }

    // ── TTL bucketing: historical vs live ────────────────────────────

    /** @test */
    public function test_ttl_is_longer_for_historical_ranges(): void
    {
        $this->assertSame(3600, self::call('filter_options_cache_ttl', time() - 7200));
    }

    /** @test */
    public function test_ttl_is_short_for_live_ranges(): void
    {
        $this->assertSame(300, self::call('filter_options_cache_ttl', time()));
    }

    /** @test */
    public function test_ttl_defaults_short_when_range_is_open_ended(): void
    {
        $this->assertSame(300, self::call('filter_options_cache_ttl', null));
    }
}
