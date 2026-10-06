<?php
declare(strict_types=1);

namespace WpSlimstat\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/** Filter suggestions validate against a fallback column list; it once lacked Channel, so Channel had no suggestions. */
class FilterOptionsColumnsTest extends TestCase
{
    public function test_every_filter_column_has_suggestions(): void
    {
        $root = dirname(__DIR__, 3);
        $db = (string) file_get_contents($root . '/admin/view/wp-slimstat-db.php');
        $admin = (string) file_get_contents($root . '/admin/index.php');

        $start = strpos($db, 'self::$columns_names = [');
        preg_match_all("~'([a-z_]+)'\s*=>\s*\[__\(~", substr($db, $start, strpos($db, '];', $start) - $start), $filters);
        $start = strpos($admin, 'wp_slimstat_db::$columns_names = [');
        preg_match_all("~'([a-z_]+)'\s*=>\s*\[~", substr($admin, $start, strpos($admin, '];', $start) - $start), $fallback);

        self::assertContains('traffic_channel', $filters[1], 'the filter list pattern still matches');
        self::assertSame([], array_values(array_diff($filters[1], $fallback[1])), 'filter columns missing from the suggestions fallback');
    }
}
