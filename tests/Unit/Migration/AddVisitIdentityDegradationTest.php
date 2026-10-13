<?php
/**
 * AddVisitIdentity must retire the "Cookieless visit grouping" notice once the live table
 * has `vid_hash` — and only then (QA R2 #6).
 *
 * The tracker records that notice when its identity probe fails on a table without the
 * column. Nothing cleared it, so for up to DEGRADATION_TTL after the migration the banner
 * said visit counts were inflated "until the add visit identity migration has run" while
 * the Migrations screen said the database was up to date.
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Migration;

use Brain\Monkey\Functions;
use SlimStat\Migration\Migrations\AddVisitIdentity;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

class AddVisitIdentityDegradationTest extends WpSlimstatTestCase
{
    use AddVisitIdentityDouble;

    protected function tearDown(): void
    {
        \wp_slimstat::$degradations = [];
        parent::tearDown();
    }

    /** @test */
    public function test_a_completed_run_clears_the_stale_notice_and_keeps_the_rest(): void
    {
        Functions\when('update_option')->justReturn(true);
        \wp_slimstat::$degradations = ['anonymous visit reuse' => 'stale', 'purge (deleting events)' => 'keep'];

        $this->assertTrue((new AddVisitIdentity($this->addVisitIdentityDb(true, ['PRIMARY', 'idx_vid_hash_dt'])))->run());

        $this->assertSame(['purge (deleting events)' => 'keep'], \wp_slimstat::$degradations);
    }

    /** @test */
    public function test_a_failed_run_keeps_the_notice(): void
    {
        $wpdb = $this->addVisitIdentityDb(false, ['PRIMARY']);
        $wpdb->shouldReceive('query')->andReturn(false);
        \wp_slimstat::$degradations = ['anonymous visit reuse' => 'still true'];

        $this->assertFalse((new AddVisitIdentity($wpdb))->run());

        $this->assertArrayHasKey('anonymous visit reuse', \wp_slimstat::$degradations);
    }
}
