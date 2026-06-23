<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use Brain\Monkey\Functions;

/**
 * Fix 2 — behavioural guard for the compact Funnels widget.
 *
 * Drives the real widget entry point wp_slimstat_reports::show_funnels() with
 * is_widget=true (the dashboard-widget / shortcode path), backed by the option
 * store + the FakeWpSlimstatDb double, and asserts the rendered HTML contains
 * EVERY configured funnel (with a tab strip + per-funnel panels) and that the
 * numbers come straight from get_funnel_results() — i.e. the widget reads the
 * same data source the main slimview6 page does.
 *
 * The exact markup contract is unit-tested in tests/funnels-widget-compact-test.php;
 * this proves the public widget branch wires option lookup + Pro gate + DB into
 * that renderer correctly.
 */
class FunnelsWidgetMultiTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Install the DB double the renderer calls (one source of funnel data).
        if (!class_exists('wp_slimstat_db', false)) {
            require_once __DIR__ . '/FakeWpSlimstatDb.php';
            class_alias(FakeWpSlimstatDb::class, 'wp_slimstat_db');
        }

        // Load the reports class (defines wp_slimstat_reports) once per process.
        if (!class_exists('wp_slimstat_reports', false)) {
            require_once dirname(__DIR__, 2) . '/admin/view/wp-slimstat-reports.php';
        }

        // Widget-path helpers not covered by the base stubs.
        Functions\when('wp_doing_ajax')->justReturn(false);
        Functions\when('esc_attr__')->returnArg(1);
    }

    private function renderWidget(): string
    {
        ob_start();
        \wp_slimstat_reports::show_funnels(['is_widget' => true]);
        return (string) ob_get_clean();
    }

    public function test_widget_renders_all_funnels_with_a_tab_strip(): void
    {
        $this->setMaxFunnels(3); // Pro unlocked
        $this->setFunnels([
            ['id' => 1, 'name' => 'Checkout flow', 'steps' => [['name' => 'Home'], ['name' => 'Pricing']]],
            ['id' => 2, 'name' => 'Signup flow',   'steps' => [['name' => 'Home'], ['name' => 'Trial']]],
        ]);
        FakeWpSlimstatDb::$next = [
            ['name' => 'Home',    'visitors' => 200, 'pct' => 100],
            ['name' => 'Pricing', 'visitors' => 34,  'pct' => 17.0],
        ];

        $html = $this->renderWidget();

        $this->assertSame(2, substr_count($html, 'class="slimstat-funnel-chart"'), 'Both funnels must render a panel.');
        $this->assertSame(2, substr_count($html, '<button type="button" class="slimstat-funnel-wtab'), 'Both funnels must get a tab button.');
        $this->assertStringContainsString('Checkout flow', $html);
        $this->assertStringContainsString('Signup flow', $html);
        $this->assertStringNotContainsString('slimstat-gf-tab', $html, 'Widget must not reuse the main-page tab class.');
    }

    public function test_widget_numbers_come_from_get_funnel_results(): void
    {
        $this->setMaxFunnels(3);
        $this->setFunnels([
            ['id' => 1, 'name' => 'Only', 'steps' => [['name' => 'A'], ['name' => 'B']]],
        ]);
        FakeWpSlimstatDb::$next = [
            ['name' => 'A', 'visitors' => 500, 'pct' => 100],
            ['name' => 'B', 'visitors' => 90,  'pct' => 18.0],
        ];

        $html = $this->renderWidget();

        // Values are the DB double's, proving the widget reads the shared source.
        $this->assertStringContainsString('18% conversion rate', $html);
        $this->assertStringContainsString('500', $html);
        $this->assertStringContainsString('90 (18%)', $html);
        // Single funnel => no tab strip.
        $this->assertStringNotContainsString('slimstat-funnel-wtab', $html);
    }

    public function test_tab_strip_is_accessible_and_zero_steps_are_muted(): void
    {
        $this->setMaxFunnels(3);
        $this->setFunnels([
            ['id' => 1, 'name' => 'Drop',  'steps' => [['name' => 'A'], ['name' => 'B']]],
            ['id' => 2, 'name' => 'Other', 'steps' => [['name' => 'A'], ['name' => 'B']]],
        ]);
        // Step 1 has visitors, step 2 drops to zero — the realistic drop-off case.
        FakeWpSlimstatDb::$next = [
            ['name' => 'A', 'visitors' => 100, 'pct' => 100],
            ['name' => 'B', 'visitors' => 0,   'pct' => 0],
        ];

        $html = $this->renderWidget();

        // Tab strip a11y (matches the main page's roles).
        $this->assertStringContainsString('role="tablist"', $html);
        $this->assertStringContainsString('role="tab"', $html);
        $this->assertStringContainsString('aria-selected="true"', $html);
        $this->assertStringContainsString('role="tabpanel"', $html);
        // The zero step is muted (data-zero), the populated step is not.
        $this->assertStringContainsString('slimstat-funnel-bar-fill" data-zero', $html);
    }

    public function test_free_tier_shows_locked_upsell_not_funnels(): void
    {
        $this->setMaxFunnels(0); // Free
        $this->setFunnels([
            ['id' => 1, 'name' => 'Hidden', 'steps' => [['name' => 'A'], ['name' => 'B']]],
        ]);

        $html = $this->renderWidget();

        $this->assertStringContainsString('slimstat-funnel--locked', $html);
        $this->assertStringNotContainsString('Hidden', $html, 'Free tier must not leak configured funnel data.');
    }
}
