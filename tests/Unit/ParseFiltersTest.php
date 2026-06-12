<?php
/**
 * Regression tests for #305 — is_empty / is_not_empty (value-less) filter
 * operators were silently dropped by parse_filters() since v5.4.0 (commit
 * 62f0434b), so the filter chip rendered but no WHERE clause reached SQL.
 *
 * Covers the parse-side guard + regex relaxation + parse→SQL round-trip, and
 * pins the original "drop incomplete value-bearing filters" intent so it is
 * preserved.
 *
 * @package WpSlimstat
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

class ParseFiltersTest extends WpSlimstatTestCase
{
    /** @var \Mockery\MockInterface&\stdClass */
    private $wpdb;

    /** Columns referenced by the tests: a varchar (email) and an int (visit_id). */
    private static array $testColumns = [
        'email'    => ['Email', 'varchar'],
        'browser'  => ['Browser', 'varchar'],
        'visit_id' => ['Visit ID', 'int'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Functions\stubs([
            '__'                  => static fn(string $text): string => $text,
            'apply_filters'       => static fn(string $tag, $value) => $value,
            'sanitize_key'        => static fn($v) => $v,
            'sanitize_text_field' => static fn($v) => $v,
            'absint'              => static fn($v) => abs((int) $v),
        ]);

        $this->wpdb = Mockery::mock('wpdb');
        $this->wpdb->prefix = 'wp_';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(
            static function (string $query, ...$args): string {
                $flat = [];
                foreach ($args as $arg) {
                    foreach (is_array($arg) ? $arg : [$arg] as $v) {
                        $flat[] = $v;
                    }
                }
                $i = 0;
                return preg_replace_callback('/%[sd]/', static function ($m) use ($flat, &$i) {
                    $val = $flat[$i] ?? '';
                    $i++;
                    return $m[0] === '%d' ? (string) intval($val) : "'" . addslashes((string) $val) . "'";
                }, $query);
            }
        );
        $GLOBALS['wpdb'] = $this->wpdb;

        $dbFile = dirname(__DIR__, 2) . '/admin/view/wp-slimstat-db.php';
        if (!class_exists('wp_slimstat_db', false)) {
            require_once $dbFile;
        }

        $ref = new \ReflectionClass(\wp_slimstat_db::class);
        $ref->getProperty('columns_names')->setValue(null, self::$testColumns);
        // all_columns_names must include 'minute' so the value-bearing malformed-date
        // case reaches the pre-switch guard (modification 3) rather than the earlier
        // column-existence guard.
        $ref->getProperty('all_columns_names')->setValue(null, array_merge(self::$testColumns, [
            'minute' => ['Minute', 'int'],
        ]));
    }

    protected function tearDown(): void
    {
        $ref = new \ReflectionClass(\wp_slimstat_db::class);
        $ref->getProperty('filters_normalized')->setValue(null, []);
        $ref->getProperty('sql_where')->setValue(null, ['columns' => '', 'time_range' => '']);
        $ref->getProperty('columns_names')->setValue(null, []);
        $ref->getProperty('all_columns_names')->setValue(null, []);
        parent::tearDown();
    }

    /** Invoke the protected _get_sql_where() via Reflection. */
    private static function sqlWhere(array $columns): string
    {
        $m = new \ReflectionMethod(\wp_slimstat_db::class, '_get_sql_where');
        $m->setAccessible(true);
        return $m->invoke(null, $columns, '');
    }

    // ── Group 1: value-less operator survives the guard ──────────────

    /** @test */
    public function test_email_is_not_empty_with_trailing_space_stores_empty_value(): void
    {
        $fn = \wp_slimstat_db::parse_filters('email is_not_empty ');
        $this->assertArrayHasKey('email', $fn['columns']);
        $this->assertSame(['is_not_empty', ''], $fn['columns']['email']);
    }

    /** @test */
    public function test_email_is_empty_with_trailing_space_stores_empty_value(): void
    {
        $fn = \wp_slimstat_db::parse_filters('email is_empty ');
        $this->assertSame(['is_empty', ''], $fn['columns']['email']);
    }

    /** @test */
    public function test_visit_id_is_not_empty_stores_empty_value(): void
    {
        $fn = \wp_slimstat_db::parse_filters('visit_id is_not_empty ');
        $this->assertSame(['is_not_empty', ''], $fn['columns']['visit_id']);
    }

    // ── Group 2: regex relaxation (post-sanitize_text_field shape) ───

    /** @test */
    public function test_email_is_not_empty_without_trailing_space_still_parses(): void
    {
        // sanitize_text_field() trims the trailing space on fs[] URL round-trips.
        $fn = \wp_slimstat_db::parse_filters('email is_not_empty');
        $this->assertSame(['is_not_empty', ''], $fn['columns']['email']);
    }

    /** @test */
    public function test_email_is_empty_without_trailing_space_still_parses(): void
    {
        $fn = \wp_slimstat_db::parse_filters('email is_empty');
        $this->assertSame(['is_empty', ''], $fn['columns']['email']);
    }

    // ── Group 3: stale-value defense ─────────────────────────────────

    /** @test */
    public function test_is_not_empty_with_stale_value_scrubs_value(): void
    {
        $fn = \wp_slimstat_db::parse_filters('email is_not_empty stale_garbage_from_ui');
        $this->assertSame(['is_not_empty', ''], $fn['columns']['email'], 'stale UI value must not propagate');
    }

    // ── Group 4: legitimate-drop guard (preserve 62f0434b intent) ────

    /** @test */
    public function test_drops_value_bearing_operator_with_empty_value(): void
    {
        $fn = \wp_slimstat_db::parse_filters('email contains ');
        $this->assertArrayNotHasKey('email', $fn['columns']);
    }

    /** @test */
    public function test_drops_malformed_date_filter_without_value(): void
    {
        // 'minute equals' (value-bearing operator, no value) must be dropped by the
        // pre-switch guard before reaching the date-special-case branch.
        $fn = \wp_slimstat_db::parse_filters('minute equals');
        $this->assertArrayNotHasKey('minute', $fn['date']);
    }

    // ── Group 5: parse → SQL round-trip ──────────────────────────────

    /** @test */
    public function test_round_trip_email_is_not_empty_produces_is_not_null_sql(): void
    {
        $fn  = \wp_slimstat_db::parse_filters('email is_not_empty ');
        $sql = self::sqlWhere($fn['columns']);
        $this->assertStringContainsString('email', $sql);
        $this->assertStringContainsString('IS NOT NULL', $sql);
    }

    /** @test */
    public function test_round_trip_visit_id_is_empty_produces_zero_sql(): void
    {
        $fn  = \wp_slimstat_db::parse_filters('visit_id is_empty ');
        $sql = self::sqlWhere($fn['columns']);
        $this->assertStringContainsString('visit_id', $sql);
        $this->assertStringContainsString('= 0', $sql);
    }

    // ── Group 6: differentiate value-less op from removal signal ─────

    /** @test */
    public function test_value_bearing_filter_still_parses_normally(): void
    {
        $fn = \wp_slimstat_db::parse_filters('email contains acme');
        $this->assertSame(['contains', 'acme'], $fn['columns']['email']);
    }

    /** @test */
    public function test_round_trip_value_less_and_value_bearing_coexist(): void
    {
        $fn = \wp_slimstat_db::parse_filters('email is_not_empty &&&browser contains Chrome');
        $this->assertSame(['is_not_empty', ''], $fn['columns']['email']);
        $this->assertSame(['contains', 'Chrome'], $fn['columns']['browser']);
    }
}
