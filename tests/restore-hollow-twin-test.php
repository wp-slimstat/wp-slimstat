<?php
/** Restore-only hollow legacy uniques may proceed only behind a nonempty pinned twin. */

declare(strict_types=1);

define('SLIMSTAT_ANSWERS_LIB', true);
require __DIR__ . '/docker/report-answers.php';

$answered = [
    'uniques_browser_pinned' => ['class' => 'ok', 'value' => [['browser' => 'Chrome']]],
    'uniques_country_pinned' => ['class' => 'ok', 'value' => [['country' => 'us']]],
];

assert(true === slimstat_restored_uniques_twin_answers('uniques_browser', true, $answered));
assert(true === slimstat_restored_uniques_twin_answers('uniques_country', true, $answered));
assert(false === slimstat_restored_uniques_twin_answers('uniques_browser', false, $answered));
assert(false === slimstat_restored_uniques_twin_answers('top_resource', true, $answered));
assert(false === slimstat_restored_uniques_twin_answers('uniques_browser', true, []));
assert(false === slimstat_restored_uniques_twin_answers('uniques_browser', true, [
    'uniques_browser_pinned' => ['class' => 'empty', 'value' => []],
]));
assert(false === slimstat_restored_uniques_twin_answers('uniques_browser', true, [
    'uniques_browser_pinned' => ['class' => 'error', 'value' => [['browser' => 'Chrome']]],
]));

$driver = (string) file_get_contents(__DIR__ . '/docker/compare-answers.sh');
assert(false !== strpos($driver, '-e SLIMSTAT_RESTORED_CORPUS="$RESTORE_MODE"'));

echo "PASS: restored legacy uniques require successful nonempty pinned twins; synthetic stays strict\n";
