#!/usr/bin/env python3
"""Non-Docker refusal and wiring checks for immutable C2 capture prep."""
import os
from pathlib import Path
import subprocess

script = Path(__file__).with_name('compare-answers.sh')
env = dict(os.environ, SLIMSTAT_SEED_PROFILE='seed-profile-verify.json',
           SLIMSTAT_RESTORE_DUMP='/definitely/missing/c2.sql.gz', WORK_ROOT='/tmp/c2-unused')
result = subprocess.run(['bash', str(script), 'HEAD', 'HEAD'], env=env,
                        capture_output=True, text=True)
assert result.returncode == 1 and 'restore dump not found' in result.stdout + result.stderr

source = script.read_text()
for required in ('DISABLE_WP_CRON true', '$o["is_tracking"] = "off"',
                 '$o["auto_purge"] = 0', 'SELECT MAX(dt) FROM wp_slim_stats',
                 "data['capture_windows']", 'exports/before/block-$last-fingerprints.json',
                 'SLIMSTAT_PARITY_DIAGNOSTIC=/tmp/slimstat-parity-diagnostic.json',
                 'block-$b-rep-$rep.diagnostic.invalid.json', 'return "$snapshot_rc"'):
    assert required in source, required
print('PASS: C2 capture prep refuses unpinned restore and wires immutable capture controls')
