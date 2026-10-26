#!/usr/bin/env python3
"""Execute the real comparator: missing and non-answering evidence must fail."""
import copy
import json
from pathlib import Path
import subprocess
import tempfile

comparator = Path(__file__).parent / 'bench/lib/parity-compare.php'
report = dict(hash='abc', bytes=10, numbers=['12'], pairs={}, error=None)
base = dict(fingerprint_hash='fixture', stats_rows=12, anchor_date='2026-01-01',
            cells={'historical': {'report': report}})
with tempfile.TemporaryDirectory() as directory:
    root = Path(directory)
    def check(before, after, success):
        for name, value in [('before', before), ('after', after)]:
            (root / name).write_text(json.dumps(value))
        result = subprocess.run(['php', '-r',
            'define("ABSPATH", "/"); $args = array_slice($argv, 2); require $argv[1];',
            str(comparator), str(root / 'before'), str(root / 'after')], capture_output=True, text=True)
        assert (result.returncode == 0) == success, result.stdout + result.stderr
        assert ('VERDICT: PASS' in result.stdout) == success, result.stdout
    check(base, base, True)
    for field in ['fingerprint_hash', 'stats_rows', 'anchor_date', 'cells']:
        changed = copy.deepcopy(base)
        del changed[field]
        check(changed, changed, False)
    for field in report:
        changed = copy.deepcopy(base)
        del changed['cells']['historical']['report'][field]
        check(changed, changed, False)
    changed = copy.deepcopy(base)
    changed['cells']['historical']['extra'] = report
    check(base, changed, False)
    check(changed, base, False)
    changed = copy.deepcopy(base)
    changed['cells']['historical']['report']['error'] = 'real failure'
    check(changed, changed, False)
    check(changed, base, False)
    check(base, changed, False)
    changed = copy.deepcopy(base)
    changed['cells']['historical']['report']['bytes'] = 0
    check(changed, changed, False)
    changed = copy.deepcopy(base)
    changed['cells']['historical']['report']['hash'] = 'different'
    check(base, changed, False)
print('PASS: real parity comparator rejects missing reports, incomplete evidence, errors and differences')
# Execute the snapshot's actual extraction closures without booting WordPress.
probe = r'''
function wp_strip_all_tags($s) { return strip_tags($s); }
$s = file_get_contents($argv[1]);
$start = strpos($s, '$normalise =');
$end = strpos($s, '/**', strpos($s, '$extract_numbers ='));
eval(substr($s, $start, $end - $start));
if (count($extract_numbers(implode(' ', range(1, 250)))) !== 250) { exit(1); }
if ($normalise('2026-01-01 12:00:00') === $normalise('2026-01-02 12:00:00')) { exit(2); }
if ($normalise('1700000000') === $normalise('1700000001')) { exit(3); }
if ($extract_numbers('<p>Rank<span>0</span></p>') !== ['0']) { exit(4); }
if (!in_array('12', $extract_numbers('<div data-data="{&quot;totals&quot;:[{&quot;v1&quot;:12}]}"></div>'), true)) { exit(5); }
'''
subprocess.run(['php', '-r', probe, str(comparator.with_name('parity-snapshot.php'))], check=True)
print('PASS: snapshot preserves dates, chart payloads, element boundaries and all numeric values')

# Execute the snapshot's real coverage refusal: it may emit only a separately named,
# explicitly invalid diagnostic and must leave the accepted snapshot path absent.
coverage_probe = r'''
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
$source = file_get_contents($argv[1]);
$start = strpos($source, '$counts =');
$end = strpos($source, 'file_put_contents($out_path', $start);
$fragment = substr($source, $start, $end - $start);
$out_path = $argv[2]; $diagnostic_path = $argv[3];
putenv('SLIMSTAT_PARITY_DIAGNOSTIC=' . $diagnostic_path);
$snapshot = ['cells' => ['historical-filtered' => ['report-x' => [
    'error' => null, 'bytes' => 40, 'numbers' => [], 'pairs' => [],
]]]];
$cell_contexts = ['historical-filtered' => [
    'scope' => 'historical', 'filtered' => true, 'requested_filter' => 'fixture',
    'resolved_filter' => ['browser_type' => ['equals', '0']],
    'resolved_window' => ['start' => 1, 'end' => 2],
]];
register_shutdown_function(static function () use ($out_path, $diagnostic_path) {
    $diagnostic = json_decode((string) @file_get_contents($diagnostic_path), true);
    if (file_exists($out_path) || ($diagnostic['valid'] ?? true) !== false
        || ($diagnostic['verdict'] ?? '') !== 'diagnostic_only'
        || ($diagnostic['cells'][0]['report_id'] ?? '') !== 'report-x'
        || ($diagnostic['cells'][0]['refusal'] ?? '') !== 'render_only') {
        file_put_contents($diagnostic_path . '.failed', 'bad refusal');
    }
});
eval($fragment);
'''
with tempfile.TemporaryDirectory() as directory:
    root = Path(directory)
    result = subprocess.run(['php', '-r', coverage_probe,
        str(comparator.with_name('parity-snapshot.php')), str(root / 'accepted.json'),
        str(root / 'capture.diagnostic.invalid.json')], capture_output=True, text=True)
    assert result.returncode == 1, result.stdout + result.stderr
    assert not (root / 'accepted.json').exists()
    assert not (root / 'capture.diagnostic.invalid.json.failed').exists()
print('PASS: failed snapshot keeps accepted output absent and emits only invalid diagnostics')

bootstrap_probe = r'''
namespace SlimStat\Reports {
    class Bootstrap {
        static function get_instance() { return new self; }
        function init() { throw new \RuntimeException('registry failed'); }
    }
}
namespace {
    function is_user_logged_in() { return true; }
    define('SLIMSTAT_ANALYTICS_DIR', '/definitely-not-used/');
    class wp_slimstat_admin {}
    class wp_slimstat { public static $settings = []; }
    class wp_slimstat_reports { public static $reports = []; static function init() {} }
    require $argv[1];
    try { slimstat_bench_bootstrap_reports(); }
    catch (\RuntimeException $e) { exit($e->getMessage() === 'registry failed' ? 0 : 2); }
    exit(1);
}
'''
subprocess.run(['php', '-r', bootstrap_probe, str(comparator.with_name('reports-bootstrap.php'))], check=True)
print('PASS: report registry initialization failure cannot become a partial snapshot')
