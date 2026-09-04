<?php
/**
 * A suite can lose coverage without losing a test. This is the check that notices.
 *
 * PITFALLS 41. `Processor.php` moved from `date_i18n('U')` to `now()`; the unit stub class had no
 * `now()`, so two `ProcessorTest` cases threw. Both wrap their subject in `try/catch` and call
 * `markTestIncomplete()` on any `Throwable` — so the throw arrived as a TODO, the suite reported
 * **OK**, and two behavioural assertions had silently stopped executing.
 *
 *     Tests: 317, Assertions: 602, Incomplete: 5      before
 *     Tests: 317, Assertions: 598, Incomplete: 7      after
 *
 * The test count is identical. **The four missing assertions were the entire signal**, and nothing
 * in the pipeline was looking at them.
 *
 * WHY THIS IS NOT A BAN ON `markTestIncomplete()`. Seven such blocks exist and they are deliberate:
 * these tests exercise tracker paths that genuinely cannot run without a bootstrapped WordPress,
 * and the authors chose "record a TODO" over "fail on every machine". That judgement is fine. What
 * is not fine is that the mechanism converts every FUTURE breakage of those tests into a TODO as
 * well — it is a permanent invitation, and only a number can tell the two apart.
 *
 * SO THE FLOOR IS ON THREE THINGS, and the third is the one that matters:
 *
 *   tests       >= floor   a deleted test is not a passing test
 *   assertions  >= floor   THE one that catches a silent degradation
 *   incomplete  <= ceiling a test that stopped running must be a decision, not a drift
 *
 * Ratchets, not equalities: adding tests must not require editing this file, and a rise in
 * assertions is the direction nobody needs protecting from.
 *
 * 7.4-safe: reads JUnit XML that `composer test:unit` / `test:integration` emit. It does NOT run
 * PHPUnit itself — those two need the dev autoloader, and this file has to stay runnable in a lane
 * that has only the committed production one.
 */

declare(strict_types=1);

$plugin_root = dirname(__DIR__);
$floor_file  = __DIR__ . '/ASSERTION-FLOOR.json';
$failures    = [];

if (!is_file($floor_file)) {
    fwrite(STDERR, "FAIL: {$floor_file} is missing — there is no floor to check against\n");
    exit(1);
}

$floors = json_decode((string) file_get_contents($floor_file), true);
if (!is_array($floors) || [] === $floors) {
    fwrite(STDERR, "FAIL: ASSERTION-FLOOR.json did not parse into a non-empty array\n");
    exit(1);
}

/**
 * Totals from one JUnit file.
 *
 * PHPUnit writes nested <testsuite> elements and the ROOT carries the run's totals — so summing
 * every element would multiply the real numbers by the nesting depth. Counted from <testcase>
 * instead, which is unambiguous and also lets the incomplete tally be derived the same way the
 * runner reports it.
 *
 * @return array{tests:int,assertions:int,incomplete:int}|null
 */
function slimstat_junit_totals(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }

    $xml = @simplexml_load_file($path);
    if (false === $xml) {
        return null;
    }

    $totals = ['tests' => 0, 'assertions' => 0, 'incomplete' => 0];

    foreach ($xml->xpath('//testcase') ?: [] as $case) {
        $totals['tests']++;
        $totals['assertions'] += (int) ($case['assertions'] ?? 0);

        foreach ($case->children() as $child) {
            if (in_array($child->getName(), ['skipped', 'incomplete'], true)) {
                $totals['incomplete']++;
                break;
            }
        }
    }

    return $totals;
}

/**
 * The newest mtime across the code a suite is supposed to have exercised.
 *
 * FRESHNESS IS PART OF THE CHECK, not a nicety. `build/` is gitignored and never cleaned, so a
 * JUnit file survives branch switches indefinitely — and PHPUnit's JUnit logger flushes at the END
 * of a run, so a crashed run writes nothing at all and leaves the previous file in place.
 * Demonstrated both ways before this guard existed: back-dating the XML to 2015 still reported
 * PASS, and a PHPUnit that exited 255 without writing left the check validating whatever run last
 * happened to succeed — possibly on another branch.
 *
 * Inside `test:all` that is unreachable, because Composer aborts the chain on the first failing
 * script. But `composer test:assertion-floor` is a registered, directly invitable command, and a
 * gate that is only sound when invoked a particular way is a gate that will be invoked the other
 * way.
 */
function slimstat_newest_source_mtime(string $root): int
{
    $newest = 0;

    foreach (['src', 'admin', 'tests'] as $dir) {
        $path = $root . '/' . $dir;
        if (!is_dir($path)) {
            continue;
        }

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ('.php' !== substr($file->getPathname(), -4)) {
                continue;
            }
            $newest = max($newest, (int) $file->getMTime());
        }
    }

    return $newest;
}

$newest_source = slimstat_newest_source_mtime($plugin_root);
$measured      = [];

foreach ($floors as $suite => $floor) {
    $path = $plugin_root . '/' . $floor['junit'];
    $now  = slimstat_junit_totals($path);

    if (null !== $now && is_file($path) && (int) filemtime($path) < $newest_source) {
        $failures[] = sprintf(
            '%s: %s is OLDER than the newest PHP file in src/, admin/ or tests/ (%s vs %s). It '
                . 'describes a different tree — re-run `composer test:%s`. A JUnit file outlives '
                . 'the run that wrote it, and a crashed PHPUnit writes none at all, so an aged '
                . 'report is exactly what a green result would be made of',
            $suite,
            $floor['junit'],
            gmdate('Y-m-d H:i:s', (int) filemtime($path)),
            gmdate('Y-m-d H:i:s', $newest_source),
            $suite
        );
        continue;
    }

    if (null === $now) {
        $failures[] = sprintf(
            '%s: no readable JUnit at %s. Run `composer test:%s` first — this check reads what the '
                . 'suite reported and cannot conjure it, and a missing file must fail rather than '
                . 'pass, or the floor is satisfied by never running the tests at all',
            $suite,
            $floor['junit'],
            $suite
        );
        continue;
    }

    $measured[$suite] = $now;

    printf(
        "  %-12s tests %d (floor %d) · assertions %d (floor %d) · incomplete %d (ceiling %d)\n",
        $suite,
        $now['tests'],
        $floor['tests'],
        $now['assertions'],
        $floor['assertions'],
        $now['incomplete'],
        $floor['incomplete_max']
    );

    if ($now['tests'] < $floor['tests']) {
        $failures[] = sprintf('%s: %d tests, floor is %d — a test was deleted or stopped being collected',
            $suite, $now['tests'], $floor['tests']);
    }

    if ($now['assertions'] < $floor['assertions']) {
        $failures[] = sprintf(
            '%s: %d assertions against a floor of %d. THE SUITE LOST COVERAGE. If the test count '
                . 'held steady, something stopped executing without failing — a stub missing a '
                . 'method, a guard short-circuiting, a try/catch swallowing into markTestIncomplete. '
                . 'This is PITFALLS 41 exactly, and the number is the only place it shows',
            $suite,
            $now['assertions'],
            $floor['assertions']
        );
    }

    if ($now['incomplete'] > $floor['incomplete_max']) {
        $failures[] = sprintf(
            '%s: %d incomplete against a ceiling of %d — a test that used to run does not any more. '
                . 'Fix it, or raise the ceiling deliberately and say why',
            $suite,
            $now['incomplete'],
            $floor['incomplete_max']
        );
    }
}

if ($failures) {
    fwrite(STDERR, 'FAIL: assertion floor (' . count($failures) . " problem(s))\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    fwrite(STDERR, "\nRaise a floor only when the new number is the one you meant to produce.\n");
    exit(1);
}

echo "PASS: every suite is at or above its assertion floor\n";
