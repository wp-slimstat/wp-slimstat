<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Guards the "General" landing page registration: it must be the FIRST entry
 * in $screens_info so add_menus() picks it as the top-level parent slug, and
 * it must declare a real callback so WordPress has something to render.
 *
 * admin/index.php registers hooks on load, so — same as
 * GoalsFunnelsReportPlacementTest — this asserts against the raw source
 * rather than requiring the file.
 */
class GeneralScreenRegistrationTest extends TestCase
{
    public function test_slimgeneral_is_registered_with_expected_shape(): void
    {
        $php = file_get_contents($this->indexPath());

        $this->assertMatchesRegularExpression(
            "/'slimgeneral'\s*=>\s*\[[\s\S]*?'show_in_sidebar'\s*=>\s*true[\s\S]*?\]/",
            $php,
            'slimgeneral must be registered and visible in the sidebar'
        );

        $this->assertMatchesRegularExpression(
            "/'slimgeneral'\s*=>\s*\[[\s\S]*?'callback'\s*=>\s*\[self::class,\s*'wp_slimstat_include_general'\]/",
            $php,
            'slimgeneral must point at its own render callback'
        );
    }

    public function test_slimgeneral_is_the_first_screen(): void
    {
        $php = file_get_contents($this->indexPath());

        $screensInfoStart = strpos($php, 'self::$screens_info = [');
        $this->assertNotFalse($screensInfoStart, 'Could not locate $screens_info array literal');

        $firstKeyPos    = strpos($php, "'slimgeneral'", $screensInfoStart);
        $otherKeyPos    = strpos($php, "'slimview1'", $screensInfoStart);

        $this->assertNotFalse($firstKeyPos, 'slimgeneral key not found in $screens_info');
        $this->assertNotFalse($otherKeyPos, 'slimview1 key not found in $screens_info');
        $this->assertLessThan(
            $otherKeyPos,
            $firstKeyPos,
            'slimgeneral must be declared before slimview1 so it becomes the top-level landing page'
        );
    }

    public function test_render_callback_includes_general_view(): void
    {
        $php = file_get_contents($this->indexPath());

        $this->assertMatchesRegularExpression(
            "/function\s+wp_slimstat_include_general\s*\(\s*\)[\s\S]{0,200}include\(__DIR__\s*\.\s*'\/view\/general\.php'\)/",
            $php,
            'wp_slimstat_include_general() must include admin/view/general.php'
        );
    }

    /**
     * Regression guard: add_menu_page() and the $parent screen's own
     * add_submenu_page() call both resolve to the identical WP hook
     * (toplevel_page_{$parent} — see get_plugin_page_hookname(), which
     * treats any slug present in $admin_page_hooks as 'toplevel' regardless
     * of the parent_page argument passed to add_submenu_page()). WP fires
     * every callback registered via add_action() on that hook, so a
     * hardcoded add_menu_page() callback that differs from the resolved
     * parent screen's own callback causes BOTH to run — i.e. the page body
     * renders twice. This was latent (masked because slimview1's callback
     * happened to equal the hardcoded one) until slimgeneral, whose
     * callback legitimately differs, would have made the duplication visible.
     */
    public function test_add_menu_page_uses_the_resolved_parent_screens_callback(): void
    {
        $php = file_get_contents($this->indexPath());

        $this->assertStringNotContainsString(
            "[self::class, 'wp_slimstat_include_view'],\n            'dashicons-chart-area'",
            $php,
            'add_menu_page() must not hardcode wp_slimstat_include_view as its callback'
        );

        $this->assertMatchesRegularExpression(
            "/add_menu_page\\(\\s*[\\s\\S]*?\\\$parent,\\s*self::\\\$screens_info\\[\\\$parent\\]\\['callback'\\]/",
            $php,
            'add_menu_page() must use the resolved parent screen\'s own callback, so it fires only once'
        );
    }

    /**
     * Regression guard for the filters-and-daterange partial extraction:
     * index.php and general.php must both call the same shared partial for
     * the Dimension/Operator/Value filter builder + date-range picker, so
     * their top bars can never independently drift — a single get_template()
     * call rather than two copies pasted in sync by hand.
     */
    public function test_index_and_general_share_the_filters_and_daterange_partial(): void
    {
        $this->assertFileExists(
            dirname(__DIR__, 2) . '/admin/view/partials/filters-and-daterange.php',
            'The shared filters-and-daterange partial must exist'
        );

        foreach (['index.php', 'general.php'] as $view) {
            $php = file_get_contents(dirname(__DIR__, 2) . '/admin/view/' . $view);
            $this->assertStringContainsString(
                "get_template('filters-and-daterange')",
                $php,
                $view . ' must render the shared filters-and-daterange partial'
            );
        }
    }

    /**
     * The General and Goals-&-Funnels asset gates both answer "is this the
     * one screen that needs this page-scoped stylesheet/script" — they
     * should read the same already-resolved self::$current_screen rather
     * than one of them re-deriving it from $_GET, which would drift the two
     * checks apart (e.g. across a POST-based navigation where $_GET is
     * absent but self::$current_screen is still correctly resolved).
     */
    public function test_general_asset_gates_use_current_screen_not_raw_get(): void
    {
        $php = file_get_contents($this->indexPath());

        $this->assertStringNotContainsString(
            "\$_GET['page']) && 'slimgeneral' === \$_GET['page']",
            $php,
            'General asset gates must not re-derive the screen from $_GET'
        );

        $this->assertSame(
            2,
            substr_count($php, "'slimgeneral' === self::\$current_screen"),
            'Both the CSS and JS gates must check self::$current_screen'
        );
    }

    /**
     * The General page's boxes are real report entries (slim_p10_01..08),
     * driven through the shared wp_slimstat_reports::$reports /
     * report_header()/callback_wrapper()/report_footer() system every other
     * screen uses — not hardcoded inline markup. Each must declare
     * 'slimgeneral' among its locations and be pinned (same "always render on
     * its dedicated screen" pattern as Goals & Funnels — see
     * GoalsFunnelsReportPlacementTest) so a saved layout that drags a copy
     * elsewhere can't leave the General page empty.
     */
    public function test_general_reports_are_registered_and_pinned_to_slimgeneral(): void
    {
        $php = file_get_contents($this->reportsPath());

        foreach (['slim_p10_01', 'slim_p10_02', 'slim_p10_03', 'slim_p10_04', 'slim_p10_05', 'slim_p10_06', 'slim_p10_07', 'slim_p10_08'] as $report_id) {
            $this->assertMatchesRegularExpression(
                "/'{$report_id}'\\s*=>\\s*\\[[\\s\\S]*?'locations'\\s*=>\\s*\\['slimgeneral'\\][\\s\\S]*?'pinned'\\s*=>\\s*true/",
                $php,
                "{$report_id} must be registered, scoped to slimgeneral, and pinned"
            );
        }
    }

    /**
     * The non-table General reports render themselves, so their callbacks
     * point at a real method on \SlimStat\Modules\GeneralReports — not a
     * closure or a string function name, so the Customize screen and
     * callback_wrapper() can call them exactly like any other report.
     */
    public function test_general_reports_point_at_general_reports_class(): void
    {
        $php = file_get_contents($this->reportsPath());

        $expected = [
            'slim_p10_01' => 'statsRow',
            'slim_p10_02' => 'pageviewsChart',
            'slim_p10_07' => 'campaigns',
            'slim_p10_08' => 'goalsUpsell',
        ];

        foreach ($expected as $report_id => $method) {
            $this->assertMatchesRegularExpression(
                "/'{$report_id}'\\s*=>\\s*\\[[\\s\\S]*?'callback'\\s*=>\\s*\\[\\\\SlimStat\\\\Modules\\\\GeneralReports::class,\\s*'{$method}'\\]/",
                $php,
                "{$report_id} must call GeneralReports::{$method}()"
            );
        }
    }

    /**
     * The four table reports must render through the SHARED renderer,
     * wp_slimstat_reports::raw_results_to_html(), exactly like slim_p1_08 and
     * every other top-N report — never through a General-only renderer.
     *
     * This is the regression guard for the defect that motivated the switch:
     * a hand-rolled row renderer reproduced the bars and percentages but not
     * raw_results_to_html()'s per-column label formatting (get_resource_title(),
     * browser icons, country flags), so those tables rendered rows carrying a
     * number and no name at all.
     */
    public function test_general_table_reports_use_the_shared_renderer(): void
    {
        $php = file_get_contents($this->reportsPath());

        $expected = [
            'slim_p10_03' => 'referer_type',
            'slim_p10_04' => 'resource',
            'slim_p10_05' => 'country',
            'slim_p10_06' => 'browser',
        ];

        foreach ($expected as $report_id => $column) {
            $this->assertMatchesRegularExpression(
                "/'{$report_id}'\\s*=>\\s*\\[[\\s\\S]*?'callback'\\s*=>\\s*\\[self::class,\\s*'raw_results_to_html'\\]/",
                $php,
                "{$report_id} must render through raw_results_to_html(), not a bespoke renderer"
            );

            $this->assertMatchesRegularExpression(
                "/'{$report_id}'\\s*=>\\s*\\[[\\s\\S]*?'columns'\\s*=>\\s*'{$column}'/",
                $php,
                "{$report_id} must declare columns => '{$column}' — raw_results_to_html() reads each row's label from the key that names, and picks its per-column formatting from it"
            );

            $this->assertMatchesRegularExpression(
                "/'{$report_id}'\\s*=>\\s*\\[[\\s\\S]*?'raw'\\s*=>\\s*\\[/",
                $php,
                "{$report_id} must declare a 'raw' data-source callable"
            );
        }
    }

    /**
     * wp_slimstat_reports::$user_reports must declare a 'slimgeneral' key up
     * front (same as slimview1..6/dashboard/inactive) so the init() merge
     * loop and admin/view/layout.php's generic foreach over $user_reports
     * both see the location on a fresh install, before any user has a saved
     * layout.
     */
    public function test_user_reports_declares_slimgeneral_key(): void
    {
        $php = file_get_contents($this->reportsPath());

        $this->assertMatchesRegularExpression(
            "/public static \\\$user_reports = \\[\\s*'slimgeneral'\\s*=>\\s*\\[\\]/",
            $php,
            "\$user_reports must declare 'slimgeneral' => [] as its first location"
        );
    }

    /**
     * general.php must render through the SAME report_header()/
     * callback_wrapper()/report_footer() loop over
     * wp_slimstat_reports::$user_reports['slimgeneral'] as every other
     * screen's admin/view/index.php — not a bespoke $boxes foreach with
     * inline HTML, which is what made the granularity dropdown a no-op
     * (slimstat-chart.js requires a .postbox > .inside ancestor that only
     * report_header()/report_footer() produce).
     */
    public function test_general_view_uses_the_shared_report_loop(): void
    {
        $php = file_get_contents($this->generalViewPath());

        $this->assertStringContainsString(
            "wp_slimstat_reports::\$user_reports['slimgeneral']",
            $php,
            'general.php must loop over the slimgeneral user_reports list'
        );
        $this->assertStringContainsString(
            'wp_slimstat_reports::report_header($a_report_id)',
            $php,
            'general.php must call report_header() for each report'
        );
        $this->assertStringContainsString(
            'wp_slimstat_reports::callback_wrapper(',
            $php,
            'general.php must call callback_wrapper() for each report'
        );
        $this->assertStringContainsString(
            'wp_slimstat_reports::report_footer()',
            $php,
            'general.php must call report_footer() for each report'
        );

        // Regression guard: the old bespoke per-box markup (row-i/bar/txt
        // classes, the $render_box_rows closure) must be gone, not merely
        // supplemented by the new loop.
        $this->assertStringNotContainsString('$render_box_rows', $php);
        $this->assertStringNotContainsString('class="row-i"', $php);
    }

    /**
     * \SlimStat\Modules\GeneralReports must exist and expose every callback
     * method referenced from the registry, so a PSR-4 autoload miss or a
     * renamed method fails a fast, WP-bootstrap-free test rather than a
     * fatal on the live admin screen.
     */
    public function test_general_reports_class_exposes_every_registered_callback(): void
    {
        $this->assertTrue(
            class_exists(\SlimStat\Modules\GeneralReports::class),
            'SlimStat\\Modules\\GeneralReports must exist'
        );

        $methods = [
            // Reports that render themselves.
            'statsRow', 'pageviewsChart', 'campaigns', 'goalsUpsell',
            // `raw` data-source callables for the shared renderer.
            'trafficSourcesRaw', 'topColumnRaw',
            // Free-tier gating chrome, hooked in wp_slimstat_admin::init().
            'register_hooks', 'markGatedReports', 'injectUnlockCta',
        ];

        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists(\SlimStat\Modules\GeneralReports::class, $method),
                "GeneralReports::{$method}() must exist"
            );
        }
    }

    /**
     * The hand-rolled row renderer must stay deleted. It reproduced the bars
     * and percentages but none of raw_results_to_html()'s per-column label
     * formatting, which is what left rows showing a count and no name.
     */
    public function test_general_reports_has_no_bespoke_row_renderer(): void
    {
        $php = file_get_contents(dirname(__DIR__, 2) . '/src/Modules/GeneralReports.php');

        foreach (['function renderRows', 'function renderGatedBox', 'function renderPager'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $php,
                "GeneralReports::{$needle}() must not come back — the tables render through raw_results_to_html()"
            );
        }
    }

    /**
     * The free-tier blur must key off the server-applied marker class, never
     * off row position.
     *
     * raw_results_to_html() emits the SQL debug message and the "Showing x -
     * y of z" pagination as <p> siblings of the rows, and wraps the rows in
     * an extra <div> on the non-AJAX path — so a positional selector shifts
     * or stops matching depending on the render path and the debug setting.
     * An earlier revision used :nth-child(n + 3) and did not match at all on
     * first paint.
     */
    public function test_free_tier_blur_targets_the_marker_class_not_a_position(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2) . '/admin/assets/css/general.css');

        $this->assertStringContainsString(
            'p.slimstat-row-synthetic',
            $css,
            'the blur must target the marker class GeneralReports::markSyntheticRows() applies'
        );

        foreach (['nth-child(n + 3)', 'nth-child(n+3)', 'nth-last-of-type'] as $positional) {
            $this->assertStringNotContainsString(
                $positional,
                $css,
                "positional blur selector '{$positional}' must not come back"
            );
        }

        $this->assertStringContainsString(
            'slimstat_report_row_classes',
            file_get_contents($this->reportsPath()),
            'raw_results_to_html() must expose the per-row class filter the marker relies on'
        );
    }

    private function reportsPath(): string
    {
        return dirname(__DIR__, 2) . '/admin/view/wp-slimstat-reports.php';
    }

    private function generalViewPath(): string
    {
        return dirname(__DIR__, 2) . '/admin/view/general.php';
    }

    private function indexPath(): string
    {
        return dirname(__DIR__, 2) . '/admin/index.php';
    }
}
