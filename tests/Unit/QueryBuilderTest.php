<?php
/**
 * Query builder unit tests for wp_slimstat_db SQL generation.
 *
 * @package WpSlimstat
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

/**
 * Unit tests for wp_slimstat_db query builder.
 *
 * Exercises the SQL-generation methods (get_single_where_clause,
 * _get_sql_where, get_combined_where) without touching a real database.
 *
 * We intentionally skip wp_slimstat_db::init() — it pulls in date logic,
 * $_GET/$_REQUEST parsing, and count_records(). Instead we set the static
 * properties ($columns_names, $all_columns_names, $filters_normalized)
 * directly via Reflection.
 *
 * @see \wp_slimstat_db
 */
class QueryBuilderTest extends WpSlimstatTestCase
{
    /**
     * Mock wpdb instance shared across tests.
     *
     * @var \Mockery\MockInterface&\stdClass
     */
    private $wpdb;

    /**
     * Minimal $columns_names map — only the dimensions used in tests.
     * Format mirrors the production data: [ 'column' => ['Label', 'type'] ]
     */
    private static array $testColumns = [
        'browser'          => ['Browser', 'varchar'],
        'country'          => ['Country Code', 'varchar'],
        'ip'               => ['IP Address', 'varchar'],
        'other_ip'         => ['Originating IP', 'varchar'],
        'resource'         => ['Permalink', 'varchar'],
        'referer'          => ['Referer', 'varchar'],
        'language'         => ['Language', 'varchar'],
        'user_agent'       => ['User Agent', 'varchar'],
        'city'             => ['City', 'varchar'],
        'username'         => ['Username', 'varchar'],
        'page_performance' => ['Page Speed', 'int'],
        'visit_id'         => ['Visit ID', 'int'],
        'screen_width'     => ['Screen Width', 'int'],
        'screen_height'    => ['Screen Height', 'int'],
        'content_id'       => ['Resource ID', 'int'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Stub WP functions the class file may reference at include time or
        // in method bodies.
        Functions\stubs([
            '__'                  => static fn(string $text): string => $text,
            'apply_filters'       => static fn(string $tag, $value) => $value,
            'sanitize_key'        => static fn($v) => $v,
            'sanitize_text_field' => static fn($v) => $v,
            'absint'              => static fn($v) => abs((int) $v),
        ]);

        // Build a mock wpdb whose prepare() mimics the real placeholder
        // replacement: %s → quoted string, %d → integer.
        $this->wpdb = Mockery::mock('wpdb');
        $this->wpdb->prefix = 'wp_';

        $this->wpdb->shouldReceive('prepare')
            ->andReturnUsing(static function (string $query, ...$args): string {
                // Flatten arrays (used by the 'between' operator).
                $flat = [];
                foreach ($args as $arg) {
                    if (is_array($arg)) {
                        foreach ($arg as $v) {
                            $flat[] = $v;
                        }
                    } else {
                        $flat[] = $arg;
                    }
                }

                $i = 0;
                return preg_replace_callback('/%[sd]/', static function ($m) use ($flat, &$i) {
                    $val = $flat[$i] ?? '';
                    $i++;
                    if ($m[0] === '%d') {
                        return (string) intval($val);
                    }
                    return "'" . addslashes((string) $val) . "'";
                }, $query);
            });

        $GLOBALS['wpdb'] = $this->wpdb;

        // Ensure the wp_slimstat stub has expected settings.
        \wp_slimstat::$settings['geolocation_country'] = 'off';
        \wp_slimstat::$settings['show_sql_debug']      = 'off';

        // Load the class under test exactly once.
        $dbFile = dirname(__DIR__, 2) . '/admin/view/wp-slimstat-db.php';
        if (!class_exists('wp_slimstat_db', false)) {
            require_once $dbFile;
        }

        // Populate the static column maps without calling init() (which
        // triggers date parsing, count_records, toggle_date_i18n_filters, etc.).
        $ref = new \ReflectionClass(\wp_slimstat_db::class);

        $colNames = $ref->getProperty('columns_names');
        $colNames->setValue(null, self::$testColumns);

        $allColNames = $ref->getProperty('all_columns_names');
        $allColNames->setValue(null, array_merge(self::$testColumns, [
            'dt'      => ['Timestamp', 'int'],
            'dt_out'  => ['Exit Timestamp', 'int'],
            'hour'    => ['Hour', 'int'],
            'day'     => ['Day', 'int'],
            'month'   => ['Month', 'int'],
            'year'    => ['Year', 'int'],
        ]));
    }

    protected function tearDown(): void
    {
        // Reset static state to avoid leaking between tests.
        $ref = new \ReflectionClass(\wp_slimstat_db::class);

        $filtersNorm = $ref->getProperty('filters_normalized');
        $filtersNorm->setValue(null, []);

        $sqlWhere = $ref->getProperty('sql_where');
        $sqlWhere->setValue(null, ['columns' => '', 'time_range' => '']);

        $colNames = $ref->getProperty('columns_names');
        $colNames->setValue(null, []);

        $allColNames = $ref->getProperty('all_columns_names');
        $allColNames->setValue(null, []);

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // get_single_where_clause — operator coverage
    // ------------------------------------------------------------------

    /**
     * @test
     */
    public function test_single_where_equals_generates_correct_sql(): void
    {
        $sql = \wp_slimstat_db::get_single_where_clause('browser', 'equals', 'Firefox');

        $this->assertStringContainsString('browser', $sql);
        $this->assertStringContainsString("'Firefox'", $sql);
        // The 'equals' (default) operator uses "column = %s"
        $this->assertMatchesRegularExpression('/browser\s*=\s*/', $sql);
    }

    /**
     * BUG GUARD (M1, query layer) — build_goal_where() must drop a value-bearing
     * operator that has an empty value, because get_single_where_clause() would
     * otherwise return an unprepared fragment containing a literal "%s" placeholder
     * (it skips prepare() when the value is empty), which breaks the funnel/goal SQL.
     *
     * @test
     */
    public function test_build_goal_where_drops_empty_value_for_value_bearing_operator(): void
    {
        $build = new \ReflectionMethod(\wp_slimstat_db::class, 'build_goal_where');
        $build->setAccessible(true);

        // Value-bearing operator + empty value → no clause (no unbound %s reaches SQL).
        $this->assertSame(
            '',
            $build->invoke(null, ['dimension' => 'resource', 'operator' => 'equals', 'value' => ''], 't1')
        );

        // A real value still yields a prepared clause with no leftover placeholder.
        $real = $build->invoke(null, ['dimension' => 'resource', 'operator' => 'equals', 'value' => '/pricing'], 't1');
        $this->assertStringNotContainsString('%s', $real, 'A prepared clause must not contain a raw %s placeholder');
        $this->assertStringContainsString("'/pricing'", $real);

        // A valueless operator (is_empty) is still allowed with no value.
        $valueless = $build->invoke(null, ['dimension' => 'resource', 'operator' => 'is_empty', 'value' => ''], 't1');
        $this->assertNotSame('', $valueless);
        $this->assertStringNotContainsString('%s', $valueless);
    }

    /**
     * @test
     */
    public function test_single_where_contains_uses_like(): void
    {
        $sql = \wp_slimstat_db::get_single_where_clause('browser', 'contains', 'Fire');

        $this->assertStringContainsString('LIKE', $sql);
        // Value should be wrapped with % wildcards
        $this->assertStringContainsString('%Fire%', $sql);
    }

    /**
     * @test
     */
    public function test_single_where_does_not_contain_uses_not_like(): void
    {
        $sql = \wp_slimstat_db::get_single_where_clause('browser', 'does_not_contain', 'bot');

        $this->assertStringContainsString('NOT LIKE', $sql);
        $this->assertStringContainsString('%bot%', $sql);
    }

    /**
     * @test
     */
    public function test_single_where_is_not_equal_to(): void
    {
        $sql = \wp_slimstat_db::get_single_where_clause('country', 'is_not_equal_to', 'US');

        $this->assertStringContainsString('<>', $sql);
        $this->assertStringContainsString('country', $sql);
    }

    /**
     * @test
     */
    public function test_single_where_starts_with(): void
    {
        $sql = \wp_slimstat_db::get_single_where_clause('resource', 'starts_with', '/blog');

        $this->assertStringContainsString('LIKE', $sql);
        // starts_with wraps value as "value%"
        $this->assertStringContainsString('/blog%', $sql);
    }

    /**
     * @test
     */
    public function test_single_where_ends_with(): void
    {
        $sql = \wp_slimstat_db::get_single_where_clause('resource', 'ends_with', '.html');

        $this->assertStringContainsString('LIKE', $sql);
        $this->assertStringContainsString('%.html', $sql);
    }

    /**
     * @test
     */
    public function test_single_where_is_empty_for_varchar_uses_is_null(): void
    {
        $sql = \wp_slimstat_db::get_single_where_clause('browser', 'is_empty', '');

        $this->assertStringContainsString('IS NULL', $sql);
    }

    /**
     * @test
     */
    public function test_single_where_is_not_empty_for_varchar_uses_is_not_null(): void
    {
        $sql = \wp_slimstat_db::get_single_where_clause('browser', 'is_not_empty', '');

        $this->assertStringContainsString('IS NOT NULL', $sql);
    }

    /**
     * @test
     */
    public function test_single_where_is_empty_for_int_uses_zero(): void
    {
        $sql = \wp_slimstat_db::get_single_where_clause('visit_id', 'is_empty', '');

        $this->assertStringContainsString('= 0', $sql);
    }

    /**
     * @test
     */
    public function test_single_where_is_greater_than(): void
    {
        $sql = \wp_slimstat_db::get_single_where_clause('page_performance', 'is_greater_than', '500');

        $this->assertStringContainsString('>', $sql);
        $this->assertStringContainsString('page_performance', $sql);
    }

    /**
     * @test
     */
    public function test_single_where_is_less_than(): void
    {
        $sql = \wp_slimstat_db::get_single_where_clause('page_performance', 'is_less_than', '100');

        $this->assertStringContainsString('<', $sql);
        $this->assertStringContainsString('page_performance', $sql);
    }

    /**
     * @test
     */
    public function test_single_where_between(): void
    {
        $sql = \wp_slimstat_db::get_single_where_clause('screen_width', 'between', '320,1920');

        $this->assertStringContainsString('BETWEEN', $sql);
        $this->assertStringContainsString('320', $sql);
        $this->assertStringContainsString('1920', $sql);
    }

    /**
     * @test
     */
    public function test_single_where_matches_uses_regexp(): void
    {
        $sql = \wp_slimstat_db::get_single_where_clause('user_agent', 'matches', '^Mozilla');

        $this->assertStringContainsString('REGEXP', $sql);
        $this->assertStringNotContainsString('NOT REGEXP', $sql);
    }

    /**
     * @test
     */
    public function test_single_where_does_not_match_uses_not_regexp(): void
    {
        $sql = \wp_slimstat_db::get_single_where_clause('user_agent', 'does_not_match', 'bot$');

        $this->assertStringContainsString('NOT REGEXP', $sql);
    }

    /**
     * @test
     */
    public function test_single_where_sounds_like(): void
    {
        $sql = \wp_slimstat_db::get_single_where_clause('city', 'sounds_like', 'London');

        $this->assertStringContainsString('SOUNDEX', $sql);
    }

    // ------------------------------------------------------------------
    // Special-character escaping
    // ------------------------------------------------------------------

    /**
     * @test
     */
    public function test_single_where_escapes_single_quotes(): void
    {
        $sql = \wp_slimstat_db::get_single_where_clause('browser', 'equals', "O'Reilly");

        // htmlentities with ENT_QUOTES converts ' to &#039; before prepare().
        // Our mock's addslashes then escapes the apostrophe in &#039; is a no-op,
        // so the raw unescaped O'Reilly must not appear.
        $this->assertStringNotContainsString("O'Reilly'", $sql, 'Raw single-quote must not break out of the SQL string');
    }

    /**
     * @test
     *
     * These tests intentionally expect escaped values. The implementation
     * currently passes raw values, which is a known bug. When esc_like() is
     * added to the LIKE operators in wp-slimstat-db.php, these tests will
     * start passing.
     */
    public function test_single_where_escapes_percent_in_like(): void
    {
        $this->markTestIncomplete('Requires esc_like() fix in wp_slimstat_db — see E-DEV-WPSLIMSTAT-XXX');

        // The 'contains' operator wraps with %...% — an embedded % in the value
        // must be escaped to \% so it matches a literal percent sign, not a wildcard.
        $sql = \wp_slimstat_db::get_single_where_clause('resource', 'contains', '100%');

        $this->assertStringContainsString('LIKE', $sql);
        $this->assertStringContainsString('100\%', $sql);
    }

    /**
     * @test
     */
    public function test_single_where_escapes_double_quotes(): void
    {
        $sql = \wp_slimstat_db::get_single_where_clause('browser', 'equals', 'My"Browser');

        // htmlentities converts " to &quot; so the raw double-quote should not
        // appear in the prepared value.
        $this->assertStringContainsString('browser', $sql);
        $this->assertStringContainsString('&quot;', $sql);
        $this->assertStringNotContainsString('My"Browser', $sql);
    }

    /**
     * @test
     *
     * These tests intentionally expect escaped values. The implementation
     * currently passes raw values, which is a known bug. When esc_like() is
     * added to the LIKE operators in wp-slimstat-db.php, these tests will
     * start passing.
     */
    public function test_single_where_handles_underscore_in_like(): void
    {
        $this->markTestIncomplete('Requires esc_like() fix in wp_slimstat_db — see E-DEV-WPSLIMSTAT-XXX');

        // Underscore is a LIKE wildcard in MySQL; it must be escaped to \_
        // so it matches a literal underscore, not any single character.
        $sql = \wp_slimstat_db::get_single_where_clause('resource', 'contains', 'my_page');

        $this->assertStringContainsString('LIKE', $sql);
        $this->assertStringContainsString('my\_page', $sql);
    }

    // ------------------------------------------------------------------
    // Table alias support
    // ------------------------------------------------------------------

    /**
     * @test
     */
    public function test_single_where_with_table_alias(): void
    {
        $sql = \wp_slimstat_db::get_single_where_clause('browser', 'equals', 'Chrome', 't1');

        $this->assertStringContainsString('t1.browser', $sql);
    }

    // ------------------------------------------------------------------
    // IP dimension special handling
    // ------------------------------------------------------------------

    /**
     * @test
     */
    public function test_single_where_ip_is_empty_uses_zero_ip(): void
    {
        $sql = \wp_slimstat_db::get_single_where_clause('ip', 'is_empty', '');

        $this->assertStringContainsString('0.0.0.0', $sql);
    }

    // ------------------------------------------------------------------
    // _get_sql_where — combining multiple filters with AND
    // ------------------------------------------------------------------

    /**
     * @test
     */
    public function test_get_sql_where_combines_filters_with_and(): void
    {
        $filters = [
            'browser' => ['equals', 'Firefox'],
            'country' => ['equals', 'US'],
        ];

        // _get_sql_where is protected — use Reflection to call it.
        $ref = new \ReflectionMethod(\wp_slimstat_db::class, '_get_sql_where');

        $sql = $ref->invoke(null, $filters, '');

        $this->assertStringContainsString('browser', $sql);
        $this->assertStringContainsString('country', $sql);
        $this->assertStringContainsString(' AND ', $sql);
    }

    /**
     * @test
     */
    public function test_get_sql_where_returns_empty_for_no_filters(): void
    {
        $ref = new \ReflectionMethod(\wp_slimstat_db::class, '_get_sql_where');

        $sql = $ref->invoke(null, [], '');

        $this->assertSame('', $sql);
    }

    /**
     * @test
     */
    public function test_get_sql_where_skips_addon_filters(): void
    {
        $filters = [
            'addon_custom' => ['equals', 'test'],
            'browser'      => ['equals', 'Chrome'],
        ];

        $ref = new \ReflectionMethod(\wp_slimstat_db::class, '_get_sql_where');

        $sql = $ref->invoke(null, $filters, '');

        $this->assertStringContainsString('browser', $sql);
        $this->assertStringNotContainsString('addon_custom', $sql);
        // Only one real filter, so no AND
        $this->assertStringNotContainsString(' AND ', $sql);
    }

    /**
     * @test
     */
    public function test_get_sql_where_with_alias(): void
    {
        $filters = [
            'browser' => ['contains', 'Fire'],
        ];

        $ref = new \ReflectionMethod(\wp_slimstat_db::class, '_get_sql_where');

        $sql = $ref->invoke(null, $filters, 't1');

        $this->assertStringContainsString('t1.browser', $sql);
    }

    // ------------------------------------------------------------------
    // get_combined_where — date range integration
    // ------------------------------------------------------------------

    /**
     * @test
     */
    public function test_get_combined_where_includes_date_range(): void
    {
        $start = 1700000000;
        $end   = 1700086400;

        // Set filters_normalized with a date range via Reflection.
        $ref = new \ReflectionProperty(\wp_slimstat_db::class, 'filters_normalized');
        $ref->setValue(null, [
            'columns' => [
                'browser' => ['equals', 'Firefox'],
            ],
            'utime' => [
                'start' => $start,
                'end'   => $end,
            ],
        ]);

        $sql = \wp_slimstat_db::get_combined_where('', '*', true);

        // Should contain BETWEEN with both timestamps
        $this->assertStringContainsString('BETWEEN', $sql);
        $this->assertStringContainsString((string) $start, $sql);
        $this->assertStringContainsString((string) $end, $sql);
        // Should also contain the browser filter
        $this->assertStringContainsString('browser', $sql);
    }

    /**
     * @test
     */
    public function test_get_combined_where_without_date_filters(): void
    {
        $ref = new \ReflectionProperty(\wp_slimstat_db::class, 'filters_normalized');
        $ref->setValue(null, [
            'columns' => [
                'country' => ['equals', 'DE'],
            ],
            'utime' => [
                'start' => 1700000000,
                'end'   => 1700086400,
            ],
        ]);

        $sql = \wp_slimstat_db::get_combined_where('', '*', false);

        // Date filters disabled — BETWEEN should NOT appear
        $this->assertStringNotContainsString('BETWEEN', $sql);
        // Column filter should still be present
        $this->assertStringContainsString('country', $sql);
    }

    /**
     * @test
     */
    public function test_get_combined_where_falls_back_to_1_equals_1_when_no_column_filters(): void
    {
        $ref = new \ReflectionProperty(\wp_slimstat_db::class, 'filters_normalized');
        $ref->setValue(null, [
            'columns' => [],
            'utime' => [
                'start' => 1700000000,
                'end'   => 1700086400,
            ],
        ]);

        $sql = \wp_slimstat_db::get_combined_where('', '*', true);

        // When no column filters, the WHERE should contain 1=1
        $this->assertStringContainsString('1=1', $sql);
        // And date range should still be present
        $this->assertStringContainsString('BETWEEN', $sql);
    }

    /**
     * @test
     */
    public function test_get_combined_where_appends_to_existing_where(): void
    {
        $ref = new \ReflectionProperty(\wp_slimstat_db::class, 'filters_normalized');
        $ref->setValue(null, [
            'columns' => [
                'language' => ['equals', 'en-us'],
            ],
            'utime' => [
                'start' => 1700000000,
                'end'   => 1700086400,
            ],
        ]);

        $sql = \wp_slimstat_db::get_combined_where('resource IS NOT NULL', '*', true);

        // The pre-existing WHERE should be preserved
        $this->assertStringContainsString('resource IS NOT NULL', $sql);
        // The column filter should be appended with AND
        $this->assertStringContainsString('language', $sql);
        // Date range too
        $this->assertStringContainsString('BETWEEN', $sql);
    }

    // ------------------------------------------------------------------
    // get_top — ORDER BY with expression columns carrying as_column
    // ------------------------------------------------------------------

    /**
     * Run get_top() against the mock connection and return every SQL string it executed.
     *
     * @param array<string,mixed> $args get_top() array arguments
     * @return string[]
     */
    private function captureGetTopSql(array $args): array
    {
        $captured = [];
        $this->wpdb->shouldReceive('get_results')
            ->andReturnUsing(static function ($sql) use (&$captured) {
                $captured[] = (string) $sql;
                return [];
            });

        // get_top() unconditionally allows caching; the lookup must miss so the
        // query actually reaches get_results().
        $this->stubTransientCacheMiss();

        $ref = new \ReflectionProperty(\wp_slimstat_db::class, 'filters_normalized');
        $ref->setValue(null, ['misc' => ['limit_results' => 20]]);

        \wp_slimstat_db::get_top($args + ['use_date_filters' => false]);

        return $captured;
    }

    /**
     * The ORDER BY clause (through to the end of the SQL) of the single query a
     * get_top() call executed — asserting on the way that a query WAS executed and
     * that it carries an ORDER BY at all, so each test keeps only its distinctive
     * assertions and an absent ORDER BY cannot make substr() hand back the whole
     * statement as the "clause".
     */
    private function getTopOrderClause(array $args): string
    {
        $captured = $this->captureGetTopSql($args);
        $this->assertNotEmpty($captured, 'get_top() executed no query at all');

        $orderPos = stripos($captured[0], 'ORDER BY');
        $this->assertNotFalse($orderPos, "no ORDER BY in: {$captured[0]}");

        return substr($captured[0], (int) $orderPos);
    }

    /**
     * BUG GUARD — the tie-break must order by the ALIAS, never by the aliased
     * expression. `$_column` is rewritten to "<expr> AS <alias>" before the
     * tie-break block, and appending THAT produced
     *
     *     ORDER BY counthits DESC, REPLACE(...) AS referer ASC
     *
     * which MySQL rejects ("near 'AS referer ASC'") — every as_column report
     * (Top Referring Domains, platform, trailing-slash resource, language)
     * rendered empty while the identical query without the tie-break had worked.
     *
     * @test
     */
    public function test_get_top_tiebreak_orders_by_alias_not_aliased_expression(): void
    {
        $orderClause = $this->getTopOrderClause([
            'columns'   => 'REPLACE( SUBSTRING_INDEX( ( SUBSTRING_INDEX( ( SUBSTRING_INDEX( referer, "://", -1 ) ), "/", 1 ) ), ".", -5 ), "www.", "" )',
            'as_column' => 'referer',
        ]);

        // An aliased expression inside ORDER BY is a syntax error, full stop.
        $this->assertStringNotContainsStringIgnoringCase(
            ' AS ',
            $orderClause,
            "ORDER BY carries an aliased expression — invalid SQL: {$orderClause}"
        );

        // The tie-break itself must still be there, spelled as the bare alias.
        $this->assertMatchesRegularExpression(
            '/ORDER BY\s+counthits DESC,\s*referer ASC/i',
            $orderClause,
            "tie-break on the alias is missing: {$orderClause}"
        );
    }

    /**
     * BUG GUARD (companion) — when the caller already orders by the alias, no
     * tie-break is appended. Under the defect the containment check searched
     * $_order_by for the whole "<expr> AS <alias>" string, which can essentially
     * never match, so a duplicate (and invalid) sort column was appended even
     * when the caller had ordered by the column already.
     *
     * @test
     */
    public function test_get_top_no_duplicate_tiebreak_when_caller_orders_by_alias(): void
    {
        $orderClause = $this->getTopOrderClause([
            'columns'   => 'CONCAT("p-", SUBSTRING(platform, 1, 3))',
            'as_column' => 'platform',
            'order_by'  => 'platform ASC',
        ]);

        $this->assertStringNotContainsStringIgnoringCase(' AS ', $orderClause);
        $this->assertSame(
            1,
            substr_count(strtolower($orderClause), 'platform'),
            "the sort column appears more than once: {$orderClause}"
        );
    }
}
