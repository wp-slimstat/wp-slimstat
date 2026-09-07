#!/usr/bin/env python3
"""Behavioral controls for the host DDL observer, independent of Docker."""
import pathlib
import subprocess
import tempfile

harness = pathlib.Path(__file__).parent.resolve() / 'docker'
with tempfile.TemporaryDirectory() as temp:
    root = pathlib.Path(temp)
    for name, state, result, expected in [
        ('interrupted', 'altering table', '{"index":false}', 0),
        ('success-is-not-interruption', 'altering table', '{"index":true}', 1),
        ('no-observation', '', '{"index":false}', 1),
        ('fatal-is-not-proof', 'altering table', '', 1),
    ]:
        art = root / name
        art.mkdir()
        script = r'''
source "$1/interrupt-ddl.sh"
HARNESS_DIR="$1"; ART="$2"; observation="$3"; outcome="$4"
dc() { return 0; }
wpc() {
  if [ -n "$outcome" ]; then printf 'DDL-WORKER:%s\n' "$outcome"; fi
  case "$outcome" in *false*) return 1;; '') return 1;; *) return 0;; esac
}
mysql_q() { [ -z "$observation" ] || printf '42\t%s\tALTER TABLE wp_slim_stats ADD INDEX idx (dt)\n' "$observation"; }
mysql_exec() { [ "$1" = 'KILL QUERY 42;' ]; }
interrupt_migration_ddl
'''
        r = subprocess.run(['bash', '-c', script, 'test', str(harness), str(art), state, result], capture_output=True)
        assert (r.returncode == 0) == (expected == 0), (name, r.stdout, r.stderr)
print('PASS: observed interrupted DDL required; success, missing observation, and fatal refused')
