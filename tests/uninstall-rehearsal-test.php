<?php
declare(strict_types=1);
$oracle = __DIR__ . '/docker/uninstall-oracle.php';
if (!is_file($oracle)) { fwrite(STDERR, "FAIL: uninstall oracle absent\n"); exit(1); }
require $oracle;
$before = ['tables' => ['local' => ['owned' => 'row-hash'], 'external' => ['owned' => 'row-hash']],
    'sentinels' => ['unrelated' => 'sentinel-hash'], 'blogs' => ['1' => ['settings' => true, 'layout_metadata' => true, 'credentials' => true,
    'free_setting' => 'on', 'pro_setting' => true, 'free_cron' => ['first' => true, 'second' => true], 'pro_cron' => ['pro' => true]]],
    'network_credentials' => true, 'network_free_setting' => 'on', 'network_pro_setting' => true,
    'files' => ['geo' => 'geo-hash', 'cache' => 'cache-hash', 'unrelated' => 'sentinel-hash'],
    'plugins' => ['free' => true, 'pro' => false]];
$after = $before;
$after['blogs']['1']['credentials'] = false;
$after['blogs']['1']['free_cron'] = ['first' => false, 'second' => false];
$after['network_credentials'] = false;
$after['files']['cache'] = null;
$after['plugins']['free'] = false;
$checks = 0;
$assert = static function ($ok, $label) use (&$checks) { ++$checks; if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); } };
$assert([] === slimstat_uninstall_compare($before, $after, 'keep', 'local', false), 'healthy keep');
foreach (['credential', 'data', 'sentinel', 'hook', 'partial-cron'] as $control) {
    $bad = $after;
    if ('credential' === $control) { $bad['blogs']['1']['credentials'] = true; }
    if ('data' === $control) { $bad['tables']['local']['owned'] = null; }
    if ('sentinel' === $control) { $bad['sentinels']['unrelated'] = null; }
    if ('partial-cron' === $control) { $bad['blogs']['1']['free_cron']['second'] = true; }
    if ('hook' === $control) { $bad = $before; }
    $assert([] !== slimstat_uninstall_compare($before, $bad, 'keep', 'local', false), 'required-red ' . $control);
}
$deleted = $after;
$deleted['tables']['external']['owned'] = null;
$deleted['blogs']['1']['settings'] = false;
$deleted['blogs']['1']['layout_metadata'] = false;
$deleted['blogs']['1']['free_setting'] = null;
$deleted['blogs']['1']['pro_setting'] = false;
$deleted['files']['geo'] = null;
$assert([] === slimstat_uninstall_compare($before, $deleted, 'delete', 'external', false), 'healthy external delete');
$refused = $deleted;
$refused['files'] = $before['files'];
$refused['tables']['local']['owned'] = null;
$refused['tables']['external']['owned'] = $before['tables']['external']['owned'];
$assert([] === slimstat_uninstall_compare($before, $refused, 'delete-path-refused', 'local', false), 'unsafe upload path refused while opt-in tables delete');
$refused['files']['geo'] = null;
$assert([] !== slimstat_uninstall_compare($before, $refused, 'delete-path-refused', 'local', false), 'path refusal control');
$deleted['tables']['local']['owned'] = null;
$assert([] !== slimstat_uninstall_compare($before, $deleted, 'delete', 'external', false), 'wrong database red');
$assert([] === slimstat_uninstall_compare($before, array_replace($before, ['plugins' => ['free' => false, 'pro' => false]]), 'cli-delete', 'local', false), 'CLI file deletion preserves uninstall-owned resources');
$proBefore = $before;
$proBefore['plugins']['pro'] = true;
$proAfter = $proBefore;
$proAfter['plugins']['pro'] = false;
$proAfter['blogs']['1']['pro_setting'] = false;
$proAfter['blogs']['1']['pro_cron'] = ['pro' => false];
$proAfter['network_pro_setting'] = false;
$assert([] === slimstat_uninstall_compare($proBefore, $proAfter, 'pro', 'external', true), 'validate intermediate Pro uninstall');
$final = $proAfter;
$final['tables']['external']['owned'] = null;
$final['blogs']['1'] = ['settings' => false, 'layout_metadata' => false, 'credentials' => false, 'free_setting' => null,
    'pro_setting' => false, 'free_cron' => ['first' => false, 'second' => false], 'pro_cron' => ['pro' => false]];
$final['network_credentials'] = false;
$final['files']['geo'] = null;
$final['files']['cache'] = null;
$final['plugins']['free'] = false;
$assert([] === slimstat_uninstall_compare($proAfter, $final, 'delete', 'external', false), 'Free deletion uses validated intermediate baseline');
$assert([] !== slimstat_uninstall_compare($proBefore, $final, 'delete', 'external', false), 'original baseline cannot model already removed Pro assets');
$src = file_get_contents(__DIR__ . '/docker/rehearse-uninstall.sh');
$assert(false !== strpos($src, 'compare_before="$case_art/after-pro.json"'), 'sequence selects validated intermediate baseline');
$assert(false !== strpos($src, '"$compare_before" "$case_art/after.json"'), 'comparison consumes selected baseline');
foreach (['source ', 'lib.sh', 'write_verdict', 'publish_verdict', 'plugin uninstall', 'plugin delete', 'credentials', 'drop-retained', 'retain-deleted', 'fixture_sha256', 'image'] as $needle) {
    $assert(false !== strpos($src, $needle), 'harness contains ' . $needle);
}
echo "PASS: uninstall rehearsal oracle and controls ($checks checks)\n";
