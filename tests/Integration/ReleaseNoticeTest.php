<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use Brain\Monkey\Functions;

/**
 * The 6.0 release notice. Sites updating from 5.x see one dismissible notice: a
 * short form on the Dashboard and Plugins screens (7 days) and a long form with
 * one row per new feature on SlimStat screens (15 days), both counted from one
 * anchor option written on the first render. It waits while a database
 * migration is owed, carries no purchase link, and new installs never see it
 * (get_fresh_defaults() sets 'no'; pinned by admin-ui-render-guards-test.php).
 */
class ReleaseNoticeTest extends IntegrationTestCase
{
    private const ANCHOR = 'slimstat_release_notice_since';

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('DAY_IN_SECONDS')) {
            define('DAY_IN_SECONDS', 86400);
        }
        Functions\when('wpautop')->returnArg(1);
        Functions\when('wp_kses_post')->returnArg(1);
        Functions\when('admin_url')->alias(static fn($path = '') => 'https://example.test/wp-admin/' . $path);
        \wp_slimstat::$settings['notice_latest_news'] = 'on';
    }

    protected function tearDown(): void
    {
        unset(\wp_slimstat::$settings['notice_latest_news']);
        parent::tearDown();
    }

    private static function render(bool $compact, bool $is_pro = false, ?bool $migration_owed = false, bool $commerce = false): string
    {
        ob_start();
        \wp_slimstat_admin::show_latest_news($compact, $is_pro, $migration_owed, $commerce);
        return (string) ob_get_clean();
    }

    private static function badge(string $option, int $days): string
    {
        $m = new \ReflectionMethod('wp_slimstat_admin', 'new_badge');
        return (string) $m->invoke(null, $option, $days);
    }

    /** Every <li> of the what's-new list, as raw HTML. */
    private static function rows(string $html): array
    {
        preg_match_all('#<li>(.*?)</li>#s', $html, $m);
        return $m[1];
    }

    public function test_long_form_lists_one_row_per_feature_with_one_link_each(): void
    {
        $html = self::render(false, false, false, true);

        $this->assertStringContainsString('id="slimstat-notice-latest-news"', $html);
        $this->assertStringContainsString('<ul class="slimstat-whats-new">', $html);
        $rows = self::rows($html);
        $this->assertCount(4, $rows, 'Channels, Ecommerce, Heatmaps, Shortcodes');
        foreach ($rows as $row) {
            $this->assertSame(1, substr_count($row, '<a '), "One link per row: $row");
            $this->assertStringContainsString('<span class="dashicons ', $row);
            $this->assertSame(substr_count($row, 'class="dashicons '), substr_count($row, 'aria-hidden="true"'), 'Every icon is hidden from screen readers');
        }
        foreach (['slimview5', 'slimview7', 'slimheatmap', 'slimshortcodes', 'slimstat_migration'] as $page) {
            $this->assertStringContainsString('page=' . $page . '"', $html, "Links to $page");
        }
        $this->assertStringContainsString('Open Heatmaps', $html);
    }

    public function test_long_form_drops_the_ecommerce_row_without_woocommerce(): void
    {
        $html = self::render(false, false, false, false);

        $this->assertCount(3, self::rows($html));
        $this->assertStringNotContainsString('slimview7', $html);
        $this->assertStringContainsString('slimstat_migration', $html);
    }

    public function test_short_form_offers_explore_and_review_migration_only(): void
    {
        $html = self::render(true, false, false, true);

        $this->assertStringContainsString('id="slimstat-notice-latest-news"', $html);
        $this->assertMatchesRegularExpression('#<a class="button button-primary" href="[^"]*page=slimview2">Explore SlimStat 6</a>#', $html);
        $this->assertStringContainsString('page=slimstat_migration', $html);
        $this->assertStringNotContainsString('slimstat-whats-new', $html);
        foreach (['slimview5', 'slimview7', 'slimheatmap', 'slimshortcodes'] as $page) {
            $this->assertStringNotContainsString($page, $html, "The short form has no $page link");
        }
    }

    public function test_paragraphs_are_separated_by_blank_lines_only(): void
    {
        // show_message() runs wpautop(): a single newline would become <br>.
        foreach ([true, false] as $compact) {
            $html = self::render($compact);
            $this->assertSame(0, preg_match("/(?<!\n)\n(?!\n)/", $html), 'No lone newline in the notice body');
            $this->assertStringContainsString("\n\n", $html);
        }
    }

    public function test_renders_nothing_once_dismissed(): void
    {
        \wp_slimstat::$settings['notice_latest_news'] = 'no';

        $this->assertSame('', self::render(true));
        $this->assertSame('', self::render(false));
        $this->assertArrayNotHasKey(self::ANCHOR, $this->optionStore, 'A dismissed notice never anchors its window');
    }

    public function test_waits_while_a_migration_is_owed_without_anchoring(): void
    {
        $this->assertSame('', self::render(true, false, true));
        $this->assertSame('', self::render(false, false, true));
        $this->assertArrayNotHasKey(self::ANCHOR, $this->optionStore, 'The window starts only once the notice can show');
    }

    public function test_no_migration_manager_means_no_migration_owed(): void
    {
        // Migrations disabled, or init priority 70 has not built the manager yet.
        $this->assertNull(\SlimStat\Migration\MigrationService::manager());
        $this->assertStringContainsString('slimstat-notice-latest-news', self::render(true, false, null));
    }

    public function test_first_render_anchors_the_window(): void
    {
        $this->assertArrayNotHasKey(self::ANCHOR, $this->optionStore);
        self::render(true);
        $this->assertEqualsWithDelta(time(), $this->optionStore[self::ANCHOR], 2);
    }

    public function test_short_form_expires_after_7_days_long_form_after_15(): void
    {
        $this->optionStore[self::ANCHOR] = time() - 8 * DAY_IN_SECONDS;
        $this->assertSame('', self::render(true), 'Day 8: gone from Dashboard and Plugins');
        $this->assertStringContainsString('slimstat-whats-new', self::render(false), 'Day 8: still on SlimStat screens');

        $this->optionStore[self::ANCHOR] = time() - 16 * DAY_IN_SECONDS;
        $this->assertSame('', self::render(true));
        $this->assertSame('', self::render(false), 'Day 16: gone everywhere');
    }

    public function test_new_badge_window_length_is_a_parameter(): void
    {
        $this->optionStore['slimstat_test_since'] = time() - 8 * DAY_IN_SECONDS;
        $this->assertSame('', self::badge('slimstat_test_since', 7));
        $this->assertStringContainsString('New', self::badge('slimstat_test_since', 15));

        $m = new \ReflectionMethod('wp_slimstat_admin', 'new_badge');
        $this->assertSame(15, $m->getParameters()[1]->getDefaultValue(), 'Menu pills keep their 15-day window');
    }

    public function test_no_purchase_link_in_any_variant(): void
    {
        foreach ([true, false] as $compact) {
            foreach ([true, false] as $is_pro) {
                $html = self::render($compact, $is_pro, false, true);
                $this->assertStringNotContainsString('wp-slimstat.com', $html);
                $this->assertStringNotContainsString('pricing', $html);
                $this->assertStringNotContainsString('page=slimpro', $html);
                $this->assertStringNotContainsString('Upgrade', $html);
            }
        }
    }

    public function test_pro_variant_says_what_pro_3_adds(): void
    {
        $free = self::render(false, false);
        $pro  = self::render(false, true);

        $this->assertStringNotContainsString('SlimStat Pro 3.0.0', $free);
        $this->assertStringContainsString('SlimStat Pro 3.0.0 adds heatmap page previews', $pro);
        $this->assertStringContainsString('Open Heatmaps', $pro);
        $this->assertStringContainsString('SlimStat Pro 3.0.0 adds', self::render(true, true));
    }

    public function test_hook_callback_ignores_the_empty_string_core_passes(): void
    {
        // do_action('admin_notices') hands '' to a callback registered with one accepted arg.
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/index.php');
        $this->assertMatchesRegularExpression("/add_action\('admin_notices',\s*\[self::class,\s*'show_latest_news'\],\s*10,\s*0\)/", $source);
    }
}
