#!/usr/bin/env python3
"""The compatibility probe must refuse missing/wrong reports and degraded Pro boot."""
import pathlib
import subprocess
import tempfile

probe = pathlib.Path(__file__).parent.resolve() / 'docker/probe-pro-mixed-window.php'
with tempfile.TemporaryDirectory() as temp:
    root = pathlib.Path(temp)
    view = root / 'wp-slimstat/admin/view'
    view.mkdir(parents=True)
    (view / 'wp-slimstat-db.php').write_text('<?php')
    wrapper = root / 'probe.php'
    wrapper.write_text(r'''<?php
namespace WpSlimstatPro\Addon\Addons {
class EmailReportsAddon {
    static function get_report_output($rows, $columns, $title, $html) {
        return "Resource\n" . (getenv('PROBE_MODE') === 'wrong-csv' ? '/wrong' : $rows[0]['resource']) . "\n";
    }
}}
namespace {
define('ABSPATH', __DIR__); define('WP_PLUGIN_DIR', __DIR__);
class wp_slimstat_db {
    static function init() {}
    static function get_recent($column, $where, $having, $dates) {
        return getenv('PROBE_MODE') === 'empty' ? [] : [['resource' => '/rehearse-deferred-window']];
    }
}
function get_option($key, $default) { return getenv('PROBE_MODE') === 'degraded' ? ['pro_email' => 'failure'] : []; }
include $argv[1];
}
''')
    import os
    for mode in ['healthy', 'empty', 'wrong-csv', 'degraded']:
        r = subprocess.run(['php', str(wrapper), str(probe)], capture_output=True, env=dict(os.environ, PROBE_MODE=mode))
        assert (r.returncode == 0) == (mode == 'healthy'), (mode, r.stdout, r.stderr)
        if mode == 'healthy':
            assert b'PRO-MIXED-WINDOW:' in r.stdout
print('PASS: healthy mixed report accepted; empty report, incorrect CSV and Pro degradation refused')
