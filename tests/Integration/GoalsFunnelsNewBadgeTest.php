<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use Brain\Monkey\Functions;

/**
 * The Goals & Funnels sidebar item shows a "New" badge for 15 days after the
 * feature became available on this site, then it disappears. The window is
 * anchored (per-site option slimstat_goals_funnels_since) the first time the
 * admin menu builds, so existing installs start their countdown on update. (#20)
 */
class GoalsFunnelsNewBadgeTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The badge logic uses DAY_IN_SECONDS; ensure it exists in the harness.
        if (!defined('DAY_IN_SECONDS')) {
            define('DAY_IN_SECONDS', 86400);
        }
        Functions\when('esc_html__')->returnArg(1);
    }

    private static function badge(): string
    {
        $m = new \ReflectionMethod('wp_slimstat_admin', 'goals_funnels_new_badge');
        $m->setAccessible(true);
        return (string) $m->invoke(null);
    }

    public function test_badge_shows_and_anchors_window_on_first_build(): void
    {
        $this->assertArrayNotHasKey('slimstat_goals_funnels_since', $this->optionStore);

        $badge = self::badge();
        $this->assertStringContainsString('New', $badge, 'A fresh install shows the New badge');
        $this->assertStringContainsString('slimstat-gf-new-badge', $badge);
        $this->assertArrayHasKey(
            'slimstat_goals_funnels_since',
            $this->optionStore,
            'The first menu build must anchor the 15-day window'
        );
    }

    public function test_badge_shows_within_the_window(): void
    {
        $this->optionStore['slimstat_goals_funnels_since'] = time() - (5 * DAY_IN_SECONDS);
        $this->assertStringContainsString('New', self::badge());
    }

    public function test_badge_hidden_after_the_window(): void
    {
        $this->optionStore['slimstat_goals_funnels_since'] = time() - (16 * DAY_IN_SECONDS);
        $this->assertSame('', self::badge(), 'The badge disappears once the 15-day window elapses');
    }

    public function test_only_the_goals_funnels_item_gets_the_badge(): void
    {
        // Wiring guard: the badge is appended only to the slimview6 sidebar label
        // (not to its page title), and the badge style is emitted globally.
        $admin = file_get_contents(dirname(__DIR__, 2) . '/admin/index.php');
        $this->assertNotFalse($admin, 'Could not read admin/index.php');
        $this->assertMatchesRegularExpression(
            "/'slimview6'\s*===\s*\\\$a_screen_id\s*\)\s*\{\s*\\\$menu_label\s*\.=\s*self::goals_funnels_new_badge\(\);/s",
            $admin,
            'Only the slimview6 menu label appends the New badge'
        );
        $this->assertStringContainsString('#adminmenu .slimstat-gf-new-badge', $admin, 'Badge style must be emitted for the global sidebar');
    }
}
