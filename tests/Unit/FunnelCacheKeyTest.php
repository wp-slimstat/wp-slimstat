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
 * It ALSO folds in a column-filter signature, so toggling a global report filter
 * (e.g. "browser equals X") produces a new key instead of serving the stale,
 * unfiltered transient — the funnel equivalent of how Goals key on the filter
 * WHERE. The signature participates WITHOUT disturbing the "slimstat_funnel_"
 * prefix that clear_goals_cache() sweeps with a LIKE.
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

    /** Empty-filter signature — the "no global filter applied" baseline. */
    private const NO_FILTERS = 'd751713988987e9331980363e24189ce'; // md5(serialize([]))

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists('wp_slimstat_db', false)) {
            require_once dirname(__DIR__, 2) . '/admin/view/wp-slimstat-db.php';
        }
    }

    /**
     * Invoke the private static helper via reflection.
     *
     * The window and the column filters are set on $filters_normalized rather than
     * passed as arguments: results_cache_key() reads them from there, so driving them
     * any other way would test a path production never takes.
     *
     * @param string $filtersSig Stands in for a distinct set of column filters. It is
     *                           planted in $filters_normalized['columns'] as an opaque
     *                           marker, so two different values still mean "two
     *                           different filter sets" and NO_FILTERS still means none.
     */
    private static function key(array $steps, int $start, int $end, string $filtersSig, $cacheVer): string
    {
        \wp_slimstat_db::$filters_normalized['utime']   = ['start' => $start, 'end' => $end];
        \wp_slimstat_db::$filters_normalized['columns'] = self::NO_FILTERS === $filtersSig
            ? []
            : ['marker' => $filtersSig];

        $m = new \ReflectionMethod(\wp_slimstat_db::class, 'funnel_cache_key');
        $m->setAccessible(true);
        return $m->invoke(null, $steps, $cacheVer);
    }

    /** @test */
    public function test_sub_hour_end_drift_collapses_to_one_key(): void
    {
        // The exact bug: SSR computes end = T, the AJAX twin computes end = T + a few
        // seconds. Same hour bucket → same key → the twin reuses the SSR result.
        $a = self::key(self::$steps, 1000, 1718900000, self::NO_FILTERS, '0');
        $b = self::key(self::$steps, 1000, 1718900005, self::NO_FILTERS, '0');
        $this->assertSame($a, $b, 'Two ends within the same hour must share one cache key');
        $this->assertStringStartsWith('slimstat_funnel_', $a);
    }

    /** @test */
    public function test_a_new_hour_bucket_changes_the_key(): void
    {
        $base = self::key(self::$steps, 1000, 1718900000, self::NO_FILTERS, '0');
        $nextHour = self::key(self::$steps, 1000, 1718900000 + 3600, self::NO_FILTERS, '0');
        $this->assertNotSame($base, $nextHour, 'Crossing the hour bucket must produce a new key');
    }

    /** @test */
    public function test_start_and_cache_version_participate_in_the_key(): void
    {
        $base = self::key(self::$steps, 1000, 1718900000, self::NO_FILTERS, '0');
        $this->assertNotSame($base, self::key(self::$steps, 2000, 1718900000, self::NO_FILTERS, '0'), 'A different start must change the key');
        $this->assertNotSame($base, self::key(self::$steps, 1000, 1718900000, self::NO_FILTERS, '1'), 'A cache-version bump must change the key');
    }

    /** @test */
    public function test_key_is_step_signature_order_sensitive_and_id_independent(): void
    {
        $base = self::key(self::$steps, 1000, 1718900000, self::NO_FILTERS, '0');

        // Different rules → different key.
        $this->assertNotSame($base, self::key([self::$steps[0]], 1000, 1718900000, self::NO_FILTERS, '0'), 'Different steps must change the key');

        // A->B is not B->A: step order is significant.
        $reversed = array_reverse(self::$steps);
        $this->assertNotSame($base, self::key($reversed, 1000, 1718900000, self::NO_FILTERS, '0'), 'Reversed step order must change the key');

        // Only step rules + window + filters feed the key — the funnel id is never an
        // input, so two funnels with identical steps collide on purpose (#19 contract).
        $sameRulesDifferentLabels = [
            ['dimension' => 'resource', 'operator' => 'contains', 'value' => '/', 'name' => 'Home A'],
            ['dimension' => 'resource', 'operator' => 'contains', 'value' => '/pricing', 'name' => 'Pricing A'],
        ];
        $this->assertSame($base, self::key($sameRulesDifferentLabels, 1000, 1718900000, self::NO_FILTERS, '0'), 'Per-step labels must not affect the key');
    }

    /** @test */
    public function test_column_filter_signature_participates_in_the_key(): void
    {
        // The funnel-filter bug: same steps + same window, but a different active
        // report filter MUST produce a different key (otherwise a stale unfiltered
        // transient is served). And the same filter must reuse the key.
        $unfiltered = self::key(self::$steps, 1000, 1718900000, self::NO_FILTERS, '0');
        $filteredA  = self::key(self::$steps, 1000, 1718900000, md5('browser=firefox'), '0');
        $filteredB  = self::key(self::$steps, 1000, 1718900000, md5('browser=chrome'), '0');

        $this->assertNotSame($unfiltered, $filteredA, 'Applying a column filter must change the key');
        $this->assertNotSame($filteredA, $filteredB, 'Different column filters must produce different keys');
        $this->assertSame($filteredA, self::key(self::$steps, 1000, 1718900000, md5('browser=firefox'), '0'), 'The same filter must reuse the key');
    }

    /** @test */
    public function test_filter_signature_differs_within_the_same_hour_bucket(): void
    {
        // Guards that the filter sig is not swallowed by the hour-bucketing: same
        // steps, same start, ends in the SAME hour bucket, different filters → keys differ.
        $a = self::key(self::$steps, 1000, 1718900000, md5('browser=firefox'), '0');
        $b = self::key(self::$steps, 1000, 1718900005, md5('browser=chrome'), '0');
        $this->assertNotSame($a, $b, 'Different filters in the same hour bucket must still differ');
    }

    /** @test */
    public function test_filtered_key_keeps_the_gc_prefix(): void
    {
        // clear_goals_cache() sweeps transients with LIKE 'slimstat_funnel_%'.
        // The filter sig folds into the trailing md5 term, so the prefix is preserved.
        $filtered = self::key(self::$steps, 1000, 1718900000, md5('browser=firefox'), '0');
        $this->assertStringStartsWith('slimstat_funnel_', $filtered);
    }
}
