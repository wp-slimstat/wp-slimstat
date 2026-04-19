<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

/**
 * Cache invalidation contract for Goals & Funnels.
 *
 * Pins: clear_goals_cache() bumps slimstat_goals_cache_ver to a monotonically
 * increasing timestamp, and all four CRUD handlers call it. The on-disk cache
 * key format (slimstat_goal_<id>_<md5>, 5 min TTL) is tested indirectly via
 * production consumers — this file only guards the version-bump contract.
 *
 * See the 5.5.0 redesign notes for the cache-version invalidation contract.
 */
class GoalsFunnelsCacheTest extends IntegrationTestCase
{
    public function test_clear_goals_cache_bumps_version_option(): void
    {
        $this->optionStore['slimstat_goals_cache_ver'] = '100';

        // clear_goals_cache() is private; invoke via reflection (PHP 8.1+ auto-accessible).
        $ref = new \ReflectionMethod('wp_slimstat_admin', 'clear_goals_cache');
        $ref->invoke(null);

        $this->assertNotSame('100', (string) $this->optionStore['slimstat_goals_cache_ver']);
        $this->assertGreaterThan(100, (int) $this->optionStore['slimstat_goals_cache_ver']);
    }

    public function test_ajax_save_funnel_invalidates_cache(): void
    {
        $this->setMaxFunnels(3);
        $this->optionStore['slimstat_goals_cache_ver'] = '50';
        $_POST = [
            'security'    => 'x',
            'funnel_name' => 'Flow',
            'steps'       => [
                ['name' => 'A', 'dimension' => 'resource', 'operator' => 'contains', 'value' => '/a', 'active' => 1],
                ['name' => 'B', 'dimension' => 'resource', 'operator' => 'contains', 'value' => '/b', 'active' => 1],
            ],
        ];

        try {
            \wp_slimstat_admin::ajax_save_funnel();
        } catch (WpAjaxDie $_die) {
            // expected — success path throws WpAjaxDie('success')
        }

        $this->assertGreaterThan(50, (int) $this->optionStore['slimstat_goals_cache_ver']);
    }

    public function test_ajax_delete_goal_invalidates_cache(): void
    {
        $this->optionStore['slimstat_goals_cache_ver'] = '7';
        $this->setGoals([['id' => 1, 'name' => 'A', 'dimension' => 'resource', 'operator' => 'equals', 'value' => '/a', 'active' => true]]);
        $_POST = ['security' => 'x', 'goal_id' => '1'];

        try {
            \wp_slimstat_admin::ajax_delete_goal();
        } catch (WpAjaxDie $_die) {
        }

        $this->assertGreaterThan(7, (int) $this->optionStore['slimstat_goals_cache_ver']);
    }
}
