<?php
declare(strict_types=1);
$file = __DIR__ . '/docker/network-rehearsal-oracle.php';
if (!is_file($file)) { fwrite(STDERR, "FAIL: network rehearsal oracle absent\n"); exit(1); }
require $file;
$before = ['blogs' => ['1' => ['fingerprint' => 'one', 'missing' => ['meta'], 'archived' => false],
    '2' => ['fingerprint' => 'two', 'missing' => ['meta'], 'archived' => true]], 'pending' => []];
$after = $before;
foreach ($after['blogs'] as &$blog) { $blog['missing'] = []; }
unset($blog);
$n = 0;
$check = static function ($ok, $label) use (&$n) { ++$n; if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); } };
$check([] === slimstat_network_rehearsal_compare($before, $after), 'healthy per-blog completion');
foreach (['missing', 'fingerprint', 'archived', 'pending'] as $kind) {
    $bad = $after;
    if ('pending' === $kind) { $bad['pending'] = [2]; }
    elseif ('missing' === $kind) { $bad['blogs']['2'][$kind] = ['meta']; }
    elseif ('archived' === $kind) { $bad['blogs']['2'][$kind] = false; }
    else { $bad['blogs']['2'][$kind] = 'one'; }
    $check([] !== slimstat_network_rehearsal_compare($before, $bad), 'required-red ' . $kind);
}
foreach (['' => 'eval', 'http://example.test/site-2/' => '--url=http://example.test/site-2/'] as $url => $expected) {
    $script = 'source ' . escapeshellarg(__DIR__ . '/docker/lib.sh') . '; wpc() { printf "%s" "$1"; }; run_vintage_installer' . ('' === $url ? '' : ' ' . escapeshellarg($url));
    $output = []; $rc = 0;
    exec('bash -c ' . escapeshellarg($script), $output, $rc);
    $check(0 === $rc && [$expected] === $output, 'shared vintage installer site routing');
}
$observer = file_get_contents(__DIR__ . '/docker/network-rehearsal-observer.php');
$check(false !== strpos($observer, "add_action('update_site_option',"), 'observer uses the actual core post-write action');
$installer = file_get_contents(__DIR__ . '/docker/lib.sh');
$check(false !== strpos($installer, 'array_merge(wp_slimstat::init_options(),'), 'vintage fixtures include arm-owned defaults');
$src = file_get_contents(__DIR__ . '/docker/rehearse-upgrade-network.sh');
foreach (['resolve_arm_zip', 'run_vintage_installer "$site_url" </dev/null', 'installed_sites" -eq 101', 'kill -9', 'interrupted.json', '/wp-admin/network/', 'no-resume', 'write_verdict', 'publish_verdict', 'image inspect', 'fixture_sha256'] as $needle) {
    $check(false !== strpos($src, $needle), 'network harness: ' . $needle);
}
echo "PASS: network rehearsal controls ($n checks)\n";
