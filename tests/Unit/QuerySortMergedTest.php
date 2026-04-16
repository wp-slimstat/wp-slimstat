<?php
/**
 * Unit tests for Query::sortMergedResults().
 *
 * Verifies that the split-query merge path in getAll() produces correctly
 * sorted output after summing counthits from historical + live partitions.
 *
 * @package WpSlimstat
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use SlimStat\Utils\Query;

class QuerySortMergedTest extends WpSlimstatTestCase
{
    private $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wpdb         = Mockery::mock('stdClass');
        $this->wpdb->prefix = 'wp_';

        $GLOBALS['wpdb'] = $this->wpdb;

        Functions\stubs([
            'get_transient'    => false,
            'set_transient'    => true,
            'delete_transient' => true,
            'wp_json_encode'   => static fn($data, ...$opts) => json_encode($data),
        ]);

        $this->wpdb->shouldReceive('prepare')
            ->andReturnUsing(function () {
                $args   = func_get_args();
                $format = str_replace(['%s', '%d'], "'%s'", $args[0]);
                $values = array_slice($args, 1);
                if (count($values) === 1 && is_array($values[0])) {
                    $values = $values[0];
                }
                return vsprintf($format, $values);
            });
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helper: invoke protected sortMergedResults() via Reflection
    // ------------------------------------------------------------------

    private function invokeSortMergedResults(Query $query, array $results): array
    {
        $ref = new \ReflectionMethod(Query::class, 'sortMergedResults');
        return $ref->invoke($query, $results);
    }

    // ------------------------------------------------------------------
    // sortMergedResults — direct tests
    // ------------------------------------------------------------------

    public function test_numeric_desc_sort(): void
    {
        $query = Query::select(['resource', 'COUNT(*) AS counthits'])
            ->from('wp_slim_stats')
            ->orderBy('counthits DESC');

        $input = [
            ['resource' => '/a', 'counthits' => '10'],
            ['resource' => '/b', 'counthits' => '50'],
            ['resource' => '/c', 'counthits' => '30'],
        ];

        $result = $this->invokeSortMergedResults($query, $input);

        $this->assertSame('/b', $result[0]['resource']);
        $this->assertSame('/c', $result[1]['resource']);
        $this->assertSame('/a', $result[2]['resource']);
    }

    public function test_multi_field_sort_desc_then_asc_tiebreaker(): void
    {
        $query = Query::select(['resource', 'COUNT(*) AS counthits'])
            ->from('wp_slim_stats')
            ->orderBy('counthits DESC, resource ASC');

        $input = [
            ['resource' => '/b', 'counthits' => '10'],
            ['resource' => '/a', 'counthits' => '10'],
            ['resource' => '/c', 'counthits' => '20'],
        ];

        $result = $this->invokeSortMergedResults($query, $input);

        $this->assertSame('/c', $result[0]['resource']);  // 20
        $this->assertSame('/a', $result[1]['resource']);   // 10, 'a' < 'b'
        $this->assertSame('/b', $result[2]['resource']);   // 10, 'b' > 'a'
    }

    public function test_already_sorted_input_unchanged(): void
    {
        $query = Query::select(['resource', 'COUNT(*) AS counthits'])
            ->from('wp_slim_stats')
            ->orderBy('counthits DESC');

        $input = [
            ['resource' => '/top',  'counthits' => '100'],
            ['resource' => '/mid',  'counthits' => '50'],
            ['resource' => '/low',  'counthits' => '1'],
        ];

        $result = $this->invokeSortMergedResults($query, $input);

        $this->assertSame('/top', $result[0]['resource']);
        $this->assertSame('/mid', $result[1]['resource']);
        $this->assertSame('/low', $result[2]['resource']);
    }

    public function test_empty_results_returns_empty(): void
    {
        $query = Query::select(['resource', 'COUNT(*) AS counthits'])
            ->from('wp_slim_stats')
            ->orderBy('counthits DESC');

        $result = $this->invokeSortMergedResults($query, []);

        $this->assertSame([], $result);
    }

    public function test_no_order_by_returns_input_unchanged(): void
    {
        $query = Query::select(['resource', 'COUNT(*) AS counthits'])
            ->from('wp_slim_stats');
        // No orderBy() call

        $input = [
            ['resource' => '/z', 'counthits' => '1'],
            ['resource' => '/a', 'counthits' => '99'],
        ];

        $result = $this->invokeSortMergedResults($query, $input);

        $this->assertSame('/z', $result[0]['resource']);
        $this->assertSame('/a', $result[1]['resource']);
    }

    public function test_string_asc_sort(): void
    {
        $query = Query::select(['resource', 'COUNT(*) AS counthits'])
            ->from('wp_slim_stats')
            ->orderBy('resource ASC');

        $input = [
            ['resource' => '/c', 'counthits' => '1'],
            ['resource' => '/a', 'counthits' => '2'],
            ['resource' => '/b', 'counthits' => '3'],
        ];

        $result = $this->invokeSortMergedResults($query, $input);

        $this->assertSame('/a', $result[0]['resource']);
        $this->assertSame('/b', $result[1]['resource']);
        $this->assertSame('/c', $result[2]['resource']);
    }

    public function test_sql_aggregate_expression_resolves_to_alias(): void
    {
        $query = Query::select(['username', 'COUNT(*) AS counthits', 'MAX(dt) AS dt'])
            ->from('wp_slim_stats')
            ->orderBy('MAX(dt) DESC');

        // MAX(dt) resolves to the 'dt' alias in the result set.
        $input = [
            ['username' => 'alice', 'counthits' => '5',  'dt' => '1000'],
            ['username' => 'bob',   'counthits' => '10', 'dt' => '2000'],
        ];

        $result = $this->invokeSortMergedResults($query, $input);

        // MAX(dt) DESC → sorted by dt descending: bob (2000) before alice (1000)
        $this->assertSame('bob', $result[0]['username']);
        $this->assertSame('alice', $result[1]['username']);
    }

    public function test_mixed_sortable_and_expression_fields(): void
    {
        $query = Query::select(['resource', 'COUNT(*) AS counthits'])
            ->from('wp_slim_stats')
            ->orderBy('counthits DESC, REPLACE(SUBSTRING_INDEX(referer, "://", -1), "www.", "") ASC');

        $input = [
            ['resource' => '/a', 'counthits' => '5'],
            ['resource' => '/b', 'counthits' => '20'],
            ['resource' => '/c', 'counthits' => '10'],
        ];

        $result = $this->invokeSortMergedResults($query, $input);

        // counthits sorted, complex expression skipped
        $this->assertSame('/b', $result[0]['resource']);  // 20
        $this->assertSame('/c', $result[1]['resource']);   // 10
        $this->assertSame('/a', $result[2]['resource']);   // 5
    }

    public function test_null_values_sort_last(): void
    {
        $query = Query::select(['resource', 'COUNT(*) AS counthits'])
            ->from('wp_slim_stats')
            ->orderBy('counthits DESC');

        $input = [
            ['resource' => '/a', 'counthits' => null],
            ['resource' => '/b', 'counthits' => '50'],
            ['resource' => '/c', 'counthits' => '30'],
        ];

        $result = $this->invokeSortMergedResults($query, $input);

        $this->assertSame('/b', $result[0]['resource']);  // 50
        $this->assertSame('/c', $result[1]['resource']);   // 30
        $this->assertSame('/a', $result[2]['resource']);   // null → last
    }

    public function test_numeric_strings_compared_numerically(): void
    {
        $query = Query::select(['resource', 'COUNT(*) AS counthits'])
            ->from('wp_slim_stats')
            ->orderBy('counthits DESC');

        // String comparison would put '9' > '10', numeric comparison correctly puts 10 > 9
        $input = [
            ['resource' => '/nine', 'counthits' => '9'],
            ['resource' => '/ten',  'counthits' => '10'],
            ['resource' => '/two',  'counthits' => '2'],
        ];

        $result = $this->invokeSortMergedResults($query, $input);

        $this->assertSame('/ten',  $result[0]['resource']);  // 10
        $this->assertSame('/nine', $result[1]['resource']);   // 9
        $this->assertSame('/two',  $result[2]['resource']);   // 2
    }
}
