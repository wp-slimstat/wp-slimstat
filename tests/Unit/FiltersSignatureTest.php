<?php
/**
 * Guards wp_slimstat_db::filters_signature() — the column-filter component
 * of the funnel cache key.
 *
 * The signature MUST be derived from the NORMALIZED filter array
 * (self::$filters_normalized['columns']), never from get_combined_where()'s SQL:
 * that SQL is run through $wpdb->prepare(), whose per-request placeholder salt
 * would make the signature non-deterministic and re-split a server-rendered funnel
 * from its AJAX-loaded twin (the very #1 symptom the cache key exists to prevent).
 * Serializing the [col => [operator, value]] array is request-stable by construction.
 *
 * @package WpSlimstat
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit;

class FiltersSignatureTest extends WpSlimstatTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists('wp_slimstat_db', false)) {
            require_once dirname(__DIR__, 2) . '/admin/view/wp-slimstat-db.php';
        }
        \wp_slimstat_db::$filters_normalized = [];
    }

    protected function tearDown(): void
    {
        \wp_slimstat_db::$filters_normalized = [];
        parent::tearDown();
    }

    private static function sig(): string
    {
        $m = new \ReflectionMethod(\wp_slimstat_db::class, 'filters_signature');
        $m->setAccessible(true);
        return $m->invoke(null);
    }

    /** @test */
    public function test_empty_filters_produce_a_stable_baseline_hash(): void
    {
        \wp_slimstat_db::$filters_normalized = ['columns' => []];
        $this->assertSame(md5(serialize([])), self::sig(), 'No column filters must hash to the empty-array baseline');

        // Missing 'columns' key entirely must not warn and must match the baseline.
        \wp_slimstat_db::$filters_normalized = [];
        $this->assertSame(md5(serialize([])), self::sig(), 'A missing columns key must be treated as no filters');
    }

    /** @test */
    public function test_signature_is_stable_across_calls(): void
    {
        \wp_slimstat_db::$filters_normalized = ['columns' => ['browser' => ['equals', 'firefox']]];
        $first  = self::sig();
        $second = self::sig();
        $this->assertSame($first, $second, 'The same normalized filters must hash identically across calls');
    }

    /** @test */
    public function test_different_filter_values_produce_different_signatures(): void
    {
        \wp_slimstat_db::$filters_normalized = ['columns' => ['browser' => ['equals', 'firefox']]];
        $firefox = self::sig();

        \wp_slimstat_db::$filters_normalized = ['columns' => ['browser' => ['equals', 'chrome']]];
        $chrome = self::sig();

        $this->assertNotSame($firefox, $chrome, 'A different filter value must change the signature');
    }

    /** @test */
    public function test_different_columns_and_operators_change_the_signature(): void
    {
        \wp_slimstat_db::$filters_normalized = ['columns' => ['browser' => ['equals', 'firefox']]];
        $base = self::sig();

        \wp_slimstat_db::$filters_normalized = ['columns' => ['country' => ['equals', 'firefox']]];
        $this->assertNotSame($base, self::sig(), 'A different column must change the signature');

        \wp_slimstat_db::$filters_normalized = ['columns' => ['browser' => ['contains', 'firefox']]];
        $this->assertNotSame($base, self::sig(), 'A different operator must change the signature');

        // Adding a second filter must change the signature too.
        \wp_slimstat_db::$filters_normalized = ['columns' => ['browser' => ['equals', 'firefox'], 'country' => ['equals', 'us']]];
        $this->assertNotSame($base, self::sig(), 'Adding a second filter must change the signature');
    }
}
