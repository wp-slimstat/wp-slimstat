<?php
/** Cohort and denominator invariants. @license GPL-2.0-or-later */
if (PHP_SAPI !== 'cli') { exit(1); }
require __DIR__ . '/../src/Forms/Metrics.php';
use SlimStat\Forms\Metrics;
$expect = static function ($condition, $message) { if (!$condition) { throw new RuntimeException($message); } };
$serverOnly = Metrics::counters(['flags' => 64]);
$expect($serverOnly['submitted'] === 1 && $serverOnly['seen'] === 0 && $serverOnly['converted'] === 0, 'Server success cannot fabricate observed visibility.');
$complete = Metrics::counters(['flags' => 71, 'duration' => 42]);
$expect($complete['converted'] === 1 && $complete['completed_starts'] === 1 && $complete['duration'] === 42, 'The same observed session completes the funnel.');
$error = Metrics::counters(['flags' => 15]);
$expect($error['invalid'] === 1 && $error['submitted'] === 0, 'Validation must not count as submission.');
$expect(Metrics::counters(['flags' => 71 | 71, 'duration' => 42]) === $complete, 'Retries are idempotent.');
$expect(Metrics::rate(0, 0) === null, 'No denominator is unknown, not zero.');
$expect(Metrics::rate(2, 10) === 20.0, 'Conversion rate uses the observed cohort.');
echo "PASS: cohort, retry and denominator invariants\n";
