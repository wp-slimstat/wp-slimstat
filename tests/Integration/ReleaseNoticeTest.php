<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use Brain\Monkey\Functions;

/**
 * The 6.0 release notice. Sites updating from 5.x see one dismissible notice on
 * the Dashboard and Plugins screens, one row per new feature, for 7 days, counted from an anchor option
 * written on the first render. It waits while a database migration is owed,
 * carries no purchase link, and new installs never see it (get_fresh_defaults()
 * sets 'no'; the screens and that default are pinned by admin-ui-render-guards-test.php).
 */
class ReleaseNoticeTest extends IntegrationTestCase
{
    private const ANCHOR = 'slimstat_release_notice_since';

    /** [handle, js] of every wp_add_inline_script() call. */
    private array $inline = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('DAY_IN_SECONDS')) {
            define('DAY_IN_SECONDS', 86400);
        }
        Functions\when('wpautop')->returnArg(1);
        Functions\when('wp_kses_post')->returnArg(1);
        Functions\when('admin_url')->alias(static fn($path = '') => 'https://example.test/wp-admin/' . $path);
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('wp_create_nonce')->justReturn('n0nce');
        Functions\when('wp_add_inline_script')->alias(function ($handle, $js) {
            $this->inline[] = [$handle, $js];
            return true;
        });
        \wp_slimstat::$settings['notice_latest_news'] = 'on';
    }

    protected function tearDown(): void
    {
        unset(\wp_slimstat::$settings['notice_latest_news']);
        parent::tearDown();
    }

    private static function render(bool $is_pro = false, ?bool $migration_owed = false, bool $commerce = true): string
    {
        ob_start();
        \wp_slimstat_admin::show_latest_news($is_pro, $migration_owed, $commerce);
        return (string) ob_get_clean();
    }

    /** Every <li> of the what's-new list, as raw HTML. */
    private static function rows(string $html): array
    {
        preg_match_all('#<li>(.*?)</li>#s', $html, $m);
        return $m[1];
    }

    private static function badge(string $option, int $days): string
    {
        $m = new \ReflectionMethod('wp_slimstat_admin', 'new_badge');
        return (string) $m->invoke(null, $option, $days);
    }

    public function test_lists_one_row_per_feature_with_one_link_each(): void
    {
        $html = self::render();

        $this->assertStringContainsString('id="slimstat-notice-latest-news"', $html);
        $this->assertStringContainsString('<strong class="slimstat-release-notice__title">SlimStat 6 is here.</strong>', $html);
        $this->assertStringContainsString('<ul class="slimstat-whats-new">', $html);
        $rows = self::rows($html);
        $this->assertCount(4, $rows, 'Channels, Ecommerce, Heatmaps, Shortcodes');
        foreach ($rows as $row) {
            $this->assertSame(1, substr_count($row, '<a '), "One link per row: $row");
            $this->assertSame(1, substr_count($row, '<span class="dashicons dashicons-'), "One icon per row: $row");
            $this->assertSame(1, substr_count($row, 'aria-hidden="true"'), "The icon is hidden from screen readers: $row");
        }
        foreach (['slimview5' => 'Open Traffic Sources', 'slimview7' => 'Open Ecommerce', 'slimheatmap' => 'Open Heatmaps', 'slimshortcodes' => 'Open Shortcodes', 'slimstat_migration' => 'Review migration'] as $page => $text) {
            $this->assertStringContainsString('page=' . $page . '">' . $text . '</a>', $html);
        }
        $this->assertStringContainsString('Review migration</a>: optional database steps, nothing runs until you choose.', $html);
        $this->assertSame(5, substr_count($html, '<a '), 'Four rows and Review migration, no other link');
        $this->assertStringNotContainsString('Explore SlimStat 6', $html);
    }

    public function test_drops_the_ecommerce_row_without_woocommerce(): void
    {
        $html = self::render(false, false, false);

        $this->assertCount(3, self::rows($html));
        $this->assertStringNotContainsString('slimview7', $html);
        $this->assertStringContainsString('slimstat_migration', $html);
    }

    public function test_prints_the_brand_mark_style_once_with_no_remote_url(): void
    {
        $html = self::render();

        $this->assertSame(1, substr_count($html, '<style>'));
        $this->assertStringContainsString('#slimstat-notice-latest-news::before{', $html);
        $this->assertStringContainsString('.slimstat-whats-new{', $html, 'The rows are styled where tokens.css does not load');
        $this->assertSame(0, preg_match('#url\(\s*["\']?(https?:)?//#', $html), 'The mark is inline: no request leaves the site');
    }

    public function test_dismiss_script_is_added_only_when_the_notice_renders(): void
    {
        self::render(false, true);
        $this->assertSame([], $this->inline, 'Nothing while the notice waits');

        self::render();
        $this->assertCount(1, $this->inline);
        [$handle, $js] = $this->inline[0];
        $this->assertSame('common', $handle);
        $this->assertStringContainsString('{"action":"slimstat_notice_latest_news","security":"n0nce"}', $js);
        $this->assertStringContainsString('#slimstat-notice-latest-news .notice-dismiss', $js);
    }

    public function test_paragraphs_are_separated_by_blank_lines_only(): void
    {
        // show_message() runs wpautop(): a single newline would become <br>.
        $html = self::render();
        $this->assertSame(0, preg_match("/(?<!\n)\n(?!\n)/", $html), 'No lone newline in the notice body');
        $this->assertStringContainsString("\n\n", $html);
    }

    public function test_renders_nothing_once_dismissed(): void
    {
        \wp_slimstat::$settings['notice_latest_news'] = 'no';

        $this->assertSame('', self::render());
        $this->assertArrayNotHasKey(self::ANCHOR, $this->optionStore, 'A dismissed notice never anchors its window');
    }

    public function test_waits_while_a_migration_is_owed_without_anchoring(): void
    {
        $this->assertSame('', self::render(false, true));
        $this->assertArrayNotHasKey(self::ANCHOR, $this->optionStore, 'The window starts only once the notice can show');
    }

    public function test_no_migration_manager_means_no_migration_owed(): void
    {
        // Migrations disabled, or init priority 70 has not built the manager yet.
        $this->assertNull(\SlimStat\Migration\MigrationService::manager());
        $this->assertStringContainsString('slimstat-notice-latest-news', self::render(false, null));
    }

    public function test_first_render_anchors_the_window(): void
    {
        $this->assertArrayNotHasKey(self::ANCHOR, $this->optionStore);
        self::render();
        $this->assertEqualsWithDelta(time(), $this->optionStore[self::ANCHOR], 2);
    }

    public function test_expires_after_7_days(): void
    {
        $this->optionStore[self::ANCHOR] = time() - 6 * DAY_IN_SECONDS;
        $this->assertStringContainsString('slimstat-notice-latest-news', self::render(), 'Day 6: still showing');

        $this->optionStore[self::ANCHOR] = time() - 8 * DAY_IN_SECONDS;
        $this->assertSame('', self::render(), 'Day 8: gone');
    }

    public function test_new_badge_window_length_is_a_parameter(): void
    {
        $this->optionStore['slimstat_test_since'] = time() - 8 * DAY_IN_SECONDS;
        $this->assertSame('', self::badge('slimstat_test_since', 7));
        $this->assertStringContainsString('New', self::badge('slimstat_test_since', 15));

        $m = new \ReflectionMethod('wp_slimstat_admin', 'new_badge');
        $this->assertSame(15, $m->getParameters()[1]->getDefaultValue(), 'Menu pills keep their 15-day window');
    }

    public function test_no_purchase_link_in_either_variant(): void
    {
        foreach ([true, false] as $is_pro) {
            $html = self::render($is_pro);
            $this->assertStringNotContainsString('wp-slimstat.com', $html);
            $this->assertStringNotContainsString('pricing', $html);
            $this->assertStringNotContainsString('page=slimpro', $html);
            $this->assertStringNotContainsString('Upgrade', $html);
        }
    }

    public function test_pro_variant_says_what_pro_3_adds(): void
    {
        $this->assertStringNotContainsString('SlimStat Pro 3.0.0', self::render(false));
        $this->assertStringContainsString('SlimStat Pro 3.0.0 adds heatmap page previews and Ecommerce email reports.', self::render(true));
    }

    public function test_hook_callback_ignores_the_empty_string_core_passes(): void
    {
        // do_action('admin_notices') hands '' to a callback registered with one accepted arg.
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/index.php');
        $this->assertMatchesRegularExpression("/add_action\('admin_notices',\s*\[self::class,\s*'show_latest_news'\],\s*10,\s*0\)/", $source);
    }
}
