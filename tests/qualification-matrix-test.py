#!/usr/bin/env python3
"""Required-red controls for complete matrix enumeration and honest core attribution."""
import hashlib
import json
import os
import pathlib
import subprocess
import tempfile

helper = pathlib.Path(__file__).parent / 'docker/check-matrix.py'
cell = 'php7.4-wp5.6'
env = dict(os.environ, QUALIFICATION_FREE_SHA256='a' * 64, QUALIFICATION_PRO_SHA256='b' * 64)
with tempfile.TemporaryDirectory() as temp:
    root = pathlib.Path(temp)
    art = root / 'cells' / cell / 'artifacts'
    art.mkdir(parents=True)

    def put(name, value):
        (art / name).write_text(json.dumps(value))

    def base():
        for f in art.iterdir():
            f.unlink()
        put('free-installed.json', dict(slug='wp-slimstat', zip_sha256='a' * 64, shipping_files_verified=1))
        put('pro-installed.json', dict(slug='wp-slimstat-pro', zip_sha256='b' * 64, shipping_files_verified=1))
        put('cell.json', dict(cell=cell, php='7.4', wp='5.6', status='PASS', runtime_checks_complete=True))
        (art / 'wp-download.log').write_text('Downloaded WordPress 5.6')
        put('wp-availability.json', dict(requested_version='5.6', available=True, exit_status=0, log_sha256=hashlib.sha256((art / 'wp-download.log').read_bytes()).hexdigest()))
        put('core-requirements.json', dict(kind='declared-php-floor', wp_version='5.6', required_php='5.6.20', actual_php='7.4.33'))

    def run(expected, label, cells=None):
        r = subprocess.run(['python3', '-O', str(helper), str(root), *(cells or [cell])], env=env, capture_output=True)
        assert (r.returncode == 0) == expected, (label, r.stdout, r.stderr)

    base(); run(True, 'complete cell')
    for name in ['free-installed.json', 'pro-installed.json']:
        base(); (art / name).unlink(); run(False, 'missing installed bytes proof')
        base(); put(name, dict(slug='wp-slimstat', zip_sha256='c' * 64, shipping_files_verified=1)); run(False, 'wrong artifact digest')
        base(); put(name, dict(slug='wp-slimstat' if name.startswith('free') else 'wp-slimstat-pro', zip_sha256=('a' if name.startswith('free') else 'b') * 64, shipping_files_verified=0)); run(False, 'no shipping files verified')
    base()
    missing = dict(env); missing.pop('QUALIFICATION_FREE_SHA256')
    assert subprocess.run(['python3', '-O', str(helper), str(root), cell], env=missing, capture_output=True).returncode != 0
    base(); (art / 'cell.json').unlink(); run(False, 'missing cell')
    base(); (art / 'cell.json').write_text('{'); run(False, 'truncated cell')
    for status in ['FAIL', 'UNKNOWN', 'UNAVAILABLE-PREREQUISITE']:
        base(); put('cell.json', dict(cell=cell, php='7.4', wp='5.6', status=status, runtime_checks_complete=True)); run(False, status)
    base(); put('cell.json', dict(cell=cell, php='7.4', wp='5.6', status='PASS')); run(False, 'incomplete runtime')
    base(); (art / 'wp-availability.json').unlink(); run(False, 'missing availability')
    base(); (art / 'wp-download.log').write_text('changed'); run(False, 'changed download evidence')
    base(); run(False, 'unrun enumerated lane', [cell, 'php8.4-wp7.1'])
    for actual_floor, valid in [('8.0.0', True), ('5.6.20', False)]:
        base()
        put('cell.json', dict(cell=cell, php='7.4', wp='5.6', status='BLOCKED-BY-WP-CORE', runtime_checks_complete=False))
        requirements = dict(kind='declared-php-floor', wp_version='5.6', required_php=actual_floor, actual_php='7.4.33')
        put('core-requirements.json', requirements); put('core-incompatibility.json', requirements)
        run(valid, 'actual declared PHP floor')
    for origin, valid in [('wp-includes/example.php', True), ('wp-content/plugins/wp-slimstat/example.php', False)]:
        base()
        put('cell.json', dict(cell=cell, php='7.4', wp='5.6', status='BLOCKED-BY-WP-CORE', runtime_checks_complete=False))
        raw = ('Fatal error: incompatibility in /var/www/html/' + origin).encode()
        (art / 'core-incompatibility.log').write_bytes(raw)
        put('core-incompatibility.json', dict(kind='plugin-free-core-fatal', phase='core-install', log_sha256=hashlib.sha256(raw).hexdigest()))
        run(valid, 'core versus plugin fatal origin')
print('PASS: complete enumeration, availability, runtime completion and independently checked core evidence required')
