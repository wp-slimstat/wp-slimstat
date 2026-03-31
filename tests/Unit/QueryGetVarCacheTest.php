<?php
/**
 * Regression test: getVar() must skip cache when date range overlaps today.
 *
 * Covers the fix for issue #270 — country percentages >100% in Audience Location map
 * caused by getVar() returning stale cached $pageviews while getAll() returned
 * fresh per-country counts via date range splitting.
 *
 * @package WpSlimstat
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use SlimStat\Utils\Query;

class QueryGetVarCacheTest extends WpSlimstatTestCase
{
    private $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wpdb         = Mockery::mock('stdClass');
        $this->wpdb->prefix = 'wp_';

        $GLOBALS['wpdb'] = $this->wpdb;

        Functions\stubs([
            'get_transient'    => false,
            'set_transient'    => true,
            'delete_transient' => true,
        ]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    /**
     * When the date range is entirely in the past, getVar() should use cache.
     */
    public function testGetVarCachesWhenDateRangeInPast(): void
    {
        $yesterday = strtotime('yesterday 00:00:00');
        $twoDaysAgo = strtotime('-2 days 00:00:00');

        // First call: no cache, runs query
        $this->wpdb->shouldReceive('prepare')
            ->andReturnUsing(function () {
                $args = func_get_args();
                $format = str_replace(['%s', '%d'], "'%s'", $args[0]);
                // Query::prepareQuery may pass values as a single array arg
                $values = array_slice($args, 1);
                if (count($values) === 1 && is_array($values[0])) {
                    $values = $values[0];
                }
                return vsprintf($format, $values);
            });
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('42');

        // set_transient should be called (caching the result)
        Functions\expect('set_transient')
            ->once()
            ->andReturn(true);

        $query = Query::select('COUNT(id) as counthits')
            ->from('wp_slim_stats')
            ->where('dt', 'BETWEEN', [$twoDaysAgo, $yesterday])
            ->allowCaching(true);

        $result = $query->getVar();
        $this->assertEquals('42', $result);
    }

    /**
     * When the date range includes today, getVar() must NOT use cache.
     * This prevents stale $pageviews from causing percentages >100%.
     */
    public function testGetVarSkipsCacheWhenDateRangeIncludesToday(): void
    {
        $threeDaysAgo = strtotime('-3 days 00:00:00');
        $now = time();

        $this->wpdb->shouldReceive('prepare')
            ->andReturnUsing(function () {
                $args = func_get_args();
                $format = str_replace(['%s', '%d'], "'%s'", $args[0]);
                $values = array_slice($args, 1);
                if (count($values) === 1 && is_array($values[0])) {
                    $values = $values[0];
                }
                return vsprintf($format, $values);
            });

        // Query should always run (no cache)
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('100');

        // get_transient should NOT be called (cache bypassed)
        Functions\expect('get_transient')->never();

        // set_transient should NOT be called (not caching live data)
        Functions\expect('set_transient')->never();

        $query = Query::select('COUNT(id) as counthits')
            ->from('wp_slim_stats')
            ->where('dt', 'BETWEEN', [$threeDaysAgo, $now])
            ->allowCaching(true);

        $result = $query->getVar();
        $this->assertEquals('100', $result);
    }

    /**
     * When caching is disabled, getVar() should not cache regardless of date range.
     */
    public function testGetVarNoCacheWhenCachingDisabled(): void
    {
        $threeDaysAgo = strtotime('-3 days 00:00:00');
        $yesterday = strtotime('yesterday 23:59:59');

        $this->wpdb->shouldReceive('prepare')
            ->andReturnUsing(function () {
                $args = func_get_args();
                $format = str_replace(['%s', '%d'], "'%s'", $args[0]);
                $values = array_slice($args, 1);
                if (count($values) === 1 && is_array($values[0])) {
                    $values = $values[0];
                }
                return vsprintf($format, $values);
            });

        $this->wpdb->shouldReceive('get_var')->once()->andReturn('50');

        Functions\expect('get_transient')->never();
        Functions\expect('set_transient')->never();

        $query = Query::select('COUNT(id) as counthits')
            ->from('wp_slim_stats')
            ->where('dt', 'BETWEEN', [$threeDaysAgo, $yesterday])
            ->allowCaching(false);

        $result = $query->getVar();
        $this->assertEquals('50', $result);
    }
}
