<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use Brain\Monkey\Functions;

/**
 * Sidebar items for new features show a "New" pill for 15 days after the
 * feature became available on the site, then it disappears. Each window is
 * anchored in its own per-site option (the screen's 'new_badge') the first
 * time the admin menu builds, so existing installs start every countdown on
 * update and the windows run independently. Heatmaps also carries a "Pro" pill
 * ('pro_badge') when Pro is missing. Uninstall cleanup of the anchors is
 * proven at runtime by tests/uninstall-data-safety-test.php. (#20)
 */
class MenuNewBadgeTest extends IntegrationTestCase
{
    /** Screen id => anchor option. Channels lives on Traffic Sources (slimview5). */
    private const OPTIONS = [
        'slimview5'      => 'slimstat_channels_since',
        'slimview6'      => 'slimstat_goals_funnels_since',
        'slimview7'      => 'slimstat_ecommerce_since',
        'slimheatmap'    => 'slimstat_heatmaps_since',
        'slimshortcodes' => 'slimstat_shortcodes_since',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // The badge logic uses DAY_IN_SECONDS; ensure it exists in the harness.
        if (!defined('DAY_IN_SECONDS')) {
            define('DAY_IN_SECONDS', 86400);
        }
        Functions\when('esc_html__')->returnArg(1);
    }

    private static function badge(string $option): string
    {
        $m = new \ReflectionMethod('wp_slimstat_admin', 'new_badge');
        return (string) $m->invoke(null, $option);
    }

    private static function label(array $screen_info, bool $pro_installed): string
    {
        $m = new \ReflectionMethod('wp_slimstat_admin', 'sidebar_label');
        return (string) $m->invoke(null, $screen_info, $pro_installed);
    }

    private static function heatmaps(): array
    {
        return ['title' => 'Heatmaps', 'new_badge' => 'slimstat_heatmaps_since'];
    }

    private static function adminSource(): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/admin/index.php');
        self::assertNotFalse($source, 'Could not read admin/index.php');
        return $source;
    }

    /** The $screens_info entry for $screen, up to the next screen key. */
    private static function screenEntry(string $source, string $screen): string
    {
        $found = preg_match("/^\s+'" . preg_quote($screen, '/') . "'\s*=>\s*\[((?:(?!^\s+'\w+'\s*=>\s*\[\s*$).)*)/ms", $source, $m);
        self::assertSame(1, $found, "$screen is not a key in \$screens_info");
        return $m[1];
    }

    public static function badgedScreens(): array
    {
        $cases = [];
        foreach (self::OPTIONS as $screen => $option) {
            $cases[$screen] = [$option];
        }
        return $cases;
    }

    public function test_the_five_features_and_their_options(): void
    {
        // Pinned literally: a renamed option would restart every running countdown
        // (Goals & Funnels shipped first). $screens_info is built in init(), which
        // this harness does not boot, so the entries are read from source.
        $source = self::adminSource();
        foreach (self::OPTIONS as $screen => $option) {
            $this->assertMatchesRegularExpression("/'new_badge'\s*=>\s*'$option'/", self::screenEntry($source, $screen), "$screen carries $option");
        }
        foreach (['slimemail'] as $screen) {
            $this->assertMatchesRegularExpression("/'pro_badge'\s*=>\s*true/", self::screenEntry($source, $screen), "$screen carries the Pro pill");
        }
        $this->assertSame(count(self::OPTIONS), preg_match_all("/'new_badge'\s*=>/", $source), 'No other screen carries a New pill');
        $this->assertSame(1, preg_match_all("/'pro_badge'\s*=>/", $source), 'No other screen carries a Pro pill');
    }

    /**
     * @dataProvider badgedScreens
     */
    public function test_badge_shows_and_anchors_window_on_first_build(string $option): void
    {
        $this->assertArrayNotHasKey($option, $this->optionStore);

        $badge = self::badge($option);
        $this->assertStringContainsString('New', $badge, 'A fresh install shows the New badge');
        $this->assertStringContainsString('slimstat-menu-new-badge', $badge);
        $this->assertArrayHasKey($option, $this->optionStore, 'The first menu build must anchor the 15-day window');
        $this->assertEqualsWithDelta(time(), $this->optionStore[$option], 2);
        $this->assertSame([$option], array_keys($this->optionStore), 'Only this feature\'s window may be anchored');
    }

    /**
     * @dataProvider badgedScreens
     */
    public function test_badge_shows_within_the_window_and_keeps_its_anchor(string $option): void
    {
        $anchor = time() - (14 * DAY_IN_SECONDS);
        $this->optionStore[$option] = $anchor;
        $this->assertStringContainsString('New', self::badge($option));
        $this->assertSame($anchor, $this->optionStore[$option], 'An anchored window must never be re-anchored');
    }

    /**
     * @dataProvider badgedScreens
     */
    public function test_badge_hidden_once_the_window_elapses(string $option): void
    {
        $this->optionStore[$option] = time() - (15 * DAY_IN_SECONDS);
        $this->assertSame('', self::badge($option), 'The badge disappears on day 15');
        $this->optionStore[$option] = time() - (16 * DAY_IN_SECONDS);
        $this->assertSame('', self::badge($option));
    }

    public function test_windows_run_independently(): void
    {
        // Goals & Funnels shipped months earlier: its window is long over while the
        // four newer features are mid-countdown or not yet anchored.
        $this->optionStore['slimstat_goals_funnels_since'] = time() - (120 * DAY_IN_SECONDS);
        $this->optionStore['slimstat_heatmaps_since']      = time() - (16 * DAY_IN_SECONDS);
        $this->optionStore['slimstat_ecommerce_since']     = time() - (3 * DAY_IN_SECONDS);

        $this->assertSame('', self::badge('slimstat_goals_funnels_since'));
        $this->assertSame('', self::badge('slimstat_heatmaps_since'));
        $this->assertStringContainsString('New', self::badge('slimstat_ecommerce_since'));
        $this->assertStringContainsString('New', self::badge('slimstat_channels_since'));
        $this->assertStringContainsString('New', self::badge('slimstat_shortcodes_since'));
    }

    public function test_each_site_keeps_its_own_windows(): void
    {
        // WordPress scopes get_option()/update_option() to the current site, so the
        // windows stay per-site as long as nothing goes through the network-wide
        // *_site_option() API. Two option stores stand in for two sites.
        Functions\expect('get_site_option')->never();
        Functions\expect('update_site_option')->never();
        Functions\expect('add_site_option')->never();

        $expired = time() - (16 * DAY_IN_SECONDS);
        $this->optionStore = array_fill_keys(self::OPTIONS, $expired);
        foreach (self::OPTIONS as $option) {
            $this->assertSame('', self::badge($option), "Site A: $option window is over");
        }
        $siteA = $this->optionStore;

        $this->optionStore = [];
        foreach (self::OPTIONS as $option) {
            $this->assertStringContainsString('New', self::badge($option), "Site B: $option is new here");
        }

        $this->assertSame(array_fill_keys(self::OPTIONS, $expired), $siteA, 'Site A anchors are untouched');
        $this->assertSame(array_values(self::OPTIONS), array_keys($this->optionStore), 'Site B anchored its own windows');
    }

    public function test_heatmaps_label_carries_new_without_implying_the_whole_page_needs_pro(): void
    {
        $this->assertSame(
            '<span class="slimstat-menu-label">Heatmaps <span class="slimstat-menu-new-badge">New</span></span>',
            self::label(self::heatmaps(), false)
        );
    }

    public function test_heatmaps_label_drops_pro_when_pro_is_installed(): void
    {
        $this->assertSame('<span class="slimstat-menu-label">Heatmaps <span class="slimstat-menu-new-badge">New</span></span>', self::label(self::heatmaps(), true));
    }

    public function test_heatmaps_has_no_pro_badge_after_its_new_window(): void
    {
        $this->optionStore['slimstat_heatmaps_since'] = time() - (16 * DAY_IN_SECONDS);
        $this->assertSame('<span class="slimstat-menu-label">Heatmaps</span>', self::label(self::heatmaps(), false));
        $this->assertSame('<span class="slimstat-menu-label">Heatmaps</span>', self::label(self::heatmaps(), true));
    }

    public function test_email_report_keeps_pro_only_and_goals_funnels_new_only(): void
    {
        $email = ['title' => 'Email Report', 'pro_badge' => true];
        $this->assertSame('<span class="slimstat-menu-label">Email Report <span class="slimstat-menu-pro-badge">Pro</span></span>', self::label($email, false));
        $this->assertSame('<span class="slimstat-menu-label">Email Report</span>', self::label($email, true));
        $this->assertSame(
            '<span class="slimstat-menu-label">Goals &amp; Funnels <span class="slimstat-menu-new-badge">New</span></span>',
            self::label(['title' => 'Goals &amp; Funnels', 'new_badge' => 'slimstat_goals_funnels_since'], false)
        );
    }

    public function test_unbadged_screens_get_a_plain_label_and_write_nothing(): void
    {
        $this->optionStore = [];
        $this->assertSame('<span class="slimstat-menu-label">Overview</span>', self::label(['title' => 'Overview'], false));
        $this->assertSame('<span class="slimstat-menu-label">Overview</span>', self::label(['title' => 'Overview'], true));
        $this->assertSame([], $this->optionStore);
    }

    public function test_sidebar_gets_badged_label_and_page_title_stays_plain(): void
    {
        $source = self::adminSource();
        $this->assertMatchesRegularExpression(
            "/add_submenu_page\(\s*\\\$parent,\s*\\\$a_screen_info\['title'\],\s*self::sidebar_label\(\\\$a_screen_info,\s*\\\$pro_installed\),/s",
            $source,
            'The page title stays plain; only the sidebar label carries the pills'
        );
        $this->assertStringContainsString('#adminmenu .slimstat-menu-new-badge', $source, 'Badge style must be emitted for the global sidebar');
        $this->assertStringContainsString('#adminmenu .slimstat-menu-pro-badge', $source);
    }
}
