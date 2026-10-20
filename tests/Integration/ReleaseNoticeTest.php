<?php
/** @license GPL-2.0-or-later */
declare(strict_types=1);
namespace WpSlimstat\Tests\Integration;
use Brain\Monkey\Functions;

/** The release bar shares per-user dismissal and first exposure across all placements. */
class ReleaseNoticeTest extends IntegrationTestCase
{
    private array $meta = [];
    private array $scripts = [];
    private int $user = 1;
    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
        Functions\when('get_current_user_id')->alias(fn() => $this->user);
        Functions\when('get_user_meta')->alias(fn($id, $key) => $this->meta[$id][$key] ?? '');
        Functions\when('add_user_meta')->alias(function ($id, $key, $value) { if (!isset($this->meta[$id][$key])) { $this->meta[$id][$key] = $value; } return true; });
        Functions\when('update_user_meta')->alias(function ($id, $key, $value) { $this->meta[$id][$key] = $value; return true; });
        Functions\when('wp_enqueue_style')->justReturn(null);
        Functions\when('wp_enqueue_script')->alias(function ($handle) { $this->scripts[] = $handle; });
        Functions\when('wp_localize_script')->justReturn(null);
        Functions\when('get_current_screen')->justReturn((object) ['id' => 'dashboard']);
        Functions\when('get_current_blog_id')->justReturn(1);
        Functions\when('wp_create_nonce')->justReturn('n0nce');
        Functions\when('esc_attr__')->returnArg();
        Functions\when('current_user_can')->alias(fn() => $this->capability);
        \wp_slimstat::$settings['notice_latest_news'] = 'on';
    }
    protected function tearDown(): void
    {
        unset(\wp_slimstat::$settings['notice_latest_news']);
        parent::tearDown();
    }
    private function render(bool $pro = false, ?bool $migration = false, bool $commerce = true, string $placement = 'notice'): string
    {
        ob_start();
        try { \wp_slimstat_admin::show_latest_news($pro, $migration, $commerce, $placement); return (string) ob_get_contents(); }
        finally { ob_end_clean(); }
    }
    public function test_bar_links_and_dismissal_are_accessible_and_attributed(): void
    {
        $html = $this->render();
        foreach (['slimview5', 'slimview7', 'slimheatmap', 'slimshortcodes', 'page=slimpro', 'utm_campaign=whats-new', 'utm_content=notice', 'aria-label="Dismiss SlimStat 6 announcement"', 'data-dismiss-nonce="n0nce"', 'role="status"'] as $value) { $this->assertStringContainsString($value, $html); }
        $this->assertSame(1, substr_count($html, '<aside'));
        $this->assertSame(5, substr_count($html, '<a '));
        $this->assertContains('slimstat-promotions', $this->scripts);
        $this->assertStringNotContainsString('wp-slimstat.com/pricing', $html, 'the release bar is a secondary internal action');
    }
    public function test_woocommerce_and_pro_states_have_appropriate_destinations(): void
    {
        $this->assertStringNotContainsString('slimview7', $this->render(false, false, false));
        $pro = $this->render(true);
        $this->assertStringContainsString('Open Heatmaps', $pro);
        $this->assertStringContainsString('Open Ecommerce', $pro);
        $this->assertStringNotContainsString('page=slimpro', $pro);
        $this->assertStringNotContainsString('Explore Pro', $pro);
        $this->assertStringNotContainsString('in Free', $pro);
    }
    public function test_first_exposure_is_shared_and_never_restarted_by_navigation(): void
    {
        $this->assertSame([], $this->meta);
        $this->render();
        $since = $this->meta[1]['slimstat_release_60_since'];
        $this->assertEqualsWithDelta(time(), $since, 2);
        $this->assertStringContainsString('utm_campaign=banner', $this->render(false, false, true, 'bar'));
        $this->assertSame($since, $this->meta[1]['slimstat_release_60_since']);
    }
    public function test_expires_after_fourteen_days_without_resetting(): void
    {
        $this->meta[1]['slimstat_release_60_since'] = time() - 13 * DAY_IN_SECONDS;
        $this->assertStringContainsString('slimstat-release-bar', $this->render());
        $expired = time() - 14 * DAY_IN_SECONDS;
        $this->meta[1]['slimstat_release_60_since'] = $expired;
        $this->assertSame('', $this->render());
        $this->assertSame('', $this->render(false, false, true, 'bar'));
        $this->assertSame($expired, $this->meta[1]['slimstat_release_60_since']);
    }
    public function test_dismissal_is_per_user_and_shared_between_placements(): void
    {
        $this->meta[1]['slimstat_release_60_dismissed'] = 1;
        $this->assertSame('', $this->render());
        $this->assertSame('', $this->render(false, false, true, 'bar'));
        $this->user = 2;
        $this->assertStringContainsString('slimstat-release-bar', $this->render());
        $this->assertArrayHasKey('slimstat_release_60_since', $this->meta[2]);
        $this->assertArrayNotHasKey('slimstat_release_60_since', $this->meta[1]);
    }
    public function test_disabled_migration_and_permission_states_do_not_start_the_clock(): void
    {
        \wp_slimstat::$settings['notice_latest_news'] = 'no';
        $this->assertSame('', $this->render());
        \wp_slimstat::$settings['notice_latest_news'] = 'on';
        $this->assertSame('', $this->render(false, true));
        $this->capability = false;
        $this->assertSame('', $this->render());
        $this->assertSame([], $this->meta);
        $this->assertSame([], $this->scripts);
    }
    public function test_no_migration_manager_does_not_hide_the_release(): void
    {
        $this->assertNull(\SlimStat\Migration\MigrationService::manager());
        $this->assertStringContainsString('slimstat-release-bar', $this->render(false, null));
    }
    public function test_badges_keep_their_independent_existing_window(): void
    {
        $m = new \ReflectionMethod('wp_slimstat_admin', 'new_badge');
        $this->optionStore['slimstat_test_since'] = time() - 8 * DAY_IN_SECONDS;
        $this->assertSame('', $m->invoke(null, 'slimstat_test_since', 7));
        $this->assertStringContainsString('New', $m->invoke(null, 'slimstat_test_since', 15));
        $this->assertSame(15, $m->getParameters()[1]->getDefaultValue());
    }
    public function test_staged_update_preserves_migration_information_and_adds_only_an_authorized_pro_link(): void
    {
        Functions\when('wp_strip_all_tags')->alias(static fn($value) => strip_tags($value));
        $render = static function ($response) { ob_start(); \wp_slimstat_admin::show_update_message([], $response); return (string) ob_get_clean(); };
        $this->assertSame('', $render((object) []), 'No pending notice means no invented update');
        $offer = (object) ['upgrade_notice' => '<b>Back up before migration.</b> Added columns remain compatible.'];
        $html = $render($offer);
        $this->assertStringContainsString('Back up before migration. Added columns remain compatible.', $html);
        $this->assertStringContainsString('utm_campaign=update-notice', $html);
        $this->capability = false;
        $this->assertStringNotContainsString('<a', $render($offer));
    }
}
