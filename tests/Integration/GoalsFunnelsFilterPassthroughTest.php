<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard: funnels must honor the active global report filters, not just
 * the date range (#22).
 *
 * Two defects made funnels ignore filters that Goals respected:
 *   A) the funnel cache key omitted the column-filter signature, so toggling a
 *      filter served the stale unfiltered transient;
 *   B) the lazily-loaded (non-first) funnel tab never POSTed the active fs[...]
 *      filters, so its AJAX request computed unfiltered.
 *
 * The fix folds funnel_filters_signature() into funnel_cache_key(), exposes a
 * reusable SlimStatGetFiltersForAjax() in admin.js, and posts those filters on
 * funnel-load. The per-step "Test" affordance is deliberately left unfiltered
 * (it validates a raw rule while building).
 *
 * Source-shape test (mirrors GoalsFunnelsAjaxInitTest) — no live MySQL.
 */
class GoalsFunnelsFilterPassthroughTest extends TestCase
{
    private function read(string $relpath): string
    {
        $contents = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relpath);
        if ($contents === '') {
            $this->fail("Could not read {$relpath}");
        }
        return $contents;
    }

    public function test_cache_key_includes_a_filter_signature_argument(): void
    {
        $db = $this->read('admin/view/wp-slimstat-db.php');

        // The key builder must accept and fold in a column-filter signature.
        $this->assertMatchesRegularExpression(
            '/function funnel_cache_key\([^)]*\$filters_sig[^)]*\)/',
            $db,
            'funnel_cache_key() must take a $filters_sig argument'
        );
        $this->assertStringContainsString(
            'function funnel_filters_signature(',
            $db,
            'A funnel_filters_signature() helper must exist'
        );

        // The signature must derive from the NORMALIZED filter array, never from the
        // prepared get_combined_where() SQL (whose $wpdb->prepare salt is unstable).
        $this->assertMatchesRegularExpression(
            "/funnel_filters_signature\(\)\s*\{[\s\S]*?serialize\(self::\\\$filters_normalized\['columns'\]/",
            $db,
            'funnel_filters_signature() must serialize $filters_normalized[columns], not the prepared SQL'
        );

        // get_funnel_results() must feed the signature into the key.
        $this->assertStringContainsString(
            'self::funnel_filters_signature()',
            $db,
            'get_funnel_results() must pass the filter signature into the cache key'
        );
    }

    public function test_admin_js_exposes_a_filter_getter_that_skips_date_keys(): void
    {
        $js = $this->read('admin/assets/js/admin.js');

        $this->assertStringContainsString(
            'window.SlimStatGetFiltersForAjax',
            $js,
            'admin.js must expose SlimStatGetFiltersForAjax for other scripts'
        );
        $this->assertStringContainsString(
            '.slimstat-post-filter',
            $js,
            'The getter must harvest the hidden .slimstat-post-filter inputs'
        );
        // Date/window keys must be excluded — sourced from the server's canonical
        // NON_COLUMN_FILTER_KEYS (localized) so the skip-list never drifts.
        $this->assertStringContainsString(
            'non_column_filter_keys',
            $js,
            'The getter must source its skip-list from the localized NON_COLUMN_FILTER_KEYS'
        );

        // And the server must localize that canonical list for the JS layer.
        $admin = $this->read('admin/index.php');
        $this->assertStringContainsString(
            "'non_column_filter_keys'",
            $admin,
            'admin/index.php must localize non_column_filter_keys into SlimStatAdminParams'
        );
        $this->assertStringContainsString(
            'wp_slimstat_db::NON_COLUMN_FILTER_KEYS',
            $admin,
            'The localized list must come from the canonical NON_COLUMN_FILTER_KEYS constant'
        );
    }

    public function test_funnel_load_posts_the_active_filters(): void
    {
        $js = $this->read('admin/assets/js/goals-funnels.js');

        // The merged var must derive from the reusable getter...
        $this->assertMatchesRegularExpression(
            '/loadFilters\s*=\s*\(typeof window\.SlimStatGetFiltersForAjax/',
            $js,
            'goals-funnels.js must source the active filters from SlimStatGetFiltersForAjax'
        );
        // ...and be merged into the funnel lazy-load POST body.
        $this->assertMatchesRegularExpression(
            '/slimstat_load_funnel_data[\s\S]{0,400}loadFilters/',
            $js,
            'The funnel lazy-load request must merge the active global filters (loadFilters)'
        );
    }

    public function test_test_step_is_deliberately_left_unfiltered(): void
    {
        $js = $this->read('admin/assets/js/goals-funnels.js');

        // Scope decision: the per-step "Test" validates a raw rule while building,
        // so it must NOT inherit the page's active report filters.
        if (!preg_match('/slimstat_test_funnel_step[\s\S]{0,400}/', $js, $m)) {
            $this->fail('Could not locate the slimstat_test_funnel_step request');
        }
        $this->assertStringNotContainsString(
            'SlimStatGetFiltersForAjax',
            $m[0],
            'The funnel-step Test must stay unfiltered (raw-rule validation)'
        );
    }
}
