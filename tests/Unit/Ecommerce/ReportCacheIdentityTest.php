<?php
/**
 * #409 and the filtered-Ecommerce miss: the Ecommerce page, its Copy Prompt and an interval
 * switch ask the same question, so they must read one transient. The page keyed "end" as now
 * rounded up to the minute and the prompt keyed 23:59:59 of the same day (both clamp the query
 * to now), and a contains/starts/ends filter put wpdb's per-request LIKE marker into the scope
 * part of the key, so a filtered page never hit at all (PITFALLS 257).
 *
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Ecommerce;

use SlimStat\Ecommerce\Report;
use WpSlimstat\Tests\Unit\WpSlimstatTestCase;

class ReportCacheIdentityTest extends WpSlimstatTestCase
{
    private const NOW = 1762858800; // 2025-11-11 11:00:00 UTC

    /** @var mixed */
    private $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        \Brain\Monkey\Functions\when('get_option')->justReturn('0'); // Query::cacheHash() reads slimstat_data_ver
        $this->wpdb = $GLOBALS['wpdb'] ?? null;
        // Same fake as tests/query-placeholder-cache-test.php: each request gets its own marker.
        $GLOBALS['wpdb'] = new class {
            public $marker = '{marker-a}';
            public function remove_placeholder_escape($sql) { return str_replace($this->marker, '%', $sql); }
        };
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->wpdb;
        parent::tearDown();
    }

    private static function identity(int $start, int $end, string $scope = '', int $now = self::NOW): array
    {
        $ref = new \ReflectionMethod(Report::class, 'cacheIdentity');
        if (\PHP_VERSION_ID < 80100) {
            $ref->setAccessible(true);
        }
        return $ref->invoke(null, [$start, $end], $now, $scope);
    }

    public function test_page_prompt_and_a_later_page_share_one_identity(): void
    {
        $today = self::NOW - self::NOW % DAY_IN_SECONDS;
        $page = self::identity($today, self::NOW + 5, '', self::NOW + 6); // the clock ticked between normalization and the key
        self::assertSame($page, self::identity($today, $today + DAY_IN_SECONDS - 1, '', self::NOW + 20), 'Copy Prompt keys the end of the day');
        self::assertSame($page, self::identity($today, self::NOW + 35, '', self::NOW + 35), 'the page rendered again 30 s later');
    }

    /** CodeRabbit on #411: "Yesterday" at 00:00:30 must not read the "This week" entry, which holds today's first orders. */
    public function test_a_range_that_closed_in_the_last_minute_keeps_its_end(): void
    {
        $today = self::NOW - self::NOW % DAY_IN_SECONDS;
        $yesterday = self::identity($today - DAY_IN_SECONDS, $today - 1, '', $today + 30);
        self::assertSame($today - 1, $yesterday[1]);
        self::assertNotSame($yesterday, self::identity($today - DAY_IN_SECONDS, $today + 30, '', $today + 30));
        self::assertSame(self::NOW - 1, self::identity(self::NOW - HOUR_IN_SECONDS, self::NOW - 1, '', self::NOW + 30)[1], 'last hour, 30 s after it closed');
    }

    public function test_past_ranges_keep_their_end(): void
    {
        $a = self::identity(1000, self::NOW - DAY_IN_SECONDS);
        self::assertSame(self::NOW - DAY_IN_SECONDS, $a[1]);
        self::assertNotSame($a, self::identity(1000, self::NOW - 2 * DAY_IN_SECONDS));
    }

    public function test_the_like_marker_does_not_split_the_key(): void
    {
        $first = self::identity(1000, self::NOW, "AND t1.resource LIKE '{marker-a}page{marker-a}'");
        $GLOBALS['wpdb']->marker = '{marker-b}';
        self::assertSame($first, self::identity(1000, self::NOW, "AND t1.resource LIKE '{marker-b}page{marker-b}'"));
        self::assertNotSame($first, self::identity(1000, self::NOW, "AND t1.resource LIKE '{marker-b}other{marker-b}'"));
    }
}
