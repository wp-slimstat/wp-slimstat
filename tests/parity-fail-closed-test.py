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
