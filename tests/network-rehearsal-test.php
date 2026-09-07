<?php
declare(strict_types=1);
$file = __DIR__ . '/docker/network-rehearsal-oracle.php';
if (!is_file($file)) { fwrite(STDERR, "FAIL: network rehearsal oracle absent\n"); exit(1); }
require $file;
$before = ['blogs' => ['1' => ['fingerprint' => 'one', 'missing' => ['meta'], 'archived' => false, 'domain' => 'example.test', 'path' => '/'],
    '2' => ['fingerprint' => 'two', 'missing' => ['meta'], 'archived' => true, 'domain' => 'example.test', 'path' => '/site-2/']], 'pending' => []];
$after = $before;
foreach ($after['blogs'] as &$blog) { $blog['missing'] = []; }
unset($blog);
$n = 0;
$check = static function ($ok, $label) use (&$n) { ++$n; if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); } };
$check([] === slimstat_network_rehearsal_compare($before, $after), 'healthy per-blog completion');
foreach (['missing', 'fingerprint', 'archived', 'domain', 'path', 'pending'] as $kind) {
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
// Exercise the real provisioning expression in both native WordPress network modes.
$subdomains = false;
$inserted = [];
function is_subdomain_install() { global $subdomains; return $subdomains; }
function get_network() { return (object) ['domain' => 'example.test']; }
function get_current_network_id() { return 1; }
function wp_insert_site($site) { global $inserted; $inserted = $site; return 2; }
require_once __DIR__ . '/lib/source-scan.php';
$probe = slimstat_blank_comments(file_get_contents(__DIR__ . '/docker/probe-network-rehearsal.php'));
$check(1 === preg_match('/\$id = wp_insert_site\((.*?)\);/s', $probe, $matches), 'real site creation expression located');
foreach ([false, true] as $subdomains) {
    $i = 2;
    eval($matches[0]);
    $check($inserted['domain'] === ($subdomains ? 'site-2.example.test' : 'example.test'), 'native network domain');
    $check($inserted['path'] === ($subdomains ? '/' : '/site-2/'), 'native network path');
}
$bad = $before;
unset($bad['blogs']['2']['domain']);
$check([] !== slimstat_network_rehearsal_compare($bad, $after), 'missing topology evidence refused');
$observer = file_get_contents(__DIR__ . '/docker/network-rehearsal-observer.php');
$check(false !== strpos($observer, "add_action('update_site_option',"), 'observer uses the actual core post-write action');
$installer = file_get_contents(__DIR__ . '/docker/lib.sh');
$check(false !== strpos($installer, 'array_merge(wp_slimstat::init_options(),'), 'vintage fixtures include arm-owned defaults');
$product = file_get_contents(dirname(__DIR__) . '/wp-slimstat.php');
$check(
    false !== strpos($product, "self::\$settings['auto_purge'] ?? 0"),
    'purge health check tolerates settings that are not initialized during activation'
);
$src = file_get_contents(__DIR__ . '/docker/rehearse-upgrade-network.sh');
foreach (['resolve_arm_zip', 'run_vintage_installer "$site_url" </dev/null', 'installed_sites" -eq 101', 'kill -9', 'interrupted.json', '/wp-admin/network/', 'no-resume', 'write_verdict', 'publish_verdict', 'image inspect', 'fixture_sha256'] as $needle) {
    $check(false !== strpos($src, $needle), 'network harness: ' . $needle);
}
echo "PASS: network rehearsal controls ($n checks)\n";
