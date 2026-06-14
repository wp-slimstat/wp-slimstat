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

    // Mirror the real wp_slimstat_db public contract: sanitize_goal() reads this
    // shared list once the class (aliased to this fake) is loaded in-process.
    public static array $valueless_operators = ['is_empty', 'is_not_empty'];

    // ensure_goals_db_initialized() (admin/index.php) calls init() and then pins
    // the date window on this shared array, so the fake must expose both for the
    // funnel Test + lazy-load AJAX handlers to run without a live DB. (#6/#8)
    public static array $filters_normalized = [];

    public static function init($_filters = ''): void
    {
        // No-op double — the real init() hydrates $columns_names + filters.
    }

    public static function get_funnel_results(array $funnel): array
    {
        return self::$next;
    }

    public static function get_goal_results(array $goal): array
    {
        return self::$getGoalResults;
    }
}
