<?php
/** Behavioral contracts for third-party outcome evidence. @license GPL-2.0-or-later */
if (PHP_SAPI !== 'cli') { exit(1); }
require __DIR__ . '/../src/Forms/Outcome.php';
use SlimStat\Forms\Outcome;

$cases = [
	['cf7', ['status' => 'mail_sent'], 'submitted'],
	['cf7', ['status' => 'validation_failed'], 'invalid'],
	['cf7', ['status' => 'spam'], 'spam'],
	['cf7', ['status' => 'mail_failed'], 'failed'],
	['cf7', ['status' => 'mail_sent', 'demo_mode' => true], 'unknown'],
	['cf7', ['status' => 'new_upstream_status'], 'unknown'],
	['gravity', ['status' => 'active'], 'submitted'],
	['gravity', ['status' => 'spam'], 'spam'],
	['gravity', [], 'unknown'],
	['formidable', ['is_draft' => '0'], 'submitted'],
	['formidable', ['is_draft' => '1'], 'unknown'],
	['formidable', ['is_draft' => '2'], 'unknown'],
	['formidable', ['is_draft' => '4', 'spam' => true], 'spam'],
	['formidable', ['is_draft' => null], 'unknown'],
	['forminator', ['success' => true], 'unknown'],
	['forminator', ['success' => true, 'entry_status' => 'active'], 'submitted'],
	['forminator', ['success' => true, 'entry_status' => 'spam'], 'spam'],
	['forminator', ['success' => true, 'entry_status' => 'pending'], 'unknown'],
	['forminator', ['success' => false, 'entry_status' => 'active'], 'failed'],
	['ninja', ['errors' => ['fields' => ['1' => 'invalid']]], 'invalid'],
	['ninja', ['form_id' => 1, 'processed_actions' => [], 'settings' => ['is_preview' => false]], 'submitted'],
	['ninja', ['form_id' => 1, 'processed_actions' => [], 'settings' => ['is_preview' => true]], 'unknown'],
	['ninja', [], 'unknown'],
	['ninja', ['errors' => null], 'unknown'],
	['unknown-plugin', ['success' => true], 'unknown'],
];
foreach ($cases as [$adapter, $evidence, $expected]) {
	$actual = Outcome::classify($adapter, $evidence);
	if ($actual !== $expected) { throw new RuntimeException($adapter . ': expected ' . $expected . ', got ' . $actual); }
}
foreach (['cf7', 'gravity', 'formidable', 'forminator', 'ninja'] as $adapter) {
	foreach ([null, false, 'success', 1, new stdClass()] as $malformed) {
		if ('unknown' !== Outcome::classify($adapter, $malformed)) { throw new RuntimeException('Malformed evidence must fail closed.'); }
	}
}
echo "PASS: outcome contracts reject spam, drafts, honeypot success and changed shapes\n";
