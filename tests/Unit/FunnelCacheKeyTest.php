<?php
/**
 * Determinism guard for wp_slimstat_db::funnel_cache_key() (#1).
 *
 * Two identical-config funnels showed different numbers (995 vs 991) because the
 * cache key embedded the rendered $date_where SQL, whose live "end = now" carries
 * second precision — so a server-rendered funnel and its AJAX-loaded twin computed
 * seconds apart missed each other's transient and recomputed against live traffic.
 *
 * The key now hour-buckets the date window, so the two share one entry. This pins:
 *   - sub-hour end drift collapses to one key (the actual bug);
 *   - a new hour bucket / different start / cache-version bump → a new key;
 *   - the key is the normalized step signature (order-sensitive), never the funnel id.
 *
 * @package WpSlimstat
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit;

class FunnelCacheKeyTest extends WpSlimstatTestCase
{
    /** @var array<int,array<string,string>> a representative 2-step funnel */
    private static array $steps = [
        ['dimension' => 'resource', 'operator' => 'contains', 'value' => '/'],
        ['dimension' => 'resource', 'operator' => 'contains', 'value' => '/pricing'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists('wp_slimstat_db', false)) {
            require_once dirname(__DIR__, 2) . '/admin/view/wp-slimstat-db.php';
        }
    }

    /** Invoke the private static helper via reflection. */
    private static function key(array $steps, int $start, int $end, $cacheVer): string
    {
        $m = new \ReflectionMethod(\wp_slimstat_db::class, 'funnel_cache_key');
        $m->setAccessible(true);
        return $m->invoke(null, $steps, $start, $end, $cacheVer);
    }

    /** @test */
    public function test_sub_hour_end_drift_collapses_to_one_key(): void
    {
        // The exact bug: SSR computes end = T, the AJAX twin computes end = T + a few
        // seconds. Same hour bucket → same key → the twin reuses the SSR result.
        $a = self::key(self::$steps, 1000, 1718900000, '0');
        $b = self::key(self::$steps, 1000, 1718900005, '0');
        $this->assertSame($a, $b, 'Two ends within the same hour must share one cache key');
        $this->assertStringStartsWith('slimstat_funnel_', $a);
    }

    /** @test */
    public function test_a_new_hour_bucket_changes_the_key(): void
    {
        $base = self::key(self::$steps, 1000, 1718900000, '0');
        $nextHour = self::key(self::$steps, 1000, 1718900000 + 3600, '0');
        $this->assertNotSame($base, $nextHour, 'Crossing the hour bucket must produce a new key');
    }

    /** @test */
    public function test_start_and_cache_version_participate_in_the_key(): void
    {
        $base = self::key(self::$steps, 1000, 1718900000, '0');
        $this->assertNotSame($base, self::key(self::$steps, 2000, 1718900000, '0'), 'A different start must change the key');
        $this->assertNotSame($base, self::key(self::$steps, 1000, 1718900000, '1'), 'A cache-version bump must change the key');
    }

    /** @test */
    public function test_key_is_step_signature_order_sensitive_and_id_independent(): void
    {
        $base = self::key(self::$steps, 1000, 1718900000, '0');

        // Different rules → different key.
        $this->assertNotSame($base, self::key([self::$steps[0]], 1000, 1718900000, '0'), 'Different steps must change the key');

        // A->B is not B->A: step order is significant.
        $reversed = array_reverse(self::$steps);
        $this->assertNotSame($base, self::key($reversed, 1000, 1718900000, '0'), 'Reversed step order must change the key');

        // Only step rules + window feed the key — the funnel id is never an input, so two
        // funnels with identical steps collide on purpose (the #19 contract preserved).
        $sameRulesDifferentLabels = [
            ['dimension' => 'resource', 'operator' => 'contains', 'value' => '/', 'name' => 'Home A'],
            ['dimension' => 'resource', 'operator' => 'contains', 'value' => '/pricing', 'name' => 'Pricing A'],
        ];
        $this->assertSame($base, self::key($sameRulesDifferentLabels, 1000, 1718900000, '0'), 'Per-step labels must not affect the key');
    }
}
