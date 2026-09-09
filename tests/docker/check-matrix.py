#!/usr/bin/env python3
"""Fail a compatibility matrix unless every enumerated lane has an answering verdict."""
import hashlib
import json
import os
import pathlib
import re
import sys

root = pathlib.Path(sys.argv[1])
expected = sys.argv[2:]
if not expected or len(expected) != len(set(expected)):
    raise SystemExit('nonempty unique matrix enumeration required')


def require(condition, message):
    if not condition:
        raise ValueError(message)


def version(value):
    require(isinstance(value, str) and re.fullmatch(r'\d+\.\d+(?:\.\d+)?', value), 'unrecognized runtime version')
    return tuple((list(map(int, value.split('.'))) + [0])[:3])


artifacts = {slug: os.environ.get('QUALIFICATION_' + kind + '_SHA256', '')
             for kind, slug in [('FREE', 'wp-slimstat'), ('PRO', 'wp-slimstat-pro')]}
for digest in artifacts.values():
    require(re.fullmatch(r'[0-9a-f]{64}', digest), 'exact paired artifact digests required')

results = []
errors = []
for cell in expected:
    art = root / 'cells' / cell / 'artifacts'
    try:
        match = re.fullmatch(r'php(\d+\.\d+)-wp(\d+\.\d+(?:\.\d+)?)', cell)
        require(match is not None, 'unknown cell identity')
        php, wp = match.groups()
        result = json.loads((art / 'cell.json').read_text())
        require(isinstance(result, dict), 'verdict is not an object')
        require((result.get('cell'), result.get('php'), result.get('wp')) == (cell, php, wp), 'mismatched cell identity')
        results.append(result)
        require(result.get('status') in ('PASS', 'BLOCKED-BY-WP-CORE'), 'failed, unavailable, or unknown outcome: ' + str(result.get('status')))
        availability = json.loads((art / 'wp-availability.json').read_text())
        require(availability.get('available') is True and type(availability.get('exit_status')) is int and availability['exit_status'] == 0 and availability.get('requested_version') == wp, 'core download not proven available')
        require(hashlib.sha256((art / 'wp-download.log').read_bytes()).hexdigest() == availability.get('log_sha256'), 'download log checksum mismatch')
        runtime = json.loads((art / 'core-requirements.json').read_text())
        require(runtime.get('wp_version') == wp, 'downloaded WordPress version mismatch')
        actual, floor = version(runtime.get('actual_php')), version(runtime.get('required_php'))
        require(actual[:2] == version(php)[:2], 'actual PHP lane mismatch')
        if result['status'] == 'PASS':
            require(result.get('runtime_checks_complete') is True and actual >= floor, 'runtime checks incomplete or unsupported core PHP floor')
            for kind, slug in [('free', 'wp-slimstat'), ('pro', 'wp-slimstat-pro')]:
                installed = json.loads((art / (kind + '-installed.json')).read_text())
                require(installed.get('slug') == slug and installed.get('zip_sha256') == artifacts[slug], 'installed artifact identity mismatch')
                require(type(installed.get('shipping_files_verified')) is int and installed['shipping_files_verified'] > 0, 'shipping bytes not verified')
            continue
        evidence = json.loads((art / 'core-incompatibility.json').read_text())
        if evidence.get('kind') == 'declared-php-floor':
            require(evidence == runtime and actual < floor, 'declared core PHP incompatibility not proven')
        else:
            require(evidence.get('kind') == 'plugin-free-core-fatal' and evidence.get('phase') in ('core-install', 'core-http-boot'), 'unverified core incompatibility')
            raw = (art / 'core-incompatibility.log').read_bytes()
            text = raw.decode(errors='replace')
            require(hashlib.sha256(raw).hexdigest() == evidence.get('log_sha256'), 'core fatal log checksum mismatch')
            require(re.search(r'Fatal error|Parse error|Uncaught', text, re.I) and re.search(r'/var/www/html/(wp-includes/|wp-admin/|wp-[a-z-]+\.php)', text), 'no core-origin fatal')
            require(not re.search(r'/wp-content/(plugins|mu-plugins)/', text), 'plugin-origin failure is not core incompatibility')
    except (OSError, ValueError, KeyError, TypeError, AttributeError) as exc:
        errors.append({'cell': cell, 'error': str(exc)})
print(json.dumps({'artifacts': artifacts, 'expected_cells': expected, 'results': results, 'errors': errors, 'status': 'FAIL' if errors else 'PASS'}, indent=2))
raise SystemExit(bool(errors))
