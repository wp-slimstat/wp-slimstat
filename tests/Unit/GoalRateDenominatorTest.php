<?php
/**
 * Goal conversion rate over 100%: a goal recomputed at 10:06 divided 10:06 uniques by a
 * visitor total cached at 10:00 (15 min transient, hour-bucketed key), so 7 converters over
 * 5 cached visitors showed 140%. A window that reaches today must count its denominator
 * fresh; a historical window keeps the transient (PITFALLS 277 class).
 *
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit;

use Brain\Monkey\Functions;

class GoalRateDenominatorTest extends WpSlimstatTestCase
{
    /** @var array<int,mixed> */
    private $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists('wp_slimstat_db', false)) {
            require_once dirname(__DIR__, 2) . '/admin/view/wp-slimstat-db.php';
        }
        $this->saved = [$GLOBALS['wpdb'] ?? null, \wp_slimstat::$wpdb, \wp_slimstat_db::$filters_normalized];
        $GLOBALS['wpdb'] = new class {
            public $prefix = 'wp_';
            public function prepare($query, ...$args) { return vsprintf($query, $args); }
        };
        // Not a \wpdb, so visitor_id_expr() assumes the manifest columns without probing.
        \wp_slimstat::$wpdb = new class {
            public function get_var($sql) { return '9'; }
        };
        // The cached denominator is stale: 5 visitors, while the table now holds 9.
        Functions\when('get_transient')->justReturn(5);
        Functions\when('set_transient')->justReturn(true);
    }

    protected function tearDown(): void
    {
        [$GLOBALS['wpdb'], \wp_slimstat::$wpdb, \wp_slimstat_db::$filters_normalized] = $this->saved;
        parent::tearDown();
    }

    private static function denominator(int $start, int $end): int
    {
        \wp_slimstat_db::$filters_normalized['utime'] = ['start' => $start, 'end' => $end];
        \wp_slimstat_db::$filters_normalized['columns'] = [];
        $m = new \ReflectionMethod(\wp_slimstat_db::class, 'get_total_unique_visitors');
        if (\PHP_VERSION_ID < 80100) {
            $m->setAccessible(true);
        }
        // A scope per call, so the request memo cannot answer for the transient.
        return $m->invoke(null, 't1.id > ' . random_int(1, PHP_INT_MAX));
    }

    public function test_a_window_reaching_today_counts_fresh_visitors(): void
    {
        $today = intdiv(\wp_slimstat::now(), DAY_IN_SECONDS) * DAY_IN_SECONDS;
        self::assertSame(9, self::denominator($today - 6 * DAY_IN_SECONDS, \wp_slimstat::now()));
    }

    public function test_a_historical_window_keeps_the_cached_total(): void
    {
        $today = intdiv(\wp_slimstat::now(), DAY_IN_SECONDS) * DAY_IN_SECONDS;
        self::assertSame(5, self::denominator($today - 7 * DAY_IN_SECONDS, $today - 1));
    }
}
