<?php

declare(strict_types=1);

namespace WpSlimstat\Tests\Integration;

/**
 * Test double for wp_slimstat_db used by ajax_load_funnel_data tests.
 * Installed via class_alias so the handler's include_once no-ops (class already exists).
 */
final class FakeWpSlimstatDb
{
    public static array $next = [];
    public static array $getGoalResults = ['uniques' => 0, 'total' => 0, 'cr' => 0];

    public static function get_funnel_results(array $funnel): array
    {
        return self::$next;
    }

    public static function get_goal_results(array $goal): array
    {
        return self::$getGoalResults;
    }
}
