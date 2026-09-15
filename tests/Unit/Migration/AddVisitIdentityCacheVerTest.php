<?php
/**
 * AddVisitIdentity must orphan the goal/funnel/unique-visitor transients when it
 * completes — and only when it completes.
 *
 * Blind review measured the window this closes: those caches are LADDER-BLIND
 * (their keys hash range + filters + version, never the SQL), so answers computed
 * under the degraded identity ladder would otherwise keep serving for up to 15
 * minutes after the column lands. Rotating slimstat_goals_cache_ver — the same
 * option clear_goals_cache() rotates — orphans them the moment the schema is
 * whole. A FAILED run must not rotate: the schema did not change, and the cached
 * answers are still the right answers for the table as it stands.
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Migration;

use Brain\Monkey\Functions;
use Mockery;
use SlimStat\Migration\Migrations\AddVisitIdentity;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

class AddVisitIdentityCacheVerTest extends WpSlimstatTestCase
{
    use AddVisitIdentityDouble;

    /** @test */
    public function test_a_completed_run_rotates_the_goals_cache_version(): void
    {
        Functions\expect('update_option')
            ->once()
            ->with('slimstat_goals_cache_ver', Mockery::type('string'), false)
            ->andReturn(true);

        $this->assertTrue((new AddVisitIdentity($this->addVisitIdentityDb(true, ['PRIMARY', 'idx_vid_hash_dt'])))->run());
    }

    /** @test */
    public function test_a_failed_run_does_not_rotate_the_cache_version(): void
    {
        $wpdb = $this->addVisitIdentityDb(false, ['PRIMARY']);
        // Both the INPLACE attempt and the bare retry refuse: run() must fail.
        $wpdb->shouldReceive('query')->andReturn(false);

        Functions\expect('update_option')->never();

        $this->assertFalse((new AddVisitIdentity($wpdb))->run());
    }
}
