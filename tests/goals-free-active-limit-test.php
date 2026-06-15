<?php
/**
 * Behavior test for the Free-tier active-goal auto-pause.
 *
 * Free allows one active goal. When more are active (e.g. left over from a
 * Pro→Free downgrade), wp_slimstat_reports::pause_excess_free_goals() keeps the
 * newest active goal active and pauses the rest. The helper is pure (no WP), so
 * we load the class and call it directly with a few scenarios.
 *
 * Run: php tests/goals-free-active-limit-test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../admin/view/wp-slimstat-reports.php';

$failures = 0;
$check = static function (bool $ok, string $msg) use (&$failures): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        $failures++;
    }
};
$activeIds = static function (array $goals): array {
    $out = [];
    foreach ($goals as $g) {
        if (!empty($g['active'])) {
            $out[] = (int) $g['id'];
        }
    }
    sort($out);
    return $out;
};

// 1) Two active on Free (max 1): newest id stays active, older paused.
$goals = [
    ['id' => 1, 'name' => 'Old', 'active' => true],
    ['id' => 2, 'name' => 'New', 'active' => true],
];
$out = wp_slimstat_reports::pause_excess_free_goals($goals, 1);
$check($activeIds($out) === [2], 'newest goal (id 2) must remain the only active one');
$check(false === $out[0]['active'], 'older goal (id 1) must be paused');
$check(true === $out[1]['active'], 'newest goal (id 2) must stay active');

// 2) "Newest" is decided by id, not array order.
$goals = [
    ['id' => 9, 'name' => 'Newest, listed first', 'active' => true],
    ['id' => 3, 'name' => 'Older, listed second', 'active' => true],
];
$out = wp_slimstat_reports::pause_excess_free_goals($goals, 1);
$check($activeIds($out) === [9], 'highest id stays active regardless of array order');

// 3) Already within the limit: returned unchanged (nothing flipped, so the
//    caller writes nothing).
$goals = [
    ['id' => 1, 'name' => 'Only active', 'active' => true],
    ['id' => 2, 'name' => 'Already paused', 'active' => false],
];
$out = wp_slimstat_reports::pause_excess_free_goals($goals, 1);
$check($out === $goals, 'a list already within the limit must be returned unchanged');

// 4) Pro limit (max 5): three active stay active (count <= max).
$goals = [
    ['id' => 1, 'active' => true],
    ['id' => 2, 'active' => true],
    ['id' => 3, 'active' => true],
];
$out = wp_slimstat_reports::pause_excess_free_goals($goals, 5);
$check($activeIds($out) === [1, 2, 3], 'within the (Pro) limit, no goal is paused');

// 5) Keeps the newest N active when max > 1.
$out = wp_slimstat_reports::pause_excess_free_goals($goals, 2);
$check($activeIds($out) === [2, 3], 'keeps the newest 2 active, pauses the oldest');

// 6) A limit of 0 pauses every active goal (zero allowed → zero active).
$goals = [
    ['id' => 1, 'active' => true],
    ['id' => 2, 'active' => true],
];
$out = wp_slimstat_reports::pause_excess_free_goals($goals, 0);
$check($activeIds($out) === [], 'a limit of 0 must pause every active goal');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} check(s) failed in goals-free-active-limit-test.php\n");
    exit(1);
}

echo "OK: Free active-goal auto-pause keeps the newest goal(s) active\n";
