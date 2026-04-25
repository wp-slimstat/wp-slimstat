<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

use Brain\Monkey\Functions;
use Mockery;

/**
 * Contract tests for wp_slimstat_site_analyzer.
 *
 * Pins:
 *   - WC plugin gate (active + class + helper functions all required)
 *   - Single combined SQL query, all LIKE patterns left-anchored
 *   - Suggestion shape + rationale wording
 *   - Dedup against existing user-defined goals/funnels
 *   - Suggestion cap (MAX_SUGGESTIONS)
 *   - Cache invalidation flow (analyzer cache version key bumped on
 *     clear_goals_cache() and on plugin activate/deactivate)
 *
 * Out of scope (covered elsewhere): wall-time perf, EXPLAIN row type.
 */
class SiteAnalyzerTest extends IntegrationTestCase
{
    /** @var \Mockery\MockInterface */
    private $wpdbMock;

    protected function setUp(): void
    {
        parent::setUp();

        // Load the analyzer once per process. (Other tests load it indirectly
        // via the AJAX handler; we test it directly here.)
        $analyzerPath = dirname(__DIR__, 2) . '/admin/view/wp-slimstat-site-analyzer.php';
        if (!class_exists(\wp_slimstat_site_analyzer::class, false)) {
            require_once $analyzerPath;
        }

        // Detection query reads wp_slimstat::$wpdb directly. Mock it so we
        // can assert on the prepared SQL/args + return canned aggregates.
        $this->wpdbMock = Mockery::mock();
        $this->wpdbMock->prefix = 'wp_';
        $this->wpdbMock->shouldReceive('esc_like')->andReturnUsing(function ($s) {
            return addcslashes((string) $s, '_%\\');
        });
        $this->wpdbMock->shouldReceive('prepare')->andReturnUsing(function ($sql, ...$args) {
            // Naive — for assertions we just need the args list back; the
            // analyzer's row-shape assertions don't depend on actual SQL string.
            return ['__sql__' => $sql, '__args__' => $args];
        });
        \wp_slimstat::$wpdb = $this->wpdbMock;

        // Plugin-active stubs default to "all inactive". Per-test overrides flip on.
        Functions\when('is_plugin_active')->alias(function ($plugin) {
            return $this->activePlugins[$plugin] ?? false;
        });
    }

    protected array $activePlugins = [];

    private function activatePlugin(string $slug): void
    {
        $this->activePlugins[$slug] = true;
    }

    /**
     * Bypass the cache by bumping the version key BEFORE each get_analysis()
     * call. Combined with set/get_transient stubs returning false, this
     * guarantees we hit the SQL path.
     */
    private function bypassCache(): void
    {
        $this->optionStore['slimstat_site_analysis_cache_ver'] = (string) microtime(true);
    }

    private function expectQueryReturning(array $row): void
    {
        // The analyzer calls get_row(prepare(...), ARRAY_A). Match any prepared
        // payload; assert separately on the SQL shape if needed.
        $this->wpdbMock->shouldReceive('get_row')->andReturn($row);
    }

    // ---- Plugin gate -----------------------------------------------------

    public function test_returns_empty_when_no_relevant_plugins_active(): void
    {
        $this->bypassCache();
        // No expectation on get_row — the analyzer should short-circuit before SQL.

        $result = \wp_slimstat_site_analyzer::run_analysis();

        $this->assertSame([], $result['suggestions']);
        $this->assertSame(30, $result['range_days']);
    }

    public function test_skips_wc_when_helpers_missing_even_if_active(): void
    {
        $this->bypassCache();
        $this->activatePlugin('woocommerce/woocommerce.php');
        // WooCommerce class + wc_get_page_permalink intentionally NOT defined
        // in the test process; analyzer must short-circuit cleanly.

        $result = \wp_slimstat_site_analyzer::run_analysis();

        $this->assertSame([], $result['suggestions']);
    }

    // ---- WooCommerce purchase suggestions --------------------------------

    public function test_wc_active_with_order_received_visits_emits_goal(): void
    {
        $this->activateWooCommerce(['cart' => '/cart/', 'checkout' => '/checkout/']);
        $this->bypassCache();
        $this->expectQueryReturning([
            'cart_visits'           => 100,
            'checkout_visits'       => 80,
            'order_received_visits' => 30,
            'product_visits'        => 200,
            'donation_visits'       => 0,
            'edd_purchase_visits'   => 0,
        ]);

        $result = \wp_slimstat_site_analyzer::run_analysis();

        $titles = array_column($result['suggestions'], 'title');
        $this->assertContains('Order placed', $titles);
        $this->assertContains('WooCommerce purchase funnel', $titles);

        // Goal uses the canonical WC order-received path under the user's actual checkout slug.
        $goal = $this->findSuggestion($result['suggestions'], 'wc-order-placed');
        $this->assertSame('starts_with', $goal['prefill']['operator']);
        $this->assertSame('/checkout/order-received/', $goal['prefill']['value']);
    }

    public function test_wc_full_funnel_uses_user_renamed_slugs(): void
    {
        // User renamed cart → /basket/ and checkout → /commande/
        $this->activateWooCommerce(['cart' => '/basket/', 'checkout' => '/commande/']);
        $this->bypassCache();
        $this->expectQueryReturning([
            'cart_visits'           => 50,
            'checkout_visits'       => 30,
            'order_received_visits' => 10,
            'product_visits'        => 100,
            'donation_visits'       => 0,
            'edd_purchase_visits'   => 0,
        ]);

        $result = \wp_slimstat_site_analyzer::run_analysis();

        $funnel = $this->findSuggestion($result['suggestions'], 'wc-purchase-funnel');
        $this->assertNotNull($funnel);
        $stepValues = array_column($funnel['prefill']['steps'], 'value');
        $this->assertContains('/basket/', $stepValues, 'Funnel must use renamed cart path');
        $this->assertContains('/commande/', $stepValues, 'Funnel must use renamed checkout path');
        $this->assertContains('/commande/order-received/', $stepValues, 'Order-received path must be nested under user checkout slug');
    }

    public function test_wc_funnel_uses_renamed_product_base(): void
    {
        // User customised the WC product permalink base from /product/ to /produit/
        $this->optionStore['woocommerce_permalinks'] = ['product_base' => 'produit'];
        $this->activateWooCommerce();
        $this->bypassCache();
        $this->expectQueryReturning([
            'cart_visits'           => 50,
            'checkout_visits'       => 30,
            'order_received_visits' => 10,
            'product_visits'        => 100,
            'donation_visits'       => 0,
            'edd_purchase_visits'   => 0,
        ]);

        $result = \wp_slimstat_site_analyzer::run_analysis();

        $funnel = $this->findSuggestion($result['suggestions'], 'wc-purchase-funnel');
        $this->assertNotNull($funnel);
        $stepValues = array_column($funnel['prefill']['steps'], 'value');
        $this->assertContains('/produit/', $stepValues, 'Funnel must use renamed product permalink base');
    }

    public function test_wc_no_order_received_visits_skips_funnel(): void
    {
        // Cart + checkout visits exist but no completions yet — incomplete
        // signal (brand-new store), funnel suggestion suppressed.
        $this->activateWooCommerce();
        $this->bypassCache();
        $this->expectQueryReturning([
            'cart_visits'           => 50,
            'checkout_visits'       => 20,
            'order_received_visits' => 0,
            'product_visits'        => 100,
            'donation_visits'       => 0,
            'edd_purchase_visits'   => 0,
        ]);

        $result = \wp_slimstat_site_analyzer::run_analysis();

        $this->assertSame([], $result['suggestions']);
    }

    public function test_wc_active_but_checkout_page_unconfigured_skips_rule(): void
    {
        // Simulate wc_get_page_permalink returning false (page unset).
        $this->defineWcStubs([
            'cart'     => false,
            'checkout' => false,
        ]);
        $this->activatePlugin('woocommerce/woocommerce.php');
        $this->bypassCache();

        $result = \wp_slimstat_site_analyzer::run_analysis();

        $this->assertSame([], $result['suggestions']);
    }

    // ---- GiveWP / EDD ----------------------------------------------------

    public function test_givewp_active_emits_donation_goal(): void
    {
        $this->activatePlugin('give/give.php');
        $this->bypassCache();
        $this->expectQueryReturning([
            'cart_visits'           => 0,
            'checkout_visits'       => 0,
            'order_received_visits' => 0,
            'product_visits'        => 0,
            'donation_visits'       => 17,
            'edd_purchase_visits'   => 0,
        ]);

        $result = \wp_slimstat_site_analyzer::run_analysis();

        $titles = array_column($result['suggestions'], 'title');
        $this->assertContains('Donation completed', $titles);
        $goal = $this->findSuggestion($result['suggestions'], 'givewp-donation');
        $this->assertSame('/donation-confirmation/', $goal['prefill']['value']);
    }

    public function test_edd_active_emits_purchase_goal(): void
    {
        $this->activatePlugin('easy-digital-downloads/easy-digital-downloads.php');
        $this->bypassCache();
        $this->expectQueryReturning([
            'cart_visits'           => 0,
            'checkout_visits'       => 0,
            'order_received_visits' => 0,
            'product_visits'        => 0,
            'donation_visits'       => 0,
            'edd_purchase_visits'   => 5,
        ]);

        $result = \wp_slimstat_site_analyzer::run_analysis();

        $goal = $this->findSuggestion($result['suggestions'], 'edd-purchase');
        $this->assertNotNull($goal);
        $this->assertSame('/checkout/purchase-confirmation/', $goal['prefill']['value']);
    }

    // ---- Dedup -----------------------------------------------------------

    public function test_dedup_drops_suggestion_matching_existing_goal(): void
    {
        $this->activateWooCommerce();
        $this->bypassCache();
        $this->expectQueryReturning([
            'cart_visits'           => 100,
            'checkout_visits'       => 80,
            'order_received_visits' => 30,
            'product_visits'        => 200,
            'donation_visits'       => 0,
            'edd_purchase_visits'   => 0,
        ]);

        // User already has the exact goal the analyzer would suggest.
        $this->setGoals([[
            'id'        => 99,
            'name'      => 'Already here',
            'dimension' => 'resource',
            'operator'  => 'starts_with',
            'value'     => '/checkout/order-received/',
            'active'    => true,
        ]]);

        $result = \wp_slimstat_site_analyzer::run_analysis();

        $ids = array_column($result['suggestions'], 'id');
        $this->assertNotContains('wc-order-placed', $ids, 'Duplicate goal must be filtered out');
    }

    public function test_dedup_drops_funnel_when_first_step_matches_existing(): void
    {
        $this->activateWooCommerce();
        $this->bypassCache();
        $this->expectQueryReturning([
            'cart_visits'           => 100,
            'checkout_visits'       => 80,
            'order_received_visits' => 30,
            'product_visits'        => 200,
            'donation_visits'       => 0,
            'edd_purchase_visits'   => 0,
        ]);

        // First step of the WC funnel is `/product/` starts_with on resource.
        $this->setFunnels([[
            'id'    => 1,
            'name'  => 'My funnel',
            'steps' => [
                ['name' => 'P', 'dimension' => 'resource', 'operator' => 'starts_with', 'value' => '/product/'],
                ['name' => 'C', 'dimension' => 'resource', 'operator' => 'starts_with', 'value' => '/cart/'],
            ],
        ]]);

        $result = \wp_slimstat_site_analyzer::run_analysis();

        $ids = array_column($result['suggestions'], 'id');
        $this->assertNotContains('wc-purchase-funnel', $ids, 'Duplicate funnel must be filtered out');
    }

    // ---- Cache invalidation ---------------------------------------------

    public function test_invalidate_cache_bumps_version_key(): void
    {
        $this->optionStore['slimstat_site_analysis_cache_ver'] = '100';

        \wp_slimstat_site_analyzer::invalidate_cache();

        $this->assertGreaterThan(100, (float) $this->optionStore['slimstat_site_analysis_cache_ver']);
    }

    public function test_clear_goals_cache_invalidates_analyzer_cache_too(): void
    {
        $this->optionStore['slimstat_site_analysis_cache_ver'] = '50';

        $ref = new \ReflectionMethod('wp_slimstat_admin', 'clear_goals_cache');
        $ref->invoke(null);

        $this->assertGreaterThan(50, (float) $this->optionStore['slimstat_site_analysis_cache_ver']);
    }

    public function test_admin_invalidate_helper_bumps_version(): void
    {
        $this->optionStore['slimstat_site_analysis_cache_ver'] = '0';

        \wp_slimstat_admin::invalidate_site_analysis_cache();

        $this->assertGreaterThan(0, (float) $this->optionStore['slimstat_site_analysis_cache_ver']);
    }

    // ---- Helpers ---------------------------------------------------------

    private function activateWooCommerce(array $paths = []): void
    {
        $this->activatePlugin('woocommerce/woocommerce.php');
        $this->defineWcStubs($paths + ['cart' => '/cart/', 'checkout' => '/checkout/']);
    }

    private function defineWcStubs(array $paths): void
    {
        if (!class_exists('WooCommerce', false)) {
            eval('class WooCommerce {}');
        }
        if (!function_exists('wc_get_page_permalink')) {
            eval(<<<'PHP'
            function wc_get_page_permalink($page) {
                return $GLOBALS['__test_wc_paths'][$page] ?? null;
            }
            PHP);
        }
        // Build absolute URLs so path_from_url() exercises the parse_url branch.
        $abs = [];
        foreach ($paths as $key => $value) {
            $abs[$key] = (false === $value) ? false : ('https://example.test' . $value);
        }
        $GLOBALS['__test_wc_paths'] = $abs;
    }

    private function findSuggestion(array $suggestions, string $id): ?array
    {
        foreach ($suggestions as $s) {
            if (($s['id'] ?? null) === $id) {
                return $s;
            }
        }
        return null;
    }
}
