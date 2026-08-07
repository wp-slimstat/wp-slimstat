<?php
/**
 * Round-trip test for the Free-tier goal pause MARKER and its reactivation.
 *
 * When the license lapses, pause_excess_free_goals() pauses excess goals AND
 * tags them paused_by_tier. When Pro capability returns, restore_tier_paused_goals()
 * reactivates exactly those tagged goals and clears the marker, leaving goals the
 * user paused by hand untouched. Both helpers are pure, so we load the class and
 * call them directly. (#21)
 *
 * Run: php tests/goals-tier-restore-test.php
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

// Pause on Free (max 1): newest (id 3) stays active; id 1 and 2 paused + tagged.
$goals = [
	['id' => 1, 'active' => true],
	['id' => 2, 'active' => true],
	['id' => 3, 'active' => true],
];
$paused = wp_slimstat_reports::pause_excess_free_goals($goals, 1);
$check(true === $paused[2]['active'], 'newest goal (id 3) stays active');
$check(false === $paused[0]['active'] && !empty($paused[0]['paused_by_tier']), 'paused goal id 1 carries the paused_by_tier marker');
$check(false === $paused[1]['active'] && !empty($paused[1]['paused_by_tier']), 'paused goal id 2 carries the paused_by_tier marker');

// Reactivation restores exactly the tagged goals and clears the marker.
$restored = wp_slimstat_reports::restore_tier_paused_goals($paused);
foreach ($restored as $g) {
	$check(true === $g['active'], 'every tier-paused goal is reactivated on restore');
	$check(!isset($g['paused_by_tier']), 'the paused_by_tier marker is cleared after restore');
}

// A user-paused goal (no marker) must stay paused; only the tagged one returns.
$mixed = [
	['id' => 1, 'active' => false],                          // user-paused, no marker
	['id' => 2, 'active' => false, 'paused_by_tier' => true], // tier-paused
];
$out = wp_slimstat_reports::restore_tier_paused_goals($mixed);
$check(false === $out[0]['active'], 'a user-paused goal (no marker) stays paused');
$check(true === $out[1]['active'] && !isset($out[1]['paused_by_tier']), 'only the tier-paused goal is reactivated');

// Nothing tagged → returned unchanged (caller writes nothing).
$plain = [['id' => 1, 'active' => true], ['id' => 2, 'active' => false]];
$out   = wp_slimstat_reports::restore_tier_paused_goals($plain);
$check($out === $plain, 'a list with no tier-paused goals is returned unchanged');

if ($failures > 0) {
	fwrite(STDERR, "{$failures} check(s) failed in goals-tier-restore-test.php\n");
	exit(1);
}

echo "OK: tier pause-marker and reactivation round-trip correctly\n";
