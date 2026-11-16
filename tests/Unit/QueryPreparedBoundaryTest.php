<?php
declare(strict_types=1);
namespace WpSlimstat\Tests\Unit;

use SlimStat\Utils\Query;

final class QueryPreparedBoundaryTest extends WpSlimstatTestCase
{
    private $previousDb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = \wp_slimstat::$wpdb;
    }

    protected function tearDown(): void
    {
        \wp_slimstat::$wpdb = $this->previousDb;
        parent::tearDown();
    }

    /** @dataProvider predicates */
    public function test_actual_query_keeps_values_out_of_templates($operator, $value, string $fragment, array $bindings): void
    {
        $db = \Mockery::mock('stdClass');
        \wp_slimstat::$wpdb = $db;
        $template = 'SELECT * FROM wp_slim_stats WHERE ' . $fragment;
        if ($bindings) {
            $arguments = count($bindings) === 1 ? $bindings : [$bindings];
            $db->shouldReceive('prepare')->once()->with($template, ...$arguments)->andReturn('prepared query');
            self::assertSame('prepared query', Query::select()->from('wp_slim_stats')->where('browser_type', $operator, $value)->getSqlQuery());
        } else {
            $db->shouldNotReceive('prepare');
            self::assertSame($template, Query::select()->from('wp_slim_stats')->where('browser_type', $operator, $value)->getSqlQuery());
        }
    }

    public static function predicates(): array
    {
        $cases = [];
        foreach (['=', '!=', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE'] as $operator) {
            $cases[$operator] = [$operator, 'test', "browser_type $operator %s", ['test']];
        }
        return $cases + [
            'zero' => ['=', 0, 'browser_type = %s', [0]],
            'IN zero' => ['IN', [0, 2, 3], 'browser_type IN (%s, %s, %s)', [0, 2, 3]],
            'NOT IN zero' => ['NOT IN', [0, 1], 'browser_type NOT IN (%s, %s)', [0, 1]],
            'IN one' => ['IN', [0], 'browser_type = %s', [0]],
            'BETWEEN zero' => ['BETWEEN', [0, 10], 'browser_type BETWEEN %s AND %s', [0, 10]],
            'IS NULL' => ['IS', null, 'browser_type IS NULL', []],
            'IS NOT NULL' => ['IS NOT', null, 'browser_type IS NOT NULL', []],
            'quote injection' => ['=', "' OR 1=1 --", 'browser_type = %s', ["' OR 1=1 --"]],
            'wildcards' => ['LIKE', '%_\\quote', 'browser_type LIKE %s', ['%_\\quote']],
            'HTML value' => ['=', '<script>alert(1)</script>', 'browser_type = %s', ['<script>alert(1)</script>']],
        ];
    }

    public function test_clause_composition_preserves_binding_order(): void
    {
        $db = \Mockery::mock('stdClass');
        \wp_slimstat::$wpdb = $db;
        $db->shouldReceive('prepare')->once()->with(
            'SELECT country, COUNT(*) AS n FROM wp_slim_stats WHERE dt BETWEEN %s AND %s AND browser_type != %s GROUP BY country ORDER BY n DESC LIMIT 5',
            [100, 200, 1]
        )->andReturn('prepared query');
        self::assertSame('prepared query', Query::select('country, COUNT(*) AS n')->from('wp_slim_stats')
            ->where('dt', 'BETWEEN', [100, 200])->where('browser_type', '!=', 1)
            ->groupBy('country')->orderBy('n DESC')->limit(5)->getSqlQuery());
    }

    public function test_operator_is_not_a_raw_sql_escape_hatch(): void
    {
        \wp_slimstat::$wpdb = new \stdClass();
        $this->expectException(\InvalidArgumentException::class);
        Query::select()->from('wp_slim_stats')->where('resource', '= 1 OR 1=1 --', 'value');
    }
}
